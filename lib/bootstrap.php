<?php
declare(strict_types=1);

/**
 * Shared entry point. Every public script requires this first.
 *
 * There is no autoloader, no container and no framework, because the target is
 * LWS shared hosting: upload the folder over FTP and it runs. Nothing here may
 * assume Composer, a writable temp dir, a database or a CLI.
 */

define('VCD_VERSION', '1.0.0');
define('VCD_ROOT', dirname(__DIR__));
define('VCD_DATA', VCD_ROOT . '/data');
define('VCD_SITE_URL', 'https://vibecodedetector.fanficnow.com');
define('VCD_REPO_URL', 'https://github.com/goldo9824/vibecode-detector');

/**
 * Per-IP request budgets, as [requests, window in seconds].
 *
 * Named rather than written inline at the call sites because they are coupled:
 * a crawl spends from the url bucket as well as its own, so url has to be
 * roomy enough that it never becomes the real crawl limit. VCD_LIMIT_URL is
 * sized against VCD_LIMIT_CRAWL for exactly that reason, and a test asserts
 * the relationship still holds.
 */
define('VCD_LIMIT_URL',   array(40, 600));
define('VCD_LIMIT_CRAWL', array(10, 180));
define('VCD_LIMIT_CODE',  array(60, 600));
define('VCD_LIMIT_GIT',   array(60, 600));

/**
 * Budget for authenticated API callers (api/website.php), keyed by the API
 * key itself rather than by IP — one key is meant to be shared across
 * whatever machines its holder runs, so the budget should not be split (or
 * multiplied) by how many addresses happen to use it. Deliberately far
 * roomier than VCD_LIMIT_URL: a caller trusted enough to hold a key is
 * trusted more than an anonymous visitor, and the global fetch-concurrency
 * cap (VCD_MAX_CONCURRENT_FETCHES) is the backstop that actually protects
 * shared hosting, not this number.
 */
define('VCD_LIMIT_API_URL', array(500, 600));

/**
 * Global cap on url-mode fetches in flight at once, across every visitor.
 *
 * vcd_rate_limit() bounds one IP's own use of the tool; it does nothing
 * against a flood of slow requests spread across many different addresses,
 * each comfortably under its own limit, arriving at the same time. On shared
 * hosting the scarce resource is the worker pool itself — a handful of
 * PHP-FPM children — and a url-mode fetch that blocks on a slow or
 * unresponsive site for several seconds holds one of them hostage. This is
 * sized well under a typical shared-hosting worker count, so a saturated
 * fetch queue fails a request with a plain "try again" rather than the whole
 * site, code and git tabs included, going unresponsive with it.
 */
define('VCD_MAX_CONCURRENT_FETCHES', 8);

/** How long a slot is honoured for before it is treated as abandoned. */
define('VCD_SLOT_TTL', 40);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/ApiKeys.php';
require_once __DIR__ . '/Catalog.php';
require_once __DIR__ . '/Report.php';
require_once __DIR__ . '/Text.php';
require_once __DIR__ . '/CodeAnalyzer.php';
require_once __DIR__ . '/SiteAnalyzer.php';
require_once __DIR__ . '/GitAnalyzer.php';
require_once __DIR__ . '/SiteSurvey.php';

/**
 * The public base URL of this installation.
 *
 * Derived from the request rather than hardcoded, because the certificate
 * bakes a "verify at" address into the PDF and that address has to be the host
 * the certificate was actually issued by. A constant gets this wrong the moment
 * the site is reachable at more than one name.
 *
 * Host is client-controlled, so it is format-checked before use. Nothing
 * security-relevant hangs off it: the worst a forged Host achieves is a PDF
 * with a wrong link, issued to the person who forged it.
 */
function vcd_site_url(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
    if ($host === '' || !preg_match('~^[a-z0-9]([a-z0-9.\-]{0,253}[a-z0-9])?(:\d{1,5})?$~i', $host)) {
        return $cached = VCD_SITE_URL;
    }

    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

    $scriptName = isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '';
    $path = $scriptName === '' ? '' : vcd_url_base($scriptName, vcd_script_depth());

    return $cached = ($https ? 'https://' : 'http://') . $host . $path;
}

