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
        $this->checkTypeAndIcons();
        $this->checkComponentDefaults();
        $this->checkSymmetry();
        $this->checkContent();
        $this->checkHumanMarks();
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
                $hits[] = 'the document title is still "' . $title . '"';
            }
        }

        if (preg_match('~<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']{0,200})["\']~i', $head, $m)) {
            $desc = trim($m[1]);
            if (preg_match('~^(?:generated by create next app|web site created using create-react-app|vite\s*\+|lovable generated project|created with bolt|my v0 project|a new .{0,20}app|astro description)~i', $desc)) {
                $hits[] = 'the description is the one the scaffold shipped with: "' . Report::excerpt($desc, 70) . '"';
            }
        }

        if (preg_match('~<link[^>]+href=["\']/?(?:vite|next|nuxt|astro|svelte)\.svg["\']~i', $head)) {
            $hits[] = 'the favicon is still the framework\'s own logo';
        }
        if (preg_match('~You need to enable JavaScript to run this app~i', $head)) {
            $hits[] = 'the create-react-app noscript block is untouched';
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
            $this->r->flag('st.untouched_scaffold', array_merge($hits, $supporting));
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
        foreach ($parts as $label => $re) {
            if (preg_match($re, $hay)) {
                $found[] = $label;
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
            $evidence = array(implode(', ', array_slice($found, 0, 6)) . ' — all present, all at their defaults');
            if (count($kitPaths) >= 5) {
                $evidence[] = sprintf('%d untouched component-kit files (%s…) named in the source map',
                    count($kitPaths), implode(', ', array_slice(array_unique($kitPaths), 0, 4)));
            }
            $this->generatedShell = true;
            $this->r->flag('st.generated_stack', $evidence);
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
        if (preg_match_all('~\b(?:bg|text|from|via|to|border|ring)-(?:indigo|violet|purple|fuchsia)-\d{2,3}\b~i', $this->markup(), $m)) {
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

        if (preg_match_all('~class=["\'][^"\']*\brounded-2xl\b[^"\']*\bshadow-(?:lg|xl|md)\b[^"\']*["\']~i', $this->markup(), $m)) {
            if (count($m[0]) >= 2) {
                $evidence[] = sprintf('%d cards sharing the rounded-2xl + shadow default', count($m[0]));
            }
        }
        if (preg_match_all('~\brounded-(?:2xl|3xl|full)\b~i', $this->markup()) >= 8) {
            $evidence[] = 'every surface on the page shares one border radius';
        }
        if (preg_match('~class=["\'][^"\']*\brounded-2xl\b[^"\']*["\'][^>]*>\s*<div[^>]+class=["\'][^"\']*\brounded-(?:xl|2xl)\b~i', $this->markup())) {
            $evidence[] = 'cards nested inside cards';
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
                    sprintf('py-%s on %d of %d spaced sections', $top, $counts[$top], count($m[1])),
                ));
            }
        }

        $css = $this->scanBlob();

        // A heading painted with a clipped gradient rather than a colour.
        if (preg_match('~bg-clip-text[^"\']*text-transparent|text-transparent[^"\']*bg-clip-text~i', $this->markup())
            || preg_match('~background-clip:\s*text~i', $css)) {
            $this->r->flag('ae.gradient_text', array('a headline filled with a background gradient instead of a colour'));
        }

        // Frosted glass on everything.
        $blur = preg_match_all('~backdrop-blur(?:-\w+)?\b~i', $this->markup())
              + preg_match_all('~backdrop-filter:\s*blur~i', $css);
        if ($blur >= 3) {
            $this->r->flag('ae.glassmorphism', array(
                sprintf('%d frosted-glass surfaces on one page', $blur),
            ));
        }

        // The little badge sitting over the headline.
        $announce = '~(?:introducing|announcing|new\b|now (?:with|in|available)|just (?:launched|shipped)|v\d|coming soon|beta|early access|backed by|✨|🎉|🚀)~iu';
        if (preg_match('~<(?:div|span|a|p)[^>]*class=["\'][^"\']*\b(?:rounded-full|pill|badge|chip)\b[^"\']*["\'][^>]*>\s*(?:<[^>]+>\s*)*[^<]{3,60}</~i', $this->html, $m)
            && preg_match($announce, $m[0])) {
            $this->r->flag('ae.hero_pill', array(Report::excerpt(strip_tags($m[0]), 90)));
        } elseif (preg_match('~class=["\'][^"\']*\brounded-full\b[^"\']*\b(?:border|bg-|px-)~i', $this->markup())) {
            // Client-rendered: the pill's classes and its caption are in the
            // bundle rather than the document, so they are matched apart.
            foreach (explode("\n", (string) $this->bundleCopy) as $line) {
                if (strlen($line) <= 60 && preg_match($announce, $line)) {
                    $this->r->flag('ae.hero_pill', array(Report::excerpt($line, 90)));
                    break;
                }
            }
        }

        // A cue telling you to do the thing you already know how to do.
        if (preg_match('~class=["\'][^"\']*\b(?:scroll-(?:indicator|down|hint|cue)|mouse-scroll|animate-bounce)\b~i', $this->markup())
            || preg_match('~(?:scroll (?:down|to explore)|explore more)\s*(?:<|$)~i', $this->copy())
            || preg_match('~<svg[^>]*class=["\'][^"\']*animate-bounce~i', $this->html)) {
            $this->r->flag('ae.scroll_indicator', array('an animated scroll cue sits under the opening section'));
        }

        // Soft blurred colour floating behind the hero.
        $orbs = preg_match_all('~class=["\'][^"\']*\b(?:blur-(?:2xl|3xl)|rounded-full)\b[^"\']*\b(?:absolute|fixed)\b[^"\']*["\']~i', $this->markup())
              + preg_match_all('~class=["\'][^"\']*\b(?:absolute|fixed)\b[^"\']*\bblur-(?:2xl|3xl)\b[^"\']*["\']~i', $this->markup())
              + preg_match_all('~filter:\s*blur\((?:6[0-9]|[7-9][0-9]|\d{3,})px\)~i', $css);
        if ($orbs >= 2) {
            $this->r->flag('ae.glow_orbs', array(
                sprintf('%d heavily blurred shapes positioned behind the content', $orbs),
            ));
        }

        // The bento grid.
        $spans = preg_match_all('~\b(?:col|row)-span-[2-6]\b~i', $this->markup());
        if ($spans >= 4 && preg_match('~\bgrid-cols-(?:3|4|6|12)\b~i', $this->markup())) {
            $this->r->flag('ae.bento_grid', array(
                sprintf('%d unequal tile spans inside one grid', $spans),
            ));
        }

        // An endless strip of logos.
        if (preg_match('~class=["\'][^"\']*\b(?:marquee|animate-marquee|logo-?(?:scroll|ticker|cloud|strip)|infinite-scroll)\b~i', $this->markup())
            || (preg_match('~@keyframes\s+(?:marquee|scroll|ticker)~i', $css)
                && preg_match('~(?:trusted by|as seen (?:in|on)|used by|powering)~i', $this->copy()))) {
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
        if (!$this->r->has('ae.floating_nav')
            && preg_match('~class=["\'][^"\']*\b(?:fixed|sticky)\b[^"\']*\bbackdrop-blur[^"\']*\b(?:rounded-full|rounded-2xl|inset-x-|mx-auto)~i', $this->markup(), $m)) {
            $this->r->flag('ae.floating_nav', array(
                'a fixed, blurred, rounded bar built in the bundle: ' . Report::excerpt($m[0], 80),
            ));
        }

        // The coloured left-border card.
        if (preg_match('~border-l-(?:2|4|\[\d)~i', $this->markup()) && preg_match('~\bborder-l-\d?\s*[^"\']*\bborder-(?:indigo|violet|purple|blue|emerald|amber)-\d{3}~i', $this->markup())) {
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
                implode(' ', array_slice($iconEmoji, 0, 8)) . ' — each alone in its own element, where an icon would go',
            ));
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
            if (stripos($text, $n) !== false) $hits[] = $n;
        }
        $titles = array();
        foreach (array('Verified User', 'Head of Operations', 'Product Manager at', 'CEO, Company',
                       'Satisfied Customer', 'Happy Customer', 'Founder & CEO',
                       'Client satisfait', 'Utilisateur vérifié', 'Cliente satisfecho') as $t) {
            if (stripos($text, $t) !== false) $titles[] = $t;
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
            if (preg_match('~' . $c . '~iu', $text, $m)) $found[] = $m[0];
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
                       'insert .{3,20} here', 'sample text', 'company name here',
                       'texte ici', 'votre texte', 'contenu à venir', 'bientôt disponible',
                       'texto de ejemplo', 'próximamente', 'ihr text hier', 'demnächst verfügbar') as $p) {
            if (preg_match('~' . $p . '~iu', $text, $m)) $ph[] = $m[0];
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
        if (preg_match_all('~\b(\d{1,3}(?:,\d{3})*|\d+)\s*(?:k|K|M|m)?\+\s*(?:happy\s+)?(?:users?|customers?|developers?|teams?|downloads?|companies|businesses|creators?|members?|clients?|utilisateurs?|usuarios?|kunden|nutzer)~iu', $text, $m)) {
            foreach ($m[0] as $hit) $stats[] = trim($hit);
        }
        if (preg_match_all('~\b(?:99\.9+|9[5-9])\s*%\s*(?:uptime|accuracy|satisfaction|faster|reliable|de satisfaction|disponibilité|zufriedenheit)~iu', $text, $m)) {
            foreach ($m[0] as $hit) $stats[] = trim($hit);
        }
        if (preg_match_all('~\b\d{1,3}x\s+(?:faster|better|more|cheaper|productive|plus rapide|más rápido|schneller)~iu', $text, $m)) {
            foreach ($m[0] as $hit) $stats[] = trim($hit);
        }
        // Attribution defuses it: a sourced number is a claim someone stands behind.
        $sourced = preg_match('~\b(?:source|according to|survey|report|study|measured|benchmark|selon|étude|estudio|laut|studie)\b~iu', $text);
        if (count($stats) >= 2 && !$sourced) {
            $this->r->flag('ct.stat_inflation', $stats);
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
                $found[] = $hit[0];
            }
        }
        if ($found) {
            $this->r->flag('hu.typos', $found);
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
                    sprintf('the footer still reads %d', $latest),
                ));
            }
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
