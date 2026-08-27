<?php
declare(strict_types=1);

/**
 * Thrown for anything the user should see as a plain message rather than a
 * stack trace: bad URL, blocked target, unreachable host.
 */
final class FetchError extends Exception
{
}

/**
 * Fetches a page and a few of its own assets.
 *
 * This endpoint takes a URL from an anonymous stranger and asks the server to
 * request it, which is the textbook setup for using us as a proxy into
 * whatever else lives on this host or its network. So: scheme allow-list,
 * every resolved address checked against the private ranges, redirects
 * followed by hand with the same check applied at each hop, and a hard cap on
 * both size and time.
 */
/**
 * Not final, only so the crawler tests can substitute a double.
 *
 * The guard cannot be tested end to end against a real server, because it
 * correctly refuses to fetch localhost — which is the point of it. Overriding
 * the two public fetch methods in a test lets the crawl loop, the budget, the
 * robots handling and the deduplication be exercised without any of that
 * touching the network or relaxing a single check in production.
 */
class Fetcher
{
    const UA = 'VibeCodeDetector/1.0 (+https://vibecodedetector.fanficnow.com; page analysis on user request)';
    const MAX_BYTES = 3145728;   // 3 MB
    const MAX_ASSET_BYTES = 786432; // 768 KB
    const TIMEOUT = 12;
    const ASSET_TIMEOUT = 6;
    const MAX_REDIRECTS = 4;
    const MAX_ASSETS = 4;

    /**
     * Source maps: how many, and how much of one to take.
     *
     * A map is the single most valuable thing a deployed page can hand over.
     * `sourcesContent` carries the pre-build source — comments, names, the
     * whole of what a minifier normally destroys — so one map turns a page
     * that could only be read for style into a page that can be read for code.
     * Maps are also the largest thing on a site: 2 MB is common and 20 MB
     * happens, so the cap is deliberate and the budget is two of them.
     */
    const MAX_MAPS = 2;
    const MAX_MAP_BYTES = 2097152;   // 2 MB
    const MAP_TIMEOUT = 6;

    /**
     * Total wall clock the maps may have, whatever their number.
     *
     * Two maps at the per-request timeout would be twelve seconds on top of a
     * document and four assets, which is more than the thirty this is allowed
     * on shared hosting. The budget makes the second map opportunistic: it is
     * read when the first was quick and skipped when it was not.
     */
    const MAP_BUDGET = 8.0;

    /**
     * Vetted addresses per host, for the lifetime of this Fetcher.
     *
     * A whole-site crawl calls assertSafe() on the same host for the entry
     * page, its assets, robots.txt and up to fifty inner pages — the same DNS
     * answer, checked the same way, every time. Caching it here does not
     * weaken the guard: the cache is a plain instance property, so it starts
     * empty on every request and cannot carry a stale answer from one visitor's
     * crawl into another's. It only removes redundant lookups within the one
     * crawl that already committed to trusting this host.
     *
     * @var array<string,string[]>
     */
    private $safeHostCache = array();

    /**
     * Whether to follow sourceMappingURL.
     *
     * On by default, and turned off for a whole-site crawl: reading one page's
     * original source is worth several seconds when that page is the whole
     * analysis, and is not worth it when those seconds come out of the budget
     * for the other forty-nine pages.
     *
     * @var bool
     */
    private $readSourceMaps = true;

    /** Stop following source maps, for callers that would rather have pages. */
    public function skipSourceMaps(): void
    {
        $this->readSourceMaps = false;
    }

    /**
     * @return array{url:string,body:string,status:int,assets:array<string,string>,contentType:string,headers:array<string,string>,sourceMaps:array<int,array<string,mixed>>}
     * @throws FetchError
     */
    public function fetchSite(string $url): array
    {
        $url = $this->normalize($url);
        $doc = $this->get($url, self::MAX_BYTES, self::TIMEOUT);

        if ($doc['status'] >= 400) {
            throw new FetchError(sprintf('The site answered with HTTP %d. Nothing to read.', $doc['status']));
        }
        if ($doc['body'] === '') {
            throw new FetchError('The site returned an empty response.');
        }
        if (stripos($doc['contentType'], 'html') === false && !preg_match('~<html|<!doctype~i', $doc['body'])) {
            throw new FetchError('That URL did not return an HTML page (content type: ' . ($doc['contentType'] ?: 'unknown') . ').');
        }

        $doc['assets'] = $this->fetchAssets($doc['url'], $doc['body']);
        $doc['sourceMaps'] = $this->readSourceMaps
            ? $this->fetchSourceMaps($doc['url'], $doc['assets'])
            : array();
        return $doc;
    }

