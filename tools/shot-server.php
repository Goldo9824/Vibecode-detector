<?php
declare(strict_types=1);

/**
 * The renderer, for an operator who has a real server to run it on.
 *
 * The site itself is built for shared hosting, which cannot render a page —
 * see lib/Snapshot.php. This is the other half: a machine you control takes
 * the screenshot and hands it back, so no third party is told which addresses
 * your visitors are checking. It is one file on purpose. Copy it to a server
 * that has Chromium and start it:
 *
 *     VCD_SHOT_SECRET=$(openssl rand -hex 32) \
 *       php -S 0.0.0.0:8791 tools/shot-server.php
 *
 * Then put the same secret and that address in data/snapshot-config.php on
 * the site. Nothing else is installed, and nothing is written down: the page
 * is rendered to a temporary file, sent, and deleted.
 *
 * Dropping it into an existing docroot works as well as the built-in server —
 * it is an ordinary PHP script and reads its settings from the environment:
 *
 *   VCD_SHOT_SECRET         required; the shared secret, same on both ends
 *   VCD_SHOT_BROWSER        path to Chromium, if it is not on the usual list
 *   VCD_SHOT_BROWSER_FLAGS  extra Chromium flags, one per line or separated by
 *                           semicolons (a proxy, a language, a user agent) —
 *                           neither character appears inside a Chromium flag,
 *                           so a flag whose value has spaces still works
 *   VCD_SHOT_MAX_CONCURRENT how many renders may run at once (default 4)
 *   VCD_SHOT_ALLOW_PRIVATE  set to 1 only when testing against localhost
 *
 * See docs/SNAPSHOTS.md for the whole setup, including running it behind a
 * real web server with TLS.
 */

const SHOT_TIMEOUT   = 20;      // seconds before a render is abandoned
const SHOT_MAX_SKEW  = 600;     // how long a signed request stays valid
const SHOT_MAX_PIXEL = 2000;

$secret = (string) getenv('VCD_SHOT_SECRET');

header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

if ($secret === '') {
    // Refusing to run without one is the point: an unauthenticated renderer
    // on a public address is a screenshot service for the whole internet,
    // billed to whoever set it up.
    shot_fail(500, 'This renderer has no VCD_SHOT_SECRET set, so it will not answer.');
}

$url = isset($_GET['url']) ? (string) $_GET['url'] : '';
$w   = isset($_GET['w']) ? (int) $_GET['w'] : 1200;
$h   = isset($_GET['h']) ? (int) $_GET['h'] : 900;
$exp = isset($_GET['e']) ? (int) $_GET['e'] : 0;
$sig = isset($_GET['t']) ? (string) $_GET['t'] : '';

if ($url === '' || $sig === '') {
    shot_fail(400, 'Ask for a url, signed.');
}

// The signature covers the size and the expiry as well as the address, so a
// request captured from a log is neither replayable later nor editable into a
// request for something else.
$expected = hash_hmac('sha256', $url . '|' . $w . '|' . $h . '|' . $exp, $secret);
if (!hash_equals($expected, $sig)) {
    shot_fail(401, 'That request is not signed by this renderer\'s secret.');
}
if ($exp < time() || $exp > time() + SHOT_MAX_SKEW) {
    shot_fail(401, 'That request has expired.');
}

if (!shot_public_url($url)) {
    shot_fail(403, 'That address is not a public web page.');
}

$w = max(320, min(SHOT_MAX_PIXEL, $w));
$h = max(240, min(SHOT_MAX_PIXEL, $h));

// A signed request can still arrive faster than a browser can be started, and
// every render is a whole Chromium. Past this many at once the honest answer
// is "busy" — the site treats a 5xx as "still working" and asks again.
$slot = shot_slot();
if ($slot === null) {
    header('Retry-After: 5');
    shot_fail(503, 'This renderer is busy. Try again shortly.');
}

$png = shot_render($url, $w, $h);
if ($png === null) {
    shot_fail(502, 'The page could not be rendered.');
}

header('Content-Type: image/png');
header('Content-Length: ' . strlen($png));
echo $png;

/**
 * Chromium, headless, once — and once more the old way if this build predates
 * --headless=new, which is the one difference between Chromium versions worth
 * coding around rather than documenting.
 */
function shot_render(string $url, int $w, int $h): ?string
{
    $browser = shot_browser();
    if ($browser === null) {
        error_log('vcd-shot: no Chromium found; set VCD_SHOT_BROWSER');
        return null;
    }

    foreach (array('--headless=new', '--headless') as $headless) {
        $png = shot_run($browser, $headless, $url, $w, $h);
        if ($png !== null) {
            return $png;
        }
    }

    return null;
}

