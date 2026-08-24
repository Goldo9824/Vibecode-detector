<?php
declare(strict_types=1);

require_once __DIR__ . '/Fetcher.php';

/**
 * Walks a site from one entry page.
 *
 * Fetching one page on request is a browser visit. Fetching ten is a crawl, and
 * a crawl has obligations a visit does not: robots.txt is honoured, the request
 * rate is bounded, and there is a hard wall-clock budget so a slow site cannot
 * hold a PHP worker open until the host kills it.
 *
 * Every URL still goes through Fetcher, so the SSRF guard applies to each hop —
 * a page cannot redirect the crawler onto a private address by linking to one.
 */
final class Crawler
{
    /**
     * Page ceiling.
     *
     * Rarely the binding constraint: BUDGET_SECONDS almost always stops the
     * crawl first. Treat this as "never more than", not "will read this many".
     */
    const MAX_PAGES = 50;

    /**
     * Wall-clock ceiling for the whole crawl, and the real limit in practice.
     *
     * Shared hosting typically allows 30 seconds per request, and the crawl has
     * to finish, aggregate and render inside that — so this leaves headroom
     * rather than spending the lot on fetching. At a typical half-second to a
     * second per page that is somewhere between 25 and 45 pages; a slow site
     * will give fewer, and the report says so instead of pretending otherwise.
     */
    const BUDGET_SECONDS = 24.0;

    /**
     * Per-page download cap for inner pages.
     *
     * The entry page gets the full allowance, but holding fifty documents at
     * the 3 MB transfer limit would mean 150 MB resident, and shared hosting
     * commonly caps PHP at 128 MB. Half a megabyte of HTML is far more than any
     * of these checks needs, and fifty of those is a comfortable 25 MB.
     */
    const MAX_PAGE_BYTES = 524288;

    /** Per-page transfer timeout, so one stalled page cannot eat the budget. */
    const PAGE_TIMEOUT = 6;

    /**
     * Sitemap limits.
     *
     * Three documents is enough for an index plus a couple of children, and
     * the whole thing is skipped once half the crawl budget is gone: a list of
     * pages is worth having, but never at the cost of the pages themselves.
     */
    const MAX_SITEMAPS = 3;
    const MAX_SITEMAP_BYTES = 524288;
    const SITEMAP_TIMEOUT = 5;

    /** Extensions that are never an HTML page worth reading. */
    const SKIP_EXT = 'jpg|jpeg|png|gif|webp|avif|svg|ico|bmp|tiff|mp4|webm|mov|avi|mp3|wav|ogg|flac|pdf|zip|gz|tar|rar|7z|dmg|exe|woff2?|ttf|otf|eot|css|js|json|xml|rss|atom|txt|csv|xlsx?|docx?|pptx?';

    /** @var Fetcher */
    private $fetcher;
    /** @var string[] */
    private $notes = array();
    /** @var array<string,int> internal links that answered with an error */
    private $missing = array();
    /** @var string[]|null robots.txt disallow prefixes, null until loaded */
    private $disallow = null;
    /** @var string[] sitemap addresses robots.txt pointed at */
    private $sitemapUrls = array();
    /** @var array<int,string> lastmod values seen in the sitemap */
    private $lastmods = array();
    /** @var int URLs listed in the sitemap, whether or not they were read */
    private $sitemapCount = 0;

    public function __construct(?Fetcher $fetcher = null)
    {
        $this->fetcher = $fetcher ?? new Fetcher();
    }

    /** @return string[] */
    /**
     * Links the crawl followed that answered with an error.
     *
     * @return array<string,int> url => status
     */
    public function missing(): array
    {
        return $this->missing;
    }

    public function notes(): array
    {
        return $this->notes;
    }

    /**
     * What the sitemap said, for the site-wide checks to read.
     *
     * @return array{urls:int,lastmods:string[]}
     */
    public function sitemap(): array
    {
        return array('urls' => $this->sitemapCount, 'lastmods' => array_values($this->lastmods));
    }


