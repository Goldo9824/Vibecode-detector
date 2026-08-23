<?php
declare(strict_types=1);

/**
 * Key-authenticated API for analysing a live page or whole site.
 *
 * Separate from api/analyze.php (which is what the browser UI calls) because
 * it answers to a different caller with a different trust level: a request
 * here carries an API key set up by hand on this installation and never
 * committed to the repo (see data/api-keys.txt), so it gets one shared,
 * generous rate budget keyed to that key instead of the anonymous per-IP one.
 *
 *   curl -H "X-Api-Key: <key>" "https://example.com/api/website.php?url=https://target.example"
 */

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Fetcher.php';
require_once dirname(__DIR__) . '/lib/Crawler.php';
require_once dirname(__DIR__) . '/lib/UsageLog.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'POST') {
    vcd_fail('Send this as a GET or POST request.', 405);
}

$apiKey = vcd_request_api_key();
$matchedKey = vcd_api_key_lookup($apiKey);
if ($matchedKey === null) {
    vcd_fail('Missing or invalid API key. Send it in the X-Api-Key header.', 401);
}

if (!vcd_rate_limit('api-url', VCD_LIMIT_API_URL[0], VCD_LIMIT_API_URL[1], 'key:' . $apiKey)) {
    vcd_fail(sprintf(
        'Rate limit exceeded: this key allows %d requests every %d minutes.',
        VCD_LIMIT_API_URL[0], (int) round(VCD_LIMIT_API_URL[1] / 60)
    ), 429);
}

// Same global backstop url-mode uses in api/analyze.php: a per-key budget
// does nothing against several keys (or several holders of one key) all
// fetching a slow site at once, and that is what actually exhausts the
// worker pool on shared hosting.
$slot = vcd_acquire_fetch_slot();
if ($slot === null) {
    vcd_fail('This tool is busy reading other pages right now. Try again in a few seconds.', 503);
}
if ($slot !== '') {
    register_shutdown_function('vcd_release_fetch_slot', $slot);
}

$url   = isset($_REQUEST['url']) ? (string) $_REQUEST['url'] : '';
$crawl = !empty($_REQUEST['crawl']);

$fetcher = new Fetcher();

if ($crawl) {
    try {
        $crawler = new Crawler($fetcher);
        $pages = $crawler->crawl($url);
    } catch (FetchError $e) {
        vcd_fail($e->getMessage(), 400);
    }

    $entry = $pages[0]['url'];
    $result = (new SiteSurvey($entry, $pages, $crawler->notes(), $crawler->sitemap(), $crawler->missing()))->analyze()->toArray();
} else {
    try {
        $doc = $fetcher->fetchSite($url);
    } catch (FetchError $e) {
        vcd_fail($e->getMessage(), 400);
    }

    $analyzer = new SiteAnalyzer($doc['url'], $doc['body'], $doc['assets'], array(
        'status'     => $doc['status'],
        'headers'    => isset($doc['headers']) ? $doc['headers'] : array(),
        'sourceMaps' => isset($doc['sourceMaps']) ? $doc['sourceMaps'] : array(),
    ));
    $result = $analyzer->analyze()->toArray();

    if ($doc['url'] !== $fetcher->normalize($url)) {
        $result['notes'][] = 'The request was redirected to ' . $doc['url'] . ', which is what was analysed.';
    }
}

// If a database is configured (see docs/ADMIN.md), this records the mode,
// the target address and which key was used — never the fetched page
// content, which is discarded the same as it always was. See lib/UsageLog.php.
UsageLog::record('api', $matchedKey['id'], $result['mode'], $result['target']);

// The certificate token is a signature over the result, computed here and
// thrown away — it is not stored, and is not how usage above gets recorded.
$result['cert'] = vcd_cert_token($result);

vcd_json($result);