/**
 * How many directory levels below the app root the running script sits.
 *
 * api/certificate.php is one; index.php is zero. This is a filesystem fact
 * about the project's own layout, which is knowable and stable, unlike where
 * the host has decided to put its document root.
 */
function vcd_script_depth(): int
{
    $script = isset($_SERVER['SCRIPT_FILENAME']) ? realpath((string) $_SERVER['SCRIPT_FILENAME']) : false;
    $appRoot = realpath(VCD_ROOT);
    if ($script === false || $appRoot === false) {
        return 0;
    }

    $dir  = rtrim(str_replace('\\', '/', dirname($script)), '/');
    $root = rtrim(str_replace('\\', '/', $appRoot), '/');
    if ($dir === $root) {
        return 0;
    }
    if (strpos($dir, $root . '/') !== 0) {
        return 0;
    }

    $relative = trim(substr($dir, strlen($root)), '/');
    return $relative === '' ? 0 : substr_count($relative, '/') + 1;
}

/**
 * The URL directory the app is mounted at.
 *
 * Derived from the request path rather than from DOCUMENT_ROOT, because the
 * two are not related in the way the earlier version assumed. On this host the
 * app lives in a directory named after its own domain while DOCUMENT_ROOT
 * reports the parent, so subtracting one from the other produced a path
 * segment out of the domain name and every certificate went out saying
 * "example.com/example.com/verify".
 *
 * SCRIPT_NAME is the URL the request actually arrived on, so it cannot
 * disagree with reality. Strip the levels the script sits below the app root
 * and what remains is where the app is mounted: nothing at a domain root,
 * "/tools/vcd" for a subdirectory install.
 */
function vcd_url_base(string $scriptName, int $depth): string
{
    $dir = str_replace('\\', '/', dirname($scriptName));
    $segments = array_values(array_filter(explode('/', $dir), 'strlen'));

    if ($depth > 0) {
        $segments = array_slice($segments, 0, max(0, count($segments) - $depth));
    }

    return $segments ? '/' . implode('/', $segments) : '';
}

/**
 * The key that signs certificates.
 *
 * Written once to data/ on first use. If that directory is not writable (some
 * shared hosts mount the docroot read-only), fall back to a value derived from
 * this installation so certificates still verify against themselves — weaker,
 * but a broken download is worse than a weaker signature on a novelty PDF.
 */
function vcd_secret(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $file = VCD_DATA . '/secret.key';
    if (is_readable($file)) {
        $key = trim((string) file_get_contents($file));
        if (strlen($key) >= 32) {
            return $cached = $key;
        }
    }

    $key = bin2hex(random_bytes(32));
    if (!is_dir(VCD_DATA)) {
        @mkdir(VCD_DATA, 0755, true);
    }
    if (is_dir(VCD_DATA) && @file_put_contents($file, $key, LOCK_EX) !== false) {
        @chmod($file, 0600);
        return $cached = $key;
    }

    return $cached = hash('sha256', 'vcd|' . VCD_ROOT . '|' . (string) @filemtime(__FILE__));
}

function vcd_sign(string $payload): string
{
    return hash_hmac('sha256', $payload, vcd_secret());
}

function vcd_verify(string $payload, string $signature): bool
{
    return hash_equals(vcd_sign($payload), $signature);
}