    /**
     * Breadth-first from the entry page, same origin only.
     *
     * @return array<int,array{url:string,body:string,assets:array<string,string>,status:int}>
     * @throws FetchError if the entry page itself cannot be read
     */
    public function crawl(string $entryUrl, int $maxPages = self::MAX_PAGES): array
    {
        $started = microtime(true);
        $maxPages = max(1, min($maxPages, self::MAX_PAGES));

        // The entry page is fetched with its assets: it is the one page that
        // gets the full single-page treatment. Source maps are the exception —
        // reading one costs seconds that a crawl would rather spend on pages,
        // so whole-site mode trades depth on the entry page for breadth across
        // the rest, and the single-page mode is where the deep read lives.
        $this->fetcher->skipSourceMaps();
        $entry = $this->fetcher->fetchSite($entryUrl);
        $origin = $this->originOf($entry['url']);
        if ($origin === null) {
            throw new FetchError('That URL has no host to crawl.');
        }

        $pages = array(array(
            'url' => $entry['url'], 'body' => $entry['body'],
            'assets' => $entry['assets'], 'status' => $entry['status'],
            // Only the entry page carries transport detail and source maps:
            // it is the one page fetched with the full single-page treatment.
            'meta' => array(
                'status'     => $entry['status'],
                'headers'    => isset($entry['headers']) ? $entry['headers'] : array(),
                'sourceMaps' => isset($entry['sourceMaps']) ? $entry['sourceMaps'] : array(),
            ),
        ));
        $seen = array($this->canonical($entry['url']) => true);

        // robots.txt is loaded now rather than on the first inner page: it has
        // to be read either way, and reading it here also gets the Sitemap:
        // lines, which are the best list of a site's own pages that exists.
        $this->disallow = $this->loadRobots($origin);

        $queue = $this->linksFrom($entry['body'], $entry['url'], $origin);

        // Links first, sitemap second. Following links reads the site the way
        // a visitor would; the sitemap then fills in what the navigation does
        // not reach, which on a generated site is often most of it.
        foreach ($this->readSitemaps($origin, $started) as $url) {
            $queue[] = $url;
        }

        $skippedForRobots = 0;

        while ($queue && count($pages) < $maxPages) {
            if (microtime(true) - $started > self::BUDGET_SECONDS) {
                $this->notes[] = sprintf(
                    'The crawl stopped after %d pages because it ran out of its time budget. What it did read is below.',
                    count($pages)
                );
                break;
            }

            $next = array_shift($queue);
            $key = $this->canonical($next);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            if (!$this->allowedByRobots($next, $origin)) {
                $skippedForRobots++;
                continue;
            }

            try {
                // Inner pages are read without their assets: the entry page has
                // already paid for the stylesheet, and ten more asset fetches
                // would spend the whole budget on files that barely differ.
                $page = $this->fetchDocument($next);
            } catch (FetchError $e) {
                continue;
            }
            if ($page === null) {
                continue;
            }

            $pages[] = $page;

            if (count($pages) < $maxPages) {
                foreach ($this->linksFrom($page['body'], $page['url'], $origin) as $link) {
                    if (!isset($seen[$this->canonical($link)])) {
                        $queue[] = $link;
                    }
                }
            }
        }

        if ($skippedForRobots > 0) {
            $this->notes[] = sprintf(
                '%d page%s skipped because robots.txt asks crawlers not to read %s.',
                $skippedForRobots, $skippedForRobots === 1 ? '' : 's',
                $skippedForRobots === 1 ? 'it' : 'them'
            );
        }
        if (count($pages) === 1) {
            $this->notes[] = 'Only one page could be read, so the site-wide checks had nothing to compare. This is the same reading the single-page mode would give.';
        }

        return $pages;
    }

    // ---------------------------------------------------------------- fetching

    /**
     * @return array{url:string,body:string,assets:array<string,string>,status:int}|null
     * @throws FetchError
     */
    private function fetchDocument(string $url): ?array
    {
        $doc = $this->fetcher->fetchDocument($url, self::MAX_PAGE_BYTES, self::PAGE_TIMEOUT);

        if ($doc['status'] >= 400) {
            // Kept rather than dropped: a link the site's own navigation offers
            // and the site's own server refuses is a finding, not a failure.
            $this->missing[$url] = (int) $doc['status'];
            return null;
        }
        if ($doc['body'] === '') {
            return null;
        }
        if (stripos($doc['contentType'], 'html') === false && !preg_match('~<html|<!doctype~i', $doc['body'])) {
            return null;
        }

        return array(
            'url' => $doc['url'], 'body' => $doc['body'],
            'assets' => array(), 'status' => $doc['status'],
        );
    }

    // --------------------------------------------------------------- sitemap

