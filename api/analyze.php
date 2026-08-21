<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Fetcher.php';
require_once dirname(__DIR__) . '/lib/Crawler.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    vcd_fail('Send this as a POST request.', 405);
}

$mode = isset($_POST['mode']) ? (string) $_POST['mode'] : '';

if ($mode === 'url') {
    $crawl = !empty($_POST['crawl']);

    // A crawl is ten times the work of a visit, so it gets its own, much
    // tighter budget. Both buckets are checked for a crawl: it is still a page
    // fetch, and it should not be a way around the page limit.
    if (!vcd_rate_limit('url', 20, 600)) {
        vcd_fail('That is a lot of pages in ten minutes. Give it a moment.', 429);
    }
    if ($crawl && !vcd_rate_limit('crawl', 4, 600)) {
        vcd_fail('Whole-site reads are limited to four every ten minutes, because they cost the site being read as well as this one.', 429);
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
        $result = (new SiteSurvey($entry, $pages, $crawler->notes()))->analyze()->toArray();
    } else {
        try {
            $doc = $fetcher->fetchSite($url);
        } catch (FetchError $e) {
            vcd_fail($e->getMessage(), 400);
        }

        $analyzer = new SiteAnalyzer($doc['url'], $doc['body'], $doc['assets'], array('status' => $doc['status']));
        $result = $analyzer->analyze()->toArray();

        if ($doc['url'] !== $fetcher->normalize($url)) {
            $result['notes'][] = 'The request was redirected to ' . $doc['url'] . ', which is what was analysed.';
        }
    }

} elseif ($mode === 'code') {
    if (!vcd_rate_limit('code', 60, 600)) {
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
    if (!vcd_rate_limit('git', 60, 600)) {
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

// Nothing about the request is stored. The certificate token is a signature
// over the result, computed here and thrown away — there is no database and
// no log, so a certificate can be checked but never looked up.
$result['cert'] = vcd_cert_token($result);

vcd_json($result);
