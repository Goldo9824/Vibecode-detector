<?php
declare(strict_types=1);

require_once __DIR__ . '/Report.php';
require_once __DIR__ . '/Text.php';
require_once __DIR__ . '/CodeAnalyzer.php';

/**
 * Reads a served page the way you would read it yourself with View Source
 * open: fingerprints first, then structure, then the look of the thing.
 *
 * Only the document and a handful of its own stylesheets and scripts are
 * fetched. Nothing is probed, guessed at or enumerated — this reads what a
 * browser would have loaded anyway.
 */
final class SiteAnalyzer
{
    /**
     * Ceiling on the haystack the pattern checks run over.
     *
     * The fetcher will hand back up to 3 MB of document plus four assets, and
     * roughly sixty patterns run across that. On the shared hosting this
     * targets — modest CPU, a 30-second limit — the unbounded version can spend
     * long enough to be killed mid-request. Fingerprint checks still see
     * everything, because those are the ones worth never missing; the heavier
     * stylistic scans get a bounded prefix and the report says so.
     */
    const MAX_SCAN = 786432;

    /** @var string */
    private $html;
    /** @var string */
    private $url;
    /** @var array<string,string> assets: url => body */
    private $assets;
    /** @var array<string,mixed> */
    private $meta;
    /** @var Report */
    private $r;
    /** @var string */
    private $text;
    /** @var string|null */
    private $blob = null;
    /** @var bool */
    private $truncated = false;

    /**
     * @param array<string,string> $assets same-origin css/js that the page pulls in
     * @param array<string,mixed>  $meta   transport details from the fetcher
     */
    public function __construct(string $url, string $html, array $assets = array(), array $meta = array())
    {
        $this->url    = $url;
        $this->html   = $html;
        $this->assets = $assets;
        $this->meta   = $meta;
        // Bounded, and cut on a character boundary so the /u patterns that read
        // it keep matching instead of erroring into an empty result.
        $this->text   = Text::visibleText(Text::safeCut($html, self::MAX_SCAN));
    }

    public function analyze(): Report
    {
        $host = (string) parse_url($this->url, PHP_URL_HOST);
        $this->r = new Report('url', $this->url);
        $this->r->setSubtitle($host);
        $this->r->stat('bytes', strlen($this->html));
        $this->r->stat('assets', count($this->assets));
        $this->r->stat('httpStatus', isset($this->meta['status']) ? $this->meta['status'] : null);

        if (strlen($this->html) < 1500) {
            $this->r->stat('thin', true);
            $this->r->note('The page returned very little markup. If it renders its content with JavaScript, most of what matters never reached this analyser.');
        }

        $this->checkFingerprints($host);
        $this->checkShell();
        $this->checkComments();
        $this->checkPalette();
        $this->checkTypeAndIcons();
        $this->checkComponentDefaults();
        $this->checkSymmetry();
        $this->checkContent();
        $this->checkHumanMarks();
        $this->checkMedia();
        $this->checkCms();
        $this->checkAssets();

        if ($this->truncated || strlen($this->html) > self::MAX_SCAN) {
            $this->r->stat('scanTruncated', true);
            $this->r->note(sprintf(
                'This page and its assets run to more than %d KB, so the stylistic scans read the first %d KB of it. Fingerprint checks still saw the whole document.',
                (int) round((strlen($this->html) + array_sum(array_map('strlen', $this->assets))) / 1024),
                (int) round(self::MAX_SCAN / 1024)
            ));
        }

        $this->r->note('Signs run in one direction only. Finding a builder\'s fingerprint identifies it; finding none proves nothing, because agentic editors write into an ordinary repository and leave no trace in the served page.');

        return $this->r;
    }

    // ------------------------------------------------------- hard fingerprints

