<?php
declare(strict_types=1);

/**
 * The tags that decide how a page looks in a search result and in a shared
 * link, written once instead of by hand on every page.
 *
 * They were hand-written per page, and had drifted the way hand-written head
 * tags always do: the front page had a Twitter card and no canonical, the
 * field guide had a canonical URL in its og:url and no Twitter title, and the
 * verify page had neither. A search engine reading three pages of one site
 * should not be able to tell they were written on three different days.
 *
 * Everything is absolute, because a relative og:image is not a URL a scraper
 * can fetch, and the site URL comes from vcd_site_url() so a fork on another
 * domain — or in a subdirectory — gets its own addresses rather than this
 * one's.
 */
final class Seo
{
    const SITE_NAME = 'Vibe Code Detector';
    const LOCALE = 'en_GB';
    const SOCIAL_IMAGE = '/assets/img/social-preview.png';

    /**
     * The whole head block: title, description, canonical, robots, Open Graph,
     * Twitter, and any structured data the page wants.
     *
     * @param array{
     *   title:string,
     *   description:string,
     *   path:string,
     *   type?:string,
     *   socialTitle?:string,
     *   socialDescription?:string,
     *   imageAlt?:string,
     *   robots?:string,
     *   jsonLd?:array<int,array<string,mixed>>
     * } $page
     */
    public static function head(array $page): string
    {
        $title = (string) $page['title'];
        $description = (string) $page['description'];
        $canonical = self::url((string) $page['path']);
        $type = isset($page['type']) ? (string) $page['type'] : 'website';

        $socialTitle = isset($page['socialTitle']) ? (string) $page['socialTitle'] : $title;
        $socialDesc = isset($page['socialDescription']) ? (string) $page['socialDescription'] : $description;
        $imageAlt = isset($page['imageAlt'])
            ? (string) $page['imageAlt']
            : 'Vibe Code Detector — reads the tells, shows its working';
        $image = self::url(self::SOCIAL_IMAGE);

        $out = '<title>' . self::esc($title) . '</title>' . "\n"
             . self::meta('description', $description)
             . '<link rel="canonical" href="' . self::esc($canonical) . '">' . "\n";

        // A page that says noindex has nothing to gain from the rest of it, but
        // it still gets a canonical: a certificate URL carries a payload in its
        // query string, and without one every payload is a separate page.
        if (isset($page['robots']) && $page['robots'] !== '') {
            return $out . self::meta('robots', (string) $page['robots']);
        }

        $out .= self::meta('robots', 'index, follow, max-image-preview:large, max-snippet:-1');

        $out .= self::property('og:site_name', self::SITE_NAME)
              . self::property('og:locale', self::LOCALE)
              . self::property('og:type', $type)
              . self::property('og:title', $socialTitle)
              . self::property('og:description', $socialDesc)
              . self::property('og:url', $canonical)
              . self::property('og:image', $image)
              . self::property('og:image:width', '1280')
              . self::property('og:image:height', '640')
              . self::property('og:image:alt', $imageAlt);

        $out .= self::meta('twitter:card', 'summary_large_image')
              . self::meta('twitter:title', $socialTitle)
              . self::meta('twitter:description', $socialDesc)
              . self::meta('twitter:image', $image)
              . self::meta('twitter:image:alt', $imageAlt);

        if (!empty($page['jsonLd'])) {
            foreach ($page['jsonLd'] as $block) {
                $out .= self::jsonLd($block);
            }
        }

        return $out;
    }

    /** An absolute URL for a path on this site, whatever domain it is running on. */
    public static function url(string $path): string
    {
        $base = rtrim(vcd_site_url(), '/');
        if ($path === '' || $path === '/') {
            return $base . '/';
        }
        return $base . '/' . ltrim($path, '/');
    }

    /**
     * One block of JSON-LD.
     *
     * JSON_UNESCAPED_SLASHES keeps the URLs readable; JSON_HEX_TAG is what
     * stops a description containing "</script>" from ending the block early,
     * which is the only way structured data can turn into an injection.
     *
     * @param array<string,mixed> $data
     */
    public static function jsonLd(array $data): string
    {
        $json = json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        if ($json === false) {
            return '';
        }
        return '<script type="application/ld+json">' . $json . '</script>' . "\n";
    }

    /**
     * The site itself, as structured data. Every page carries it, which is how
     * a search engine learns the name to show under the result rather than
     * guessing one out of the domain.
     *
     * @return array<string,mixed>
     */
    public static function siteSchema(): array
    {
        return array(
            '@context' => 'https://schema.org',
            '@type'    => 'WebSite',
            'name'     => self::SITE_NAME,
            'url'      => self::url('/'),
            'inLanguage' => 'en',
        );
    }

    private static function meta(string $name, string $content): string
    {
        return '<meta name="' . self::esc($name) . '" content="' . self::esc($content) . '">' . "\n";
    }

    private static function property(string $property, string $content): string
    {
        return '<meta property="' . self::esc($property) . '" content="' . self::esc($content) . '">' . "\n";
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
