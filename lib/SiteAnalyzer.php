<?php
declare(strict_types=1);

require_once __DIR__ . '/Report.php';
require_once __DIR__ . '/Evidence.php';
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
    /** @var array<string,string> */
    private $headers = array();
    /** @var array<int,array<string,mixed>> */
    private $maps = array();
    /** @var string|null class attributes harvested from the bundles */
    private $bundleClasses = null;
    /** @var string|null prose harvested from the bundles */
    private $bundleCopy = null;
    /** @var string|null */
    private $markup = null;
    /** @var string|null */
    private $copy = null;
    /** @var bool set once something says this page is a generator's own output */
    private $generatedShell = false;
    /** @var array<int,SourceContext>|null every document this page was read from */
    private $documents = null;
    /** @var string[] server-side markers found in the response headers */
    private $transportLegacy = array();
    /** @var string[]|null */
    private $mapPaths = null;
    /** @var string|null */
    private $bundleSource = null;
    /** @var string|null */
    private $language = null;

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

        if (isset($meta['headers']) && is_array($meta['headers'])) {
            foreach ($meta['headers'] as $name => $value) {
                $this->headers[strtolower((string) $name)] = (string) $value;
            }
        }
        if (isset($meta['sourceMaps']) && is_array($meta['sourceMaps'])) {
            $this->maps = $meta['sourceMaps'];
        }
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

        // Thinness is a property of the evidence, not of the document. A
        // client-rendered page serves 300 bytes of markup and half a megabyte
        // of bundle, and calling that thin would pull every reading of a
        // single-page app back toward the middle on the grounds that its
        // index.html is short — which was true of the reading and never true
        // of the page.
        $readable = strlen($this->html) + array_sum(array_map('strlen', $this->assets));
        foreach ($this->maps as $map) {
            $readable += isset($map['content']) ? strlen((string) $map['content']) : 0;
        }
        $this->r->stat('readableBytes', $readable);

        if ($readable < 1500) {
            $this->r->stat('thin', true);
            $this->r->note('The page returned very little markup. If it renders its content with JavaScript, most of what matters never reached this analyser.');
        } elseif (strlen($this->html) < 1500) {
            $this->r->note('The document itself is almost empty: this page builds its interface in the browser. What was read came from the scripts and stylesheets it loads, where the class names and the copy survive as string literals.');
        }

        // Order matters in one place only: whatever can establish that this
        // page is a generator's own output runs before checkComments(), which
        // withholds its human-leaning finding when that is already known.
        $this->checkFingerprints($host);
        $this->checkTransport();
        $this->checkScaffold();
        $this->checkStack();
        $this->checkClientBackend();
        $this->checkShell();
        $this->checkComments();
        $this->checkPalette();
        $this->checkNeonPalette();
        $this->checkBackgroundGradient();
        $this->checkTypeAndIcons();
        $this->checkComponentDefaults();
        $this->checkSymmetry();
        $this->checkContent();
        $this->checkPublishing();
        $this->checkForms();
        $this->checkPlaceholderIdentity();
        $this->checkProseRhythm();
        $this->checkAltText();
        $this->checkHumanMarks();
        $this->checkCareMarks();
        $this->checkMedia();
        $this->checkCms();
        $this->checkAssets();
        $this->checkSourceMaps();

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
        // Fingerprints get the widest haystack in the tool: the document, the
        // names of everything it pulled in, and — where a source map handed one
        // over — the original file paths. A builder's marker surviving in any
        // of those identifies it just as well as one in the markup.
        $hay = $this->html . "\n" . implode("\n", array_keys($this->assets))
             . "\n" . implode("\n", $this->mapPaths());

        $rules = array(
            'fp.lovable' => array(
                '~cdn\.gpteng\.co~i'          => 'the Lovable runtime is loaded from cdn.gpteng.co',
                '~gptengineer\.js~i'          => 'gptengineer.js is referenced',
                '~/lovable-uploads/~i'        => 'assets are served from /lovable-uploads/',
                '~lovable-tagger~i'           => 'the lovable-tagger build plugin left its marker',
                '~\.lovable\.(?:app|dev)~i'   => 'a lovable.app host is referenced',
                '~Edit with Lovable~i'        => 'the "Edit with Lovable" badge is present',
                '~/__l5e/|\~flock\.js~i'      => 'a Lovable runtime path is present',
                '~\bdata-lov(?:able)?-(?:id|name)=~i' => 'lovable-tagger\'s data-lov-id attributes were shipped to production',
                '~lovable\.dev/opengraph-image~i' => 'the social preview image still points at lovable.dev',
                '~Lovable Generated Project~i' => 'the page still calls itself a "Lovable Generated Project"',
            ),
            'fp.bolt' => array(
                '~Made in Bolt~i'             => 'the "Made in Bolt" badge is present',
                '~\.bolt\.host~i'             => 'a bolt.host deployment host is referenced',
                '~bolt\.new~i'                => 'bolt.new is referenced in the page',
                '~stackblitz\.com/~i'         => 'a StackBlitz/Bolt asset host is referenced',
            ),
            'fp.v0' => array(
                '~Built with v0~i'            => 'the "Built with v0" badge is present',
                '~v0\.dev|v0\.app~i'          => 'v0 is referenced in the page',
                '~v0-[a-z0-9-]+\.vercel\.app~i' => 'the page is served from a v0 preview host',
                '~My v0 Project~i'            => 'the page is still called "My v0 Project"',
            ),
            'fp.replit' => array(
                '~replit-badge|replit\.com/badge~i' => 'the Replit badge is embedded',
                '~\.repl\.co|\.replit\.(?:app|dev)~i' => 'a Replit host is referenced',
                '~replit-dev-banner|__replco~i' => 'a Replit development banner script is loaded',
            ),
            'fp.base44' => array(
                '~@base44/sdk~i'              => 'the Base44 SDK is loaded',
                '~\.base44\.app~i'            => 'a base44.app host is referenced',
            ),
        );

        foreach ($rules as $id => $patterns) {
            $found = array();
            foreach ($patterns as $re => $why) {
                if (preg_match($re, $hay, $mm, PREG_OFFSET_CAPTURE)) {
                    $found[] = Excerpt::atOffset($hay, (int) $mm[0][1], 1, '', 2, $why);
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
            if (preg_match($re, $hay, $mm, PREG_OFFSET_CAPTURE)) {
                $named[] = Excerpt::atOffset($hay, (int) $mm[0][1], 1, '', 2,
                    $tool . ' left its signature in the page');
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
                $this->r->flag('fp.generator_meta', array(
                    $this->locate($m[0])->withText('<meta name="generator" content="' . $gen . '">'),
                ));
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

    // ------------------------------------------------------------- transport

    /**
     * What the response headers say, which is nothing about who wrote the page
     * and quite a lot about how it got deployed.
     *
     * The platform is recorded as a fact and never scored: Vercel and Netlify
     * host as much hand-written work as generated, and treating a deployment
     * target as evidence of authorship would be exactly the kind of inference
     * this tool refuses everywhere else. What is scored is header hygiene,
     * which is configuration rather than hosting — somebody either wrote a
     * policy or accepted whatever the platform did by default.
     */
    private function checkTransport(): void
    {
        if (!$this->headers) {
            return; // crawl mode reads inner pages without keeping their headers
        }

        $server = strtolower($this->header('server'));
        $platform = null;
        foreach (array(
            'Vercel'          => array('x-vercel-id', 'x-vercel-cache'),
            'Netlify'         => array('x-nf-request-id'),
            'Railway'         => array('x-railway-request-id'),
            'Render'          => array('x-render-origin-server'),
            'Fly.io'          => array('fly-request-id'),
            'Cloudflare'      => array('cf-ray'),
            'GitHub Pages'    => array('x-github-request-id'),
        ) as $name => $keys) {
            foreach ($keys as $k) {
                if ($this->header($k) !== '') {
                    $platform = $name;
                    break 2;
                }
            }
        }
        if ($platform === null && $server !== '') {
            foreach (array('vercel', 'netlify', 'cloudflare', 'gse', 'awselb', 'nginx', 'apache', 'litespeed', 'caddy', 'openresty') as $needle) {
                if (strpos($server, $needle) !== false) {
                    $platform = $this->header('server');
                    break;
                }
            }
        }
        if ($platform !== null) {
            $this->r->stat('hosting', $platform);
        }
        if ($this->header('x-powered-by') !== '') {
            $this->r->stat('poweredBy', $this->header('x-powered-by'));
        }

        $csp   = $this->header('content-security-policy');
        $hsts  = $this->header('strict-transport-security');
        $frame = $this->header('x-frame-options');
        $nosniff = $this->header('x-content-type-options');
        $perms = $this->header('permissions-policy');
        $ref   = $this->header('referrer-policy');

        $present = 0;
        foreach (array($csp, $hsts, $frame, $nosniff, $perms, $ref) as $h) {
            if ($h !== '') $present++;
        }
        // Recorded, never scored. Almost nothing sets these — not the generated
        // apps and not the hand-built sites on shared hosting either — so their
        // absence separates nothing and would only add a constant to every
        // reading. Their presence is a different matter, below.
        $this->r->stat('securityHeaders', $present);

        // A policy with directives in it, not the single-token placeholder that
        // a panel switch produces.
        $realCsp = $csp !== '' && preg_match('~(?:default|script|frame-ancestors|style)-src~i', $csp);
        $longHsts = $hsts !== '' && preg_match('~max-age=(\d+)~i', $hsts, $m) && (int) $m[1] >= 15768000;
        if ($realCsp && ($longHsts || $perms !== '')) {
            $evidence = array('a content security policy with real directives');
            if ($longHsts) $evidence[] = 'HSTS with a long max-age';
            if ($perms !== '') $evidence[] = 'a permissions policy';
            $this->r->flag('hu.hardened_headers', $evidence);
        }

        // A session cookie from a server-side framework says the page was
        // rendered by something, which is a different world from a bundle on a
        // static host.
        $cookies = $this->header('set-cookie');
        if ($cookies !== '' && preg_match('~\b(PHPSESSID|wordpress_|wp-settings|JSESSIONID|ASP\.NET_SessionId|laravel_session|_rails_session|django_session|symfony)\b~i', $cookies, $m)) {
            $this->transportLegacy[] = 'a ' . $m[1] . ' session cookie: something server-side is rendering this';
        }
    }

    // -------------------------------------------------------------- scaffold

    /**
     * The starter template, still wearing its factory label.
     *
     * Every one of these strings is written by a project generator and read by
     * the first person to open the file. A title of "Vite + React + TS" on a
     * deployed site does not mean the code was generated; it means nobody
     * opened index.html, which in practice is the same population.
     */
    private function checkScaffold(): void
    {
        $head = strlen($this->html) > 65536 ? substr($this->html, 0, 65536) : $this->html;
        $hits = array();

        if (preg_match('~<title[^>]*>\s*([^<]{0,160})</title>~i', $head, $m)) {
            $title = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($title !== '') {
                $this->r->stat('title', $title);
            }
            // Only titles nobody chooses. "Home", "App" and a company called
            // Astro are all somebody's deliberate choice; "Document" is what
            // an editor's HTML snippet writes and nothing else ever does.
            if (preg_match('~^(?:vite\s*\+\s*(?:react|vue|svelte|preact|lit|solid)(?:\s*\+\s*(?:ts|js))?|vite app|react app|create react app|next app|create next app|next\.js app|nuxt app|my app|my v0 project|v0 app|sveltekit app|document|untitled|untitled document|index)$~i', $title)) {
                $hits[] = $this->locate($m[0])->withText('the document title is still "' . $title . '"');
            }
        }

        if (preg_match('~<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']{0,200})["\']~i', $head, $m)) {
            $desc = trim($m[1]);
            if (preg_match('~^(?:generated by create next app|web site created using create-react-app|vite\s*\+|lovable generated project|created with bolt|my v0 project|a new .{0,20}app|astro description)~i', $desc)) {
                $hits[] = $this->locate($m[0])
                    ->withText('the description is the one the scaffold shipped with: "' . Report::excerpt($desc, 70) . '"');
            }
        }

        if (preg_match('~<link[^>]+href=["\']/?(?:vite|next|nuxt|astro|svelte)\.svg["\']~i', $head, $m)) {
            $hits[] = $this->locate($m[0])->withText('the favicon is still the framework\'s own logo');
        }
        if (preg_match('~You need to enable JavaScript to run this app~i', $head, $m)) {
            $hits[] = $this->locate($m[0])->withText('the create-react-app noscript block is untouched');
        }
        // Supporting evidence only. A page whose lang attribute disagrees with
        // its copy is a page nobody edited, which is the same story — but on
        // its own it is as likely to be an ordinary oversight, and this signal
        // is weighted for the tells that are not.
        $supporting = array();
        if ($hits && preg_match('~<html[^>]+lang=["\']en["\']~i', $head)) {
            $spoken = $this->declaredLanguage();
            if ($spoken !== '' && $spoken !== 'en') {
                $supporting[] = 'and declares English while the copy is written in ' . $spoken;
            }
        }

        if ($hits) {
            $this->generatedShell = true;
            $this->r->flag('st.untouched_scaffold', array_merge($hits, $supporting), count($hits));
        }
    }

    /**
     * The language the copy is actually in, guessed only well enough to catch a
     * scaffold's lang="en" sitting over a page that is plainly not English.
     *
     * Returns '' when there is not enough text to be sure, which is most of the
     * time and is the right answer when it is.
     */
    private function declaredLanguage(): string
    {
        if ($this->language !== null) {
            return $this->language;
        }
        $text = ' ' . strtolower($this->copy()) . ' ';
        if (str_word_count($text) < 60) {
            return $this->language = '';
        }
        $markers = array(
            'fr' => array(' le ', ' la ', ' les ', ' des ', ' vous ', ' nous ', ' pour ', ' avec ', ' est ', ' plus '),
            'es' => array(' el ', ' la ', ' los ', ' las ', ' para ', ' con ', ' que ', ' una ', ' nuestro ', ' más '),
            'de' => array(' der ', ' die ', ' das ', ' und ', ' mit ', ' für ', ' ist ', ' auf ', ' wir ', ' sie '),
            'en' => array(' the ', ' and ', ' for ', ' with ', ' you ', ' our ', ' that ', ' this ', ' from ', ' are '),
        );
        $best = '';
        $bestScore = 0;
        foreach ($markers as $lang => $words) {
            $score = 0;
            foreach ($words as $w) {
                $score += substr_count($text, $w);
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $lang;
            }
        }
        // A clear win, or no answer at all: a near-tie between two languages
        // means the page is bilingual or too short, and neither is a finding.
        return $this->language = ($bestScore >= 12 ? $best : '');
    }

    // ------------------------------------------------------------------ stack

    /**
     * Which parts the page was assembled from.
     *
     * No single library here says anything. What says something is the whole
     * kit arriving together: the builders scaffold from one component stack,
     * and a page carrying all of it at its defaults was assembled by whatever
     * had that stack already rather than chosen piece by piece.
     */
    private function checkStack(): void
    {
        $hay = $this->scanBlob() . "\n" . implode("\n", $this->mapPaths());

        $parts = array(
            'Radix primitives'         => '~@radix-ui|data-radix-|radix-ui/react~i',
            'class-variance-authority' => '~class-variance-authority|cva\(\s*[\'"]~i',
            'tailwind-merge'           => '~tailwind-merge|twMerge~i',
            'clsx'                     => '~\bclsx\b~i',
            'Lucide icons'             => '~lucide-react|\blucide\b~i',
            'a toast library'          => '~\bsonner\b|react-hot-toast|<Toaster|useToast~i',
            'cmdk'                     => '~\bcmdk\b~i',
            'vaul'                     => '~\bvaul\b~i',
            'Embla carousel'           => '~embla-carousel~i',
            'Framer Motion'            => '~framer-motion|motion/react~i',
            'React Hook Form + Zod'    => '~react-hook-form~i',
            'TanStack Query'           => '~@tanstack/react-query~i',
        );
        $found = array();
        $firstHit = null;
        foreach ($parts as $label => $re) {
            if (preg_match($re, $hay, $mm, PREG_OFFSET_CAPTURE)) {
                $found[] = $label;
                if ($firstHit === null) {
                    $firstHit = Excerpt::atOffset($hay, (int) $mm[0][1], 1, '', 2);
                }
            }
        }

        $framework = null;
        if (preg_match('~__NEXT_DATA__|/_next/static|self\.__next_f~i', $hay))      $framework = 'Next.js';
        elseif (preg_match('~/_nuxt/|__NUXT__~i', $hay))                           $framework = 'Nuxt';
        elseif (preg_match('~__remixContext|/build/_shared/~i', $hay))             $framework = 'Remix';
        elseif (preg_match('~astro-island|/_astro/~i', $hay))                      $framework = 'Astro';
        elseif (preg_match('~__sveltekit_|/_app/immutable/~i', $hay))              $framework = 'SvelteKit';
        elseif (preg_match('~/assets/index-[A-Za-z0-9_-]{6,}\.js|__vite__|/@vite/~i', $hay)) $framework = 'Vite';
        elseif (preg_match('~/static/js/main\.[a-z0-9]{8}\.js~i', $hay))           $framework = 'Create React App';
        if ($framework !== null) {
            $this->r->stat('framework', $framework);
        }

        // The component kit's own directory, straight out of a source map: the
        // files shadcn/ui writes, at the path it writes them to.
        $kitPaths = array();
        foreach ($this->mapPaths() as $path) {
            if (preg_match('~(?:^|/)components/ui/([a-z-]+)\.(?:tsx?|jsx?|vue|svelte)$~i', $path, $m)) {
                $kitPaths[] = $m[1];
            }
        }

        if (count($found) >= 4) {
            $summary = implode(', ', array_slice($found, 0, 6)) . ' — all present, all at their defaults';
            $evidence = array($firstHit !== null
                ? $firstHit->withCount(count($found))->withText($summary)
                : Excerpt::plain($summary, count($found)));
            if (count($kitPaths) >= 5) {
                $evidence[] = sprintf('%d untouched component-kit files (%s…) named in the source map',
                    count($kitPaths), implode(', ', array_slice(array_unique($kitPaths), 0, 4)));
            }
            $this->generatedShell = true;
            $this->r->flag('st.generated_stack', $evidence, count($found));
        }
    }

    // --------------------------------------------------------- client backend

    /**
     * What the page does about a server, which is often nothing.
     *
     * Two shapes turn up again and again in generated applications, and both
     * are visible from the outside. One is a database addressed straight from
     * the browser with its key alongside it. The other is no backend at all:
     * localStorage holding what a table would hold, and a login that consists
     * of writing true into it.
     */
    private function checkClientBackend(): void
    {
        $hay = $this->scanBlob();
        foreach ($this->maps as $map) {
            if (!empty($map['content'])) {
                $hay .= "\n" . $map['content'];
            }
        }

        $backend = array();
        $keys = array();

        if (preg_match('~https?://([a-z0-9-]+)\.supabase\.(?:co|in)~i', $hay, $m)) {
            $backend[] = 'a Supabase project called directly from the browser';
            // The anon key travels next to the URL and is a JWT, so its shape
            // is unmistakable. The value is never echoed back.
            if (preg_match('~\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{10,}~', $hay)) {
                $keys[] = 'a Supabase key shipped in the page JavaScript, which is only safe if row-level security was set up, and it usually is not';
            }
        }
        if (preg_match('~firebaseapp\.com|firebase(?:io|storage)~i', $hay)
            && preg_match('~apiKey\s*[:=]\s*["\']AIza~', $hay)) {
            $backend[] = 'a Firebase project configured in the page itself';
            $keys[] = 'a Firebase web API key inline in the bundle';
        }
        if (preg_match('~dangerouslyAllowBrowser\s*:\s*true~i', $hay)) {
            $keys[] = 'an AI SDK started with dangerouslyAllowBrowser: true, which exists to let a key that belongs on a server run in a browser instead';
        }
        if (preg_match('~["\'](?:sk-ant-[A-Za-z0-9_-]{10,}|sk-[A-Za-z0-9]{32,}|AKIA[0-9A-Z]{16})["\']~', $hay)) {
            $keys[] = 'a provider or cloud credential literal in client-side code';
        }

        // localStorage doing a database's job.
        $writes = preg_match_all('~localStorage\s*(?:\.\s*setItem\s*\(|\[)~i', $hay);
        $serialised = preg_match('~localStorage[^;\n]{0,80}JSON\s*\.\s*stringify~i', $hay);
        if ($writes >= 3 && $serialised) {
            $backend[] = sprintf('%d writes into localStorage, storing serialised records: the browser is the database', $writes);
        }

        if ($backend) {
            $this->r->flag('st.client_only_backend', $backend);
        }
        if ($keys) {
            $this->r->flag('se.exposed_client_key', $keys);
        }

        // Auth that a user can grant themselves.
        if (preg_match('~(?:localStorage|sessionStorage)[^;\n]{0,60}["\'](?:isLoggedIn|loggedIn|isAdmin|is_admin|isAuthenticated|auth(?:enticated)?|user_?role|isPremium|isPro)["\']~i', $hay, $m)
            || preg_match('~["\'](?:isLoggedIn|isAdmin|isAuthenticated)["\']\s*,\s*["\']true["\']~i', $hay, $m)) {
            $this->r->flag('se.client_side_auth', array(
                Report::excerpt($m[0], 90) . ' — the browser is trusted to say whether it is allowed in',
            ));
        }
    }

    // ---------------------------------------------------------- source maps

    /**
     * Read the source the bundle was built from, when the bundle says where it
     * is.
     *
     * This is the difference between "the scripts are minified, so there was
     * nothing to read" and reading the code. Everything CodeAnalyzer knows
     * applies to what comes back, because what comes back is the file as it
     * was written: comments, names, error handling and all.
     */
    private function checkSourceMaps(): void
    {
        if (!$this->maps) {
            return;
        }
        $source = '';
        $files = 0;
        foreach ($this->maps as $map) {
            $files += isset($map['sources']) ? count((array) $map['sources']) : 0;
            if (!empty($map['content']) && strlen($source) < self::MAX_SCAN) {
                $source .= "\n" . (string) $map['content'];
            }
        }
        $this->r->stat('sourceMaps', count($this->maps));
        $this->r->stat('sourceFiles', $files);

        if (trim($source) === '') {
            return;
        }
        if (strlen($source) > self::MAX_SCAN) {
            $source = Text::safeCut($source, self::MAX_SCAN);
        }

        (new CodeAnalyzer($source))->analyze($this->r);
        $this->r->note(sprintf(
            'The page ships a source map, so %d of its original source files were read as code rather than guessed at from the bundle. Anything under a code-style or structural heading below came from that source.',
            $files
        ));
    }

    /**
     * Original file paths from every source map, deduplicated.
     *
     * @return string[]
     */
    private function mapPaths(): array
    {
        if ($this->mapPaths !== null) {
            return $this->mapPaths;
        }
        $out = array();
        foreach ($this->maps as $map) {
            foreach ((array) ($map['sources'] ?? array()) as $src) {
                $out[] = (string) $src;
            }
        }
        return $this->mapPaths = array_slice(array_values(array_unique($out)), 0, 400);
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
                $this->flagBuildPipeline(array('comments stripped and assets content-hashed by a build pipeline'));
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
                $labels[] = $this->locate($c)->withText('<!-- ' . $c . ' -->');
            }
            if (Text::hasEmoji($c)) {
                $emoji[] = $this->locate($c)->withText('<!-- ' . $c . ' -->');
            }
        }

        if (count($labels) >= 3) {
            $this->r->flag('st.section_comments', array_merge($labels, array(
                Excerpt::plain('production build tooling strips HTML comments, so these surviving means the file was deployed exactly as it was generated'),
            )), count($labels));
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
        if (preg_match_all('~\b(?:bg|text|from|via|to|border|ring)-(?:indigo|violet|purple|fuchsia)-\d{2,3}\b~i', $this->markup(), $m)) {
            $classes = array_slice(array_unique($m[0]), 0, 4);
        }

        $gradient = preg_match('~(?:bg-gradient-to-\w+[^"\']*(?:indigo|violet|purple)|linear-gradient\([^)]*(?:#6366f1|#8b5cf6|#a855f7|rgb\(9[0-9],\s*9[0-9]))~i', $css);

        $evidence = array();
        if ($found) {
            $evidence[] = $this->locate($found[0], count($found))
                ->withText('palette values in use: ' . implode(', ', array_slice($found, 0, 5)));
        }
        if ($classes) {
            $evidence[] = $this->locate($classes[0], count($classes))
                ->withText('utility classes: ' . implode(', ', $classes));
        }
        if ($gradient) {
            $evidence[] = $this->locatePattern('~(?:bg-gradient-to-\w+[^"\']*(?:indigo|violet|purple)|linear-gradient\([^)]*(?:#6366f1|#8b5cf6|#a855f7))~i',
                'an indigo-to-violet gradient is applied to a hero or heading');
        }

        // Two independent hits before flagging: a single purple button is nothing.
        if (count($evidence) >= 2 || count($found) >= 3 || count($classes) >= 3) {
            $this->r->flag('ae.indigo', $evidence);
        }
    }

    /**
     * Colours from the saturated corners nobody designs in.
     *
     * The indigo ramp above is the default answer to "make it look modern";
     * this is the default answer to "make it look futuristic", and it is a
     * different palette entirely: electric cyan, hot magenta, acid lime, at
     * full saturation, usually with a glow behind them so the page looks lit
     * from inside. What makes it a tell rather than a taste is that these
     * colours are unusable — cyan text on dark fails contrast at body sizes,
     * magenta shifts badly in print, and anybody who has shipped a design
     * system has been told so. A model has not.
     *
     * The glow matters as much as the hue. A single #00ffff is a highlight
     * somebody picked; #00ffff with a 20px shadow of itself behind it is the
     * effect being reached for.
     */
    private function checkNeonPalette(): void
    {
        $css = $this->scanBlob();

        // Full-saturation hues at the corners of the wheel, plus the ones the
        // "neon" name is attached to often enough to be searched for by it.
        $hexes = array('#00ffff', '#0ff', '#ff00ff', '#f0f', '#39ff14', '#ccff00', '#ff073a',
                       '#fe019a', '#04d9ff', '#bc13fe', '#ff2d95', '#00ff9f', '#00e5ff',
                       '#7df9ff', '#ff6ec7', '#01ff70', '#08f7fe', '#f5d300', '#fe53bb');
        $found = array();
        foreach ($hexes as $hex) {
            // Bounded so that #0ff does not match inside #0fff or a longer hex.
            if (preg_match('~' . preg_quote($hex, '~') . '\b(?![0-9a-f])~i', $css)) {
                $found[] = $hex;
            }
        }

        // Saturated utilities, which is how the same palette arrives when the
        // page is built out of classes rather than declarations.
        $classes = array();
        if (preg_match_all('~\b(?:bg|text|from|via|to|border|shadow|ring)-(?:cyan|fuchsia|lime|magenta)-(?:3|4|5)\d{2}\b~i', $this->markup(), $m)) {
            $classes = array_values(array_unique($m[0]));
        }

        // Something glowing: a shadow with no offset and no blur radius to
        // speak of is not a shadow, it is a light source.
        $glow = preg_match('~(?:box|text)-shadow:\s*(?:0\s+){2,3}(?:[1-9]\d|\d{3,})px[^;}]*(?:#0ff|#00ffff|#ff00ff|#f0f|rgba?\(\s*(?:0|255)\s*,\s*(?:255|0|20)\s*,\s*255)~i', $css)
              + preg_match('~\bshadow-\[0_0_\d{2,}px~i', $this->markup())
              + preg_match('~\bdrop-shadow-\[0_0_\d{2,}px~i', $this->markup())
              + preg_match('~\bshadow-(?:cyan|fuchsia|lime)-\d{3}/\d{1,3}\b~i', $this->markup())
              + preg_match('~--(?:neon|glow)[\w-]*\s*:~i', $css);

        $evidence = array();
        if ($found) {
            $evidence[] = $this->locate($found[0], count($found))
                ->withText('neon values in use: ' . implode(', ', array_slice($found, 0, 5)));
        }
        if ($classes) {
            $evidence[] = $this->locate($classes[0], count($classes))
                ->withText('utility classes: ' . implode(', ', array_slice($classes, 0, 4)));
        }
        if ($glow > 0) {
            $evidence[] = $this->locatePattern('~(?:box|text)-shadow:\s*0\s+0\s+\d{2,}px|shadow-\[0_0_\d{2,}px|drop-shadow-\[0_0_\d{2,}px|--(?:neon|glow)[\w-]*\s*:~i',
                'a glow behind the colour: a shadow with no offset, only radius');
        }

        // Two independent hits, or three of one kind. One cyan accent on an
        // otherwise ordinary page is a colour, not a decision about a colour.
        if (count($evidence) >= 2 || count($found) >= 3 || count($classes) >= 4) {
            $this->r->flag('ae.neon_palette', $evidence, max(count($found) + count($classes), 1));
        }
    }

    /**
     * A gradient covering the page rather than an element on it.
     *
     * Distinct from the gradient headline, which is one element wearing a ramp,
     * and from the blurred orbs, which are shapes. This is the ground itself:
     * body, or every section, laid over a two- or three-stop ramp so that no
     * part of the page is ever a flat colour. It is the cheapest available way
     * to make an empty layout look considered, and it is the reason so many
     * generated pages have no white on them anywhere.
     *
     * The selector is what makes it findable. A gradient on a button is a
     * button; a gradient on <body> is a decision about the whole page.
     */
    private function checkBackgroundGradient(): void
    {
        $css = $this->scanBlob();
        $evidence = array();

        // Declarations, read with their selector so that the surface being
        // painted is known rather than guessed at.
        $wide = 0;
        if (preg_match_all('~(?:^|[};])\s*([^{}@;]{1,160})\{([^{}]{0,600})\}~', $css, $m, PREG_SET_ORDER)) {
            foreach ($m as $rule) {
                $selector = trim($rule[1]);
                $body = $rule[2];
                if (!preg_match('~background(?:-image)?\s*:[^;}]*(?:linear|radial|conic)-gradient~i', $body)) {
                    continue;
                }
                // A gradient clipped to text is the headline tell, not this one.
                if (preg_match('~background-clip\s*:\s*text~i', $body)) {
                    continue;
                }
                if (preg_match('~(?:^|[\s,>])(?:html|body|main|#root|#app|\.(?:hero|page|app|wrapper|container|bg|background|gradient-bg))\b~i', $selector)) {
                    $wide++;
                    if (count($evidence) < 2) {
                        $evidence[] = $this->locate(trim(explode('{', $rule[0])[0]))
                            ->withText('the page ground itself: ' . Report::excerpt($selector . ' { ' . trim($body), 110));
                    }
                }
            }
        }

        // The same thing built out of utilities: a full-height surface with a
        // gradient on it, which is what a generated hero section always is.
        $utility = 0;
        if (preg_match_all('~class=["\']([^"\']*\bbg-gradient-to-[a-z]{1,2}\b[^"\']*)["\']~i', $this->markup(), $m)) {
            foreach ($m[1] as $classes) {
                if (preg_match('~\bbg-clip-text\b~i', $classes)) {
                    continue; // the headline again
                }
                if (preg_match('~\b(?:min-h-screen|h-screen|min-h-\[100vh\]|absolute\s+inset-0|fixed\s+inset-0)\b~i', $classes)) {
                    $utility++;
                    if (count($evidence) < 3) {
                        $evidence[] = $this->locate($m[0][0])
                            ->withText('a full-height surface carrying a gradient: ' . Report::excerpt($classes, 90));
                    }
                }
            }
        }

        // Or on the body element directly, which is the honest version of it.
        if (preg_match('~<body[^>]+class=["\'][^"\']*\bbg-gradient-to-[a-z]{1,2}\b~i', $this->html, $m)) {
            $utility++;
            $evidence[] = $this->locate($m[0])->withText('the gradient is on <body>');
        }

        if ($wide > 0 || $utility > 0) {
            $this->r->flag('ae.gradient_background', $evidence, max(1, $wide + $utility));
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
            $this->r->flag('ae.inter_font', array(
                $this->locate($fonts[0], count($fonts))->withText('display face: ' . implode(', ', $fonts)),
            ), count($fonts));
            $this->r->stat('fonts', implode(', ', $fonts));
        }

        $icons = array();
        if (preg_match('~lucide(?:-react)?|class=["\'][^"\']*\blucide\b~i', $css))   $icons[] = 'Lucide';
        if (preg_match('~heroicons|@heroicons/~i', $css))                            $icons[] = 'Heroicons';
        if (preg_match('~<svg[^>]+stroke-width=["\']2["\'][^>]*stroke-linecap=["\']round~i', $this->html)) $icons[] = 'a Lucide-shaped stroke set (2px, rounded caps)';
        if ($icons) {
            $this->r->flag('ae.lucide', array(
                $this->locatePattern('~lucide(?:-react)?|heroicons~i', 'icon set: ' . implode(', ', array_unique($icons))),
            ));
        }
    }

    private function checkComponentDefaults(): void
    {
        $evidence = array();

        if (preg_match_all('~class=["\'][^"\']*\brounded-2xl\b[^"\']*\bshadow-(?:lg|xl|md)\b[^"\']*["\']~i', $this->markup(), $m)) {
            if (count($m[0]) >= 2) {
                $evidence[] = $this->locate($m[0][0], count($m[0]))
                    ->withText(sprintf('%d cards sharing the rounded-2xl + shadow default', count($m[0])));
            }
        }
        $radii = preg_match_all('~\brounded-(?:2xl|3xl|full)\b~i', $this->markup());
        if ($radii >= 8) {
            $evidence[] = $this->locatePattern('~\brounded-(?:2xl|3xl|full)\b~i', 'every surface on the page shares one border radius')
                ->withCount($radii);
        }
        if (preg_match('~class=["\'][^"\']*\brounded-2xl\b[^"\']*["\'][^>]*>\s*<div[^>]+class=["\'][^"\']*\brounded-(?:xl|2xl)\b~i', $this->markup())) {
            $evidence[] = $this->locatePattern('~class=["\'][^"\']*\brounded-2xl\b[^"\']*["\'][^>]*>\s*<div~i', 'cards nested inside cards');
        }
        if ($evidence) {
            $this->r->flag('ae.shadcn_defaults', $evidence);
        }

        // py-24 everywhere.
        if (preg_match_all('~\bpy-(\d{2})\b~', $this->markup(), $m) && count($m[1]) >= 3) {
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
                    $this->locate('py-' . $top, $counts[$top])
                        ->withText(sprintf('py-%s on %d of %d spaced sections', $top, $counts[$top], count($m[1]))),
                ), $counts[$top]);
            }
        }

        $css = $this->scanBlob();

        // A heading painted with a clipped gradient rather than a colour.
        if (preg_match('~bg-clip-text[^"\']*text-transparent|text-transparent[^"\']*bg-clip-text~i', $this->markup())
            || preg_match('~background-clip:\s*text~i', $css)) {
            $this->r->flag('ae.gradient_text', array(
                $this->locatePattern('~bg-clip-text|background-clip:\s*text~i', 'a headline filled with a background gradient instead of a colour'),
            ));
        }

        // Frosted glass on everything.
        $blur = preg_match_all('~backdrop-blur(?:-\w+)?\b~i', $this->markup())
              + preg_match_all('~backdrop-filter:\s*blur~i', $css);
        if ($blur >= 3) {
            $this->r->flag('ae.glassmorphism', array(
                $this->locatePattern('~backdrop-blur(?:-\w+)?\b|backdrop-filter:\s*blur~i',
                    sprintf('%d frosted-glass surfaces on one page', $blur))->withCount($blur),
            ), $blur);
        }

        // The little badge sitting over the headline.
        $announce = '~(?:introducing|announcing|new\b|now (?:with|in|available)|just (?:launched|shipped)|v\d|coming soon|beta|early access|backed by|✨|🎉|🚀)~iu';
        if (preg_match('~<(?:div|span|a|p)[^>]*class=["\'][^"\']*\b(?:rounded-full|pill|badge|chip)\b[^"\']*["\'][^>]*>\s*(?:<[^>]+>\s*)*[^<]{3,60}</~i', $this->html, $m)
            && preg_match($announce, $m[0])) {
            $this->r->flag('ae.hero_pill', array($this->locate($m[0])->withText(Report::excerpt(strip_tags($m[0]), 90))));
        } elseif (preg_match('~class=["\'][^"\']*\brounded-full\b[^"\']*\b(?:border|bg-|px-)~i', $this->markup())) {
            // Client-rendered: the pill's classes and its caption are in the
            // bundle rather than the document, so they are matched apart.
            foreach (explode("\n", (string) $this->bundleCopy) as $line) {
                if (strlen($line) <= 60 && preg_match($announce, $line)) {
                    $this->r->flag('ae.hero_pill', array($this->locate(trim($line))->withText(Report::excerpt($line, 90))));
                    break;
                }
            }
        }

        // A cue telling you to do the thing you already know how to do.
        if (preg_match('~class=["\'][^"\']*\b(?:scroll-(?:indicator|down|hint|cue)|mouse-scroll|animate-bounce)\b~i', $this->markup())
            || preg_match('~(?:scroll (?:down|to explore)|explore more)\s*(?:<|$)~i', $this->copy())
            || preg_match('~<svg[^>]*class=["\'][^"\']*animate-bounce~i', $this->html)) {
            $this->r->flag('ae.scroll_indicator', array(
                $this->locatePattern('~animate-bounce|scroll-(?:indicator|down|hint|cue)|scroll (?:down|to explore)~i',
                    'an animated scroll cue sits under the opening section'),
            ));
        }

        // Soft blurred colour floating behind the hero.
        $orbs = preg_match_all('~class=["\'][^"\']*\b(?:blur-(?:2xl|3xl)|rounded-full)\b[^"\']*\b(?:absolute|fixed)\b[^"\']*["\']~i', $this->markup())
              + preg_match_all('~class=["\'][^"\']*\b(?:absolute|fixed)\b[^"\']*\bblur-(?:2xl|3xl)\b[^"\']*["\']~i', $this->markup())
              + preg_match_all('~filter:\s*blur\((?:6[0-9]|[7-9][0-9]|\d{3,})px\)~i', $css);
        if ($orbs >= 2) {
            $this->r->flag('ae.glow_orbs', array(
                $this->locatePattern('~blur-(?:2xl|3xl)|filter:\s*blur\(\d{2,}px\)~i',
                    sprintf('%d heavily blurred shapes positioned behind the content', $orbs))->withCount($orbs),
            ), $orbs);
        }

        // The bento grid.
        $spans = preg_match_all('~\b(?:col|row)-span-[2-6]\b~i', $this->markup());
        if ($spans >= 4 && preg_match('~\bgrid-cols-(?:3|4|6|12)\b~i', $this->markup())) {
            $this->r->flag('ae.bento_grid', array(
                $this->locatePattern('~\b(?:col|row)-span-[2-6]\b~i',
                    sprintf('%d unequal tile spans inside one grid', $spans))->withCount($spans),
            ), $spans);
        }

        // An endless strip of logos.
        if (preg_match('~class=["\'][^"\']*\b(?:marquee|animate-marquee|logo-?(?:scroll|ticker|cloud|strip)|infinite-scroll)\b~i', $this->markup())
            || (preg_match('~@keyframes\s+(?:marquee|scroll|ticker)~i', $css)
                && preg_match('~(?:trusted by|as seen (?:in|on)|used by|powering)~i', $this->copy()))) {
            $this->r->flag('ae.logo_marquee', array(
                $this->locatePattern('~marquee|logo-?(?:scroll|ticker|cloud|strip)|infinite-scroll~i', 'a looping "trusted by" logo band'),
            ));
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
                $this->r->flag('ae.floating_nav', array(
                    $this->locate($m[0])->withText('a detached, blurred header hovering below the top of the page'),
                ));
            }
        }
        if (!$this->r->has('ae.floating_nav')
            && preg_match('~class=["\'][^"\']*\b(?:fixed|sticky)\b[^"\']*\bbackdrop-blur[^"\']*\b(?:rounded-full|rounded-2xl|inset-x-|mx-auto)~i', $this->markup(), $m)) {
            $this->r->flag('ae.floating_nav', array(
                $this->locate($m[0])->withText('a fixed, blurred, rounded bar built in the bundle: ' . Report::excerpt($m[0], 80)),
            ));
        }

        // The coloured left-border card.
        if (preg_match('~border-l-(?:2|4|\[\d)~i', $this->markup()) && preg_match('~\bborder-l-\d?\s*[^"\']*\bborder-(?:indigo|violet|purple|blue|emerald|amber)-\d{3}~i', $this->markup())) {
            $this->r->flag('ae.left_border_card', array(
                $this->locatePattern('~border-l-(?:2|4)\b~i', 'accent strip down the left edge of a panel'),
            ));
        } elseif (preg_match('~border-left:\s*(?:3|4|5)px\s+solid~i', $css)) {
            $this->r->flag('ae.left_border_card', array(
                $this->locatePattern('~border-left:\s*(?:3|4|5)px\s+solid~i', 'border-left: 4px solid on a callout'),
            ));
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
        $text = $this->copy();
        $lang = $this->declaredLanguage();

        // Emoji doing an icon's job: sitting alone in their own element, or —
        // client-side — alone in their own string literal, which is the same
        // thing one build step earlier.
        $iconEmoji = array();
        if (preg_match_all('~>\s*([\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}][\x{FE0F}]?)\s*<~u', $this->html, $m)) {
            $iconEmoji = $m[1];
        }
        if (preg_match_all('~["\'`]\s*([\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}][\x{FE0F}]?)\s*["\'`]~u', (string) $this->bundleClasses . "\n" . $this->bundleSource(), $m)) {
            $iconEmoji = array_merge($iconEmoji, $m[1]);
        }
        $iconEmoji = array_unique($iconEmoji);
        if (count($iconEmoji) >= 3) {
            $this->r->flag('ct.emoji_icons', array(
                $this->locate((string) reset($iconEmoji), count($iconEmoji))
                    ->withText(implode(' ', array_slice($iconEmoji, 0, 8)) . ' — each alone in its own element, where an icon would go'),
            ), count($iconEmoji));
        }

        // Generic placeholder people. One common name is a person; two is a
        // cast list, and the difference matters because "Sarah Johnson" on a
        // real testimonial page is somebody's actual name.
        $names = array(
            'John Smith', 'Jane Doe', 'John Doe', 'Sarah Johnson', 'Michael Brown',
            'Alex Miller', 'Emily Davis', 'David Wilson', 'Jessica Williams',
            'Michael Chen', 'Sarah Chen', 'Emily Chen', 'Alex Thompson', 'Maria Garcia',
            // The same convention in the other languages this tool reads.
            'Jean Dupont', 'Marie Martin', 'Pierre Durand', 'Sophie Bernard',
            'Juan Pérez', 'María García', 'Max Mustermann', 'Erika Mustermann',
        );
        $hits = array();
        foreach ($names as $n) {
            if (stripos($text, $n) !== false) $hits[] = $this->locate($n);
        }
        $titles = array();
        foreach (array('Verified User', 'Head of Operations', 'Product Manager at', 'CEO, Company',
                       'Satisfied Customer', 'Happy Customer', 'Founder & CEO',
                       'Client satisfait', 'Utilisateur vérifié', 'Cliente satisfecho') as $t) {
            if (stripos($text, $t) !== false) $titles[] = $this->locate($t);
        }
        if (count($hits) + count($titles) >= 2) {
            $this->r->flag('ct.generic_names', array_merge($hits, $titles));
        }

        // House voice. Kept per language: applying the English list to a French
        // page finds nothing and quietly reports that French sites are more
        // human, which is a conclusion about the list rather than the page.
        $cliches = array(
            'en' => array('ship faster', 'unlock the power', 'supercharge', 'seamlessly', 'effortlessly',
                          'everything you need to', 'get started in seconds', 'loved by developers',
                          'built for the modern', 'take your .{3,20} to the next level', 'game-?changer',
                          'in just a few clicks', 'without the guesswork', 'trusted by thousands',
                          'lightning[- ]fast', 'blazing[- ]fast', 'powerful yet simple', 'say goodbye to',
                          'streamline your workflow', 'all[- ]in[- ]one platform', 'designed for teams of all sizes'),
            'fr' => array('boostez', 'sans effort', 'en toute simplicité', 'tout ce dont vous avez besoin',
                          'passez à la vitesse supérieure', 'en quelques clics', 'des milliers de clients',
                          'la solution ultime', 'révolutionnez', 'gagnez du temps et de l\'argent',
                          'conçu pour les équipes', 'dites adieu (?:à|aux)'),
            'es' => array('impulsa tu', 'sin esfuerzo', 'todo lo que necesitas', 'en pocos clics',
                          'miles de clientes', 'la solución definitiva', 'lleva tu .{3,20} al siguiente nivel',
                          'ahorra tiempo y dinero', 'diseñado para equipos', 'di adiós a'),
            'de' => array('im handumdrehen', 'mühelos', 'alles, was sie brauchen', 'in wenigen klicks',
                          'tausende (?:von )?kunden', 'die ultimative lösung', 'bringen sie ihr .{3,20} auf das nächste level',
                          'sparen sie zeit und geld', 'entwickelt für teams'),
        );
        $list = isset($cliches[$lang]) ? $cliches[$lang] : $cliches['en'];
        $found = array();
        foreach ($list as $c) {
            if (preg_match('~' . $c . '~iu', $text, $m)) $found[] = $this->locate($m[0]);
        }
        if (count($found) >= 3) {
            $this->r->flag('ct.marketing_cliche', $found, count($found));
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
                       'insert .{3,20} here', 'sample text', 'company name here',
                       'texte ici', 'votre texte', 'contenu à venir', 'bientôt disponible',
                       'texto de ejemplo', 'próximamente', 'ihr text hier', 'demnächst verfügbar') as $p) {
            if (preg_match('~' . $p . '~iu', $text, $m)) $ph[] = $this->locate($m[0]);
        }
        if ($ph) {
            $this->r->flag('ct.placeholder_copy', $ph, count($ph));
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
                    Excerpt::plain(sprintf('%d of %d links point nowhere', $dead, $total), $dead),
                    $this->locatePattern('~<a\b[^>]*href=["\'](?:#|javascript:void\(0\))["\'][^>]*>~i', 'a navigation link with nowhere to go'),
                ), $dead);
            }
        }

        // Round, unsourced numbers doing persuasive work.
        $stats = array();
        if (preg_match_all('~\b(\d{1,3}(?:,\d{3})*|\d+)\s*(?:k|K|M|m)?\+\s*(?:happy\s+)?(?:users?|customers?|developers?|teams?|downloads?|companies|businesses|creators?|members?|clients?|utilisateurs?|usuarios?|kunden|nutzer)~iu', $text, $m)) {
            foreach ($m[0] as $hit) $stats[] = $this->locate(trim($hit));
        }
        if (preg_match_all('~\b(?:99\.9+|9[5-9])\s*%\s*(?:uptime|accuracy|satisfaction|faster|reliable|de satisfaction|disponibilité|zufriedenheit)~iu', $text, $m)) {
            foreach ($m[0] as $hit) $stats[] = $this->locate(trim($hit));
        }
        if (preg_match_all('~\b\d{1,3}x\s+(?:faster|better|more|cheaper|productive|plus rapide|más rápido|schneller)~iu', $text, $m)) {
            foreach ($m[0] as $hit) $stats[] = $this->locate(trim($hit));
        }
        // Attribution defuses it: a sourced number is a claim someone stands behind.
        $sourced = preg_match('~\b(?:source|according to|survey|report|study|measured|benchmark|selon|étude|estudio|laut|studie)\b~iu', $text);
        if (count($stats) >= 2 && !$sourced) {
            $this->r->flag('ct.stat_inflation', $stats, count($stats));
        }

        // Three tiers with the middle one starred.
        if (preg_match('~\b(?:most popular|recommended|best value|le plus populaire|recommandé|más popular|beliebteste)\b~iu', $text)
            && preg_match_all('~(?:\$|€|£)\s?\d{1,4}\s*(?:/|per\s|par\s)\s*(?:mo|month|user|seat|year|mois|utilisateur|an|monat)~iu', $text) >= 3) {
            $this->r->flag('ct.pricing_three', array('three priced tiers with a "most popular" badge on the middle one'));
        }

        // Specifics pull the other way: real prices, dates, addresses, hours.
        $specific = 0;
        if (preg_match('~(?:[$£€]\s?\d{1,3}(?:[.,]\d{2})?|\d+\s?(?:€|EUR|USD|GBP))~', $text)) $specific++;
        if (preg_match('~\b\d{1,2}[:h]\d{2}\b|\b(?:mon|tue|wed|thu|fri|sat|sun)[a-z]*\s*[-–]\s*(?:mon|tue|wed|thu|fri|sat|sun)~i', $text)) $specific++;
        if (preg_match('~\b\d{1,4}[,\s]+(?:rue|avenue|street|st\.|road|rd\.|boulevard|blvd|allée|impasse|chemin|calle|straße|strasse)\b~iu', $text)) $specific++;
        if (preg_match('~\b(?:\+\d{1,3}[\s.-]?)?(?:\(?\d{2,4}\)?[\s.-]?){2,4}\d{2,4}\b~', $text)) $specific++;
        if (preg_match('~\b(?:19|20)\d{2}\b~', $text) && preg_match('~\b(?:since|established|founded|depuis|est\.|desde|seit)\b~iu', $text)) $specific++;
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
        $text = $this->copy();

        // Misspelling lists are per-language: applying the English one to French
        // copy would flag ordinary words and invent a human signal out of nothing.
        $lang = 'en';
        if (preg_match('~<html[^>]+lang=["\']([a-z]{2})~i', $this->html, $m)) {
            $lang = strtolower($m[1]);
            $this->r->stat('lang', $lang);
        }
        // One override, in one direction. A page whose author set lang="fr" is
        // taken at its word — that attribute was a decision. A page still
        // declaring the scaffold's lang="en" over copy that is plainly not
        // English is not a decision, and following it would run the English
        // list against French text, find nothing, and report the silence as
        // evidence of a generator.
        if ($lang === 'en') {
            $actual = $this->declaredLanguage();
            if ($actual !== '' && $actual !== 'en') {
                $lang = $actual;
            }
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
                'concious', 'embarass', 'harrass', 'noticable',
                'occassion', 'perseverence', 'questionaire', 'rythm', 'supercede',
                'threshhold', 'writting', 'usefull', 'carefull', 'succesfully',
            ),
            // Entries that are also ordinary English words are deliberately
            // absent. "Connection" and "language" are indeed misspelled French,
            // and they are also two of the most common English loanwords on a
            // French technology page, so the list cannot tell a typo from a
            // borrowing and should not be asked to.
            'fr' => array(
                'acceuil', 'addresse', 'professionel', 'developement', 'developpeur',
                'malgrés', 'parmis', 'biensur', 'quelquechose', 'apparament',
                'entrainement', 'evenement', 'exhorbitant', 'dilemne', 'infractus',
                'aggrandir', 'rénumération', 'pallier à', 'context', 'exemple:',
            ),
            'es' => array(
                'haber si', 'a ver si vienes', 'echo un vistazo', 'aver', 'osea',
                'enves de', 'escusa', 'abrigo de', 'exhuberante', 'idiosincracia',
            ),
            'de' => array(
                'standart', 'seperat', 'wiederspiegeln', 'aufwendig zu erreichen',
                'zumindestens', 'einzigst', 'reperatur', 'authentisch aussehende',
            ),
        );

        $misspellings = isset($lists[$lang]) ? $lists[$lang] : $lists['en'];

        $found = array();
        foreach (array_unique($misspellings) as $word) {
            if (preg_match('~\b' . preg_quote($word, '~') . '\b~iu', $text, $hit)) {
                $found[] = $this->locate($hit[0]);
            }
        }
        if ($found) {
            $this->r->flag('hu.typos', $found, count($found));
        }

        // Obligations accumulate; features get generated.
        $ops = array();
        if (preg_match('~(?:cookie[- ]?(?:consent|banner|policy)|gdpr|tarteaucitron|cookieconsent|axeptio|didomi|usercentrics|osano|klaro)~i', $this->html)) {
            $ops[] = 'a cookie consent mechanism';
        }
        if (preg_match('~href=["\'][^"\']*(?:privacy|terms|legal|mentions-legales|impressum|datenschutz|cgv|cgu|aviso-legal|politica-de-privacidad)~i', $this->html)) {
            $ops[] = 'legal or privacy pages linked from the page';
        }
        if (preg_match('~(?:googletagmanager\.com|google-analytics\.com|gtag\(|plausible\.io|matomo|umami|fathom|hotjar|clarity\.ms|posthog)~i', $this->html)) {
            $ops[] = 'a real analytics or tag-manager property';
        }
        if (preg_match('~(?:mailchimp|list-manage\.com|sendinblue|brevo|convertkit|substack|klaviyo|mailerlite)~i', $this->html)) {
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
                    $this->locate((string) $latest)->withText(sprintf('the footer still reads %d', $latest)),
                ));
            }
        }
    }

    // ------------------------------------------------------------- publishing

    /**
     * What happened between "it works" and "it is online".
     *
     * Everything here is about the steps a page acquires on the way to being
     * published: a build, a domain, a description, a favicon. None of them is
     * about how the code was written, which is the point — a page can be
     * hand-written and still be published the way a demo is, and a page that
     * skipped all of them at once was never published so much as left running.
     */
    private function checkPublishing(): void
    {
        // --- The build step that never ran ---------------------------------
        $dev = array();
        $devMarkers = array(
            '~/@vite/client~i'
                => 'the Vite development client is loaded by the served page',
            '~<script[^>]+src=["\'][^"\']*/src/(?:main|index|app)\.(?:[jt]sx?)["\']~i'
                => 'the document loads /src/main.tsx directly, unbundled',
            '~<script[^>]+type=["\']module["\'][^>]+src=["\'][^"\']+\.(?:tsx|ts|jsx)["\']~i'
                => 'a TypeScript or JSX module is served to the browser with no build step in front of it',
            '~__vite_plugin_react_preamble|RefreshRuntime\s*\.\s*injectIntoGlobalHook~i'
                => 'the React fast-refresh preamble is still in the page',
            '~webpack-dev-server|sockjs-node|__webpack_hmr~i'
                => 'a webpack dev-server hot-reload channel is wired up',
            '~(?:src|href|action)=["\']https?://(?:localhost|127\.0\.0\.1)(?::\d+)?~i'
                => 'the page still points at a localhost address',
        );
        foreach ($devMarkers as $re => $why) {
            if (preg_match($re, $this->html)) {
                $dev[] = $this->locatePattern($re, $why);
            }
        }
        if ($dev) {
            $this->generatedShell = true;
            $this->r->flag('st.dev_server_page', $dev, count($dev));
        }

        // --- Still on the platform's own subdomain --------------------------
        //
        // Builder hosts are deliberately absent: those are fingerprints and are
        // identified as such above. What is left is ordinary hosting, which is
        // why this is a nudge — a great many real projects never move off it.
        $host = strtolower((string) parse_url($this->url, PHP_URL_HOST));
        $preview = array('vercel.app', 'netlify.app', 'netlify.com', 'pages.dev', 'onrender.com',
                         'railway.app', 'up.railway.app', 'fly.dev', 'web.app', 'firebaseapp.com',
                         'glitch.me', 'surge.sh', 'streamlit.app', 'herokuapp.com', 'deno.dev',
                         'workers.dev', 'vercel.sh');
        foreach ($preview as $suffix) {
            if (substr($host, -(strlen($suffix) + 1)) === '.' . $suffix) {
                $this->r->flag('st.preview_host', array(
                    Excerpt::plain('the site answers on ' . $host . ', the platform\'s default domain, with no custom domain in front of it'),
                ));
                break;
            }
        }

        // --- None of the furniture a published page acquires ----------------
        $head = strlen($this->html) > 65536 ? substr($this->html, 0, 65536) : $this->html;
        $missing = array();
        if (!preg_match('~<meta[^>]+name=["\']description["\']~i', $head)) {
            $missing[] = 'no meta description';
        }
        if (!preg_match('~<meta[^>]+(?:property|name)=["\'](?:og:|twitter:)~i', $head)) {
            $missing[] = 'no social card';
        }
        if (!preg_match('~<link[^>]+rel=["\'][^"\']*canonical~i', $head)) {
            $missing[] = 'no canonical link';
        }
        if (!preg_match('~<link[^>]+rel=["\'][^"\']*icon~i', $head)) {
            $missing[] = 'no favicon declared';
        }
        // Three guards, because the absence of meta tags is the weakest kind of
        // evidence there is. All four have to be missing, not most of them; the
        // page has to carry enough copy to be meant for readers; and it has to
        // be a built page rather than a hand-written document, because a plain
        // HTML page that never had a social card is 2009, not 2025.
        $words = str_word_count($this->copy());
        $built = preg_match('~<script[^>]+src=["\'][^"\']+\.js~i', $head)
              || $this->r->statValue('framework') !== null;
        if (count($missing) === 4 && $words >= 150 && $built) {
            $this->r->flag('st.no_seo_furniture', array(
                Excerpt::plain(implode(', ', $missing) . ' — on a built page carrying ' . $words . ' words of copy', count($missing)),
            ), count($missing));
        }

        // --- The whole thing in one file ------------------------------------
        $styleBytes  = 0;
        $scriptBytes = 0;
        if (preg_match_all('~<style\b[^>]*>(.*?)</style>~is', $this->html, $m)) {
            foreach ($m[1] as $block) $styleBytes += strlen($block);
        }
        if (preg_match_all('~<script\b(?![^>]*\bsrc=)[^>]*>(.*?)</script>~is', $this->html, $m)) {
            foreach ($m[1] as $block) $scriptBytes += strlen($block);
        }
        $external = preg_match_all('~<(?:script[^>]+src|link[^>]+rel=["\']stylesheet["\'][^>]*href)=["\'](?!https?://(?:cdn|unpkg|fonts))[^"\']+~i', $this->html);
        if ($styleBytes >= 3000 && $scriptBytes >= 2500 && $external <= 1 && strlen($this->html) >= 20000) {
            $this->r->flag('st.single_file_page', array(
                Excerpt::plain(sprintf(
                    '%d KB of markup carrying %d KB of inline styles and %d KB of inline script, with nothing split out',
                    (int) round(strlen($this->html) / 1024), (int) round($styleBytes / 1024), (int) round($scriptBytes / 1024)
                )),
            ));
        }
    }

    // ------------------------------------------------------------ interaction

    /**
     * Whether the things that look interactive do anything.
     *
     * A form is the honest test. It is the one element on a marketing page that
     * has to reach a server to mean anything, and a generated page very often
     * has the field, the button, the focus ring and the hover state, and no
     * destination at all.
     */
    private function checkForms(): void
    {
        if (!preg_match_all('~<form\b[^>]*>~i', $this->html, $forms, PREG_OFFSET_CAPTURE)) {
            return;
        }

        $hay = $this->scanBlob();

        // Anything at all that could take a submission: a real action, a form
        // service, a mail link, a handler, a request in the bundle.
        $wired = preg_match('~<form\b[^>]*\baction=["\'](?!#|javascript:|\s*["\'])[^"\']+~i', $this->html)
              || preg_match('~\b(?:data-netlify|netlify|formspree\.io|getform\.io|usebasin\.com|formsubmit\.co|web3forms|emailjs|hsforms\.net|typeform|tally\.so|jotform|google\.com/forms)~i', $hay)
              || preg_match('~mailto:[^"\'\s]+~i', $this->html)
              || preg_match('~\bon[Ss]ubmit\s*=|addEventListener\(\s*["\']submit["\']~', $hay)
              || preg_match('~\b(?:fetch|axios|XMLHttpRequest|supabase|firebase|\$\.(?:post|ajax))\b~i', $hay);

        if ($wired) {
            return;
        }

        // A form with nothing to type into is a search box or a filter, not a
        // promise to get back to anybody.
        $fields = preg_match_all('~<(?:input|textarea|select)\b[^>]*>~i', $this->html);
        if ($fields < 2) {
            return;
        }

        $this->r->flag('st.form_to_nowhere', array(
            Excerpt::atOffset($this->html, (int) $forms[0][0][1], count($forms[0]), 'the page', 3,
                sprintf('%d form(s) with %d fields, no action, no endpoint and no handler anywhere in the page or its scripts',
                    count($forms[0]), $fields)),
        ), count($forms[0]));
    }

    // ----------------------------------------------------------------- people

    /**
     * The people on the page, and whether any of them can be reached.
     *
     * Both halves of this are about the same absence. A face from an avatar
     * service and an address at example.com are what a page has instead of a
     * customer and a business, and unlike a colour scheme they are not a taste
     * a real company might share.
     */
    private function checkPlaceholderIdentity(): void
    {
        $hay = $this->html . "\n" . (string) $this->bundleSource();

        $services = array(
            'pravatar.cc', 'i.pravatar.cc', 'randomuser.me', 'ui-avatars.com',
            'api.dicebear.com', 'dicebear.com', 'placehold.co', 'via.placeholder.com',
            'placeholder.com', 'placekitten.com', 'picsum.photos', 'loremflickr.com',
            'source.unsplash.com', 'unsplash.it', 'placeimg.com', 'avatar.iran.liara.run',
        );
        $avatars = array();
        $avatarHits = 0;
        foreach ($services as $service) {
            $n = substr_count(strtolower($hay), $service);
            if ($n > 0) {
                $avatarHits += $n;
                $avatars[] = $this->locate($service, $n);
            }
        }
        if ($avatars) {
            $this->r->flag('ct.stock_avatars', $avatars, $avatarHits);
        }

        // Contact details that cannot be contacted.
        $contact = array();
        if (preg_match_all('~[\w.+-]+@(?:example|yourdomain|your-domain|domain|email|mysite|yoursite|test)\.(?:com|org|net)~i', $hay, $m)) {
            foreach (array_unique($m[0]) as $hit) $contact[] = $this->locate($hit, count($m[0]));
        }
        if (preg_match_all('~(?:\+?1[\s.-]?)?\(?555\)?[\s.-]?\d{3}[\s.-]?\d{4}~', $this->copy(), $m)) {
            foreach (array_unique($m[0]) as $hit) $contact[] = $this->locate($hit, count($m[0]));
        }
        if (preg_match('~\b\d{2,4}\s+(?:Main|Elm|Oak|Business|Innovation|Startup)\s+(?:St|Street|Ave|Avenue|Road|Rd|Way|Blvd)\b~i', $this->copy(), $m)) {
            $contact[] = $this->locate($m[0]);
        }
        // Social links pointing at the platform rather than at an account.
        $bare = 0;
        if (preg_match_all('~href=["\']https?://(?:www\.)?(?:twitter|x|facebook|instagram|linkedin|github|youtube|tiktok)\.com/?["\']~i', $this->html, $m)) {
            $bare = count($m[0]);
            if ($bare >= 2) {
                $contact[] = $this->locate($m[0][0], $bare)
                    ->withText(sprintf('%d social links point at the platform\'s home page rather than at an account', $bare));
            }
        }
        if ($contact) {
            $this->r->flag('ct.placeholder_contact', $contact);
        }
    }

    // ------------------------------------------------------------------ prose

    /**
     * The rhythm rather than the vocabulary.
     *
     * ct.marketing_cliche reads the words a landing page uses; this reads the
     * sentence shapes a model reaches for when it has been asked to sound
     * enthusiastic. Copywriters use every one of these. What they do not do is
     * use all of them on one page, three sections apart, in the same cadence.
     */
    private function checkProseRhythm(): void
    {
        $text = $this->copy();
        if (str_word_count($text) < 120) {
            return;
        }

        $lang = $this->declaredLanguage();
        $shapes = array(
            'en' => array(
                '~\b(?:it\'?s|this is|we\'?re|they\'?re)\s+not\s+just\s+[^.,;]{3,40}[,—-]\s*(?:it\'?s|but|it is)~i',
                '~\bmore than just\s+\w+~i',
                '~\bin today\'?s\s+(?:fast-?paced|digital|modern|ever-?changing|competitive|connected)\s+(?:world|landscape|market|environment|economy)~i',
                '~\bwhether you\'?re\s+(?:an?|the)\s+[^,]{3,40},?\s+or\b~i',
                '~\b(?:delve into|tapestry|testament to|underscore[sd]?\b|paramount|the realm of|navigate the (?:complexities|landscape|world))~i',
                '~\b(?:elevate|unlock|unleash|empower|revolutioni[sz]e|supercharge|transform)\s+your\b~i',
                '~\bat the (?:heart|core|forefront) of\b~i',
                '~\bthat\'?s where\s+\w+\s+comes in\b~i',
            ),
            'fr' => array(
                '~\bil ne s\'?agit pas (?:seulement|simplement) d[eu]~i',
                '~\b(?:à l\'?ère|dans un monde) (?:du numérique|numérique|en constante évolution|en perpétuelle évolution)~i',
                '~\bque vous soyez\s+[^,]{3,40},?\s+ou\b~i',
                '~\bc\'?est (?:là )?(?:qu\'?intervient|que .{2,30} entre en jeu)~i',
                '~\bau c(?:œ|oe)ur de\b~i',
            ),
        );
        $list = isset($shapes[$lang]) ? $shapes[$lang] : $shapes['en'];

        $found = array();
        foreach ($list as $re) {
            if (preg_match($re, $text, $m)) {
                $found[] = $this->locate(trim($m[0]));
            }
        }

        // The three-item list, counted rather than matched: one is a sentence,
        // six in a page of copy is a cadence.
        $tricolon = preg_match_all('~\b\w{3,},\s+\w{3,},?\s+(?:and|et|y|und)\s+\w{3,}\b~iu', $text);
        if ($tricolon >= 4) {
            $found[] = Excerpt::plain(sprintf('%d three-item lists in one page of copy', $tricolon), $tricolon);
        }

        if (count($found) >= 3) {
            $this->r->flag('ct.llm_prose', $found, count($found) + max(0, $tricolon - 1));
        }
    }

    /**
     * Alt text that describes the picture to nobody in particular.
     *
     * Real alt text is uneven, because it is written by somebody who knows
     * which images matter: empty on the decorative ones, terse on the logo,
     * specific on the diagram. A page where every image carries the same
     * measured sentence has alt text that was generated alongside the markup.
     */
    private function checkAltText(): void
    {
        if (!preg_match_all('~<img\b[^>]*\balt=["\']([^"\']*)["\']~i', $this->html, $m)) {
            return;
        }
        $alts = $m[1];
        $total = preg_match_all('~<img\b~i', $this->html);
        if (count($alts) < 4 || count($alts) < $total) {
            return; // some image was left without one, which is the human pattern
        }

        $descriptive = 0;
        $lengths = array();
        foreach ($alts as $alt) {
            $alt = trim(html_entity_decode($alt, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($alt === '') {
                return; // an empty alt is a decision about a decorative image
            }
            $lengths[] = strlen($alt);
            if (strlen($alt) >= 28
                && preg_match('~^(?:an?|the|close-?up|photo|image|illustration|portrait|screenshot|view|group|young|modern|professional|smiling|abstract)\b~i', $alt)) {
                $descriptive++;
            }
        }

        $mean = array_sum($lengths) / count($lengths);
        if ($descriptive >= 3 && $descriptive / count($alts) >= 0.6 && $mean >= 30) {
            $this->r->flag('ct.model_alt_text', array(
                Excerpt::plain(sprintf('%d of %d images carry a written-out description, averaging %d characters',
                    $descriptive, count($alts), (int) round($mean)), $descriptive),
                $this->locate($alts[0]),
            ), $descriptive);
        }
    }

    // ------------------------------------------------------- more human marks

    /**
     * Dates, and accessibility work nobody was asked for.
     *
     * Both are evidence of time passing. A page carrying dates across months
     * was returned to; a skip link and a reduced-motion rule are what a person
     * adds after somebody complained, and no prompt asks for either.
     */
    private function checkCareMarks(): void
    {
        // --- Content dated across real time ---------------------------------
        $dates = array();
        if (preg_match_all('~<time\b[^>]*\bdatetime=["\'](\d{4})-(\d{2})~i', $this->html, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) $dates[$hit[1] . '-' . $hit[2]] = $hit[0];
        }
        $months = 'january|february|march|april|may|june|july|august|september|october|november|december'
                . '|janvier|février|mars|avril|juin|juillet|août|septembre|octobre|novembre|décembre';
        if (preg_match_all('~\b(?:\d{1,2}\s+)?(' . $months . ')\s+(?:\d{1,2},?\s+)?((?:19|20)\d{2})~iu', $this->copy(), $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) $dates[strtolower($hit[1]) . '-' . $hit[2]] = $hit[0];
        }
        if (preg_match_all('~\b((?:19|20)\d{2})-(\d{2})-\d{2}\b~', $this->copy(), $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) $dates[$hit[1] . '-' . $hit[2]] = $hit[0];
        }

        if (count($dates) >= 3) {
            $sample = array_slice(array_values($dates), 0, 3);
            $lines = array(Excerpt::plain(sprintf('%d distinct dates in the page, from %s to %s',
                count($dates), (string) $sample[0], (string) end($sample)), count($dates)));
            $lines[] = $this->locate((string) $sample[0]);
            $this->r->flag('hu.content_dates', $lines, count($dates));
        }

        // --- Accessibility somebody did on purpose --------------------------
        //
        // Withheld when the page has already identified itself as a generator's
        // own output: component kits ship aria attributes and sr-only helpers
        // by the hundred, and counting those as craft would hand a generated
        // page a human signal for using its own defaults.
        if ($this->generatedShell) {
            return;
        }
        $css = $this->scanBlob();
        $care = array();
        if (preg_match('~<a\b[^>]*href=["\']#(?:main|content|skip)[^"\']*["\'][^>]*>[^<]{0,40}(?:skip|aller au contenu|saltar)~i', $this->html, $m)) {
            $care[] = $this->locate($m[0])->withText('a skip-to-content link, which only keyboard users ever see');
        }
        if (preg_match('~@media[^{]*prefers-reduced-motion~i', $css)) {
            $care[] = $this->locatePattern('~@media[^{]*prefers-reduced-motion~i', 'a reduced-motion media query');
        }
        if (preg_match('~aria-live=["\'](?:polite|assertive)~i', $this->html, $m)) {
            $care[] = $this->locate($m[0])->withText('a live region for announcements');
        }
        $labels = preg_match_all('~<label\b[^>]*\bfor=["\'][^"\']+["\']~i', $this->html);
        $inputs = preg_match_all('~<input\b(?![^>]*type=["\'](?:hidden|submit|button)["\'])[^>]*>~i', $this->html);
        if ($labels >= 2 && $inputs > 0 && $labels >= $inputs) {
            $care[] = $this->locatePattern('~<label\b[^>]*\bfor=["\'][^"\']+["\']~i',
                sprintf('every one of the %d inputs has a label bound to it', $inputs))->withCount($labels);
        }
        if (count($care) >= 2) {
            $this->r->flag('hu.a11y_care', $care, count($care));
        }
    }

    /**
     * Whether there are pictures of anything that exists.
     *
     * Counting <img src> and looking for a file extension used to be enough.
     * It no longer is: an image served through Next's optimiser, an imgix or
     * Cloudinary transform, a srcset or a CSS background carries no extension
     * where the old check looked, so a page full of photographs read as a page
     * with none — and "no real photography" is an AI-leaning signal, which
     * made a bakery's photo gallery evidence against it.
     */
    private function checkMedia(): void
    {
        $refs = array();

        if (preg_match_all('~<img\b[^>]*\bsrc=["\']([^"\']+)["\']~i', $this->html, $m)) {
            foreach ($m[1] as $src) $refs[] = $src;
        }
        // Lazy loaders keep the real address somewhere else until it is needed.
        if (preg_match_all('~\b(?:data-src|data-lazy-src|data-original)=["\']([^"\']+)["\']~i', $this->html, $m)) {
            foreach ($m[1] as $src) $refs[] = $src;
        }
        // A srcset is a list; every candidate in it is the same picture, so the
        // first is taken and the rest ignored.
        if (preg_match_all('~\bsrcset=["\']([^"\']+)["\']~i', $this->html, $m)) {
            foreach ($m[1] as $set) {
                $first = trim((string) strtok($set, ' ,'));
                if ($first !== '') $refs[] = $first;
            }
        }
        if (preg_match_all('~background(?:-image)?:\s*(?:[^;}]*?)url\(\s*["\']?([^"\')]+)~i', $this->scanBlob(), $m)) {
            foreach ($m[1] as $src) $refs[] = $src;
        }

        $photos = 0;
        $exts = array();
        $seen = array();
        foreach ($refs as $ref) {
            $ref = rawurldecode(html_entity_decode($ref, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($ref === '' || strpos($ref, 'data:') === 0) continue;
            if (isset($seen[$ref])) continue;
            $seen[$ref] = true;

            // The extension can be anywhere now — in the path, or inside the
            // url= parameter of an optimiser.
            if (preg_match('~\.(jpe?g|png|webp|avif|gif|heic)\b~i', $ref, $e)) {
                $photos++;
                $exts[strtolower($e[1])] = true;
                continue;
            }
            // Image pipelines that serve extensionless URLs.
            if (preg_match('~/_next/image|/cdn-cgi/image/|/_vercel/image|res\.cloudinary\.com|\.imgix\.net|images\.unsplash\.com|\.imagekit\.io|/wp-content/uploads/~i', $ref)) {
                $photos++;
                $exts['optimised'] = true;
            }
        }

        $gradients = preg_match_all('~(?:bg-gradient-to-|linear-gradient\(|radial-gradient\()~i', $this->scanBlob());
        $svgs = preg_match_all('~<svg\b~i', $this->html);
        $this->r->stat('photos', $photos);

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

        $legacy = $this->transportLegacy;
        if (preg_match('~jquery(?:\.min)?\.js|jquery-\d~i', $hay)
            || preg_match('~<table[^>]*>\s*<tr~i', $hay)
            || preg_match('~-webkit-border-radius|filter:\s*progid:~i', $this->scanBlob())) {
            $legacy[] = 'jQuery, table layout or vendor-prefixed CSS: markup with an accretion history';
        }
        if ($legacy) {
            $this->r->flag('hu.legacy_stack', $legacy);
        }
    }

    /**
     * The page as the checks below want to read it: real markup, plus the
     * class attributes that never made it into the markup.
     *
     * A single-page app serves an empty div and puts the entire interface in a
     * bundle. Every check that reads `class="..."` therefore finds nothing on
     * exactly the sites this tool most wants to read. The classes are not
     * gone, though — a JSX `className` is a string literal, and a minifier has
     * no reason to touch the inside of a string. Harvesting those literals and
     * writing them back out as attributes lets one set of patterns read a
     * server-rendered page and a client-rendered one without knowing which it
     * has.
     *
     * Stylesheets are deliberately not harvested here. A compiled utility
     * sheet has one rule per class no matter how many elements use it, so
     * counting from it would answer a different question from the one the
     * count-based checks are asking. Presence-only checks read scanBlob(),
     * which does include the CSS.
     */
    private function markup(): string
    {
        if ($this->markup !== null) {
            return $this->markup;
        }
        $this->harvest();
        return $this->markup = $this->html . "\n" . (string) $this->bundleClasses;
    }

    /** Visible page text, plus the sentences found in the bundles. */
    private function copy(): string
    {
        if ($this->copy !== null) {
            return $this->copy;
        }
        $this->harvest();
        $extra = (string) $this->bundleCopy;
        return $this->copy = $extra === '' ? $this->text : $this->text . "\n" . $extra;
    }

    /**
     * One pass over the bundles, splitting their string literals into the two
     * things worth having: class lists and sentences.
     */
    private function harvest(): void
    {
        if ($this->bundleClasses !== null) {
            return;
        }
        $classes = array();
        $copy = array();
        $budget = self::MAX_SCAN;

        foreach ($this->readableSources() as $body) {
            if ($budget <= 0) break;
            if (strlen($body) > $budget) {
                $body = Text::safeCut($body, $budget);
                $this->truncated = true;
            }
            $budget -= strlen($body);

            // Every quoted run, however short. The length filter belongs in the
            // loop and not in the pattern: skipping the short literals here
            // would leave the scan starting on a closing quote, and from then
            // on it reads the code between the strings instead of the strings.
            if (!preg_match_all('~(["\'`])([^"\'`\r\n]{0,300})\\1~', $body, $m)) {
                continue;
            }
            foreach ($m[2] as $literal) {
                if (count($classes) + count($copy) >= 4000) {
                    break 2; // enough material; the rest is more of the same
                }
                if (strlen($literal) < 6) {
                    continue;
                }
                if ($this->looksLikeClassList($literal)) {
                    $classes[] = 'class="' . $literal . '"';
                } elseif ($this->looksLikeProse($literal)) {
                    $copy[] = $literal;
                }
            }
        }

        $this->bundleClasses = implode("\n", $classes);
        $this->bundleCopy = implode("\n", $copy);
    }

    /**
     * Record that a build pipeline ran — unless the page has already said who
     * ran it.
     *
     * A minified, content-hashed bundle used to mean somebody set a toolchain
     * up, and it still leans that way on a page with nothing else to go on.
     * On a page that is carrying a builder's fingerprint or is still wearing
     * its scaffold's title, it means the generator's deploy step ran, and
     * counting that as a mark of human craft would net off evidence with its
     * own side effect.
     *
     * @param string[] $evidence
     */
    private function flagBuildPipeline(array $evidence): void
    {
        if ($this->generatedShell || $this->r->hasFingerprint()) {
            return;
        }
        $this->r->flag('hu.build_stripped', $evidence);
    }

    /**
     * The bundles as one string, for the checks that want to look inside a
     * script rather than at a class list.
     */
    private function bundleSource(): string
    {
        if ($this->bundleSource !== null) {
            return $this->bundleSource;
        }
        $out = '';
        foreach ($this->readableSources() as $body) {
            if (strlen($out) >= self::MAX_SCAN) break;
            $out .= "\n" . $body;
        }
        if (strlen($out) > self::MAX_SCAN) {
            $out = Text::safeCut($out, self::MAX_SCAN);
            $this->truncated = true;
        }
        return $this->bundleSource = $out;
    }

    /**
     * Everything readable as source: the assets that are not minified beyond
     * use, and anything a source map handed back.
     *
     * @return string[]
     */
    private function readableSources(): array
    {
        $out = array();
        foreach ($this->assets as $url => $body) {
            if ($body === '') continue;
            if (preg_match('~\.css(?:[?#]|$)~i', $url)) continue; // see markup()
            $out[] = $body;
        }
        foreach ($this->maps as $map) {
            if (!empty($map['content'])) {
                $out[] = (string) $map['content'];
            }
        }
        return $out;
    }

    /**
     * Is this string literal a list of utility classes?
     *
     * Deliberately strict. A bundle is full of strings, and mistaking an
     * arbitrary one for a class list would let unrelated text answer questions
     * about how the page is styled. Two or more tokens must be shaped like
     * utility classes and almost all of them must be, so "flex items-center
     * gap-4" is taken and "GET /api/users" is not.
     */
    private function looksLikeClassList(string $literal): bool
    {
        $literal = trim($literal);
        if ($literal === '' || strpos($literal, ' ') === false) {
            return false; // a single token says too little to be worth the risk
        }
        if (preg_match('~[<>{};=()]|https?://~', $literal)) {
            return false;
        }
        $tokens = preg_split('~\s+~', $literal);
        if (!is_array($tokens) || count($tokens) < 2 || count($tokens) > 40) {
            return false;
        }

        $utility = 0;
        foreach ($tokens as $t) {
            if (preg_match('~^(?:(?:sm|md|lg|xl|2xl|hover|focus|focus-visible|active|disabled|group-hover|peer|dark|first|last|odd|even|motion-safe|motion-reduce|print|rtl|ltr|aria-\w+|data-\[[^\]]+\])[:-])*'
                . '(?:bg|text|font|leading|tracking|p|px|py|pt|pb|pl|pr|ps|pe|m|mx|my|mt|mb|ml|mr|w|h|min|max|flex|grid|col|row|gap|space|items|justify|self|place|order|rounded|border|divide|ring|outline|shadow|opacity|blur|backdrop|filter|transition|duration|delay|ease|animate|transform|scale|rotate|translate|skew|absolute|relative|fixed|sticky|static|inset|top|bottom|left|right|z|overflow|object|aspect|container|block|inline|inline-flex|inline-block|hidden|visible|invisible|cursor|select|pointer|resize|whitespace|break|truncate|list|table|sr|not-sr|from|via|to|fill|stroke|antialiased|uppercase|lowercase|capitalize|italic|underline|line|decoration|underline-offset|shrink|grow|basis|wrap|nowrap|origin|will-change|snap|touch|scroll)'
                . '(?:-[a-z0-9./%\[\]#()_-]+)*$~i', $t)) {
                $utility++;
            }
        }
        return $utility >= 2 && $utility / count($tokens) >= 0.7;
    }

    /** Is this string literal a sentence someone will read on the page? */
    private function looksLikeProse(string $literal): bool
    {
        $literal = trim($literal);
        if (strlen($literal) < 15) {
            return false;
        }
        if (preg_match('~[<>{}\\\\]|https?://|^[a-z]+([A-Z][a-z]+)+$|^[\w.-]+\.(?:js|css|json|png|svg|tsx?|jsx?)$~', $literal)) {
            return false;
        }
        $words = preg_split('~\s+~', $literal);
        if (!is_array($words) || count($words) < 3) {
            return false;
        }
        // Mostly letters and ordinary punctuation, and starting like a sentence
        // rather than like an identifier or a path.
        if (!preg_match('~^[\p{L}\p{N}\p{Pi}\x{2018}\x{201C}"\'(]~u', $literal)) {
            return false;
        }
        $letters = preg_match_all('~\p{L}~u', $literal);
        return $letters > 0 && $letters / strlen($literal) > 0.6;
    }

    /**
     * Every document this page was read from, markup first.
     *
     * Evidence found in a page can have come from the served HTML, from a
     * stylesheet or bundle it pulls in, or from the original source a map gave
     * back. Locating a match means asking each of them in that order, and
     * labelling the answer with the one that had it — evidence gathered from
     * four files and presented as though it came from one is evidence the
     * reader cannot check.
     *
     * @return SourceContext[]
     */
    private function documents(): array
    {
        if ($this->documents !== null) {
            return $this->documents;
        }

        $docs = array(new SourceContext($this->html, 'the page'));
        foreach ($this->assets as $url => $body) {
            if ($body === '') continue;
            $docs[] = new SourceContext($body, self::assetLabel($url));
        }
        foreach ($this->maps as $map) {
            if (empty($map['content'])) continue;
            $label = isset($map['url']) && $map['url'] !== ''
                ? self::assetLabel((string) $map['url']) . ' (source)'
                : 'source map';
            $docs[] = new SourceContext((string) $map['content'], $label);
        }
        return $this->documents = $docs;
    }

    /**
     * The stylesheet the document carries itself.
     *
     * Read on the same terms as a file: bounded, skipped when it is too small
     * to have habits, and skipped when it has been minified, because then the
     * habits are the build tool's rather than the author's.
     */
    private function checkInlineStyles(): void
    {
        if (!preg_match_all('~<style\b[^>]*>(.*?)</style>~is', $this->html, $m)) {
            return;
        }

        $css = '';
        foreach ($m[1] as $block) {
            $css .= "\n" . $block;
            if (strlen($css) > 200000) {
                break;
            }
        }
        if (strlen(trim($css)) < 400) {
            return;
        }

        $lines = substr_count($css, "\n") + 1;
        if ((strlen($css) / max(1, $lines)) > 300) {
            return; // minified into the document; nothing of the author left in it
        }

        (new CodeAnalyzer($css, 'inline <style>'))->analyze($this->r);
    }

    /** The file name an asset is worth being called in a report. */
    private static function assetLabel(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $base = basename($path);
        return $base !== '' ? $base : $url;
    }

    /**
     * Evidence for something quoted out of the page, shown where it was found.
     *
     * Falls back to a contextless excerpt when nothing holds the literal —
     * copy is read from a whitespace-normalised, tag-stripped copy of the
     * document, and a phrase that spans two elements exists in that reading
     * and nowhere in the markup.
     */
    private function locate(string $needle, int $count = 1): Excerpt
    {
        foreach ($this->documents() as $doc) {
            $ex = $doc->find($needle, $count);
            if ($ex->line !== null) {
                return $ex;
            }
        }
        return Excerpt::plain($needle, $count);
    }

    /**
     * Evidence for a pattern, in the first document that carries it, counted
     * across all of them.
     */
    private function locatePattern(string $pattern, string $text = ''): Excerpt
    {
        $found = null;
        $total = 0;
        foreach ($this->documents() as $doc) {
            $total += $doc->occurrences($pattern);
            if ($found === null) {
                $found = $doc->match($pattern, $text);
            }
        }
        if ($found === null) {
            return Excerpt::plain($text, max(1, $total));
        }
        return $found->withCount(max(1, $total));
    }

    /** How many times a pattern fires across everything that was read. */
    private function countPattern(string $pattern): int
    {
        $n = 0;
        foreach ($this->documents() as $doc) {
            $n += $doc->occurrences($pattern);
        }
        return $n;
    }

    /** A header, lowercased, or '' when the fetch did not record one. */
    private function header(string $name): string
    {
        return isset($this->headers[$name]) ? $this->headers[$name] : '';
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
     *
     * Stylesheets are read for the same reason scripts are, and were the
     * obvious gap: a served CSS file that no minifier has touched is the file
     * exactly as somebody wrote it, comments and declaration order and all.
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
            if (!preg_match('~\.(?:js|css)(?:[?#]|$)~i', $url)) continue;

            $readable++;
            $sub = new CodeAnalyzer($body, self::assetLabel($url));
            $sub->analyze($this->r); // signals merge into the same report
        }

        // The page's own <style> blocks, which are a stylesheet that never got
        // a file. On a single-file page they are the entire stylesheet.
        $this->checkInlineStyles();

        $this->r->stat('assetsReadable', $readable);
        $this->r->stat('assetsMinified', $minified);

        if ($minified > 0 && $readable === 0) {
            $this->flagBuildPipeline(array(
                sprintf('%d bundled asset(s), minified and content-hashed', $minified),
                'minification normalises exactly the signal style-based detection depends on, so the scripts were not read',
            ));
            if (!$this->maps) {
                $this->r->note('The page\'s scripts are bundled and minified, and no source map was offered, so no code-level reading was possible. This is the ordinary output of a build step and says nothing either way about who wrote the source.');
            }
        }
    }
}