    private function checkFingerprints(string $host): void
    {
        $hay = $this->html . "\n" . implode("\n", array_keys($this->assets));

        $rules = array(
            'fp.lovable' => array(
                '~cdn\.gpteng\.co~i'          => 'the Lovable runtime is loaded from cdn.gpteng.co',
                '~gptengineer\.js~i'          => 'gptengineer.js is referenced',
                '~/lovable-uploads/~i'        => 'assets are served from /lovable-uploads/',
                '~lovable-tagger~i'           => 'the lovable-tagger build plugin left its marker',
                '~\.lovable\.(?:app|dev)~i'   => 'a lovable.app host is referenced',
                '~Edit with Lovable~i'        => 'the "Edit with Lovable" badge is present',
                '~/__l5e/|\~flock\.js~i'      => 'a Lovable runtime path is present',
            ),
            'fp.bolt' => array(
                '~Made in Bolt~i'             => 'the "Made in Bolt" badge is present',
                '~\.bolt\.host~i'             => 'a bolt.host deployment host is referenced',
                '~bolt\.new~i'                => 'bolt.new is referenced in the page',
                '~stackblitz\.com/~i'         => 'a StackBlitz/Bolt asset host is referenced',
            ),
            'fp.v0' => array(
                '~Built with v0~i'            => 'the "Built with v0" badge is present',
                '~v0\.dev~i'                  => 'v0.dev is referenced in the page',
                '~v0-[a-z0-9-]+\.vercel\.app~i' => 'the page is served from a v0 preview host',
            ),
            'fp.replit' => array(
                '~replit-badge|replit\.com/badge~i' => 'the Replit badge is embedded',
                '~\.repl\.co|\.replit\.(?:app|dev)~i' => 'a Replit host is referenced',
            ),
            'fp.base44' => array(
                '~@base44/sdk~i'              => 'the Base44 SDK is loaded',
                '~\.base44\.app~i'            => 'a base44.app host is referenced',
            ),
        );

        foreach ($rules as $id => $patterns) {
            $found = array();
            foreach ($patterns as $re => $why) {
                if (preg_match($re, $hay)) {
                    $found[] = $why;
                }
            }
            if ($found) {
                $this->r->flag($id, $found);
            }
        }

        // The long tail of builders, named in the evidence rather than each
        // earning its own catalogue entry.
        $others = array(
            'Rocket'          => '~rocket\.new|rocket-?builder~i',
            'Create.xyz'      => '~create\.xyz|createxyz~i',
            'Tempo Labs'      => '~tempolabs\.ai|tempo-?labs~i',
            'Databutton'      => '~databutton\.com|databutton-app~i',
            'Emergent'        => '~emergent\.sh|emergentagent~i',
            'Firebase Studio' => '~idx\.google\.com|firebase-?studio~i',
            'Builder.io'      => '~builder\.io/c/|visual-?copilot~i',
            'Anima'           => '~animaapp\.com|anima-?generated~i',
            'Locofy'          => '~locofy\.ai|locofy-?generated~i',
            'Dora'            => '~dora\.run|dorabuilder~i',
            'Durable'         => '~durable\.co/site|durablesites~i',
            'Mocha'           => '~getmocha\.com|mocha-?app~i',
        );
        $named = array();
        foreach ($others as $tool => $re) {
            if (preg_match($re, $hay)) {
                $named[] = $tool . ' left its signature in the page';
            }
        }
        if ($named) {
            $this->r->flag('fp.builder_other', $named);
        }

        // The document naming its own generator.
        if (preg_match('~<meta[^>]+name=["\']generator["\'][^>]+content=["\']([^"\']+)["\']~i', $this->html, $m)) {
            $gen = $m[1];
            $this->r->stat('generator', $gen);
            if (preg_match('~(lovable|bolt|v0|base44|replit|dualite|softr|create\.xyz|tempo|famous\.ai|gpt|claude|copilot|cursor)~i', $gen)) {
                $this->r->flag('fp.generator_meta', array('<meta name="generator" content="' . $gen . '">'));
            }
        }

        // Builder host in the URL itself.
        if (preg_match('~\.(lovable\.app|bolt\.host|base44\.app|replit\.app|repl\.co)$~i', $host, $m)) {
            $map = array('lovable.app' => 'fp.lovable', 'bolt.host' => 'fp.bolt',
                         'base44.app' => 'fp.base44', 'replit.app' => 'fp.replit', 'repl.co' => 'fp.replit');
            $key = strtolower($m[1]);
            if (isset($map[$key])) {
                $this->r->flag($map[$key], array('the site is served from ' . $host));
            }
        }
    }

    // -------------------------------------------------------------- page shape

    private function checkShell(): void
    {
        $bodyInner = '';
        if (preg_match('~<body[^>]*>(.*)</body>~is', $this->html, $m)) {
            $bodyInner = $m[1];
        }

        $emptyRoot = preg_match('~<div[^>]+id=["\'](?:root|app|__next)["\'][^>]*>\s*</div>~i', $bodyInner);
        $hashedBundle = preg_match('~/assets/index-[A-Za-z0-9_-]{6,}\.js~i', $this->html);

        if ($emptyRoot && $hashedBundle) {
            $this->r->flag('st.spa_shell', array(
                'the served body is an empty mount point plus one hashed bundle',
                'this is the default output shape of the current generator stack, and also of every hand-built single-page app, so it only says where to look next',
            ));
        }

        // A complete, textbook head that arrived fully formed.
        $headBits = 0;
        foreach (array('~<meta[^>]+property=["\']og:~i', '~<meta[^>]+name=["\']twitter:~i',
                       '~<meta[^>]+name=["\']description~i', '~<link[^>]+rel=["\']canonical~i',
                       '~<meta[^>]+name=["\']viewport~i', '~<link[^>]+rel=["\'][^"\']*icon~i',
                       '~application/ld\+json~i') as $re) {
            if (preg_match($re, $this->html)) $headBits++;
        }
        $imgs = preg_match_all('~<img\b[^>]*>~i', $this->html, $imgm);
        $withAlt = 0;
        if ($imgs) {
            foreach ($imgm[0] as $tag) {
                if (preg_match('~\balt=~i', $tag)) $withAlt++;
            }
        }
        $ariaCount = preg_match_all('~\baria-[a-z]+=~i', $this->html);

        if ($headBits >= 6 && $imgs > 0 && $withAlt === $imgs && $ariaCount >= 4) {
            $this->r->flag('st.full_scaffold', array(
                sprintf('%d of 7 head conventions present, alt text on all %d images, %d ARIA attributes', $headBits, $imgs, $ariaCount),
            ));
        }
    }

