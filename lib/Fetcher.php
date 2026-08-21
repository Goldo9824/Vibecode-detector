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
     * @return array{url:string,body:string,status:int,assets:array<string,string>,contentType:string}
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

    /** @return array<string,string> */
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

        $out = array();
        foreach ($urls as $raw) {
            if (count($out) >= self::MAX_ASSETS) break;

            $abs = $this->resolveUrl($baseUrl, $raw);
            if ($abs === null) continue;

            $p = parse_url($abs);
            if (!$p || !isset($p['host']) || strcasecmp($p['host'], $base['host']) !== 0) {
                continue; // same-origin only: no third-party CDNs
            }
            if (isset($out[$abs])) continue;

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
     * @return array{url:string,body:string,status:int,contentType:string,assets:array<string,string>}
     * @throws FetchError
     */
    private function get(string $url, int $maxBytes, int $timeout): array
    {
        $seen = array();
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $pinned = $this->assertSafe($url);

            if (in_array($url, $seen, true)) {
                throw new FetchError('The site is redirecting in a loop.');
            }
            $seen[] = $url;

            $res = function_exists('curl_init')
                ? $this->curlGet($url, $maxBytes, $timeout, $pinned)
                : $this->streamGet($url, $maxBytes, $timeout);

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
                'assets'      => array(),
            );
        }
        throw new FetchError('Too many redirects.');
    }

    /**
     * @param string[] $pinned addresses assertSafe() already vetted for this host
     * @return array{status:int,body:string,contentType:string,location:string}
     */
    private function curlGet(string $url, int $maxBytes, int $timeout, array $pinned = array()): array
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
            CURLOPT_HTTPHEADER     => array('Accept: text/html,application/xhtml+xml,text/css,application/javascript,*/*;q=0.8'),
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
        );
    }

    /** @return array{status:int,body:string,contentType:string,location:string} */
    private function streamGet(string $url, int $maxBytes, int $timeout): array
    {
        if (!ini_get('allow_url_fopen')) {
            throw new FetchError('This server has neither cURL nor allow_url_fopen, so it cannot fetch pages. Use the code tab instead.');
        }
        $ctx = stream_context_create(array(
            'http' => array(
                'method'          => 'GET',
                'header'          => "User-Agent: " . self::UA . "\r\nAccept: text/html,*/*;q=0.8\r\n",
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
        foreach ((array) ($meta['wrapper_data'] ?? array()) as $h) {
            if (preg_match('~^HTTP/[\d.]+\s+(\d{3})~i', (string) $h, $m)) $status = (int) $m[1];
            if (preg_match('~^content-type:\s*(.+)$~i', (string) $h, $m))  $ctype = trim($m[1]);
            if (preg_match('~^location:\s*(.+)$~i', (string) $h, $m))      $location = trim($m[1]);
        }

        return array(
            'status'      => $status,
            'body'        => $this->toUtf8($body, $ctype),
            'contentType' => $ctype,
            'location'    => $location,
        );
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

        return array_values(array_unique($ips));
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