function shot_run(string $browser, string $headless, string $url, int $w, int $h): ?string
{
    $dir = shot_tempdir();
    if ($dir === null) {
        return null;
    }
    $file = $dir . '/shot.png';

    $flags = array(
        $headless,
        '--disable-gpu',
        '--hide-scrollbars',
        '--mute-audio',
        '--no-first-run',
        '--no-default-browser-check',
        '--disable-extensions',
        // Containers usually mount a tiny /dev/shm, which Chromium fills and
        // then dies with an error that looks like anything but a full disk.
        '--disable-dev-shm-usage',
        // Its own profile per render: two Chromiums sharing one would fight,
        // and this way there is nothing left behind to share.
        '--user-data-dir=' . $dir . '/profile',
        // Let the page's own scripts and fonts settle, then stop waiting.
        '--virtual-time-budget=8000',
        '--window-size=' . $w . ',' . $h,
        '--screenshot=' . $file,
    );

    // Whatever else this particular setup needs: a --proxy-server, a --lang,
    // a --user-agent. Anything Chromium understands, passed through as given.
    $extra = trim((string) getenv('VCD_SHOT_BROWSER_FLAGS'));
    if ($extra !== '') {
        foreach (preg_split('~[;\r\n]+~', $extra) as $flag) {
            $flag = trim((string) $flag);
            if ($flag !== '') {
                $flags[] = $flag;
            }
        }
    }

    // Chromium refuses to run as root with its sandbox on. Running as root is
    // a container habit rather than a good idea, but refusing to render is not
    // a useful answer either, so the sandbox is dropped only in that case.
    if (function_exists('getmyuid') && getmyuid() === 0) {
        $flags[] = '--no-sandbox';
    }

    $cmd = escapeshellcmd($browser);
    foreach ($flags as $flag) {
        $cmd .= ' ' . escapeshellarg($flag);
    }
    $cmd .= ' ' . escapeshellarg($url) . ' 2>/dev/null';

    shot_exec($cmd, SHOT_TIMEOUT);

    $png = is_readable($file) ? (string) file_get_contents($file) : '';
    shot_rmdir($dir);

    // Chromium exits 0 on plenty of failures, so the file is the test: a real
    // screenshot starts with the PNG magic number and is not a stub.
    if (strncmp($png, "\x89PNG\r\n\x1A\n", 8) !== 0 || strlen($png) < 512) {
        return null;
    }

    return $png;
}

/** Run a command, and kill it if it outstays the timeout. */
function shot_exec(string $cmd, int $timeout): void
{
    $descriptors = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
    $proc = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        return;
    }
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }

    $deadline = microtime(true) + $timeout;
    while (microtime(true) < $deadline) {
        $status = proc_get_status($proc);
        if (!$status['running']) {
            proc_close($proc);
            return;
        }
        usleep(100000);
    }

    proc_terminate($proc, 9);
    proc_close($proc);
}

function shot_browser(): ?string
{
    $configured = (string) getenv('VCD_SHOT_BROWSER');
    if ($configured !== '') {
        return is_executable($configured) ? $configured : null;
    }

    $candidates = array(
        'chromium', 'chromium-browser', 'chrome',
        'google-chrome', 'google-chrome-stable', 'headless_shell',
    );
    foreach ($candidates as $name) {
        $path = trim((string) @shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));
        if ($path !== '' && is_executable($path)) {
            return $path;
        }
    }

    return null;
}

/**
 * A slot from a fixed set, held by a lock rather than a counter: a crashed
 * render releases its own slot when the process dies, which a file of numbers
 * would not.
 */
function shot_slot()
{
    $max = (int) getenv('VCD_SHOT_MAX_CONCURRENT');
    if ($max <= 0) {
        $max = 4;
    }

    $dir = sys_get_temp_dir() . '/vcd-shot-slots';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return false; // no lock directory: let the render through rather than refuse everything
    }

    for ($i = 0; $i < $max; $i++) {
        $handle = @fopen($dir . '/' . $i, 'c');
        if ($handle === false) {
            return false;
        }
        if (flock($handle, LOCK_EX | LOCK_NB)) {
            return $handle; // held until this request ends and PHP closes it
        }
        fclose($handle);
    }

    return null;
}

function shot_tempdir(): ?string
{
    $dir = sys_get_temp_dir() . '/vcd-shot-' . bin2hex(random_bytes(8));
    return @mkdir($dir, 0700, true) ? $dir : null;
}

function shot_rmdir(string $dir): void
{
    $items = @scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path) && !is_link($path)) {
            shot_rmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

/**
 * Public web addresses only.
 *
 * The site checks this too before it signs anything, but a renderer that will
 * screenshot whatever it is asked for is a window into the network it runs on,
 * and it is the machine with the browser on it. So it checks for itself.
 */
function shot_public_url(string $url): bool
{
    if (getenv('VCD_SHOT_ALLOW_PRIVATE') === '1') {
        return (bool) preg_match('~^https?://~i', $url);
    }

    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return false;
    }
    $scheme = strtolower((string) $parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        return false;
    }

    $host = strtolower((string) $parts['host']);
    $ips  = filter_var($host, FILTER_VALIDATE_IP) ? array($host) : shot_resolve($host);
    if (!$ips) {
        return false;
    }
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
    }

    return true;
}

/** @return string[] */
function shot_resolve(string $host): array
{
    $ips = array();
    $v4 = @gethostbynamel($host);
    if (is_array($v4)) {
        $ips = $v4;
    }
    $records = @dns_get_record($host, DNS_AAAA);
    if (is_array($records)) {
        foreach ($records as $record) {
            if (!empty($record['ipv6'])) {
                $ips[] = (string) $record['ipv6'];
            }
        }
    }

    return $ips;
}

function shot_fail(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('ok' => false, 'error' => $message)), "\n";
    exit;
}