    private function checkComments(): void
    {
        if (!preg_match_all('~<!--(.*?)-->~s', $this->html, $m)) {
            // No comments at all in a page with a real bundle: someone ran a build.
            if (preg_match('~\.(?:js|css)\?[a-z0-9=]+|\-[A-Za-z0-9_]{8,}\.(?:js|css)~i', $this->html)) {
                $this->r->flag('hu.build_stripped', array('comments stripped and assets content-hashed by a build pipeline'));
            }
            return;
        }

        $labels = array();
        $emoji = array();
        foreach ($m[1] as $c) {
            $c = trim($c);
            if ($c === '' || stripos($c, '[if ') === 0) continue;
            // Analytics snippets ship with their own comments; they are not a tell.
            if (preg_match('~(Google Tag Manager|Google Analytics|End |Facebook Pixel|Hotjar|Cloudflare)~i', $c)) continue;

            if (preg_match('~^(?:=+|-+|\*+)?\s*(hero|header|nav(?:igation)?|features?|benefits?|how it works|testimonials?|pricing|faq|cta|call to action|footer|about|contact|stats|social proof|newsletter|gallery|team|services?|sections?)\s*(?:section|area|block|=+|-+|\*+)?\s*$~i', $c)) {
                $labels[] = '<!-- ' . $c . ' -->';
            }
            if (Text::hasEmoji($c)) {
                $emoji[] = '<!-- ' . $c . ' -->';
            }
        }

        if (count($labels) >= 3) {
            $this->r->flag('st.section_comments', array_merge($labels, array(
                'production build tooling strips HTML comments, so these surviving means the file was deployed exactly as it was generated',
            )));
        }
        if ($emoji) {
            $this->r->flag('cd.emoji_comments', $emoji);
        }
    }

    // ------------------------------------------------------------------ palette

    private function checkPalette(): void
    {
        $css = $this->scanBlob();

        $hexes = array('#6366f1', '#615fff', '#4f46e5', '#818cf8', '#8b5cf6', '#8e51ff',
                       '#7c3aed', '#a855f7', '#a78bfa', '#6d28d9', '#c084fc', '#0f172a', '#1e1b4b');
        $found = array();
        foreach ($hexes as $hex) {
            if (stripos($css, $hex) !== false) $found[] = $hex;
        }

        $classes = array();
        if (preg_match_all('~\b(?:bg|text|from|via|to|border|ring)-(?:indigo|violet|purple|fuchsia)-\d{2,3}\b~i', $this->html, $m)) {
            $classes = array_slice(array_unique($m[0]), 0, 4);
        }

        $gradient = preg_match('~(?:bg-gradient-to-\w+[^"\']*(?:indigo|violet|purple)|linear-gradient\([^)]*(?:#6366f1|#8b5cf6|#a855f7|rgb\(9[0-9],\s*9[0-9]))~i', $css);

        $evidence = array();
        if ($found)    $evidence[] = 'palette values in use: ' . implode(', ', array_slice($found, 0, 5));
        if ($classes)  $evidence[] = 'utility classes: ' . implode(', ', $classes);
        if ($gradient) $evidence[] = 'an indigo-to-violet gradient is applied to a hero or heading';

        // Two independent hits before flagging: a single purple button is nothing.
        if (count($evidence) >= 2 || count($found) >= 3 || count($classes) >= 3) {
            $this->r->flag('ae.indigo', $evidence);
        }
    }

    private function checkTypeAndIcons(): void
    {
        $css = $this->scanBlob();

        $fonts = array();
        foreach (array('Inter', 'Geist', 'Poppins', 'Space Grotesk', 'Manrope', 'Plus Jakarta Sans', 'DM Sans') as $f) {
            if (preg_match('~font-family[^;}"\']{0,60}' . preg_quote($f, '~') . '~i', $css)
                || preg_match('~fonts\.googleapis\.com[^"\']*' . preg_quote(str_replace(' ', '+', $f), '~') . '~i', $css)
                || preg_match('~["\']' . preg_quote($f, '~') . '["\'][^;]{0,40};~i', $css)) {
                $fonts[] = $f;
            }
        }
        if ($fonts) {
            $this->r->flag('ae.inter_font', array('display face: ' . implode(', ', $fonts)));
            $this->r->stat('fonts', implode(', ', $fonts));
        }

        $icons = array();
        if (preg_match('~lucide(?:-react)?|class=["\'][^"\']*\blucide\b~i', $css))   $icons[] = 'Lucide';
        if (preg_match('~heroicons|@heroicons/~i', $css))                            $icons[] = 'Heroicons';
        if (preg_match('~<svg[^>]+stroke-width=["\']2["\'][^>]*stroke-linecap=["\']round~i', $this->html)) $icons[] = 'a Lucide-shaped stroke set (2px, rounded caps)';
        if ($icons) {
            $this->r->flag('ae.lucide', array('icon set: ' . implode(', ', array_unique($icons))));
        }
    }

