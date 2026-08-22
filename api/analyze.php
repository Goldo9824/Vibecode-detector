<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Fetcher.php';
require_once dirname(__DIR__) . '/lib/Crawler.php';
require_once dirname(__DIR__) . '/lib/UsageLog.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    vcd_fail('Send this as a POST request.', 405);
}

$mode = isset($_POST['mode']) ? (string) $_POST['mode'] : '';

if ($mode === 'url') {
    $crawl = !empty($_POST['crawl']);

    // A crawl spends from both buckets: it is still a page fetch, and its own
    // budget should not be a way around the page limit. VCD_LIMIT_URL is sized
    // so that it never becomes the binding constraint on the crawl rate.
    if (!vcd_rate_limit('url', VCD_LIMIT_URL[0], VCD_LIMIT_URL[1])) {
        vcd_fail('That is a lot of pages in ten minutes. Give it a moment.', 429);
    }
    if ($crawl && !vcd_rate_limit('crawl', VCD_LIMIT_CRAWL[0], VCD_LIMIT_CRAWL[1])) {
        vcd_fail(sprintf(
            'Whole-site reads are limited to %d every %d minutes, because they cost the site being read as well as this one.',
            VCD_LIMIT_CRAWL[0], (int) round(VCD_LIMIT_CRAWL[1] / 60)
        ), 429);
    }

    // A per-IP allowance does nothing against a flood of slow requests spread
    // across many addresses at once. This caps how many url-mode fetches run
    // at the same time, across every visitor, so that a busy moment fails one
    // request with "try again" instead of exhausting the worker pool the rest
    // of the site depends on. Released on every exit path, vcd_fail()'s
    // included, because exit() does not run pending finally blocks.
    $slot = vcd_acquire_fetch_slot();
    if ($slot === null) {
        vcd_fail('This tool is busy reading other pages right now. Try again in a few seconds.', 503);
    }
    if ($slot !== '') {
        register_shutdown_function('vcd_release_fetch_slot', $slot);
    }

    $url = isset($_POST['url']) ? (string) $_POST['url'] : '';
    $fetcher = new Fetcher();

    if ($crawl) {
        try {
            $crawler = new Crawler($fetcher);
            $pages = $crawler->crawl($url);
        } catch (FetchError $e) {
            vcd_fail($e->getMessage(), 400);
        }

        $entry = $pages[0]['url'];
        $result = (new SiteSurvey($entry, $pages, $crawler->notes(), $crawler->sitemap()))->analyze()->toArray();
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

} elseif ($mode === 'code') {
    if (!vcd_rate_limit('code', VCD_LIMIT_CODE[0], VCD_LIMIT_CODE[1])) {
        vcd_fail('Slow down a little.', 429);
    }

    $code = isset($_POST['code']) ? (string) $_POST['code'] : '';
    $code = str_replace("\0", '', $code);

    if (trim($code) === '') {
        vcd_fail('Paste some code first.');
    }
    if (strlen($code) > 1048576) {
        vcd_fail('That is over a megabyte of source. Paste the interesting file instead.');
    }
    if (!preg_match('//u', $code)) {
        $code = function_exists('mb_convert_encoding')
            ? (string) mb_convert_encoding($code, 'UTF-8', 'UTF-8')
            : preg_replace('~[^\x09\x0A\x0D\x20-\x7E]~', '', $code);
    }

    $analyzer = new CodeAnalyzer($code);
    $result = $analyzer->analyze()->toArray();

} elseif ($mode === 'git') {
    if (!vcd_rate_limit('git', VCD_LIMIT_GIT[0], VCD_LIMIT_GIT[1])) {
        vcd_fail('Slow down a little.', 429);
    }

    $log = isset($_POST['log']) ? (string) $_POST['log'] : '';
    $log = str_replace("\0", '', $log);

    if (trim($log) === '') {
        vcd_fail('Paste some git log output first.');
    }
    if (strlen($log) > 2097152) {
        vcd_fail('That is a very large log. Try the most recent few hundred commits.');
    }

    $analyzer = new GitAnalyzer($log);
    $result = $analyzer->analyze()->toArray();

} else {
    vcd_fail('Unknown mode. Use "url", "code" or "git".');
}

// If the operator has configured a database (see docs/ADMIN.md), this
// records the mode, and for url/site modes the target address — never
// pasted code, a git log, or the fetched page content, none of which are
// ever written down. See lib/UsageLog.php.
$target = in_array($result['mode'], array('url', 'site'), true) ? $result['target'] : null;
UsageLog::record('ui', null, $result['mode'], $target);

// The certificate token is a signature over the result, computed here and
// thrown away — a certificate can be checked but never looked up.
$result['cert'] = vcd_cert_token($result);

vcd_json($result);
