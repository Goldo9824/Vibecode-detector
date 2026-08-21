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

    // Works when the app lives in a subdirectory as well as at the domain root.
    $path = '';
    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath((string) $_SERVER['DOCUMENT_ROOT']) : false;
    $appRoot = realpath(VCD_ROOT);
    if ($docRoot !== false && $appRoot !== false && strpos($appRoot, $docRoot) === 0) {
        $path = str_replace('\\', '/', substr($appRoot, strlen($docRoot)));
        $path = rtrim('/' . trim($path, '/'), '/');
    }

    return $cached = ($https ? 'https://' : 'http://') . $host . $path;
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
 */
function vcd_rate_limit(string $bucket, int $limit, int $windowSeconds): bool
{
    if (!is_dir(VCD_DATA) && !@mkdir(VCD_DATA, 0755, true)) {
        return true;
    }
    $dir = VCD_DATA . '/rate';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return true;
    }

    $file = $dir . '/' . $bucket . '-' . hash('sha256', vcd_client_ip() . vcd_secret()) . '.txt';
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