function vcd_b64url_encode(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function vcd_b64url_decode(string $s): string
{
    $s = strtr($s, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad) {
        $s .= str_repeat('=', 4 - $pad);
    }
    $out = base64_decode($s, true);
    return $out === false ? '' : $out;
}

/**
 * Compact certificate payload: only what the PDF prints, so a tampered
 * certificate fails verification instead of printing whatever was posted.
 *
 * @param array<string,mixed> $result
 */
function vcd_cert_token(array $result): array
{
    $ids = array();
    foreach (array_slice($result['signals'], 0, 14) as $s) {
        $ids[] = $s['id'];
    }

    $payload = array(
        'v'  => 1,
        'm'  => $result['mode'],
        't'  => Report::excerpt((string) $result['target'], 180),
        's'  => (int) $result['score'],
        'c'  => $result['verdict']['code'],
        'f'  => $result['confidence']['level'],
        'd'  => $result['analyzedAt'],
        'g'  => $ids,
        'n'  => isset($result['subtitle']) ? $result['subtitle'] : '',
        'id' => strtoupper(substr(hash('sha256', $result['target'] . $result['analyzedAt'] . vcd_secret()), 0, 12)),
    );

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $enc  = vcd_b64url_encode((string) $json);

    return array('payload' => $enc, 'sig' => vcd_sign($enc), 'id' => $payload['id']);
}

/**
 * @return array<string,mixed>|null
 */
function vcd_cert_open(string $payload, string $sig): ?array
{
    if (!vcd_verify($payload, $sig)) {
        return null;
    }
    $json = vcd_b64url_decode($payload);
    if ($json === '') {
        return null;
    }
    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['v'], $data['s'], $data['c'])) {
        return null;
    }
    return $data;
}

function vcd_json(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function vcd_fail(string $message, int $status = 400): void
{
    vcd_json(array('ok' => false, 'error' => $message), $status);
}

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function vcd_client_ip(): string
{
    // No proxy headers are trusted here: on shared hosting anyone can send them.
    return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'cli';
}

/**
 * Crude per-IP throttle, file-backed because there is no Redis and no database.
 * Fails open: if the data directory will not cooperate, the tool still works.
 *
 * $identity defaults to the caller's IP, but api/website.php passes the API
 * key instead: that budget belongs to the key, not to whatever address it is
 * used from.
 */
function vcd_rate_limit(string $bucket, int $limit, int $windowSeconds, ?string $identity = null): bool
{
    if (!is_dir(VCD_DATA) && !@mkdir(VCD_DATA, 0755, true)) {
        return true;
    }
    $dir = VCD_DATA . '/rate';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return true;
    }

    $file = $dir . '/' . $bucket . '-' . hash('sha256', ($identity ?? vcd_client_ip()) . vcd_secret()) . '.txt';
    $now  = time();

    $fh = @fopen($file, 'c+');
    if ($fh === false) {
        return true;
    }
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        return true;
    }

    $hits = array();
    $raw = (string) stream_get_contents($fh);
    foreach (explode(',', $raw) as $t) {
        $t = (int) $t;
        if ($t > 0 && $now - $t < $windowSeconds) {
            $hits[] = $t;
        }
    }

    $allowed = count($hits) < $limit;
    if ($allowed) {
        $hits[] = $now;
    }

    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, implode(',', array_slice($hits, -($limit + 5))));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);

    // Opportunistic cleanup so the directory does not grow without bound.
    if (mt_rand(1, 50) === 1) {
        foreach ((array) glob($dir . '/*.txt') as $old) {
            if (is_file($old) && $now - (int) filemtime($old) > 86400) {
                @unlink($old);
            }
        }
    }

    return $allowed;
}

/**
 * Claim one slot in the global fetch concurrency cap, or refuse.
 *
 * Returns a token to hand back to vcd_release_fetch_slot(), an empty string
 * if the data directory would not cooperate (fails open, same as
 * vcd_rate_limit()), or null if the cap is already full.
 *
 * Entries older than VCD_SLOT_TTL are dropped before counting, so a slot
 * whose request died without releasing it — a killed worker, a fatal error
 * outside any shutdown handler — cannot wedge the cap shut permanently.
 */
