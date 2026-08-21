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
    /** Nobody's site deserves more than this from a novelty detector. */
    const MAX_PAGES = 10;

    /**
     * Wall-clock ceiling for the whole crawl.
     *
     * Shared hosting typically allows 30 seconds per request. The crawl has to
     * finish, aggregate and render inside that, so it stops fetching well
     * before the limit and reports on what it managed to read.
     */
    const BUDGET_SECONDS = 18.0;

    /** Extensions that are never an HTML page worth reading. */
    const SKIP_EXT = 'jpg|jpeg|png|gif|webp|avif|svg|ico|bmp|tiff|mp4|webm|mov|avi|mp3|wav|ogg|flac|pdf|zip|gz|tar|rar|7z|dmg|exe|woff2?|ttf|otf|eot|css|js|json|xml|rss|atom|txt|csv|xlsx?|docx?|pptx?';

    /** @var Fetcher */
    private $fetcher;
    /** @var string[] */
    private $notes = array();
    /** @var string[]|null robots.txt disallow prefixes, null until loaded */
    private $disallow = null;

    public function __construct(?Fetcher $fetcher = null)
    {
        $this->fetcher = $fetcher ?? new Fetcher();
    }

    /** @return string[] */
    public function notes(): array
    {
        return $this->notes;
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
        // gets the full single-page treatment.
        $entry = $this->fetcher->fetchSite($entryUrl);
        $origin = $this->originOf($entry['url']);
        if ($origin === null) {
            throw new FetchError('That URL has no host to crawl.');
        }

        $pages = array(array(
            'url' => $entry['url'], 'body' => $entry['body'],
            'assets' => $entry['assets'], 'status' => $entry['status'],
        ));
        $seen = array($this->canonical($entry['url']) => true);

        $queue = $this->linksFrom($entry['body'], $entry['url'], $origin);
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
        $doc = $this->fetcher->fetchDocument($url);

        if ($doc['status'] >= 400 || $doc['body'] === '') {
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