    private function checkComponentDefaults(): void
    {
        $evidence = array();

        if (preg_match_all('~class=["\'][^"\']*\brounded-2xl\b[^"\']*\bshadow-(?:lg|xl|md)\b[^"\']*["\']~i', $this->html, $m)) {
            if (count($m[0]) >= 2) {
                $evidence[] = sprintf('%d cards sharing the rounded-2xl + shadow default', count($m[0]));
            }
        }
        if (preg_match_all('~\brounded-(?:2xl|3xl|full)\b~i', $this->html) >= 8) {
            $evidence[] = 'every surface on the page shares one border radius';
        }
        if (preg_match('~class=["\'][^"\']*\brounded-2xl\b[^"\']*["\'][^>]*>\s*<div[^>]+class=["\'][^"\']*\brounded-(?:xl|2xl)\b~i', $this->html)) {
            $evidence[] = 'cards nested inside cards';
        }
        if ($evidence) {
            $this->r->flag('ae.shadcn_defaults', $evidence);
        }

        // py-24 everywhere.
        if (preg_match_all('~\bpy-(\d{2})\b~', $this->html, $m) && count($m[1]) >= 3) {
            $counts = array_count_values($m[1]);
            // Most frequent wins, largest padding breaking a tie. arsort alone
            // leaves tied counts in an order that is only stable from PHP 8.0,
            // so the same page could report a different dominant value on 7.4.
            $top = null;
            foreach ($counts as $value => $n) {
                if ($top === null || $n > $counts[$top] || ($n === $counts[$top] && (int) $value > (int) $top)) {
                    $top = (string) $value;
                }
            }
            if ((int) $top >= 16 && $counts[$top] >= 3 && $counts[$top] / count($m[1]) > 0.6) {
                $this->r->flag('ae.uniform_whitespace', array(
                    sprintf('py-%s on %d of %d spaced sections', $top, $counts[$top], count($m[1])),
                ));
            }
        }

        $css = $this->scanBlob();

        // A heading painted with a clipped gradient rather than a colour.
        if (preg_match('~bg-clip-text[^"\']*text-transparent|text-transparent[^"\']*bg-clip-text~i', $this->html)
            || preg_match('~background-clip:\s*text~i', $css)) {
            $this->r->flag('ae.gradient_text', array('a headline filled with a background gradient instead of a colour'));
        }

        // Frosted glass on everything.
        $blur = preg_match_all('~backdrop-blur(?:-\w+)?\b~i', $this->html)
              + preg_match_all('~backdrop-filter:\s*blur~i', $css);
        if ($blur >= 3) {
            $this->r->flag('ae.glassmorphism', array(
                sprintf('%d frosted-glass surfaces on one page', $blur),
            ));
        }

        // The little badge sitting over the headline.
        if (preg_match('~<(?:div|span|a|p)[^>]*class=["\'][^"\']*\b(?:rounded-full|pill|badge|chip)\b[^"\']*["\'][^>]*>\s*(?:<[^>]+>\s*)*[^<]{3,60}</~i', $this->html, $m)
            && preg_match('~(?:introducing|announcing|new\b|now (?:with|in|available)|just (?:launched|shipped)|v\d|coming soon|beta|early access|backed by|✨|🎉|🚀)~iu', $m[0])) {
            $this->r->flag('ae.hero_pill', array(Report::excerpt(strip_tags($m[0]), 90)));
        }

        // A cue telling you to do the thing you already know how to do.
        if (preg_match('~class=["\'][^"\']*\b(?:scroll-(?:indicator|down|hint|cue)|mouse-scroll|animate-bounce)\b~i', $this->html)
            || preg_match('~(?:scroll (?:down|to explore)|explore more)\s*(?:<|$)~i', $this->text)
            || preg_match('~<svg[^>]*class=["\'][^"\']*animate-bounce~i', $this->html)) {
            $this->r->flag('ae.scroll_indicator', array('an animated scroll cue sits under the opening section'));
        }

        // Soft blurred colour floating behind the hero.
        $orbs = preg_match_all('~class=["\'][^"\']*\b(?:blur-(?:2xl|3xl)|rounded-full)\b[^"\']*\b(?:absolute|fixed)\b[^"\']*["\']~i', $this->html)
              + preg_match_all('~class=["\'][^"\']*\b(?:absolute|fixed)\b[^"\']*\bblur-(?:2xl|3xl)\b[^"\']*["\']~i', $this->html)
              + preg_match_all('~filter:\s*blur\((?:6[0-9]|[7-9][0-9]|\d{3,})px\)~i', $css);
        if ($orbs >= 2) {
            $this->r->flag('ae.glow_orbs', array(
                sprintf('%d heavily blurred shapes positioned behind the content', $orbs),
            ));
        }

        // The bento grid.
        $spans = preg_match_all('~\b(?:col|row)-span-[2-6]\b~i', $this->html);
        if ($spans >= 4 && preg_match('~\bgrid-cols-(?:3|4|6|12)\b~i', $this->html)) {
            $this->r->flag('ae.bento_grid', array(
                sprintf('%d unequal tile spans inside one grid', $spans),
            ));
        }

        // An endless strip of logos.
        if (preg_match('~class=["\'][^"\']*\b(?:marquee|animate-marquee|logo-?(?:scroll|ticker|cloud|strip)|infinite-scroll)\b~i', $this->html)
            || (preg_match('~@keyframes\s+(?:marquee|scroll|ticker)~i', $css)
                && preg_match('~(?:trusted by|as seen (?:in|on)|used by|powering)~i', $this->text))) {
            $this->r->flag('ae.logo_marquee', array('a looping "trusted by" logo band'));
        }

        // The detached, blurred navbar.
        if (preg_match('~<(?:header|nav)[^>]*class=["\'][^"\']*["\']~i', $this->html, $m)) {
            $navClasses = '';
            if (preg_match_all('~<(?:header|nav)[^>]*class=["\']([^"\']+)["\']~i', $this->html, $all)) {
                $navClasses = implode(' ', $all[1]);
            }
            $floating = preg_match('~\b(?:fixed|sticky)\b~i', $navClasses)
                     && preg_match('~\bbackdrop-blur~i', $navClasses)
                     && preg_match('~\b(?:rounded-full|rounded-2xl|mx-auto|inset-x-|top-[2-9])~i', $navClasses);
            if ($floating) {
                $this->r->flag('ae.floating_nav', array('a detached, blurred header hovering below the top of the page'));
            }
        }

        // The coloured left-border card.
        if (preg_match('~border-l-(?:2|4|\[\d)~i', $this->html) && preg_match('~\bborder-l-\d?\s*[^"\']*\bborder-(?:indigo|violet|purple|blue|emerald|amber)-\d{3}~i', $this->html)) {
            $this->r->flag('ae.left_border_card', array('accent strip down the left edge of a panel'));
        } elseif (preg_match('~border-left:\s*(?:3|4|5)px\s+solid~i', $css)) {
            $this->r->flag('ae.left_border_card', array('border-left: 4px solid on a callout'));
        }
    }