function vcd_acquire_fetch_slot(): ?string
{
    if (!is_dir(VCD_DATA) && !@mkdir(VCD_DATA, 0755, true)) {
        return '';
    }
    $file = VCD_DATA . '/inflight.txt';
    $now  = time();

    $fh = @fopen($file, 'c+');
    if ($fh === false) {
        return '';
    }
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        return '';
    }

    $slots = array();
    $raw = (string) stream_get_contents($fh);
    foreach (explode("\n", $raw) as $line) {
        $parts = explode('|', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }
        $ts = (int) $parts[1];
        if ($parts[0] !== '' && $ts > 0 && $now - $ts < VCD_SLOT_TTL) {
            $slots[$parts[0]] = $ts;
        }
    }

    $token = null;
    if (count($slots) < VCD_MAX_CONCURRENT_FETCHES) {
        $token = bin2hex(random_bytes(8));
        $slots[$token] = $now;
    }

    $lines = array();
    foreach ($slots as $tok => $ts) {
        $lines[] = $tok . '|' . $ts;
    }

    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, implode("\n", $lines));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);

    return $token;
}

/**
 * API keys for api/website.php, one per line in data/api-keys.txt — created
 * by hand on the live server, never by this code and never committed. Blank
 * lines and lines starting with # are ignored, so the file can carry comments
 * about who each key was given to.
 *
 * Not cached: this is read at most once per request, before anything else
 * runs, and a live server should not have to be restarted to pick up a key
 * added or removed by editing the file.
 *
 * @return string[]
 */
function vcd_api_keys(): array
{
    $file = VCD_DATA . '/api-keys.txt';
    if (!is_readable($file)) {
        return array();
    }

    $keys = array();
    foreach (explode("\n", (string) file_get_contents($file)) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $keys[] = $line;
    }
    return $keys;
}

/**
 * Look up an API key: the database first, when one is configured and a
 * matching non-revoked row exists (managed by name through admin/), then
 * the manual file as a fallback that keeps working even if the database is
 * unreachable or was never set up.
 *
 * The id is null for a file-based match, which has no identity beyond
 * "valid" — there is nothing to attribute usage to in that case.
 *
 * @return array{id:?int,name:?string}|null
 */
function vcd_api_key_lookup(string $key): ?array
{
    if ($key === '') {
        return null;
    }

    $pdo = Db::connect();
    if ($pdo !== null) {
        try {
            $found = ApiKeys::lookup($pdo, $key);
            if ($found !== null) {
                return $found;
            }
        } catch (Throwable $e) {
            // Fall through to the file-based keys below.
        }
    }

    foreach (vcd_api_keys() as $configured) {
        if (hash_equals($configured, $key)) {
            return array('id' => null, 'name' => null);
        }
    }
    return null;
}

/** Timing-safe check against every configured key, database and file alike. */
function vcd_api_key_valid(string $key): bool
{
    return vcd_api_key_lookup($key) !== null;
}

/**
 * The key the caller sent, from the X-Api-Key header. Headers, not a query
 * string or POST field, so the key never ends up in access logs or browser
 * history — this is a credential, not a parameter.
 */
function vcd_request_api_key(): string
{
    return isset($_SERVER['HTTP_X_API_KEY']) ? trim((string) $_SERVER['HTTP_X_API_KEY']) : '';
}

/** Release a slot claimed by vcd_acquire_fetch_slot(). Safe to call with '' or twice. */
function vcd_release_fetch_slot(string $token): void
{
    if ($token === '') {
        return;
    }
    $file = VCD_DATA . '/inflight.txt';
    $fh = @fopen($file, 'c+');
    if ($fh === false) {
        return;
    }
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        return;
    }

    $now = time();
    $lines = array();
    $raw = (string) stream_get_contents($fh);
    foreach (explode("\n", $raw) as $line) {
        $parts = explode('|', $line, 2);
        if (count($parts) !== 2 || $parts[0] === $token) {
            continue;
        }
        $ts = (int) $parts[1];
        if ($parts[0] !== '' && $ts > 0 && $now - $ts < VCD_SLOT_TTL) {
            $lines[] = $line;
        }
    }

    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, implode("\n", $lines));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
}
