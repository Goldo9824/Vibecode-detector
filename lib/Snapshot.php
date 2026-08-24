<?php
declare(strict_types=1);

require_once __DIR__ . '/Fetcher.php';

/**
 * The picture of the front page that sits at the top of a URL report.
 *
 * This server cannot render a page. Shared hosting has no headless browser
 * and the project has no dependencies to install one with, so the shot is
 * taken elsewhere and passed through api/snapshot.php on the way to the
 * browser. Passing it through rather than pointing the <img> straight at the
 * renderer is the whole privacy design: the visitor's browser never talks to
 * anyone but this site, so their address and their referrer stay here, and
 * the content policy in .htaccess can keep saying img-src 'self'.
 *
 * Where "elsewhere" is, is the operator's choice, and the two answers differ
 * in who learns which addresses are being checked:
 *
 *   'self'  — a machine you run, using tools/shot-server.php. Nobody outside
 *             your own hosting is told anything. This is the default, because
 *             it is the answer that gives nothing away.
 *   hosted  — mShots, Thum.io, Microlink or any other service by template.
 *             Nothing to set up, at the price of telling that service which
 *             address was analysed. Used when no renderer of your own is
 *             configured, and named under the picture when it is.
 *
 * A request to your own renderer is signed with a secret the two machines
 * share and expires in five minutes, so the renderer answers this site and
 * not the internet at large.
 *
 * Nothing is written to disk at either end. An image is fetched, streamed to
 * the one visitor who asked for it, and forgotten, which keeps "nothing is
 * stored" true of this feature the same as it is of the analysis itself.
 */
final class Snapshot
{
    const WIDTH   = 1200;
    const HEIGHT  = 900;
    const TIMEOUT = 8;

    /** Your own renderer starts a browser, so it is given longer than a service. */
    const SELF_TIMEOUT = 20;

    /** Ceiling on what will be pulled from the renderer and passed on. */
    const MAX_BYTES = 2097152;

    /**
     * Below this, a 200 is not a page.
     *
     * Every renderer here answers a first request for an unseen page with a
     * placeholder while its own queue catches up, and answers it with 200 and
     * an image rather than a status a client could read. They are all a few
     * hundred bytes of flat colour; a rendered page is tens of kilobytes.
     */
    const PLACEHOLDER_BYTES = 3072;

    /** How long a signed request to your own renderer stays good for. */
    const SIGNED_TTL = 300;

    /**
     * The renderers this knows how to ask, and what to call them in public.
     *
     * {url} is the target, {enc} the same percent-encoded, {w} and {h} the
     * viewport, {key} whatever the operator put in the config, {endpoint} the
     * address of your own renderer, and {exp}/{sig} the expiry and signature
     * that renderer checks. A service not listed here is reachable through
     * provider 'custom' and a template of the same shape, so adding one is a
     * config change rather than a patch.
     */
    const PROVIDERS = array(
        'self' => array(
            'label'    => 'this site\'s own renderer',
            'template' => '{endpoint}?url={enc}&w={w}&h={h}&e={exp}&t={sig}',
        ),
        'mshots' => array(
            'label'    => 'WordPress.com mShots',
            'template' => 'https://s0.wp.com/mshots/v1/{enc}?w={w}&h={h}',
        ),
        'thumio' => array(
            'label'    => 'Thum.io',
            'template' => 'https://image.thum.io/get/width/{w}/crop/{h}/noanimate/{url}',
        ),
        'microlink' => array(
            'label'    => 'Microlink',
            'template' => 'https://api.microlink.io/?url={enc}&screenshot=true&embed=screenshot.url&meta=false&viewport.width={w}&viewport.height={h}',
        ),
    );

    /** @var array<string,mixed>|null */
    private static $settings = null;