    private function checkSymmetry(): void
    {
        $evidence = array();

        // Numbered steps.
        if (preg_match_all('~>\s*0([123])\s*<~', $this->html, $m) && count(array_unique($m[1])) >= 3) {
            $evidence[] = '01 / 02 / 03 numbered steps';
        }

        // Repeated sibling cards with identical class strings.
        if (preg_match_all('~<(div|article|li)\s+class=["\']([^"\']{25,})["\']~i', $this->html, $m)) {
            $counts = array_count_values($m[2]);
            foreach ($counts as $cls => $n) {
                if ($n === 3 || $n === 4) {
                    if (preg_match('~\b(card|feature|benefit|rounded|shadow|p-6|p-8)\b~i', $cls)) {
                        $evidence[] = sprintf('%d identical sibling cards', $n);
                        break;
                    }
                }
            }
        }

        // Card copy of near-identical length.
        if (preg_match_all('~<p[^>]*>([^<]{40,240})</p>~i', $this->html, $m) && count($m[1]) >= 3) {
            $lens = array();
            foreach ($m[1] as $p) {
                $lens[] = str_word_count(strip_tags($p));
            }
            $mean = array_sum($lens) / count($lens);
            if ($mean > 8 && Text::stddev($lens) / $mean < 0.18) {
                $evidence[] = sprintf('%d body paragraphs averaging %d words with almost no variation between them', count($lens), (int) round($mean));
            }
        }

        if (count($evidence) >= 2) {
            $this->r->flag('ae.three_cards', $evidence);
        }
    }

    // ------------------------------------------------------------------ content