    /**
     * The site's own list of its pages, and when each of them last changed.
     *
     * Two things come out of this. The obvious one is coverage: link-following
     * only reaches what the navigation links to, and a generated site often
     * has routes that exist without being linked from anywhere. The less
     * obvious one is the lastmod column, which is a dated record of the site
     * being worked on — written by tooling rather than by an author, and one
     * of the few things on a website that is tedious to fabricate.
     *
     * @return string[] page URLs to add to the crawl queue
     */
    private function readSitemaps(string $origin, float $started): array
    {
        $queue = $this->sitemapUrls;
        if (!$queue) {
            $queue[] = $origin . '/sitemap.xml';
        }

        $out = array();
        $read = 0;
        $seen = array();

        while ($queue && $read < self::MAX_SITEMAPS) {
            if (microtime(true) - $started > self::BUDGET_SECONDS / 2) {
                break; // never spend the page budget on index files
            }
            $url = array_shift($queue);
            if (isset($seen[$url]) || $this->originOf($url) !== $origin) {
                continue;
            }
            $seen[$url] = true;

            try {
                $doc = $this->fetcher->fetchDocument($url, self::MAX_SITEMAP_BYTES, self::SITEMAP_TIMEOUT);
            } catch (FetchError $e) {
                continue;
            }
            if ($doc['status'] !== 200 || $doc['body'] === '') {
                continue;
            }
            $read++;
            $body = $doc['body'];

            // A sitemap index points at more sitemaps; those are queued and the
            // rest of this document ignored, which is what the format means.
            if (preg_match('~<sitemapindex~i', $body)) {
                if (preg_match_all('~<sitemap\b[^>]*>.*?<loc>\s*([^<\s]+)\s*</loc>~is', $body, $m)) {
                    foreach ($m[1] as $child) {
                        $queue[] = html_entity_decode($child, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    }
                }
                continue;
            }

            if (!preg_match_all('~<url\b[^>]*>(.*?)</url>~is', $body, $entries)) {
                continue;
            }
            foreach ($entries[1] as $entry) {
                if (!preg_match('~<loc>\s*([^<\s]+)\s*</loc>~i', $entry, $loc)) {
                    continue;
                }
                $this->sitemapCount++;
                if (preg_match('~<lastmod>\s*([0-9]{4}-[0-9]{2}-[0-9]{2})~i', $entry, $lm)) {
                    $this->lastmods[] = $lm[1];
                }

                $abs = html_entity_decode($loc[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($this->originOf($abs) !== $origin) {
                    continue;
                }
                $path = (string) parse_url($abs, PHP_URL_PATH);
                if ($path !== '' && preg_match('~\.(?:' . self::SKIP_EXT . ')$~i', $path)) {
                    continue;
                }
                $out[] = (string) preg_replace('~#.*$~', '', $abs);
            }
        }

        if ($this->sitemapCount > 0) {
            $this->notes[] = sprintf(
                'The site publishes a sitemap listing %d page%s, which was used to find pages the navigation does not link to.',
                $this->sitemapCount, $this->sitemapCount === 1 ? '' : 's'
            );
        }
        return array_values(array_unique($out));
    }

    // ------------------------------------------------------------------ links

    /**
     * Same-origin document links, in page order.
     *
     * @return string[]
     */
    private function linksFrom(string $html, string $baseUrl, string $origin): array
    {
        if (!preg_match_all('~<a\b[^>]*\bhref=["\']([^"\']+)["\']~i', $html, $m)) {
            return array();
        }

        $out = array();
        foreach ($m[1] as $href) {
            $abs = $this->absolute($href, $baseUrl);
            if ($abs === null) {
                continue;
            }
            if ($this->originOf($abs) !== $origin) {
                continue; // same origin only; this is not a web-wide spider
            }
            $path = (string) parse_url($abs, PHP_URL_PATH);
            if ($path !== '' && preg_match('~\.(?:' . self::SKIP_EXT . ')$~i', $path)) {
                continue;
            }
            // Anything that looks like an action rather than a document.
            if (preg_match('~/(?:logout|signout|sign-out|delete|remove|unsubscribe|cart/add|admin)(?:/|$|\?)~i', $abs)) {
                continue;
            }
            $out[] = $abs;
        }
        return array_values(array_unique($out));
    }

    private function absolute(string $href, string $baseUrl): ?string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($href === '' || $href[0] === '#') {
            return null;
        }
        if (preg_match('~^(?:javascript|mailto|tel|data|sms|ftp):~i', $href)) {
            return null;
        }

        if (preg_match('~^https?://~i', $href)) {
            $abs = $href;
        } else {
            $b = parse_url($baseUrl);
            if (!$b || empty($b['scheme']) || empty($b['host'])) {
                return null;
            }
            $root = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');

            if (strpos($href, '//') === 0) {
                $abs = $b['scheme'] . ':' . $href;
            } elseif ($href[0] === '/') {
                $abs = $root . $href;
            } else {
                $dir = isset($b['path']) ? preg_replace('~/[^/]*$~', '/', $b['path']) : '/';
                $parts = array();
                foreach (explode('/', $dir . $href) as $seg) {
                    if ($seg === '' || $seg === '.') continue;
                    if ($seg === '..') { array_pop($parts); continue; }
                    $parts[] = $seg;
                }
                $abs = $root . '/' . implode('/', $parts);
            }
        }

        // Fragments are the same document by definition.
        $abs = (string) preg_replace('~#.*$~', '', $abs);
        return $abs === '' ? null : $abs;
    }

    /** Collapse the trivial ways one page can be spelled differently. */
    private function canonical(string $url): string
    {
        $p = parse_url($url);
        if (!$p || empty($p['host'])) {
            return strtolower($url);
        }
        $path = isset($p['path']) ? $p['path'] : '/';
        $path = (string) preg_replace('~/(?:index|default)\.(?:html?|php|aspx?)$~i', '/', $path);
        if ($path === '') {
            $path = '/';
        }
        if (strlen($path) > 1) {
            $path = rtrim($path, '/');
            if ($path === '') {
                $path = '/';
            }
        }
        $query = isset($p['query']) && $p['query'] !== '' ? '?' . $p['query'] : '';
        return strtolower($p['host']) . $path . $query;
    }

    private function originOf(string $url): ?string
    {
        $p = parse_url($url);
        if (!$p || empty($p['scheme']) || empty($p['host'])) {
            return null;
        }
        return strtolower($p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : ''));
    }

    // ----------------------------------------------------------------- robots

    /**
     * Minimal robots.txt support: the wildcard group and our own.
     *
     * Deliberately conservative. A malformed or unreachable robots.txt is
     * treated as permission, which is the convention, but any Disallow that
     * matches is obeyed rather than argued with.
     */
    private function allowedByRobots(string $url, string $origin): bool
    {
        if ($this->disallow === null) {
            $this->disallow = $this->loadRobots($origin);
        }
        if (!$this->disallow) {
            return true;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($path === '') {
            $path = '/';
        }
        $query = (string) parse_url($url, PHP_URL_QUERY);
        if ($query !== '') {
            $path .= '?' . $query;
        }

        foreach ($this->disallow as $prefix) {
            if ($prefix === '') {
                continue;
            }
            if (strpos($path, $prefix) === 0) {
                return false;
            }
        }
        return true;
    }

    /** @return string[] */
    private function loadRobots(string $origin): array
    {
        try {
            $doc = $this->fetcher->fetchDocument($origin . '/robots.txt', 65536, 4);
        } catch (FetchError $e) {
            return array();
        }
        if ($doc['status'] !== 200 || $doc['body'] === '') {
            return array();
        }

        $rules = array();
        $applies = false;

        foreach (explode("\n", $doc['body']) as $line) {
            $line = trim((string) preg_replace('~#.*$~', '', $line));
            if ($line === '') {
                continue;
            }
            if (preg_match('~^user-agent\s*:\s*(.+)$~i', $line, $m)) {
                $agent = strtolower(trim($m[1]));
                $applies = ($agent === '*' || strpos($agent, 'vibecodedetector') !== false);
                continue;
            }
            // Sitemap directives are group-independent by specification, so
            // they are taken wherever they appear.
            if (preg_match('~^sitemap\s*:\s*(\S+)~i', $line, $m)) {
                $this->sitemapUrls[] = trim($m[1]);
                continue;
            }
            if ($applies && preg_match('~^disallow\s*:\s*(.*)$~i', $line, $m)) {
                $path = trim($m[1]);
                if ($path !== '') {
                    // Wildcards are not supported; the literal prefix before any
                    // wildcard is used, which errs toward obeying more.
                    $rules[] = (string) strtok($path, '*');
                }
            }
        }

        return array_values(array_unique(array_filter($rules)));
    }
}