    /** @return array<string,mixed> */
    public static function settings(): array
    {
        if (self::$settings !== null) {
            return self::$settings;
        }

        $config = array();
        $file = VCD_DATA . '/snapshot-config.php';
        if (is_readable($file)) {
            $loaded = require $file;
            if (is_array($loaded)) {
                $config = $loaded;
            }
        }

        $provider = isset($config['provider']) ? (string) $config['provider'] : 'self';
        $template = isset($config['template']) ? (string) $config['template'] : '';
        $endpoint = isset($config['endpoint']) ? rtrim((string) $config['endpoint']) : '';
        $secret   = isset($config['secret']) ? (string) $config['secret'] : '';

        // Your own renderer is the default, and nobody has one until they say
        // where it is. An installation that has configured nothing at all is
        // not asking to keep its pictures in-house — it has never been asked —
        // so it gets the hosted default, named under every picture as always.
        //
        // An endpoint with no secret is a different case: something *was*
        // configured and is wrong. Falling back there would send addresses to a
        // third party on the strength of a typo, so the picture goes away
        // instead and the operator finds a broken feature rather than a broken
        // promise.
        $fellBack = false;
        if ($provider === 'self' && $endpoint === '' && $secret === '') {
            $provider = 'mshots';
            $fellBack = true;
        }

        if (isset(self::PROVIDERS[$provider])) {
            $label = self::PROVIDERS[$provider]['label'];
            if ($template === '') {
                $template = self::PROVIDERS[$provider]['template'];
            }
        } else {
            // An operator pointing at their own renderer has to say what to
            // call it, because the caption under the picture is the whole of
            // the disclosure and "custom" tells a reader nothing.
            $label = isset($config['label']) ? (string) $config['label'] : '';
        }
        if (isset($config['label'])) {
            $label = (string) $config['label'];
        }

        $width  = isset($config['width'])  ? (int) $config['width']  : self::WIDTH;
        $height = isset($config['height']) ? (int) $config['height'] : self::HEIGHT;

        // A renderer of your own is slower to answer than a service with the
        // page already cached — it starts a browser — so it gets longer before
        // the request is called off, unless the operator has said otherwise.
        $timeout = isset($config['timeout'])
            ? (int) $config['timeout']
            : ($provider === 'self' ? self::SELF_TIMEOUT : self::TIMEOUT);

        return self::$settings = array(
            'enabled'  => !isset($config['enabled']) || !empty($config['enabled']),
            'provider' => $provider,
            'template' => $template,
            'label'    => $label,
            'endpoint' => $endpoint,
            'secret'   => $secret,
            'hosted'   => $provider !== 'self',
            'fellBack' => $fellBack,
            'key'      => isset($config['key']) ? (string) $config['key'] : '',
            'width'    => max(320, min(2000, $width)),
            'height'   => max(240, min(2000, $height)),
            'timeout'  => max(2, min(30, $timeout)),
        );
    }

    /** Test seam: forget a config that was read before the file changed. */
    public static function forget(): void
    {
        self::$settings = null;
    }

    public static function enabled(): bool
    {
        $s = self::settings();
        if (!$s['enabled'] || $s['template'] === '' || $s['label'] === '') {
            return false;
        }

        // Half-configured is off, not "off to a stranger instead": your own
        // renderer needs both an address to ask and the secret it answers to.
        if ($s['provider'] === 'self' && ($s['endpoint'] === '' || $s['secret'] === '')) {
            return false;
        }

        return true;
    }

    /**
     * What the report carries: where to ask for the picture, and who renders
     * it. Null when the feature is off or the target is not something a
     * renderer could be pointed at, and the block is then simply absent.
     *
     * @return array{url:string,provider:string,width:int,height:int}|null
     */
    public static function descriptor(string $target): ?array
    {
        if (!self::enabled() || !self::addressable($target)) {
            return null;
        }

        $s = self::settings();

        return array(
            'url' => vcd_site_url() . '/api/snapshot.php?u=' . rawurlencode($target)
                   . '&t=' . self::token($target),
            'provider' => $s['label'],
            // Whether the picture was taken off this operator's own hosting.
            // The page says it either way; this is what decides which sentence.
            'hosted'   => $s['hosted'],
            'width'    => $s['width'],
            'height'   => $s['height'],
        );
    }

    /**
     * The endpoint answers for addresses this installation itself handed out,
     * and no others. Without that it is an open image proxy: anyone could
     * spend this server's outbound requests, and the renderer's budget, on
     * whatever they liked and have the answer come back wearing this domain.
     */
    public static function token(string $target): string
    {
        return substr(vcd_sign('snapshot|v1|' . $target), 0, 32);
    }

    public static function tokenValid(string $target, string $token): bool
    {
        return hash_equals(self::token($target), $token);
    }

    public static function requestUrl(string $target, ?int $expiry = null): string
    {
        $s = self::settings();

        if ($expiry === null) {
            $expiry = time() + self::SIGNED_TTL;
        }

        return strtr($s['template'], array(
            '{endpoint}' => $s['endpoint'],
            '{url}'      => $target,
            '{enc}'      => rawurlencode($target),
            '{w}'        => (string) $s['width'],
            '{h}'        => (string) $s['height'],
            '{key}'      => rawurlencode($s['key']),
            '{exp}'      => (string) $expiry,
            '{sig}'      => self::signRequest($target, $expiry),
        ));
    }

    /**
     * What proves to your own renderer that this site asked.
     *
     * Signed over the size and the expiry as well as the address, so a request
     * lifted from a log cannot be replayed tomorrow or edited into a request
     * for a different page. tools/shot-server.php computes the same string;
     * changing the shape of it means changing both.
     */
    public static function signRequest(string $target, int $expiry): string
    {
        $s = self::settings();
        if ($s['secret'] === '') {
            return '';
        }

        return hash_hmac(
            'sha256',
            $target . '|' . $s['width'] . '|' . $s['height'] . '|' . $expiry,
            $s['secret']
        );
    }