    private function checkContent(): void
    {
        $text = $this->text;

        // Emoji doing an icon's job: sitting alone in their own element.
        $iconEmoji = array();
        if (preg_match_all('~>\s*([\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}][\x{FE0F}]?)\s*<~u', $this->html, $m)) {
            $iconEmoji = array_unique($m[1]);
        }
        if (count($iconEmoji) >= 3) {
            $this->r->flag('ct.emoji_icons', array(
                implode(' ', array_slice($iconEmoji, 0, 8)) . ' — each alone in its own element, where an icon would go',
            ));
        }

        // Generic placeholder people.
        $names = array('John Smith', 'Jane Doe', 'John Doe', 'Sarah Johnson', 'Michael Brown',
                       'Alex Miller', 'Emily Davis', 'David Wilson', 'Jessica Williams',
                       'Michael Chen', 'Sarah Chen', 'Emily Chen', 'Alex Thompson', 'Maria Garcia');
        $hits = array();
        foreach ($names as $n) {
            if (stripos($text, $n) !== false) $hits[] = $n;
        }
        $titles = array();
        foreach (array('Verified User', 'Head of Operations', 'Product Manager at', 'CEO, Company',
                       'Satisfied Customer', 'Happy Customer', 'Founder & CEO') as $t) {
            if (stripos($text, $t) !== false) $titles[] = $t;
        }
        if ($hits || count($titles) >= 2) {
            $this->r->flag('ct.generic_names', array_merge($hits, $titles));
        }

        // House voice.
        $cliches = array('ship faster', 'unlock the power', 'supercharge', 'seamlessly', 'effortlessly',
                         'everything you need to', 'get started in seconds', 'loved by developers',
                         'built for the modern', 'take your .{3,20} to the next level', 'game-?changer',
                         'in just a few clicks', 'without the guesswork', 'trusted by thousands',
                         'lightning[- ]fast', 'blazing[- ]fast', 'powerful yet simple', 'say goodbye to');
        $found = array();
        foreach ($cliches as $c) {
            if (preg_match('~' . $c . '~i', $text, $m)) $found[] = $m[0];
        }
        if (count($found) >= 3) {
            $this->r->flag('ct.marketing_cliche', $found);
        }

        // Model typography in the copy.
        $curly = preg_match_all('~[\x{2018}\x{2019}\x{201C}\x{201D}]~u', $text);
        $emdash = preg_match_all('~\x{2014}~u', $text);
        $words = max(1, str_word_count($text));
        if ($emdash >= 3 && ($emdash / ($words / 1000)) >= 2.5) {
            $this->r->flag('cd.typography', array(
                sprintf('%d em dashes across roughly %d words of copy', $emdash, $words),
            ));
        } elseif ($curly >= 6 && $emdash >= 2) {
            $this->r->flag('cd.typography', array(
                sprintf('%d curly quotes and %d em dashes in the page copy', $curly, $emdash),
            ));
        }

        // Placeholder copy.
        $ph = array();
        foreach (array('lorem ipsum', 'your text here', 'placeholder text', 'coming soon',
                       'insert .{3,20} here', 'sample text', 'company name here') as $p) {
            if (preg_match('~' . $p . '~i', $text, $m)) $ph[] = $m[0];
        }
        if ($ph) {
            $this->r->flag('ct.placeholder_copy', $ph);
        }

        // Navigation that goes nowhere.
        if (preg_match_all('~<a\b[^>]*href=["\']([^"\']*)["\']~i', $this->html, $m)) {
            $total = count($m[1]);
            $dead = 0;
            foreach ($m[1] as $href) {
                $h = trim($href);
                if ($h === '#' || $h === '' || $h === 'javascript:void(0)' || $h === '#!') $dead++;
            }
            if ($total >= 8 && $dead / $total > 0.55) {
                $this->r->flag('ct.dead_links', array(
                    sprintf('%d of %d links point nowhere', $dead, $total),
                ));
            }
        }

        // Round, unsourced numbers doing persuasive work.
        $stats = array();
        if (preg_match_all('~\b(\d{1,3}(?:,\d{3})*|\d+)\s*(?:k|K|M|m)?\+\s*(?:happy\s+)?(?:users?|customers?|developers?|teams?|downloads?|companies|businesses|creators?|members?)~i', $text, $m)) {
            foreach ($m[0] as $hit) $stats[] = trim($hit);
        }
        if (preg_match_all('~\b(?:99\.9+|9[5-9])\s*%\s*(?:uptime|accuracy|satisfaction|faster|reliable)~i', $text, $m)) {
            foreach ($m[0] as $hit) $stats[] = trim($hit);
        }
        if (preg_match_all('~\b\d{1,3}x\s+(?:faster|better|more|cheaper|productive)~i', $text, $m)) {
            foreach ($m[0] as $hit) $stats[] = trim($hit);
        }
        // Attribution defuses it: a sourced number is a claim someone stands behind.
        $sourced = preg_match('~\b(?:source|according to|survey|report|study|measured|benchmark)\b~i', $text);
        if (count($stats) >= 2 && !$sourced) {
            $this->r->flag('ct.stat_inflation', $stats);
        }

        // Three tiers with the middle one starred.
        if (preg_match('~\b(?:most popular|recommended|best value)\b~i', $text)
            && preg_match_all('~(?:\$|€|£)\s?\d{1,4}\s*(?:/|per\s)\s*(?:mo|month|user|seat|year)~iu', $text) >= 3) {
            $this->r->flag('ct.pricing_three', array('three priced tiers with a "most popular" badge on the middle one'));
        }

        // Specifics pull the other way: real prices, dates, addresses, hours.
        $specific = 0;
        if (preg_match('~(?:[$£€]\s?\d{1,3}(?:[.,]\d{2})?|\d+\s?(?:€|EUR|USD|GBP))~', $text)) $specific++;
        if (preg_match('~\b\d{1,2}[:h]\d{2}\b|\b(?:mon|tue|wed|thu|fri|sat|sun)[a-z]*\s*[-–]\s*(?:mon|tue|wed|thu|fri|sat|sun)~i', $text)) $specific++;
        if (preg_match('~\b\d{1,4}[,\s]+(?:rue|avenue|street|st\.|road|rd\.|boulevard|blvd|allée|impasse|chemin)\b~i', $text)) $specific++;
        if (preg_match('~\b(?:\+\d{1,3}[\s.-]?)?(?:\(?\d{2,4}\)?[\s.-]?){2,4}\d{2,4}\b~', $text)) $specific++;
        if (preg_match('~\b(?:19|20)\d{2}\b~', $text) && preg_match('~\b(?:since|established|founded|depuis|est\.)\b~i', $text)) $specific++;
        if ($specific >= 3) {
            $this->r->flag('hu.long_tail_copy', array(
                'the copy carries prices, hours, an address or a phone number: details that had to come from somewhere outside the page',
            ));
        }
    }