    /**
     * One document, without its assets.
     *
     * Used by the crawler for pages after the first: the entry page has already
     * paid for the stylesheet and the bundle, and refetching them for every
     * inner page would spend the whole budget on files that barely differ.
     *
     * @return array{url:string,body:string,status:int,contentType:string,assets:array<string,string>}
     * @throws FetchError
     */
    public function fetchDocument(string $url, int $maxBytes = self::MAX_BYTES, int $timeout = self::TIMEOUT): array
    {
        return $this->get($this->normalize($url), $maxBytes, $timeout);
    }

    /**
     * One resource fetched with request headers of the caller's choosing.
     *
     * Everything else here reads pages, which want the browser-shaped Accept
     * this class sends by default. An API wants a different Accept and, where
     * the operator has configured one, an Authorization header — so this is
     * the one entry point that lets a caller say so. It goes through the same
     * get() as everything else, which means the same redirect vetting, the
     * same address guard and the same size ceiling: a header parameter is not
     * a way around any of that.
     *
     * The status comes back rather than throwing, because an API's 404 and its
     * 403 mean different things to the caller and both are worth saying out
     * loud.
     *
     * @param array<string,string> $headers
     * @return array{url:string,body:string,status:int,contentType:string,headers:array<string,string>,assets:array<string,string>}
     * @throws FetchError
     */
    public function fetchApi(string $url, array $headers = array(), int $maxBytes = self::MAX_ASSET_BYTES, int $timeout = self::ASSET_TIMEOUT): array
    {
        return $this->get($this->normalize($url), $maxBytes, $timeout, $headers);
    }

    /**
     * The request headers to send, the caller's own merged over the default.
     *
     * Names are compared case-insensitively so that passing "accept" replaces
     * the default Accept rather than sending a second one, which is the kind
     * of thing an API answers with a 406 and no explanation.
     *
     * @param array<string,string> $extra
     * @return string[]
     */
    private static function requestHeaders(array $extra): array
    {
        $headers = array('accept' => 'Accept: text/html,application/xhtml+xml,text/css,application/javascript,*/*;q=0.8');
        foreach ($extra as $name => $value) {
            $name  = trim((string) $name);
            $value = trim((string) $value);
            // A newline in either half would let a caller append headers of
            // its own invention. There is no legitimate one, so they are cut.
            if ($name === '' || $value === '' || preg_match('~[\r\n]~', $name . $value)) {
                continue;
            }
            $headers[strtolower($name)] = $name . ': ' . $value;
        }
        return array_values($headers);
    }