    /**
     * Ask the renderer for the picture.
     *
     * 'pending' is the normal first answer for a page nobody has asked about
     * before: these services queue the render and serve a placeholder in the
     * meantime. It is reported as its own state rather than as a failure so
     * that the page can say "still rendering" and come back, which is the
     * difference between a slow picture and a broken one.
     *
     * @return array{state:string,body:string,type:string,reason:string}
     */
    public static function capture(string $target): array
    {
        if (!self::enabled()) {
            return self::outcome('off', '', '', 'Page pictures are switched off on this installation.');
        }
        if (!self::addressable($target)) {
            return self::outcome('failed', '', '', 'That address cannot be pictured.');
        }

        $s = self::settings();
        $res = self::request(self::requestUrl($target));
        if ($res === null) {
            return self::outcome('failed', '', '', 'The renderer could not be reached.');
        }

        // 202 is what a well-behaved renderer says while it works; 429 and 5xx
        // are both worth one more try from the browser rather than a red box,
        // since the page beneath the picture is already complete either way.
        if ($res['status'] === 202 || $res['status'] === 429 || $res['status'] >= 500) {
            return self::outcome('pending', '', '', 'The renderer is still working on it.');
        }
        if ($res['status'] !== 200) {
            return self::outcome('failed', '', '', 'The renderer answered ' . $res['status'] . '.');
        }

        $type = self::imageType($res['body']);
        if ($type === '') {
            return self::outcome('failed', '', '', 'The renderer sent something that is not an image.');
        }
        // Only a hosted service answers with filler while it queues the render.
        // Your own renderer either has the page or says it does not, and a
        // genuinely blank page compresses small enough to fail this test.
        if ($s['hosted']
            && (strlen($res['body']) < self::PLACEHOLDER_BYTES || self::looksLikeFiller($res['url']))) {
            return self::outcome('pending', '', '', 'The renderer is still working on it.');
        }

        return self::outcome('ready', $res['body'], $type, '');
    }

    /** @return array{state:string,body:string,type:string,reason:string} */
    private static function outcome(string $state, string $body, string $type, string $reason): array
    {
        return array('state' => $state, 'body' => $body, 'type' => $type, 'reason' => $reason);
    }

    /**
     * Trust the bytes over the header.
     *
     * A renderer's content type is a claim; the magic number is what the file
     * actually is. Only what a browser will draw as an image gets passed on,
     * which is what stops this endpoint being used to serve something else
     * entirely from this domain.
     */
    public static function imageType(string $body): string
    {
        if (strncmp($body, "\xFF\xD8\xFF", 3) === 0) {
            return 'image/jpeg';
        }
        if (strncmp($body, "\x89PNG\r\n\x1A\n", 8) === 0) {
            return 'image/png';
        }
        if (strncmp($body, 'RIFF', 4) === 0 && substr($body, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        if (strncmp($body, 'GIF8', 4) === 0) {
            return 'image/gif';
        }

        return '';
    }

    private static function looksLikeFiller(string $effectiveUrl): bool
    {
        $path = (string) parse_url($effectiveUrl, PHP_URL_PATH);
        return (bool) preg_match('~(loading|placeholder|default|blank)[^/]*\.(png|jpe?g|gif|webp)$~i', $path);
    }

    /**
     * A public web address, of the kind a renderer could actually visit.
     *
     * The tighter check belongs to Fetcher, which has already run against
     * anything that reached a report. This is here so the endpoint refuses
     * the obviously pointless — a file:// scheme, an intranet name — before
     * spending a request on it.
     */
    public static function addressable(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }
        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }

        $host = strtolower((string) $parts['host']);
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        return strpos($host, '.') !== false && substr($host, -6) !== '.local';
    }

    /**
     * @return array{status:int,body:string,url:string}|null
     */
    private static function request(string $url)
    {
        $timeout = self::settings()['timeout'];

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 4,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_USERAGENT      => Fetcher::UA,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_HTTPHEADER     => array('Accept: image/*'),
                CURLOPT_NOPROGRESS     => false,
                CURLOPT_PROGRESSFUNCTION => function ($ch, $dlTotal, $dlNow) {
                    return ($dlNow > self::MAX_BYTES) ? 1 : 0;
                },
            ));

            $body = curl_exec($ch);
            if ($body === false) {
                curl_close($ch);
                return null;
            }

            $out = array(
                'status' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
                'body'   => substr((string) $body, 0, self::MAX_BYTES),
                'url'    => (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
            );
            curl_close($ch);

            return $out;
        }

        if (!ini_get('allow_url_fopen')) {
            return null;
        }

        $ctx = stream_context_create(array('http' => array(
            'method'           => 'GET',
            'timeout'          => $timeout,
            'follow_location'  => 1,
            'max_redirects'    => 5,
            'ignore_errors'    => true,
            'header'           => "User-Agent: " . Fetcher::UA . "\r\nAccept: image/*\r\n",
        )));

        $stream = @fopen($url, 'rb', false, $ctx);
        if ($stream === false) {
            return null;
        }

        $meta = stream_get_meta_data($stream);
        $body = (string) stream_get_contents($stream, self::MAX_BYTES);
        fclose($stream);

        // The last status line wins: a followed redirect leaves every hop's
        // headers in wrapper_data, oldest first.
        $status = 0;
        foreach (isset($meta['wrapper_data']) && is_array($meta['wrapper_data']) ? $meta['wrapper_data'] : array() as $line) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', (string) $line, $m)) {
                $status = (int) $m[1];
            }
        }

        return array('status' => $status, 'body' => $body, 'url' => $url);
    }
}