    /**
     * Marks of a page a person has been living with.
     *
     * Typos are the most interesting of these because masking makes them
     * worse: running generated text through a model to disguise it removes
     * misspellings, while a human page keeps accumulating them.
     */
    private function checkHumanMarks(): void
    {
        $text = $this->text;

        // Misspelling lists are per-language: applying the English one to French
        // copy would flag ordinary words and invent a human signal out of nothing.
        $lang = 'en';
        if (preg_match('~<html[^>]+lang=["\']([a-z]{2})~i', $this->html, $m)) {
            $lang = strtolower($m[1]);
            $this->r->stat('lang', $lang);
        }

        $lists = array(
            'en' => array(
                'recieve', 'seperate', 'occured', 'definately', 'accomodate', 'adress',
                'buisness', 'thier', 'untill', 'sucessful', 'publically', 'calender',
                'enviroment', 'neccessary', 'existance', 'maintainance', 'refered',
                'begining', 'beleive', 'wierd', 'arguement', 'foriegn', 'goverment',
                'independant', 'occurence', 'persistant', 'priviledge', 'recomend',
                'tommorow', 'wellcome', 'succesful', 'commited', 'appologise',
                'garantee', 'guarentee', 'proffesional', 'reccomend', 'availabe',
                'concious', 'embarass', 'goverment', 'harrass', 'noticable',
                'occassion', 'perseverence', 'questionaire', 'rythm', 'supercede',
                'threshhold', 'writting', 'usefull', 'carefull', 'succesfully',
            ),
            'fr' => array(
                'acceuil', 'addresse', 'professionel', 'developement', 'developpeur',
                'malgrés', 'parmis', 'biensur', 'quelquechose', 'apparament',
                'connection', 'language', 'exemple de code source ici',
                'entrainement', 'rendez vous', 'anniversaire de la société',
            ),
        );

        $misspellings = isset($lists[$lang]) ? $lists[$lang] : $lists['en'];

        $found = array();
        foreach (array_unique($misspellings) as $word) {
            if (preg_match('~\b' . preg_quote($word, '~') . '\b~iu', $text, $hit)) {
                $found[] = $hit[0];
            }
        }
        if ($found) {
            $this->r->flag('hu.typos', $found);
        }

        // Obligations accumulate; features get generated.
        $ops = array();
        if (preg_match('~(?:cookie[- ]?(?:consent|banner|policy)|gdpr|tarteaucitron|cookieconsent|axeptio|didomi)~i', $this->html)) {
            $ops[] = 'a cookie consent mechanism';
        }
        if (preg_match('~href=["\'][^"\']*(?:privacy|terms|legal|mentions-legales|impressum|cgv|cgu)~i', $this->html)) {
            $ops[] = 'legal or privacy pages linked from the page';
        }
        if (preg_match('~(?:googletagmanager\.com|google-analytics\.com|gtag\(|plausible\.io|matomo|umami|fathom|hotjar|clarity\.ms)~i', $this->html)) {
            $ops[] = 'a real analytics or tag-manager property';
        }
        if (preg_match('~(?:mailchimp|list-manage\.com|sendinblue|brevo|convertkit|substack|klaviyo)~i', $this->html)) {
            $ops[] = 'a mailing-list provider wired in';
        }
        if (count($ops) >= 2) {
            $this->r->flag('hu.operational_stack', $ops);
        }

        // A footer that has drifted out of date.
        $thisYear = (int) gmdate('Y');
        if (preg_match_all('~(?:©|&copy;|copyright)\s*(?:\d{4}\s*[-–]\s*)?(\d{4})~i', $this->html, $m)) {
            $years = array_map('intval', $m[1]);
            $latest = max($years);
            if ($latest > 1990 && $latest < $thisYear) {
                $this->r->flag('hu.dated_copyright', array(
                    sprintf('the footer still reads %d', $latest),
                ));
            }
        }
    }