    /**
     * The page's own stylesheets and scripts, best ones first.
     *
     * Order matters because the budget is four files. A page commonly links a
     * consent banner, a chat widget and a font loader before it links the
     * bundle that actually contains the site, and taking them in document
     * order spends the whole allowance on the furniture. Candidates are ranked
     * so the application bundle and the stylesheet are fetched first.
     *
     * @return array<string,string>
     */
    private function fetchAssets(string $baseUrl, string $html): array
    {
        $base = parse_url($baseUrl);
        if (!$base || !isset($base['host'])) {
            return array();
        }

        $urls = array();
        if (preg_match_all('~<script\b[^>]*\bsrc=["\']([^"\']+)["\']~i', $html, $m)) {
            foreach ($m[1] as $src) $urls[] = $src;
        }
        if (preg_match_all('~<link\b[^>]*\brel=["\']stylesheet["\'][^>]*\bhref=["\']([^"\']+)["\']~i', $html, $m)) {
            foreach ($m[1] as $href) $urls[] = $href;
        }
        if (preg_match_all('~<link\b[^>]*\bhref=["\']([^"\']+\.css[^"\']*)["\']~i', $html, $m)) {
            foreach ($m[1] as $href) $urls[] = $href;
        }

        $candidates = array();
        foreach ($urls as $raw) {
            $abs = $this->resolveUrl($baseUrl, $raw);
            if ($abs === null) continue;

            $p = parse_url($abs);
            if (!$p || !isset($p['host']) || strcasecmp($p['host'], $base['host']) !== 0) {
                continue; // same-origin only: no third-party CDNs
            }
            if (isset($candidates[$abs])) continue;
            $candidates[$abs] = $this->assetRank($abs);
        }

        // Rank descending, first-seen order breaking ties, so the choice is the
        // same on every run and on every PHP version.
        $order = array_keys($candidates);
        $position = array_flip($order);
        usort($order, function ($a, $b) use ($candidates, $position) {
            if ($candidates[$a] === $candidates[$b]) {
                return $position[$a] <=> $position[$b];
            }
            return $candidates[$b] <=> $candidates[$a];
        });

        $out = array();
        foreach ($order as $abs) {
            if (count($out) >= self::MAX_ASSETS) break;

            try {
                $r = $this->get($abs, self::MAX_ASSET_BYTES, self::ASSET_TIMEOUT);
                if ($r['status'] < 400 && $r['body'] !== '') {
                    $out[$abs] = $r['body'];
                }
            } catch (FetchError $e) {
                // An asset that will not load is not worth failing the analysis over.
                continue;
            }
        }
        return $out;
    }