    private function checkMedia(): void
    {
        $imgs = preg_match_all('~<img\b[^>]*src=["\']([^"\']+)["\']~i', $this->html, $m);
        $srcs = $imgs ? $m[1] : array();
        $photos = 0;
        $exts = array();
        foreach ($srcs as $src) {
            if (preg_match('~\.(jpe?g|png|webp|avif|gif)(?:[?#]|$)~i', $src, $e)) {
                $photos++;
                $exts[strtolower($e[1])] = true;
            }
        }
        $gradients = preg_match_all('~(?:bg-gradient-to-|linear-gradient\(|radial-gradient\()~i', $this->scanBlob());
        $svgs = preg_match_all('~<svg\b~i', $this->html);

        if ($photos === 0 && ($gradients >= 3 || $svgs >= 5) && strlen($this->html) > 4000) {
            $this->r->flag('ae.no_real_images', array(
                sprintf('no photographic assets at all; %d gradients and %d inline vectors carry the whole page', $gradients, $svgs),
            ));
        } elseif ($photos >= 5 && count($exts) >= 2) {
            $this->r->flag('hu.real_media', array(
                sprintf('%d photographic assets in %d formats', $photos, count($exts)),
            ));
        }
    }

    private function checkCms(): void
    {
        $hay = $this->html;
        $cms = null;
        if (preg_match('~wp-content|wp-includes|wp-json~i', $hay))                 $cms = 'WordPress';
        elseif (preg_match('~static\.parastorage\.com|wix\.com|_wixCssStates~i', $hay)) $cms = 'Wix';
        elseif (preg_match('~squarespace\.com|static1\.squarespace~i', $hay))      $cms = 'Squarespace';
        elseif (preg_match('~\.webflow\.io|webflow\.com/js~i', $hay))              $cms = 'Webflow';
        elseif (preg_match('~cdn\.shopify\.com|Shopify\.theme~i', $hay))           $cms = 'Shopify';
        elseif (preg_match('~/sites/default/files|Drupal\.settings~i', $hay))      $cms = 'Drupal';
        elseif (preg_match('~/media/system/js/|Joomla~i', $hay))                   $cms = 'Joomla';
        elseif (preg_match('~prestashop|PrestaShop~', $hay))                       $cms = 'PrestaShop';

        if ($cms !== null) {
            $this->r->stat('cms', $cms);
            $this->r->flag('hu.cms', array(
                $cms . ' is doing the work here',
                'site builders and classic CMSes predate this generation of tools and are a separate phenomenon, not evidence of one',
            ));
        }

        if (preg_match('~jquery(?:\.min)?\.js|jquery-\d~i', $hay)
            || preg_match('~<table[^>]*>\s*<tr~i', $hay)
            || preg_match('~-webkit-border-radius|filter:\s*progid:~i', $this->scanBlob())) {
            $this->r->flag('hu.legacy_stack', array('jQuery, table layout or vendor-prefixed CSS: markup with an accretion history'));
        }
    }

    /**
     * The document plus as much of its assets as the scan ceiling allows,
     * built once. Five separate concatenations of a multi-megabyte string were
     * costing more than most of the checks that consumed them.
     */
    private function scanBlob(): string
    {
        if ($this->blob !== null) {
            return $this->blob;
        }
        $blob = $this->html;
        foreach ($this->assets as $body) {
            if (strlen($blob) >= self::MAX_SCAN) {
                break;
            }
            $blob .= "\n" . $body;
        }
        if (strlen($blob) > self::MAX_SCAN) {
            $blob = Text::safeCut($blob, self::MAX_SCAN);
            $this->truncated = true;
        }
        return $this->blob = $blob;
    }

    /**
     * Same-origin scripts and stylesheets get the code-level treatment, unless
     * they have been through a minifier — in which case the style signal has
     * already been normalised away and reading it would be inventing evidence.
     */
    private function checkAssets(): void
    {
        $readable = 0;
        $minified = 0;

        foreach ($this->assets as $url => $body) {
            if ($body === '' || strlen($body) < 400) continue;
            $lines = substr_count($body, "\n") + 1;
            $avg = strlen($body) / max(1, $lines);

            if ($avg > 300 || $lines < 8) {
                $minified++;
                continue;
            }
            if (!preg_match('~\.js(?:[?#]|$)~i', $url)) continue;

            $readable++;
            $sub = new CodeAnalyzer($body);
            $sub->analyze($this->r); // signals merge into the same report
        }

        $this->r->stat('assetsReadable', $readable);
        $this->r->stat('assetsMinified', $minified);

        if ($minified > 0 && $readable === 0) {
            $this->r->flag('hu.build_stripped', array(
                sprintf('%d bundled asset(s), minified and content-hashed', $minified),
                'minification normalises exactly the signal style-based detection depends on, so the scripts were not read',
            ));
            $this->r->note('The page\'s scripts are bundled and minified, so no code-level reading was possible. This is the ordinary output of a build step and says nothing either way about who wrote the source.');
        }
    }
}