    /**
     * How much this asset is worth spending one of four slots on.
     *
     * The stylesheet is top because a compiled Tailwind sheet lists every
     * utility the site actually uses, which is most of what the aesthetic
     * checks want. Then the application bundle, then anything else. Known
     * furniture — analytics, consent, chat, polyfills — is pushed to the back
     * even when it is served from the site's own domain.
     */
    private function assetRank(string $url): int
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        if (preg_match('~(?:gtag|gtm|analytics|consent|cookie|tarteaucitron|axeptio|didomi|hotjar|clarity|recaptcha|polyfill|jquery|widget|chat|intercom|crisp|tawk)~', $path)) {
            return -10;
        }
        if (substr($path, -4) === '.css') {
            return 100;
        }
        // Vite, Next, Nuxt, CRA and Remix all name their entry bundle in one of
        // these shapes.
        if (preg_match('~/(?:assets/)?(?:index|main|app|entry|client|bundle)[.-][a-z0-9_-]{4,}\.js$~', $path)
            || preg_match('~/_next/static/chunks/(?:main|app|pages/_app)[^/]*\.js$~', $path)
            || preg_match('~/_nuxt/(?:entry|index)\.[a-z0-9]+\.js$~', $path)) {
            return 90;
        }
        if (preg_match('~/(?:assets|static|_next|_nuxt|build|dist)/~', $path)) {
            return 60;
        }
        if (substr($path, -3) === '.js') {
            return 40;
        }
        return 10;
    }

    /**
     * Follow the sourceMappingURL of the scripts we already have.
     *
     * A source map is not a probe: the asset itself points at it, and a
     * browser with dev tools open fetches exactly this file. What comes back
     * is the source as it was written — before the minifier removed the
     * comments, the names and everything else the code checks read — which is
     * the difference between "the scripts were bundled, so nothing could be
     * read" and an actual reading.
     *
     * @param array<string,string> $assets
     * @return array<int,array{url:string,sources:string[],content:string}>
     */
    private function fetchSourceMaps(string $baseUrl, array $assets): array
    {
        $base = parse_url($baseUrl);
        if (!$base || !isset($base['host'])) {
            return array();
        }

        $deadline = microtime(true) + self::MAP_BUDGET;
        $out = array();
        foreach ($assets as $assetUrl => $body) {
            if (count($out) >= self::MAX_MAPS) break;
            if (!preg_match('~\.js(?:[?#]|$)~i', $assetUrl)) continue;

            // The annotation is the last line of the file by convention, so
            // only the tail is searched: scanning a megabyte of minified
            // JavaScript for it costs more than the fetch does.
            $tail = strlen($body) > 4096 ? substr($body, -4096) : $body;
            if (!preg_match('~(?://|/\*)[#@]\s*sourceMappingURL=([^\s*\'"]+)~', $tail, $m)) {
                continue;
            }
            $ref = trim($m[1]);

            // Inline maps cost nothing: the payload is already in hand.
            if (stripos($ref, 'data:') === 0) {
                $decoded = $this->decodeDataUri($ref);
                if ($decoded !== null) {
                    $map = $this->readMap($assetUrl . ' (inline)', $decoded);
                    if ($map !== null) $out[] = $map;
                }
                continue;
            }

            if (microtime(true) >= $deadline) {
                break; // out of map budget; the page still gets its ordinary read
            }

            $abs = $this->resolveUrl($assetUrl, $ref);
            if ($abs === null) continue;
            $p = parse_url($abs);
            if (!$p || !isset($p['host']) || strcasecmp($p['host'], $base['host']) !== 0) {
                continue;
            }

            try {
                $r = $this->get($abs, self::MAX_MAP_BYTES, self::MAP_TIMEOUT);
            } catch (FetchError $e) {
                continue; // a missing map is the normal case, not a failure
            }
            if ($r['status'] >= 400 || $r['body'] === '') {
                continue;
            }
            $map = $this->readMap($abs, $r['body']);
            if ($map !== null) {
                $out[] = $map;
            }
        }
        return $out;
    }

    /**
     * Pull the original paths and sources out of a source map.
     *
     * Only the site's own files are kept. A map covers every dependency that
     * went into the bundle as well, and reading node_modules would be reading
     * somebody else's code and attributing it to whoever deployed this page.
     *
     * @return array{url:string,sources:string[],content:string}|null
     */
    private function readMap(string $url, string $json): ?array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || empty($data['sources']) || !is_array($data['sources'])) {
            return null;
        }

        $sources = array();
        $content = '';
        $contents = isset($data['sourcesContent']) && is_array($data['sourcesContent'])
            ? $data['sourcesContent'] : array();

        foreach ($data['sources'] as $i => $src) {
            $src = (string) $src;
            if ($src === '') continue;
            if (preg_match('~(?:^|/)(?:node_modules|vendor|bower_components)/~', $src)) {
                continue;
            }
            $sources[] = $src;

            if (strlen($content) < self::MAX_ASSET_BYTES
                && isset($contents[$i]) && is_string($contents[$i])) {
                $content .= "\n" . $contents[$i];
            }
        }

        if (!$sources) {
            return null;
        }
        return array(
            'url'     => $url,
            'sources' => $sources,
            'content' => strlen($content) > self::MAX_ASSET_BYTES
                ? substr($content, 0, self::MAX_ASSET_BYTES) : $content,
        );
    }

    /** Decode a base64 or percent-encoded data: URI, or give up quietly. */
    private function decodeDataUri(string $uri): ?string
    {
        $comma = strpos($uri, ',');
        if ($comma === false) {
            return null;
        }
        $headerPart = substr($uri, 0, $comma);
        $payload = substr($uri, $comma + 1);
        if (strlen($payload) > self::MAX_MAP_BYTES) {
            return null;
        }
        if (stripos($headerPart, ';base64') !== false) {
            $decoded = base64_decode($payload, true);
            return $decoded === false ? null : $decoded;
        }
        return rawurldecode($payload);
    }

    /**
     * @return array{url:string,body:string,status:int,contentType:string,assets:array<string,string>,headers:array<string,string>}
     * @throws FetchError
     */
    private function get(string $url, int $maxBytes, int $timeout, array $headers = array()): array
    {
        $seen = array();
        // A credential is for the host it was issued to. Redirects here are
        // vetted but not restricted to one origin, so a target that answers
        // with a Location pointing at itself and then somewhere else would
        // otherwise be handed the Authorization header on the second hop.
        // The host is remembered from the first request and the credential is
        // dropped the moment it stops matching.
        $origin = self::hostOf($url);

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $pinned = $this->assertSafe($url);

            if (in_array($url, $seen, true)) {
                throw new FetchError('The site is redirecting in a loop.');
            }
            $seen[] = $url;

            $send = $headers;
            if (self::hostOf($url) !== $origin) {
                foreach (array_keys($send) as $name) {
                    if (strtolower((string) $name) === 'authorization') {
                        unset($send[$name]);
                    }
                }
            }

            $res = function_exists('curl_init')
                ? $this->curlGet($url, $maxBytes, $timeout, $pinned, $send)
                : $this->streamGet($url, $maxBytes, $timeout, $send);

            if ($res['status'] >= 300 && $res['status'] < 400 && $res['location'] !== '') {
                $next = $this->resolveUrl($url, $res['location']);
                if ($next === null) {
                    throw new FetchError('The site redirected somewhere unreadable.');
                }
                $url = $next;
                continue;
            }

            return array(
                'url'         => $url,
                'body'        => $res['body'],
                'status'      => $res['status'],
                'contentType' => $res['contentType'],
                'headers'     => $res['headers'],
                'assets'      => array(),
            );
        }
        throw new FetchError('Too many redirects.');
    }

    /** The host of a URL, lowercased, or '' when it has none to speak of. */
    private static function hostOf(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        return is_string($host) ? strtolower($host) : '';
    }

    /**
     * @param string[] $pinned addresses assertSafe() already vetted for this host
     * @param array<string,string> $headers extra request headers, from fetchApi()
     * @return array{status:int,body:string,contentType:string,location:string,headers:array<string,string>}
     */
    private function curlGet(string $url, int $maxBytes, int $timeout, array $pinned = array(), array $headers = array()): array
    {
        $ch = curl_init($url);

        // Pin the connection to the addresses that were actually checked.
        //
        // Without this the guard is decorative: assertSafe() resolves the host
        // and approves the answer, then cURL resolves it again independently
        // when it connects. A DNS server under the target's control can return
        // a public address for the first lookup and 127.0.0.1 for the second,
        // and the check has approved a request it never saw. CURLOPT_RESOLVE
        // closes that window by telling cURL the answer up front.
        $resolve = $this->resolveOverrides($url, $pinned);
        if ($resolve) {
            curl_setopt($ch, CURLOPT_RESOLVE, $resolve);
        }

        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false, // we vet every hop ourselves
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_USERAGENT      => self::UA,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING       => '',
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER     => self::requestHeaders($headers),
            CURLOPT_BUFFERSIZE     => 16384,
            CURLOPT_NOPROGRESS     => false,
            CURLOPT_PROGRESSFUNCTION => function ($ch, $dlTotal, $dlNow) use ($maxBytes) {
                return ($dlNow > $maxBytes) ? 1 : 0; // abort oversized downloads mid-flight
            },
        ));

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            $no  = curl_errno($ch);
            curl_close($ch);
            if ($no === CURLE_ABORTED_BY_CALLBACK) {
                throw new FetchError('That page is larger than this tool will download.');
            }
            throw new FetchError('Could not reach that URL: ' . $err);
        }

        $status     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $ctype      = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        $headers = substr((string) $raw, 0, $headerSize);
        $body    = substr((string) $raw, $headerSize);

        $location = '';
        if (preg_match('~^location:\s*(.+)$~mi', $headers, $m)) {
            $location = trim($m[1]);
        }

        return array(
            'status'      => $status,
            'body'        => $this->toUtf8(substr($body, 0, $maxBytes), $ctype),
            'contentType' => $ctype,
            'location'    => $location,
            'headers'     => $this->parseHeaders($headers),
        );
    }

    /**
     * @param array<string,string> $headers extra request headers, from fetchApi()
     * @return array{status:int,body:string,contentType:string,location:string,headers:array<string,string>}
     */
    private function streamGet(string $url, int $maxBytes, int $timeout, array $headers = array()): array
    {
        if (!ini_get('allow_url_fopen')) {
            throw new FetchError('This server has neither cURL nor allow_url_fopen, so it cannot fetch pages. Use the code tab instead.');
        }
        $ctx = stream_context_create(array(
            'http' => array(
                'method'          => 'GET',
                'header'          => "User-Agent: " . self::UA . "\r\n"
                                     . implode("\r\n", self::requestHeaders($headers)) . "\r\n",
                'timeout'         => $timeout,
                'follow_location' => 0,
                'ignore_errors'   => true,
                'max_redirects'   => 1,
            ),
            'ssl' => array('verify_peer' => true, 'verify_peer_name' => true),
        ));

        $fh = @fopen($url, 'rb', false, $ctx);
        if ($fh === false) {
            throw new FetchError('Could not reach that URL.');
        }
        $body = (string) stream_get_contents($fh, $maxBytes);
        $meta = stream_get_meta_data($fh);
        fclose($fh);

        $status = 200;
        $ctype = '';
        $location = '';
        $raw = array();
        foreach ((array) ($meta['wrapper_data'] ?? array()) as $h) {
            $raw[] = (string) $h;
            if (preg_match('~^HTTP/[\d.]+\s+(\d{3})~i', (string) $h, $m)) $status = (int) $m[1];
            if (preg_match('~^content-type:\s*(.+)$~i', (string) $h, $m))  $ctype = trim($m[1]);
            if (preg_match('~^location:\s*(.+)$~i', (string) $h, $m))      $location = trim($m[1]);
        }

        return array(
            'status'      => $status,
            'body'        => $this->toUtf8($body, $ctype),
            'contentType' => $ctype,
            'location'    => $location,
            'headers'     => $this->parseHeaders(implode("\r\n", $raw)),
        );
    }

    /**
     * Response headers, lowercased, last hop only.
     *
     * Repeated names are joined rather than overwritten, because the ones that
     * repeat — Set-Cookie above all — are the ones where the second value
     * matters as much as the first.
     *
     * @return array<string,string>
     */
    private function parseHeaders(string $raw): array
    {
        $out = array();
        // cURL hands back every hop when it follows redirects itself. It does
        // not here, but a 100 Continue or an early-hints block still produces
        // more than one status line, and only the final set describes the
        // response that was actually read.
        $blocks = preg_split('~\r?\n\r?\n~', trim($raw));
        $last = is_array($blocks) ? (string) end($blocks) : $raw;

        foreach (preg_split('~\r?\n~', $last) as $line) {
            $pos = strpos($line, ':');
            if ($pos === false || $pos === 0) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $pos)));
            $value = trim(substr($line, $pos + 1));
            if ($name === '' || $value === '') {
                continue;
            }
            if (isset($out[$name])) {
                $out[$name] .= ', ' . $value;
            } else {
                $out[$name] = $value;
            }
            // A header block is normally a dozen lines. Anything sending
            // hundreds is not worth carrying into the analyser.
            if (count($out) >= 60) {
                break;
            }
        }
        return $out;
    }

    /**
     * Build the CURLOPT_RESOLVE entries that pin a host to vetted addresses.
     *
     * @param string[] $pinned
     * @return string[]
     */
    private function resolveOverrides(string $url, array $pinned): array
    {
        if (!$pinned) {
            return array();
        }
        $p = parse_url($url);
        if (!$p || empty($p['host'])) {
            return array();
        }
        $host = strtolower(trim($p['host'], '[]'));
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return array(); // already an address; nothing to resolve
        }

        $port = isset($p['port'])
            ? (int) $p['port']
            : (strtolower((string) $p['scheme']) === 'https' ? 443 : 80);

        // cURL wants literal IPv6 in brackets.
        $addrs = array();
        foreach ($pinned as $ip) {
            $addrs[] = strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip;
        }

        return array($host . ':' . $port . ':' . implode(',', $addrs));
    }

    /**
     * Reject anything that is not a public web address.
     *
     * Returns the addresses it approved, so the caller can pin the connection
     * to exactly those rather than letting the resolver be asked twice.
     *
     * @return string[]
     * @throws FetchError
     */
    private function assertSafe(string $url): array
    {
        $p = parse_url($url);
        if (!$p || empty($p['scheme']) || empty($p['host'])) {
            throw new FetchError('That does not look like a URL.');
        }
        $scheme = strtolower($p['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new FetchError('Only http and https addresses can be analysed.');
        }
        if (!empty($p['port']) && !in_array((int) $p['port'], array(80, 443, 8080, 8443), true)) {
            throw new FetchError('Only standard web ports can be analysed.');
        }

        $host = strtolower(trim($p['host'], '[]'));

        if ($host === 'localhost' || substr($host, -6) === '.local'
            || substr($host, -9) === '.internal' || substr($host, -5) === '.home'
            || substr($host, -7) === '.lan') {
            throw new FetchError('Local addresses cannot be analysed.');
        }

        if (isset($this->safeHostCache[$host])) {
            return $this->safeHostCache[$host];
        }

        $ips = array();
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $v4 = @gethostbynamel($host);
            if (is_array($v4)) {
                $ips = $v4;
            }
            if (function_exists('dns_get_record')) {
                $aaaa = @dns_get_record($host, DNS_AAAA);
                if (is_array($aaaa)) {
                    foreach ($aaaa as $rec) {
                        if (!empty($rec['ipv6'])) $ips[] = $rec['ipv6'];
                    }
                }
            }
        }

        if (!$ips) {
            throw new FetchError('That hostname does not resolve.');
        }
        foreach ($ips as $ip) {
            if (!$this->isPublicIp($ip)) {
                throw new FetchError('That address is on a private or reserved network and will not be fetched.');
            }
        }

        return $this->safeHostCache[$host] = array_values(array_unique($ips));
    }

    private function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
            // The cloud metadata endpoint is not covered by the reserved-range flags.
            if (strpos($ip, '169.254.') === 0 || strpos($ip, '100.64.') === 0) {
                return false;
            }
            return true;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $norm = strtolower($ip);
            if ($norm === '::1' || $norm === '::') return false;
            if (preg_match('~^(f[cd]|fe8|fe9|fea|feb)~', $norm)) return false; // ULA + link-local
            if (strpos($norm, '::ffff:') === 0) {
                return $this->isPublicIp(substr($norm, 7)); // IPv4-mapped
            }
            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }
        return false;
    }

    public function normalize(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            throw new FetchError('Give it a URL first.');
        }
        if (strlen($url) > 2000) {
            throw new FetchError('That URL is absurdly long.');
        }
        if (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $url)) {
            $url = 'https://' . $url;
        }
        // Convert an internationalised domain to punycode where the host has it.
        $p = parse_url($url);
        if ($p && !empty($p['host']) && function_exists('idn_to_ascii') && preg_match('~[^\x20-\x7f]~', $p['host'])) {
            $ascii = idn_to_ascii($p['host'], IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii) {
                $url = str_replace($p['host'], $ascii, $url);
            }
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new FetchError('That does not look like a URL.');
        }
        return $url;
    }

    /** Resolve a possibly-relative reference against a base URL. */
    private function resolveUrl(string $base, string $ref): ?string
    {
        $ref = trim(html_entity_decode($ref, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($ref === '' || strpos($ref, 'data:') === 0 || strpos($ref, '#') === 0
            || stripos($ref, 'javascript:') === 0 || stripos($ref, 'mailto:') === 0) {
            return null;
        }
        if (preg_match('~^https?://~i', $ref)) {
            return $ref;
        }
        $b = parse_url($base);
        if (!$b || empty($b['scheme']) || empty($b['host'])) {
            return null;
        }
        $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');

        if (strpos($ref, '//') === 0) {
            return $b['scheme'] . ':' . $ref;
        }
        if (strpos($ref, '/') === 0) {
            return $origin . $ref;
        }
        $dir = isset($b['path']) ? preg_replace('~/[^/]*$~', '/', $b['path']) : '/';
        $path = $dir . $ref;

        // Flatten ./ and ../
        $parts = array();
        foreach (explode('/', $path) as $seg) {
            if ($seg === '.' || $seg === '') continue;
            if ($seg === '..') { array_pop($parts); continue; }
            $parts[] = $seg;
        }
        return $origin . '/' . implode('/', $parts);
    }

    private function toUtf8(string $body, string $contentType): string
    {
        $charset = '';
        if (preg_match('~charset=["\']?([\w-]+)~i', $contentType, $m)) {
            $charset = strtolower($m[1]);
        } elseif (preg_match('~<meta[^>]+charset=["\']?([\w-]+)~i', substr($body, 0, 4096), $m)) {
            $charset = strtolower($m[1]);
        }
        if ($charset !== '' && $charset !== 'utf-8' && function_exists('iconv')) {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $body);
            if ($converted !== false) {
                return $converted;
            }
        }
        // Drop anything that is not valid UTF-8 so the regexes with /u keep working.
        if (function_exists('mb_convert_encoding') && !preg_match('//u', $body)) {
            return (string) mb_convert_encoding($body, 'UTF-8', 'UTF-8');
        }
        return $body;
    }
}
