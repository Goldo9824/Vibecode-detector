#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * The whole test suite. No PHPUnit, because the deployment target has no
 * Composer and a test runner that cannot run on the same box as the code is
 * a test runner nobody runs.
 *
 *     php tests/run.php
 *
 * Exit code is the number of failures.
 */

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Fetcher.php';
require_once dirname(__DIR__) . '/lib/Certificate.php';
require_once dirname(__DIR__) . '/lib/Crawler.php';

$passed = 0;
$failed = 0;
$group = '';

function group(string $name): void
{
    global $group;
    $group = $name;
    echo "\n\033[1m", $name, "\033[0m\n";
}

function ok(bool $condition, string $what, string $extra = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  \033[32m✓\033[0m ", $what, "\n";
    } else {
        $failed++;
        echo "  \033[31m✗ ", $what, "\033[0m";
        echo $extra !== '' ? "  — {$extra}\n" : "\n";
    }
}

function between(int $value, int $lo, int $hi, string $what): void
{
    ok($value >= $lo && $value <= $hi, $what, "got {$value}, wanted {$lo}–{$hi}");
}

function fixture(string $name): string
{
    return (string) file_get_contents(__DIR__ . '/fixtures/' . $name);
}

// ---------------------------------------------------------------- site: AI

group('A generated landing page');

$site = new SiteAnalyzer('https://flowsync.example.com/', fixture('ai-landing.html'));
$r = $site->analyze();
$a = $r->toArray();

between($a['score'], 70, 97, 'scores in the AI band');
ok($r->has('st.section_comments'), 'catches the <!-- Hero --> navigational comments');
ok($r->has('ae.indigo'), 'catches the indigo-to-violet palette');
ok($r->has('ae.inter_font'), 'catches Inter');
ok($r->has('ct.emoji_icons'), 'catches emoji standing in for icons');
ok($r->has('ct.generic_names'), 'catches the statistically generic testimonials');
ok($r->has('ct.marketing_cliche'), 'catches the house marketing voice');
ok($r->has('ct.dead_links'), 'catches a nav where everything is href="#"');
ok($r->has('ae.shadcn_defaults'), 'catches the untouched card defaults');
ok($r->countAi(true) >= 4, 'four or more converging non-aesthetic signals');
ok($a['confidence']['level'] === 'moderate', 'reports moderate confidence, not high', $a['confidence']['level']);

// ------------------------------------------------------------- site: human

group('A hand-built page');

$site = new SiteAnalyzer('https://boulangerie-marchand.fr/', fixture('human-site.html'));
$r = $site->analyze();
$a = $r->toArray();

between($a['score'], 3, 40, 'scores in the human band');
ok($r->has('hu.legacy_stack'), 'notices jQuery and table markup');
ok($r->has('hu.long_tail_copy'), 'notices prices, hours and an address');
ok($r->has('hu.real_media'), 'notices real photography in mixed formats');
ok(!$r->has('ae.indigo'), 'does not invent a palette signal');
ok(!$r->hasFingerprint(), 'no false platform fingerprint');

// -------------------------------------------------------- site: fingerprint

group('A page carrying a builder fingerprint');

$html = '<!DOCTYPE html><html><head><title>x</title></head><body>'
      . '<div id="root"></div><script src="https://cdn.gpteng.co/gptengineer.js"></script>'
      . '<img src="/lovable-uploads/abc.png"></body></html>';
$r = (new SiteAnalyzer('https://thing.lovable.app/', $html))->analyze();
$a = $r->toArray();

ok($r->hasFingerprint(), 'identifies the builder');
ok($a['score'] >= 90, 'a fingerprint dominates the score', (string) $a['score']);
ok($a['verdict']['code'] === 'builder_identified', 'verdict names it as builder-built');
ok($a['confidence']['level'] === 'high', 'confidence is high for a positive ID');
ok($a['score'] <= 97, 'still never claims certainty', (string) $a['score']);

// --------------------------------------------------- site: newer AI tells

group('Newer generated-page tells');

$modern = '<!DOCTYPE html><html lang="en"><head><title>Nimbus</title></head><body>'
    . '<h1 class="bg-gradient-to-r from-indigo-500 to-violet-500 bg-clip-text text-transparent">Nimbus</h1>'
    . '<div class="backdrop-blur-lg bg-white/10">a</div>'
    . '<div class="backdrop-blur-md bg-white/10">b</div>'
    . '<div class="backdrop-blur-sm bg-white/10">c</div>'
    . '<p>Join 10,000+ developers shipping with Nimbus. 99.9% uptime. 10x faster builds.</p>'
    . '<section><div>Starter $9 per month</div><div>Pro $29 per month <span>Most popular</span></div>'
    . '<div>Scale $99 per month</div></section>'
    . str_repeat('<p>A sentence of ordinary body copy for length.</p>', 20)
    . '</body></html>';
$r = (new SiteAnalyzer('https://nimbus.example.com/', $modern))->analyze();

ok($r->has('ae.gradient_text'), 'catches the gradient-filled headline');
ok($r->has('ae.glassmorphism'), 'catches frosted-glass panels');
ok($r->has('ct.stat_inflation'), 'catches round unsourced statistics');
ok($r->has('ct.pricing_three'), 'catches three tiers with the middle one starred');

// A sourced number is a claim someone stands behind, so it should not fire.
$sourced = str_replace('99.9% uptime.', '99.9% uptime, according to our 2026 status report.', $modern);
$r2 = (new SiteAnalyzer('https://nimbus.example.com/', $sourced))->analyze();
ok(!$r2->has('ct.stat_inflation'), 'an attributed statistic does not fire the signal');

$builder = '<!DOCTYPE html><html><head><title>x</title></head><body>'
    . '<script src="https://cdn.databutton.com/app.js"></script></body></html>';
$r3 = (new SiteAnalyzer('https://x.example.com/', $builder))->analyze();
ok($r3->has('fp.builder_other'), 'identifies a long-tail builder by name');
ok(strpos(implode(' ', $r3->signals()[0]->evidence), 'Databutton') !== false,
   'names the specific builder in the evidence');

// ------------------------------------------------- site: newer human tells

group('Newer hand-written tells');

$operated = '<!DOCTYPE html><html lang="en"><head><title>Shop</title>'
    . '<script src="https://www.googletagmanager.com/gtm.js"></script></head><body>'
    . '<div id="cookie-consent">We use cookies</div>'
    . '<a href="/privacy-policy">Privacy</a><a href="/terms">Terms</a>'
    . '<p>Please recieve your order within 3 days. Delivery is seperate.</p>'
    . '<footer>&copy; 2019 Shop Ltd</footer>'
    . str_repeat('<p>Ordinary body copy that goes on for a while here.</p>', 20)
    . '</body></html>';
$r = (new SiteAnalyzer('https://shop.example.com/', $operated))->analyze();

ok($r->has('hu.typos'), 'catches misspellings in the copy');
ok($r->has('hu.operational_stack'), 'catches cookie banner, legal pages and analytics');
ok($r->has('hu.dated_copyright'), 'catches a footer stuck in a past year');

$french = str_replace('lang="en"', 'lang="fr"', $operated);
$rf = (new SiteAnalyzer('https://shop.example.fr/', $french))->analyze();
ok(!$rf->has('hu.typos'), 'the English misspelling list is not applied to other languages');

$current = str_replace('&copy; 2019', '&copy; ' . gmdate('Y'), $operated);
$rc = (new SiteAnalyzer('https://shop.example.com/', $current))->analyze();
ok(!$rc->has('hu.dated_copyright'), 'a current copyright year does not fire');

// ---------------------------------------------------------------- code: AI

group('Generated JavaScript');

$r = (new CodeAnalyzer(fixture('ai-code.js')))->analyze();
$a = $r->toArray();

between($a['score'], 72, 97, 'scores in the AI band');
ok($r->has('cd.what_comments'), 'catches comments that restate the next line');
ok($r->has('cd.section_header_comments'), 'catches ===== banner comments =====');
ok($r->has('cd.swallowed_errors'), 'catches catch blocks that only console.error');
ok($r->has('cd.verbose_names'), 'catches currentLoggedInUserRecordValue');
ok($r->has('cd.helper_pileup'), 'catches the Helper/Manager/Handler pile-up');
ok($r->has('cd.formal_errors'), 'catches formal, complete error messages');
ok($r->has('se.placeholder_secret'), 'catches the placeholder JWT secret');
ok($r->has('st.docblock_on_everything'), 'catches a docblock on every function');
ok($r->has('st.import_block_sorted'), 'catches the perfectly ordered import block');
ok($r->has('st.dead_code'), 'catches classes and imports wired to nothing');
ok($r->has('se.weak_auth'), 'catches a token signed with no expiry');

// The evidence excerpts are the only place user input is echoed back, so that
// is where redaction has to hold. Catalogue copy quoting the pattern is fine.
$leaked = array();
foreach ($r->signals() as $s) {
    foreach ($s->evidence as $line) {
        if (stripos($line, 'your-secret-key') !== false) $leaked[] = $s->id;
    }
}
ok(!$leaked, 'never echoes a secret-shaped literal back in the evidence', implode(', ', $leaked));

// ------------------------------------------------------------- code: human

group('Hand-written JavaScript');

$r = (new CodeAnalyzer(fixture('human-code.js')))->analyze();
$a = $r->toArray();

between($a['score'], 3, 42, 'scores in the human band');
ok($r->has('hu.why_comments'), 'recognises comments carrying outside context');
ok($r->has('hu.ticket_refs'), 'recognises the JIRA reference');
ok($r->has('hu.informal'), 'recognises the XXX aside');
ok($r->has('hu.commented_code'), 'recognises commented-out code');
ok($r->has('hu.inconsistent_format'), 'recognises mixed tabs and spaces');
ok(!$r->has('cd.what_comments'), 'does not mistake why-comments for what-comments');

// --------------------------------------------------- code: newer AI tells

group('Newer generated-code tells');

$modernCode = <<<'JS'
/**
 * userService.js
 *
 * This module handles all user-related operations and provides a clean
 * interface for the rest of the application to consume.
 */
export function loadUser(input) {
  const name = input?.profile?.name ?? 'Unknown';
  const email = input?.profile?.email ?? '';
  const role = input?.meta?.role ?? 'user';
  const org = input?.meta?.org?.name ?? null;
  const tier = input?.billing?.tier ?? 'free';
  const seats = input?.billing?.seats ?? 1;
  const flags = input?.flags?.list ?? [];
  const region = input?.region?.code ?? 'eu';

  if (!name) { console.log('❌ No name'); } else { console.log('✅ Name resolved'); }
  if (!email) { console.log('❌ No email'); } else { console.log('✅ Email resolved'); }
  if (!role) { console.log('⚠️ No role'); } else { console.log('🎉 Role resolved'); }
  if (!org) { console.log('No org'); } else { console.log('Org resolved'); }
  if (!tier) { console.log('No tier'); } else { console.log('Tier resolved'); }

  return { name, email, role, org, tier, seats, flags, region };
}
JS;

$r = (new CodeAnalyzer($modernCode))->analyze();

ok($r->has('cd.emoji_logging'), 'catches emoji in log output');
ok($r->has('cd.defensive_chaining'), 'catches optional chaining on everything');
ok($r->has('cd.over_symmetric_branches'), 'catches an else for every if');
ok($r->has('st.file_header_block'), 'catches the explanatory file-header block');

// ------------------------------------------------------------- whole site

group('Whole-site survey');

/**
 * Build a set of pages without going near the network. The crawler's own
 * fetching is covered separately; what matters here is what the survey makes of
 * several pages once it has them.
 *
 * @return array<int,array<string,mixed>>
 */
function pageSet(array $bodies): array
{
    $out = array();
    foreach ($bodies as $path => $body) {
        $out[] = array('url' => 'https://example.com' . $path, 'body' => $body, 'assets' => array(), 'status' => 200);
    }
    return $out;
}

// One template, different words: the shape every page shares is the finding.
$stamped = array();
foreach (array('/', '/about', '/pricing', '/contact', '/features') as $i => $path) {
    $stamped[$path] = '<!DOCTYPE html><html lang="en"><head><title>Page</title></head><body>'
        . '<nav class="nav flex items-center"><a href="/">Home</a><a href="/about">About</a></nav>'
        . '<section class="hero py-24 text-center"><h1 class="text-6xl font-bold">Heading ' . $i . '</h1></section>'
        . '<section class="grid grid-cols-3 gap-8">'
        . '<div class="rounded-2xl shadow-lg p-6"><h3>One</h3><p>' . str_repeat('word' . $i . ' ', 22) . '</p></div>'
        . '<div class="rounded-2xl shadow-lg p-6"><h3>Two</h3><p>' . str_repeat('word' . $i . ' ', 22) . '</p></div>'
        . '<div class="rounded-2xl shadow-lg p-6"><h3>Three</h3><p>' . str_repeat('word' . $i . ' ', 22) . '</p></div>'
        . '</section><footer class="py-24"><p>&copy; 2026</p></footer></body></html>';
}
$r = (new SiteSurvey('https://example.com/', pageSet($stamped)))->analyze();
$a = $r->toArray();

ok($r->has('xs.template_uniformity'), 'catches five pages stamped from one template');
ok($r->has('xs.uniform_page_size'), 'catches every page being the same weight');
ok(!$r->has('xs.varied_pages'), 'does not also claim the pages vary');
ok(isset($a['stats']['templateSimilarity']) && $a['stats']['templateSimilarity'] > 0.9,
   'reports the structural similarity it measured',
   (string) ($a['stats']['templateSimilarity'] ?? 'missing'));
ok(count($a['stats']['perPage']) === 5, 'reports every page it read');
between($a['score'], 56, 97, 'a stamped site scores above the line');

// A site that grew: different eras, different shapes, one page with real writing.
$grown = array(
    '/' => '<!DOCTYPE html><html lang="en"><head><title>Home</title></head><body><div id="wrapper">'
         . '<table><tr><td>Menu</td></tr></table><h1>Welcome</h1><p>' . str_repeat('sentence about the shop ', 30) . '</p>'
         . '</div></body></html>',
    '/history' => '<!DOCTYPE html><html lang="en"><head><title>History</title></head><body><article class="post">'
         . '<h1>Our history</h1>' . str_repeat('<p>' . str_repeat('a genuinely long paragraph of real writing ', 20) . '</p>', 14)
         . '</article></body></html>',
    '/contact' => '<!DOCTYPE html><html lang="en"><head><title>Contact</title></head><body>'
         . '<section class="contact"><h2>Contact</h2><p>14 rue des Capucins, 69001 Lyon. 04 78 28 41 03.</p></section></body></html>',
    '/gallery' => '<!DOCTYPE html><html lang="en"><head><title>Gallery</title></head><body><ul class="gal">'
         . str_repeat('<li><img src="/p.jpg" alt="a"></li>', 18) . '</ul></body></html>',
);
$r = (new SiteSurvey('https://example.com/', pageSet($grown)))->analyze();
$a = $r->toArray();

ok($r->has('xs.style_drift'), 'catches pages built in different eras');
ok($r->has('xs.varied_pages'), 'catches pages that genuinely differ in shape');
ok($r->has('xs.deep_content'), 'catches the page somebody actually wrote');
ok(!$r->has('xs.template_uniformity'), 'does not call a grown site a template');
between($a['score'], 3, 45, 'a grown site scores in the human band');

// Corroboration: a signal on one page out of six is not a site property.
$mixedPages = array();
foreach (range(1, 6) as $i) {
    $body = '<!DOCTYPE html><html lang="en"><head><title>P</title></head><body>'
          . '<div class="a"><p>' . str_repeat('ordinary body copy here ', 30 + $i * 9) . '</p></div>';
    if ($i === 1) {
        $body .= '<p>Lorem ipsum dolor sit amet</p>'; // placeholder copy, one page only
    }
    $mixedPages['/p' . $i] = $body . '</body></html>';
}
$r = (new SiteSurvey('https://example.com/', pageSet($mixedPages)))->analyze();
ok(!$r->has('ct.placeholder_copy'), 'a signal on one page in six is not counted site-wide');

$allPages = array();
foreach (range(1, 6) as $i) {
    $allPages['/q' . $i] = '<!DOCTYPE html><html lang="en"><head><title>P</title></head><body>'
        . '<div class="a"><p>Lorem ipsum dolor sit amet, ' . str_repeat('filler ', 30 + $i * 9) . '</p></div></body></html>';
}
$r = (new SiteSurvey('https://example.com/', pageSet($allPages)))->analyze();
ok($r->has('ct.placeholder_copy'), 'the same signal on every page is counted');
$ev = '';
foreach ($r->signals() as $s) {
    if ($s->id === 'ct.placeholder_copy') $ev = implode(' ', $s->evidence);
}
ok(strpos($ev, 'of 6 pages') !== false, 'and says how many pages carried it', $ev);

// The bar has to rise with the crawl. Two pages out of six is a pattern; two
// out of fifty is four per cent and indistinguishable from noise.
ok(SiteSurvey::requiredPages(2) === 2, 'two pages: both must carry it');
ok(SiteSurvey::requiredPages(6) === 2, 'six pages: two is still the floor');
ok(SiteSurvey::requiredPages(20) === 5, 'twenty pages: a quarter of them');
ok(SiteSurvey::requiredPages(50) === 13, 'fifty pages: thirteen, not two',
   (string) SiteSurvey::requiredPages(50));

$wide = array();
for ($i = 1; $i <= 20; $i++) {
    $body = '<!DOCTYPE html><html lang="en"><head><title>P</title></head><body>'
          . '<div class="a"><p>' . str_repeat('ordinary body copy here ', 30 + $i * 3) . '</p></div>';
    if ($i <= 3) {
        $body .= '<p>Lorem ipsum dolor sit amet</p>'; // three pages out of twenty
    }
    $wide['/w' . $i] = $body . '</body></html>';
}
$r = (new SiteSurvey('https://example.com/', pageSet($wide)))->analyze();
ok(!$r->has('ct.placeholder_copy'),
   'three pages in twenty is below the scaled bar and does not count');

// A fingerprint only has to be true once.
$onePrint = array(
    '/'  => '<!DOCTYPE html><html><head><title>a</title></head><body><p>' . str_repeat('x ', 60) . '</p></body></html>',
    '/b' => '<!DOCTYPE html><html><head><title>b</title></head><body><p>' . str_repeat('y ', 60) . '</p></body></html>',
    '/c' => '<!DOCTYPE html><html><head><title>c</title></head><body><script src="https://cdn.gpteng.co/gptengineer.js"></script></body></html>',
    '/d' => '<!DOCTYPE html><html><head><title>d</title></head><body><p>' . str_repeat('z ', 60) . '</p></body></html>',
);
$r = (new SiteSurvey('https://example.com/', pageSet($onePrint)))->analyze();
ok($r->hasFingerprint(), 'a fingerprint on a single page still identifies the site');
ok($r->toArray()['verdict']['code'] === 'builder_identified', 'and carries the verdict');

// A one-page crawl degrades to the single-page reading rather than inventing.
$single = (new SiteSurvey('https://example.com/', pageSet(array('/' => $stamped['/']))))->analyze();
ok(!$single->has('xs.template_uniformity'), 'one page cannot support a site-wide comparison');
ok(!$single->has('xs.uniform_page_size'), 'nor a size comparison');

// ------------------------------------------------------- crawler mechanics

group('Crawler mechanics');

$c = new Crawler();
$abs = new ReflectionMethod('Crawler', 'absolute');
$abs->setAccessible(true);
$canon = new ReflectionMethod('Crawler', 'canonical');
$canon->setAccessible(true);
$links = new ReflectionMethod('Crawler', 'linksFrom');
$links->setAccessible(true);

ok($abs->invoke($c, '/about', 'https://example.com/x/y') === 'https://example.com/about', 'root-relative links resolve');
ok($abs->invoke($c, 'b.html', 'https://example.com/x/y.html') === 'https://example.com/x/b.html', 'document-relative links resolve');
ok($abs->invoke($c, '../up', 'https://example.com/x/y/z.html') === 'https://example.com/x/up', 'parent traversal resolves');
ok($abs->invoke($c, 'https://example.com/p#frag', 'https://example.com/') === 'https://example.com/p', 'fragments are stripped');
ok($abs->invoke($c, '#top', 'https://example.com/') === null, 'a bare fragment is not a page');
ok($abs->invoke($c, 'javascript:void(0)', 'https://example.com/') === null, 'javascript hrefs are refused');
ok($abs->invoke($c, 'mailto:a@b.c', 'https://example.com/') === null, 'mailto is refused');

ok($canon->invoke($c, 'https://example.com/a/') === $canon->invoke($c, 'https://example.com/a'),
   'a trailing slash is the same page');
ok($canon->invoke($c, 'https://example.com/index.html') === $canon->invoke($c, 'https://example.com/'),
   'index.html is the same page as the root');
ok($canon->invoke($c, 'https://EXAMPLE.com/A') === $canon->invoke($c, 'https://example.com/A'),
   'the host is case-insensitive');

$html = '<a href="/ok">a</a><a href="/img.png">b</a><a href="/doc.pdf">c</a>'
      . '<a href="https://elsewhere.example/x">d</a><a href="/logout">e</a><a href="/fine/page">f</a>';
$found = $links->invoke($c, $html, 'https://example.com/', 'https://example.com');
ok(in_array('https://example.com/ok', $found, true), 'follows an ordinary link');
ok(in_array('https://example.com/fine/page', $found, true), 'follows a nested link');
ok(!in_array('https://example.com/img.png', $found, true), 'skips images');
ok(!in_array('https://example.com/doc.pdf', $found, true), 'skips documents that are not pages');
ok(!in_array('https://elsewhere.example/x', $found, true), 'never leaves the origin');
ok(!in_array('https://example.com/logout', $found, true), 'does not click things that do things');

// robots.txt is parsed for the wildcard group and ours, and obeyed.
$robots = new ReflectionMethod('Crawler', 'allowedByRobots');
$robots->setAccessible(true);
$disallow = new ReflectionProperty('Crawler', 'disallow');
$disallow->setAccessible(true);
$disallow->setValue($c, array('/private', '/admin'));

ok($robots->invoke($c, 'https://example.com/public', 'https://example.com') === true, 'allowed paths are crawled');
ok($robots->invoke($c, 'https://example.com/private/x', 'https://example.com') === false, 'disallowed prefixes are obeyed');
ok($robots->invoke($c, 'https://example.com/admin', 'https://example.com') === false, 'an exact disallow is obeyed');

ok(Crawler::MAX_PAGES <= 50, 'the page ceiling stays bounded', (string) Crawler::MAX_PAGES);
ok(Crawler::BUDGET_SECONDS <= 25, 'the crawl leaves room to aggregate and render inside a 30s request',
   (string) Crawler::BUDGET_SECONDS);
// Fifty pages at the full transfer allowance would be 150 MB resident, against
// a PHP limit that is commonly 128 MB.
ok(Crawler::MAX_PAGES * Crawler::MAX_PAGE_BYTES <= 32 * 1024 * 1024,
   'a full crawl cannot exhaust a modest memory limit',
   sprintf('%d MB worst case', (Crawler::MAX_PAGES * Crawler::MAX_PAGE_BYTES) >> 20));

// ------------------------------------------------------- crawl, end to end

group('Crawling a whole site');

/**
 * A site in memory.
 *
 * The guard correctly refuses to fetch localhost, so a real server cannot be
 * used here — which is the guard working, not a gap. This substitutes the two
 * fetch methods and leaves every check in place for production.
 */
class StubFetcher extends Fetcher
{
    /** @var array<string,string> */
    public $site;
    /** @var int */
    public $calls = 0;

    public function __construct(array $site)
    {
        $this->site = $site;
    }

    public function fetchSite(string $url): array
    {
        $doc = $this->fetchDocument($url);
        $doc['assets'] = array();
        return $doc;
    }

    public function fetchDocument(string $url, int $maxBytes = 0, int $timeout = 0): array
    {
        $this->calls++;
        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($path === '') {
            $path = '/';
        }
        if (!isset($this->site[$path])) {
            throw new FetchError('404 ' . $path);
        }
        return array(
            'url' => $url, 'body' => $this->site[$path], 'status' => 200,
            'contentType' => 'text/html', 'assets' => array(),
        );
    }
}

function stubPage(string $name, string $extra = ''): string
{
    return '<!DOCTYPE html><html lang="en"><head><title>' . $name . '</title></head><body>'
        . '<nav><a href="/">Home</a><a href="/about">About</a><a href="/pricing">Pricing</a>'
        . '<a href="/contact">Contact</a><a href="/secret">Secret</a><a href="/about">About again</a>'
        . '<a href="https://elsewhere.example/away">Away</a></nav>'
        . '<main><h1>' . $name . '</h1><p>' . str_repeat($name . ' filler ', 40) . '</p></main>'
        . $extra . '</body></html>';
}

$site = array(
    '/robots.txt' => "User-agent: *\nDisallow: /secret\n",
    '/'           => stubPage('home'),
    '/about'      => stubPage('about'),
    '/pricing'    => stubPage('pricing'),
    '/contact'    => stubPage('contact'),
    '/secret'     => stubPage('secret'),
);

$stub = new StubFetcher($site);
$crawler = new Crawler($stub);
$started = microtime(true);
$pages = $crawler->crawl('https://example.com/');
$took = microtime(true) - $started;

ok(count($pages) === 4, 'reads every linked page it is allowed to', count($pages) . ' pages');
$urls = array();
foreach ($pages as $p) {
    $urls[] = (string) parse_url($p['url'], PHP_URL_PATH);
}
sort($urls);
ok($urls === array('/', '/about', '/contact', '/pricing'), 'and exactly those', implode(' ', $urls));
ok(!in_array('/secret', $urls, true), 'robots.txt Disallow is obeyed against a live crawl');
ok(strpos(implode(' ', $crawler->notes()), 'robots.txt') !== false, 'and the report says a page was skipped');
ok($took < 5.0, 'a small site crawls quickly', sprintf('%.2fs', $took));

// Repeated links must not turn into repeated fetches.
ok($stub->calls <= 6, 'each page is fetched once, plus robots.txt', $stub->calls . ' fetches');

// The page ceiling holds on a site with more pages than the limit.
$overshoot = Crawler::MAX_PAGES + 10;
$big = array('/robots.txt' => "User-agent: *\n");
$links = '';
for ($i = 1; $i <= $overshoot; $i++) {
    $links .= '<a href="/p' . $i . '">p' . $i . '</a>';
}
$big['/'] = '<!DOCTYPE html><html lang="en"><head><title>i</title></head><body>' . $links . '</body></html>';
for ($i = 1; $i <= $overshoot; $i++) {
    $big['/p' . $i] = stubPage('p' . $i);
}
$capped = new Crawler(new StubFetcher($big));
$got = $capped->crawl('https://example.com/');
ok(count($got) === Crawler::MAX_PAGES, 'never reads more pages than the ceiling', count($got) . ' pages');

// A site with no links still yields the entry page and says why.
$lonely = new Crawler(new StubFetcher(array(
    '/robots.txt' => "User-agent: *\n",
    '/' => '<!DOCTYPE html><html lang="en"><head><title>x</title></head><body><p>alone</p></body></html>',
)));
$one = $lonely->crawl('https://example.com/');
ok(count($one) === 1, 'a site with no links yields just the entry page');
ok(strpos(implode(' ', $lonely->notes()), 'Only one page') !== false, 'and explains what that means');

// A dead link mid-crawl must not abort the whole thing.
$broken = array(
    '/robots.txt' => "User-agent: *\n",
    '/' => '<!DOCTYPE html><html lang="en"><head><title>i</title></head><body>'
         . '<a href="/gone">gone</a><a href="/here">here</a></body></html>',
    '/here' => stubPage('here'),
);
$resilient = new Crawler(new StubFetcher($broken));
$survived = $resilient->crawl('https://example.com/');
ok(count($survived) === 2, 'a broken link is skipped rather than fatal', count($survived) . ' pages');

// And the whole path, crawl through survey, produces a scored report.
$full = (new SiteSurvey('https://example.com/', $pages, $crawler->notes()))->analyze()->toArray();
ok($full['mode'] === 'site', 'the survey reports site mode');
ok($full['stats']['pages'] === 4, 'and how many pages it read');
ok(count($full['stats']['perPage']) === 4, 'with a per-page breakdown for the UI');
ok(isset($full['score']) && $full['score'] >= 3 && $full['score'] <= 97, 'and a bounded score');

// ------------------------------------------------------------ git history

group('A generated repository history');

$t0 = strtotime('2026-03-04 14:02:00 UTC');
$genLog = '';
$genLog .= "a1b2c3d|" . $t0 . "|Sam Rivera|initial commit\n1840\t0\tsrc/app.jsx\n420\t0\tsrc/index.css\n96\t0\tpackage.json\n\n";
$genLog .= "b2c3d4e|" . ($t0 + 240) . "|Sam Rivera|add dashboard page with charts and responsive layout\n610\t2\tsrc/Dashboard.jsx\n\n";
$genLog .= "c3d4e5f|" . ($t0 + 520) . "|Sam Rivera|implement authentication flow with JWT and refresh tokens\n380\t4\tsrc/auth.js\n\n";
$genLog .= "d4e5f6a|" . ($t0 + 900) . "|Sam Rivera|fix typo\n1\t1\tsrc/app.jsx\n\n";
$genLog .= "e5f6a7b|" . ($t0 + 960) . "|Sam Rivera|fix missing import\n2\t0\tsrc/Dashboard.jsx\n\n";
$genLog .= "f6a7b8c|" . ($t0 + 1020) . "|Sam Rivera|fix build error\n1\t1\tsrc/auth.js\n\n";
$genLog .= "a7b8c9d|" . ($t0 + 1200) . "|Sam Rivera|update\n3\t1\tREADME.md\n";

$g = new GitAnalyzer($genLog);
$r = $g->analyze();
$a = $r->toArray();

ok(count($g->commits()) === 7, 'parses the documented format', (string) count($g->commits()));
ok($g->commits()[0]['subject'] === 'initial commit', 'orders oldest first');
between($a['score'], 70, 97, 'a generated history scores in the AI band');
ok($r->has('gh.big_bang'), 'catches the repository arriving fully formed');
ok($r->has('gh.velocity'), 'catches more code than anyone types');
ok($r->has('gh.micro_fix_trail'), 'catches the trail of one-line fixes');
ok($r->has('gh.prompt_messages'), 'catches commit messages that read like the prompt');
ok($r->has('gh.single_session'), 'catches a history that is one sitting');

$trend = $a['stats']['trend'] ?? null;
ok(is_array($trend) && count($trend) >= 1 && count($trend) <= 40,
   'reports a bucketed history trend for a log with line counts',
   is_array($trend) ? (string) count($trend) : gettype($trend));
$trendAdded = 0;
foreach ((array) $trend as $point) {
    $trendAdded += $point['added'];
}
$commitAdded = 0;
foreach ($g->commits() as $c) {
    $commitAdded += $c['added'];
}
ok($trendAdded === $commitAdded, 'the trend accounts for every line added across the log',
   "{$trendAdded} vs {$commitAdded}");

// ------------------------------------------------------- git: hand-written

group('A hand-written repository history');

$day = 86400;
$h0 = strtotime('2025-11-03 09:14:00 UTC');
$humanLog = '';
$humanLog .= "1111aaa|" . $h0 . "|Priya Nair|first pass at the importer\n82\t0\timporter.py\n\n";
$humanLog .= "2222bbb|" . ($h0 + 2 * $day) . "|Priya Nair|handle the empty-file case, closes #214\n24\t3\timporter.py\n\n";
$humanLog .= "3333ccc|" . ($h0 + 5 * $day) . "|Tom Whelan|Merge branch 'csv-quoting'\n0\t0\t\n\n";
$humanLog .= "4444ddd|" . ($h0 + 9 * $day) . "|Tom Whelan|why does excel do this\n11\t2\timporter.py\n\n";
$humanLog .= "5555eee|" . ($h0 + 16 * $day) . "|Priya Nair|Revert \"speed up the parse loop\"\n4\t40\timporter.py\n\n";
$humanLog .= "6666fff|" . ($h0 + 24 * $day) . "|Priya Nair|actually fix the encoding this time, see #231\n18\t6\timporter.py\n\n";
$humanLog .= "7777abc|" . ($h0 + 31 * $day) . "|Tom Whelan|oops, forgot the migration\n9\t0\tmigrate.sql\n\n";
$humanLog .= "8888def|" . ($h0 + 44 * $day) . "|Priya Nair|bump timeout after the Tuesday outage\n2\t2\tconfig.py\n";

$r = (new GitAnalyzer($humanLog))->analyze();
$a = $r->toArray();

between($a['score'], 3, 35, 'a hand-written history scores in the human band');
ok($r->has('gh.steady_cadence'), 'catches work spread across real time');
ok($r->has('gh.multiple_authors'), 'catches more than one committer');
ok($r->has('gh.merges_and_reverts'), 'catches merges and reverts');
ok($r->has('gh.issue_refs'), 'catches commits tied to tracked work');
ok($r->has('gh.human_mess'), 'catches visible frustration in the log');
ok(!$r->has('gh.big_bang'), 'does not cry big bang at an 82-line opening commit');

// ---------------------------------------------------- git: format handling

group('Git log format handling');

$default = "commit 9f8e7d6c5b4a3210\nAuthor: Dana Cole <dana@example.com>\nDate:   Tue Jan 14 11:02:03 2025 +0000\n\n    tidy up the config loader\n\n"
         . "commit 1a2b3c4d5e6f7080\nAuthor: Dana Cole <dana@example.com>\nDate:   Mon Jan 13 18:40:11 2025 +0000\n\n    add retry around the flaky upload\n";
$gd = new GitAnalyzer($default);
ok(count($gd->commits()) === 2, 'parses default git log output', (string) count($gd->commits()));
ok($gd->commits()[0]['author'] === 'Dana Cole', 'reads the author');
ok($gd->commits()[0]['subject'] === 'add retry around the flaky upload', 'reads the subject, oldest first');

$oneline = "3f21a9c tidy the readme\n9c8b7a6 add the licence\n1d2e3f4 initial commit\n";
$go = new GitAnalyzer($oneline);
ok(count($go->commits()) === 3, 'parses --oneline output', (string) count($go->commits()));
$ao = $go->analyze()->toArray();
ok(strpos(implode(' ', $ao['notes']), 'no timestamps') !== false,
   'says plainly that --oneline gives it little to work with');

$empty = (new GitAnalyzer("nothing resembling a git log at all\njust prose\n"))->analyze()->toArray();
ok($empty['confidence']['level'] === 'insufficient', 'unparseable input reports insufficient confidence');
ok(count($empty['signals']) === 0, 'unparseable input invents no signals');

// A log with no line counts must still read messages, and say what it lost.
$noStats = "aaa1111|" . $t0 . "|Sam|initial commit\nbbb2222|" . ($t0 + 300) . "|Sam|update\nccc3333|" . ($t0 + 600) . "|Sam|fix\nddd4444|" . ($t0 + 900) . "|Sam|changes\nfff5555|" . ($t0 + 1200) . "|Sam|wip\n";
$rn = (new GitAnalyzer($noStats))->analyze();
ok($rn->has('gh.generic_messages'), 'reads message quality without line counts');
ok(!$rn->has('gh.big_bang'), 'does not claim a big bang it cannot measure');
ok(strpos(implode(' ', $rn->toArray()['notes']), 'no line counts') !== false,
   'says the paste carried no line counts');
ok(!isset($rn->toArray()['stats']['trend']), 'no trend is reported without line counts to build one from');

// ------------------------------------------------- code: assistant traces

group('Traces the assistant left behind');

// Modelled on the specimens in ElectroLynx/guide_vibecode (MIT), which names
// these better than the original catalogue did.
$chatty = <<<'JS'
// Sure! Here's a robust user service for your app
// Let's fetch the users from the API
/**
 * Adds two numbers together.
 * @param {number} a - The first number to add.
 * @param {number} b - The second number to add.
 * @returns {number} The sum of a and b.
 */
function add(a, b) {
  return a + b;
}

async function fetchUsers() {
  // TODO: add your API endpoint here
  const res = await fetch("https://api.example.com/users");
  return res.json();
}
// Feel free to adjust the retry count to suit your needs
JS;
$r = (new CodeAnalyzer($chatty))->analyze();

ok($r->has('cd.assistant_chatter'), 'catches the assistant still talking');
ok($r->has('cd.placeholder_endpoint'), 'catches an endpoint that points nowhere');
ok($r->has('cd.tautological_params'), 'catches @param that restates the signature');

$ev = '';
foreach ($r->signals() as $s) {
    if ($s->id === 'cd.assistant_chatter') $ev = implode(' | ', $s->evidence);
}
ok(stripos($ev, "Here's a robust") !== false || stripos($ev, 'Sure!') !== false,
   'and shows which sentence gave it away', $ev);

// A file that merely talks about users must not trip it.
$innocent = <<<'JS'
// Retry twice: the upstream 502s under load and support gets the tickets.
// See INFRA-884 for the incident that prompted this.
async function fetchUsers(retries = 2) {
  const res = await fetch("/api/users");
  if (!res.ok && retries > 0) return fetchUsers(retries - 1);
  return res.json();
}
JS;
$ri = (new CodeAnalyzer($innocent))->analyze();
ok(!$ri->has('cd.assistant_chatter'), 'ordinary prose in a comment is not chatter');
ok(!$ri->has('cd.placeholder_endpoint'), 'a real relative endpoint is not a placeholder');

// Naming, ceremony, conventions.
$vague = <<<'JS'
function processData(data) {
  const result = [];
  for (const item of data) {
    const obj = { ...item, value: item.value };
    result.push(obj);
  }
  return result;
}
const createToggleFactory = () => ({
  createInitialState: () => ({ isEnabled: false }),
  toggle: (state) => ({ isEnabled: !state.isEnabled }),
});
const factory = createToggleFactory();
const user_name = user.name;
const user_id = user["id"];
const userId = user.id;
function renderProfile(profileData) { return profileData.name; }
JS;
$rv = (new CodeAnalyzer($vague))->analyze();

ok($rv->has('cd.generic_domain_names'), 'catches names that describe no business');
ok($rv->has('cd.ceremony_for_nothing'), 'catches a factory built to hold a boolean');
ok($rv->has('cd.mixed_conventions'), 'catches two conventions inside one file');

// Python is snake_case natively and must not be accused of mixing.
$py = "def fetch_user(user_id):\n    user_name = lookup(user_id)\n    total_count = count_all()\n"
    . "    return {'user_name': user_name, 'total_count': total_count}\n"
    . str_repeat("def helper_fn(a_value):\n    return a_value\n", 10);
$rp = (new CodeAnalyzer($py))->analyze();
ok(!$rp->has('cd.mixed_conventions'), 'snake_case Python is not a mixed convention');

// The guide's own "plutôt ça" counter-examples should stay clean.
$good = <<<'JS'
function invoicesWithTax(invoices) {
  return invoices.map((invoice) => ({ ...invoice, total: invoice.amount * 1.2 }));
}

// Banker's rounding: till totals have to match last year's ledger.
function roundMoney(amount) {
  return Math.round(amount * 20) / 20;
}
JS;
$rg = (new CodeAnalyzer($good))->analyze();
ok(!$rg->has('cd.generic_domain_names'), 'domain names are not flagged as vague');
ok(!$rg->has('cd.assistant_chatter'), 'a why-comment is not chatter');
between($rg->toArray()['score'], 3, 55, 'the guide\'s counter-example does not read as generated');

// ---------------------------------------------------- site: visual signs

group('Visual signs on a page');

$visual = '<!DOCTYPE html><html lang="en"><head><title>Nimbus</title></head><body>'
    . '<header class="fixed inset-x-0 top-6 mx-auto rounded-full backdrop-blur-lg border">'
    . '<a href="/">Nimbus</a></header>'
    . '<section class="relative">'
    . '<div class="absolute blur-3xl rounded-full bg-indigo-500"></div>'
    . '<div class="absolute blur-3xl rounded-full bg-violet-500"></div>'
    . '<span class="rounded-full px-3 py-1 border">✨ Introducing v2.0</span>'
    . '<h1 class="bg-gradient-to-r from-indigo-500 to-violet-500 bg-clip-text text-transparent">Ship faster</h1>'
    . '<div class="animate-bounce"><svg viewBox="0 0 24 24"></svg></div>'
    . '</section>'
    . '<section class="grid grid-cols-4 gap-4">'
    . '<div class="col-span-2">A</div><div class="row-span-2">B</div>'
    . '<div class="col-span-2">C</div><div class="row-span-2">D</div></section>'
    . '<div class="logo-marquee"><span>ACME</span><span>VERTEX</span></div>'
    . str_repeat('<p>Ordinary body copy for length here.</p>', 20)
    . '</body></html>';
$r = (new SiteAnalyzer('https://nimbus.example.com/', $visual))->analyze();

ok($r->has('ae.hero_pill'), 'catches the badge above the headline');
ok($r->has('ae.scroll_indicator'), 'catches the scroll cue');
ok($r->has('ae.glow_orbs'), 'catches blurred colour behind the hero');
ok($r->has('ae.bento_grid'), 'catches the bento grid');
ok($r->has('ae.logo_marquee'), 'catches the endless logo strip');
ok($r->has('ae.floating_nav'), 'catches the floating blurred navbar');

// Six aesthetic signals, and the group cap still holds the score down.
ok($r->countAi(true) === 0 || $r->score() <= 55 || $r->countAi(true) > 0,
   'aesthetic signals are counted');
$aestheticOnly = new Report('url', 'x');
foreach (array('ae.hero_pill', 'ae.scroll_indicator', 'ae.glow_orbs', 'ae.bento_grid',
               'ae.logo_marquee', 'ae.floating_nav', 'ae.indigo', 'ae.gradient_text') as $id) {
    $aestheticOnly->flag($id, array('x'));
}
ok($aestheticOnly->score() <= 55,
   'eight visual signals together still cannot pass 55%', (string) $aestheticOnly->score());

// A restrained page must not collect them by accident.
$plain = '<!DOCTYPE html><html lang="en"><head><title>Shop</title></head><body>'
    . '<header><a href="/">Shop</a></header><h1>Pain au levain</h1>'
    . str_repeat('<p>Ordinary body copy that goes on for a while.</p>', 20)
    . '</body></html>';
$rp = (new SiteAnalyzer('https://shop.example.fr/', $plain))->analyze();
foreach (array('ae.hero_pill', 'ae.scroll_indicator', 'ae.glow_orbs', 'ae.bento_grid',
               'ae.logo_marquee', 'ae.floating_nav') as $id) {
    ok(!$rp->has($id), 'a plain page does not trip ' . $id);
}

// --------------------------------------------------------------- guardrails

group('Scoring guardrails');

$aesthetic = '<!DOCTYPE html><html><head><title>t</title>'
    . '<style>body{font-family:Inter}.a{color:#6366f1}.b{color:#8b5cf6}.c{background:#4f46e5}</style>'
    . '</head><body class="bg-indigo-500 text-violet-600 border-purple-400">'
    . str_repeat('<p>Some perfectly ordinary sentence about a subject.</p>', 40)
    . '</body></html>';
$r = (new SiteAnalyzer('https://example.com/', $aesthetic))->analyze();
ok($r->countAi(true) === 0, 'the aesthetic-only fixture fires no structural signals');
ok($r->score() <= 55, 'aesthetics alone cannot exceed 55%', (string) $r->score());

$empty = new Report('code', 'nothing');
$empty->stat('thin', true);
between($empty->score(), 25, 50, 'an empty report lands near the prior, not at zero');

$r = (new CodeAnalyzer("const a = 1;\nconst b = 2;\n"))->analyze();
$a = $r->toArray();
ok($a['confidence']['level'] === 'insufficient', 'two lines of code gives insufficient confidence');
between($a['score'], 30, 60, 'a thin sample is pulled toward the middle');

// Category ceilings: piling on weak style tells must not outrank a fingerprint.
$stacked = new Report('code', 'stacked');
foreach (array('cd.what_comments', 'cd.blanket_try', 'cd.swallowed_errors', 'cd.console_noise',
               'cd.verbose_names', 'cd.lazy_names', 'cd.helper_pileup', 'cd.formal_errors',
               'cd.todo_placeholders', 'cd.typography') as $id) {
    $stacked->flag($id, array('x'));
}
$fingerprinted = new Report('url', 'fingerprinted');
$fingerprinted->flag('fp.lovable', array('x'));

ok($stacked->score() < $fingerprinted->score(),
   'ten stacked style tells still score below one hard fingerprint',
   $stacked->score() . ' vs ' . $fingerprinted->score());

$five = new Report('code', 'five');
foreach (array('cd.what_comments', 'cd.blanket_try', 'cd.swallowed_errors', 'cd.console_noise', 'cd.verbose_names') as $id) {
    $five->flag($id, array('x'));
}
ok($stacked->score() - $five->score() < 8,
   'the ceiling makes the last five style tells add almost nothing',
   $five->score() . ' -> ' . $stacked->score());

// Human evidence still nets off correctly under the ceilings.
$mixed = new Report('code', 'mixed');
$mixed->flag('cd.what_comments', array('x'));
$mixed->flag('cd.blanket_try', array('x'));
$mixed->flag('hu.why_comments', array('x'));
$mixed->flag('hu.ticket_refs', array('x'));
between($mixed->score(), 15, 45, 'opposing evidence pulls the score down');

// -------------------------------------------------------------- certificate

group('Certificate signing');

$result = (new CodeAnalyzer(fixture('ai-code.js')))->analyze()->toArray();
$token = vcd_cert_token($result);

ok(vcd_verify($token['payload'], $token['sig']), 'a fresh token verifies');
ok(!vcd_verify($token['payload'], str_repeat('0', 64)), 'a wrong signature is rejected');
ok(vcd_cert_open($token['payload'], $token['sig']) !== null, 'a valid token opens');
ok(vcd_cert_open($token['payload'] . 'x', $token['sig']) === null, 'a tampered payload is rejected');

$cert = vcd_cert_open($token['payload'], $token['sig']);
ok($cert['s'] === $result['score'], 'the token carries the score it was issued with');
ok(strlen((string) $cert['id']) === 12, 'the certificate id is 12 characters');

// Forging: swap the score inside the payload and re-encode without the key.
$forged = $cert;
$forged['s'] = 3;
$forgedPayload = vcd_b64url_encode((string) json_encode($forged));
ok(vcd_cert_open($forgedPayload, $token['sig']) === null, 'an edited score fails verification');

// ---------------------------------------------------------------------- PDF

group('PDF output');

$pdf = (new Certificate($cert))->render();

ok(strncmp($pdf, '%PDF-1.4', 8) === 0, 'starts with a PDF header');
ok(substr(rtrim($pdf), -5) === '%%EOF', 'ends with %%EOF');
ok(strpos($pdf, '/Type /Catalog') !== false, 'has a document catalogue');
ok(strpos($pdf, 'startxref') !== false, 'has a cross-reference pointer');
ok(strlen($pdf) > 1200, 'is a plausible size', strlen($pdf) . ' bytes');

// The xref offsets must actually point at their objects, or readers reject it.
preg_match('~startxref\s+(\d+)~', $pdf, $m);
$xrefAt = (int) $m[1];
ok(substr($pdf, $xrefAt, 4) === 'xref', 'startxref points at the xref table');

preg_match_all('~^(\d{10}) 00000 n ~m', $pdf, $offsets);
$badOffsets = 0;
foreach ($offsets[1] as $i => $off) {
    if (!preg_match('~^' . ($i + 1) . ' 0 obj~', substr($pdf, (int) $off, 20))) {
        $badOffsets++;
    }
}
ok($badOffsets === 0, 'every xref offset lands on its object', "{$badOffsets} wrong");

$plain = (new Certificate(array_merge($cert, array('c' => 'likely_human', 's' => 12))))->render();
ok(strlen($plain) > 1200, 'renders the human-verdict variant too');

// A subject with every signal in the catalogue must still not run the evidence
// list into the fixed caveat panel at the bottom of the page.
$crowded = array_merge($cert, array('g' => array_keys(Catalog::all())));
$long = (new Certificate($crowded))->render();
ok(strlen($long) > 1200, 'renders with the whole catalogue as evidence');

// Derived, not duplicated: a hardcoded copy here silently stops testing the
// real panel the moment CAVEAT_H changes.
$caveatTop = Pdf::A4_H - Certificate::MARGIN - 40.0 - Certificate::CAVEAT_H;
$lowest = 0.0;
preg_match_all('~stream\n(.*?)\nendstream~s', $long, $streams);
foreach ($streams[1] as $s) {
    $raw = @gzuncompress($s);
    if ($raw === false) $raw = $s;
    // Text matrices are emitted in PDF space, so the lowest baseline is the
    // smallest y — convert back to top-down to compare against the panel.
    if (preg_match_all('~1 0 0 1 [\d.]+ ([\d.]+) Tm~', $raw, $ys)) {
        foreach ($ys[1] as $py) {
            $topDown = Pdf::A4_H - (float) $py;
            if ($topDown > $lowest && $topDown < Pdf::A4_H - 80) $lowest = $topDown;
        }
    }
}
ok($lowest > 0, 'found text positions to check');
ok($lowest < Pdf::A4_H - 54.0, 'nothing is drawn below the bottom margin', sprintf('lowest %.1f', $lowest));
$overrun = 0;
foreach ($streams[1] as $s) {
    $raw = @gzuncompress($s);
    if ($raw === false) $raw = $s;
    if (preg_match_all('~1 0 0 1 [\d.]+ ([\d.]+) Tm~', $raw, $ys)) {
        foreach ($ys[1] as $py) {
            $topDown = Pdf::A4_H - (float) $py;
            // The evidence list lives above the panel; the panel's own text and
            // the footer live below its top edge by design.
            if ($topDown > $caveatTop && $topDown < $caveatTop + 14) $overrun++;
        }
    }
}
ok($overrun === 0, 'the evidence list never overruns the caveat panel', "{$overrun} lines in the gap");

// ------------------------------------------------------------- PDF metrics

group('PDF text metrics');

$p = new Pdf();
$p->addPage();
ok(abs($p->textWidth('iiii', 'F1', 10) - 8.88) < 0.01, 'Helvetica narrow glyphs measure correctly');
ok(abs($p->textWidth('WWWW', 'F1', 10) - 37.76) < 0.01, 'Helvetica wide glyphs measure correctly');
ok(abs($p->textWidth('abcd', 'F4', 10) - 24.0) < 0.01, 'Courier measures as monospaced');
ok($p->textWidth('AAA', 'F2', 10) > $p->textWidth('AAA', 'F1', 10), 'bold is wider than regular');

$lines = $p->wrap(str_repeat('word ', 60), 100, 'F1', 10);
ok(count($lines) > 1, 'long text wraps');
$tooWide = 0;
foreach ($lines as $line) {
    if ($p->textWidth($line, 'F1', 10) > 100.5) $tooWide++;
}
ok($tooWide === 0, 'no wrapped line exceeds the column');
ok(count($p->wrap('Supercalifragilistic', 20, 'F1', 10)) > 1, 'an over-wide single word is broken');

// ------------------------------------------------------------------ fetcher

group('Fetcher safety');

$f = new Fetcher();
$blocked = array(
    'http://127.0.0.1/', 'http://localhost/admin', 'https://192.168.1.1/',
    'http://10.0.0.5/', 'http://169.254.169.254/latest/meta-data/',
    'file:///etc/passwd', 'gopher://example.com/', 'http://[::1]/',
    'http://internal.local/', 'https://example.com:22/',
);
foreach ($blocked as $url) {
    $rejected = false;
    try {
        $ref = new ReflectionMethod('Fetcher', 'assertSafe');
        $ref->setAccessible(true);
        $ref->invoke($f, $f->normalize($url));
    } catch (FetchError $e) {
        $rejected = true;
    } catch (Throwable $e) {
        $rejected = true;
    }
    ok($rejected, 'refuses ' . $url);
}

ok($f->normalize('example.com') === 'https://example.com', 'a bare host gets https://');

// assertSafe must hand back the addresses it approved, and curlGet must pin the
// connection to them. Without that the guard is decorative: cURL re-resolves on
// connect, so a DNS server under the target's control can answer once with a
// public address and once with 127.0.0.1.
$safe = new ReflectionMethod('Fetcher', 'assertSafe');
$safe->setAccessible(true);
$approved = $safe->invoke($f, 'https://example.com/');
ok(is_array($approved) && $approved !== array(), 'assertSafe returns the addresses it vetted',
   is_array($approved) ? implode(',', $approved) : gettype($approved));

$overrides = new ReflectionMethod('Fetcher', 'resolveOverrides');
$overrides->setAccessible(true);

$pin = $overrides->invoke($f, 'https://example.com/some/path', array('93.184.216.34'));
ok($pin === array('example.com:443:93.184.216.34'), 'https pins to port 443',
   implode(' ', (array) $pin));

// A crawl calls assertSafe() on the same host for the entry page, its assets,
// robots.txt and every inner page — the cache exists so that costs one DNS
// lookup instead of dozens.
$cacheProp = new ReflectionProperty('Fetcher', 'safeHostCache');
$cacheProp->setAccessible(true);

$f2 = new Fetcher();
$firstLookup = $safe->invoke($f2, 'https://example.com/');
$cached = $cacheProp->getValue($f2);
ok(isset($cached['example.com']), 'the vetted addresses are cached against the host');

$secondLookup = $safe->invoke($f2, 'https://example.com/a/different/path');
ok($secondLookup === $firstLookup, 'a second lookup for the same host reuses the cached addresses',
   implode(',', $secondLookup) . ' vs ' . implode(',', $firstLookup));

ok($cacheProp->getValue(new Fetcher()) === array(), 'a new Fetcher starts with an empty cache');

$pin = $overrides->invoke($f, 'http://example.com/', array('93.184.216.34', '93.184.216.35'));
ok($pin === array('example.com:80:93.184.216.34,93.184.216.35'), 'http pins every vetted address',
   implode(' ', (array) $pin));

$pin = $overrides->invoke($f, 'https://example.com/', array('2606:2800:220:1:248:1893:25c8:1946'));
ok($pin === array('example.com:443:[2606:2800:220:1:248:1893:25c8:1946]'),
   'IPv6 is bracketed the way cURL wants it', implode(' ', (array) $pin));

ok($overrides->invoke($f, 'https://93.184.216.34/', array('93.184.216.34')) === array(),
   'a literal address needs no override');

$src = (string) file_get_contents(dirname(__DIR__) . '/lib/Fetcher.php');
ok(strpos($src, 'CURLOPT_RESOLVE') !== false, 'the vetted address is actually pinned on the request');

$rejectedEmpty = false;
try { $f->normalize('   '); } catch (FetchError $e) { $rejectedEmpty = true; }
ok($rejectedEmpty, 'refuses an empty URL');

// ------------------------------------------------------------------- brand

group('Brand assets');

$svg = Brand::markSvg();
ok(strpos($svg, '<svg') === 0, 'the mark renders as SVG');
ok(substr_count($svg, '<path') === 2, 'the mark has both halves of the trace');

$onDisk = (string) file_get_contents(dirname(__DIR__) . '/assets/img/logo.svg');
ok(strpos($onDisk, Brand::markSvg(120, 'currentColor', '#b8402e', 'role="img" aria-label="Vibe Code Detector"')) !== false,
   'assets/img/logo.svg matches lib/Brand.php (run tools/build-assets.php)');

// ------------------------------------------------------------ rate budgets

group('Request budgets');

ok(VCD_LIMIT_CRAWL === array(10, 180), 'whole-site reads are ten every three minutes',
   implode('/', VCD_LIMIT_CRAWL));

// A crawl spends from the url bucket too, so url must be roomy enough that it
// never becomes the real crawl limit. At 20/600 it was: ten crawls every three
// minutes is about thirty-three per ten, and the url bucket would have stopped
// them at twenty while blaming the wrong thing.
$crawlsPerUrlWindow = (int) ceil(VCD_LIMIT_CRAWL[0] / VCD_LIMIT_CRAWL[1] * VCD_LIMIT_URL[1]);
ok(VCD_LIMIT_URL[0] >= $crawlsPerUrlWindow,
   'the url bucket cannot become the binding constraint on crawls',
   sprintf('url allows %d per %ds, crawls need %d', VCD_LIMIT_URL[0], VCD_LIMIT_URL[1], $crawlsPerUrlWindow));

foreach (array('VCD_LIMIT_URL' => VCD_LIMIT_URL, 'VCD_LIMIT_CRAWL' => VCD_LIMIT_CRAWL,
               'VCD_LIMIT_CODE' => VCD_LIMIT_CODE, 'VCD_LIMIT_GIT' => VCD_LIMIT_GIT) as $name => $budget) {
    ok(count($budget) === 2 && $budget[0] > 0 && $budget[1] > 0, $name . ' is a sane budget');
}

// And the throttle itself has to actually enforce a limit, not just hold one.
$bucket = 'test' . bin2hex(random_bytes(4));
$allowed = 0;
for ($i = 0; $i < 8; $i++) {
    if (vcd_rate_limit($bucket, 5, 600)) {
        $allowed++;
    }
}
$writable = is_dir(VCD_DATA . '/rate') && is_writable(VCD_DATA . '/rate');
if ($writable) {
    ok($allowed === 5, 'the throttle allows exactly its limit then stops', $allowed . ' of 8 allowed');
    foreach ((array) glob(VCD_DATA . '/rate/' . $bucket . '-*.txt') as $tmp) {
        @unlink($tmp);
    }
} else {
    // Documented behaviour: an unwritable data directory fails open rather
    // than taking the site down.
    ok($allowed === 8, 'an unwritable data directory fails open', $allowed . ' of 8 allowed');
}

// A per-IP throttle does nothing against a flood spread across many
// addresses at once; the global concurrency cap is the backstop for that.
$slotsWritable = is_dir(VCD_DATA) && is_writable(VCD_DATA);
if ($slotsWritable) {
    @unlink(VCD_DATA . '/inflight.txt'); // start from a clean slot table

    $tokens = array();
    for ($i = 0; $i < VCD_MAX_CONCURRENT_FETCHES + 3; $i++) {
        $tokens[] = vcd_acquire_fetch_slot();
    }
    $held = array_filter($tokens, function ($t) { return $t !== null; });
    ok(count($held) === VCD_MAX_CONCURRENT_FETCHES,
       'the concurrency cap allows exactly its limit of slots then refuses',
       count($held) . ' of ' . VCD_MAX_CONCURRENT_FETCHES);

    foreach ($tokens as $t) {
        if ($t !== null && $t !== '') {
            vcd_release_fetch_slot($t);
        }
    }
    $reopened = vcd_acquire_fetch_slot();
    ok($reopened !== null, 'releasing a slot frees it back up for the next request');
    if ($reopened !== null && $reopened !== '') {
        vcd_release_fetch_slot($reopened);
    }

    @unlink(VCD_DATA . '/inflight.txt');
} else {
    $reopened = vcd_acquire_fetch_slot();
    ok($reopened !== null, 'an unwritable data directory fails open for the concurrency cap too');
}

// ---------------------------------------------------------- public base url

group('Where the app thinks it lives');

// This shipped wrong. The base path was derived by subtracting DOCUMENT_ROOT
// from the app directory, which assumes the two differ only by the URL
// subdirectory. On the live host the app sits in a folder named after its own
// domain while DOCUMENT_ROOT reports the parent, so the domain name became a
// path segment and every certificate said example.com/example.com/verify.

// Served from a domain root, app directory named after the domain.
ok(vcd_url_base('/index.php', 0) === '', 'a domain root gives no path at all',
   vcd_url_base('/index.php', 0));
ok(vcd_url_base('/api/certificate.php', 1) === '', 'nor does a script one level down',
   vcd_url_base('/api/certificate.php', 1));
ok(vcd_url_base('/verify.php', 0) === '', 'nor the verify page');

// Served from a subdirectory.
ok(vcd_url_base('/tools/vcd/index.php', 0) === '/tools/vcd', 'a subdirectory install keeps its prefix',
   vcd_url_base('/tools/vcd/index.php', 0));
ok(vcd_url_base('/tools/vcd/api/certificate.php', 1) === '/tools/vcd',
   'and the prefix is the same from one level down',
   vcd_url_base('/tools/vcd/api/certificate.php', 1));

// Degenerate inputs must not invent segments.
ok(vcd_url_base('/', 0) === '', 'a bare slash gives nothing');
ok(vcd_url_base('/api/certificate.php', 5) === '', 'an over-deep script cannot go negative');
ok(vcd_url_base('/a/b/c/d.php', 2) === '/a', 'levels are stripped from the end');

// The whole point: the domain must never appear twice.
$base = vcd_url_base('/api/certificate.php', 1);
ok(strpos('vibecodedetector.fanficnow.com' . $base, 'fanficnow.com/vibecodedetector') === false,
   'the host cannot end up doubled in a certificate URL',
   'vibecodedetector.fanficnow.com' . $base);

// Depth is measured against this project's own layout, which is knowable.
ok(vcd_script_depth() >= 0, 'script depth is never negative');

// ------------------------------------------------------- bounded scanning

group('Bounded scanning');

// A blind substr can split a UTF-8 character, and every /u pattern run over the
// result then returns null rather than matching nothing — which reads as "no
// signals found" instead of an error.
$multibyte = str_repeat('café — naïve ', 200);
for ($cut = 20; $cut < 40; $cut++) {
    $piece = Text::safeCut($multibyte, $cut);
    if (!preg_match('//u', $piece)) {
        ok(false, 'safeCut never leaves invalid UTF-8', "broke at {$cut} bytes");
        break;
    }
    if ($cut === 39) {
        ok(true, 'safeCut never leaves invalid UTF-8 at any offset');
    }
}
ok(strlen(Text::safeCut('abc', 100)) === 3, 'safeCut leaves short input alone');
ok(Text::safeCut('', 10) === '', 'safeCut handles an empty string');

// A page past the ceiling still analyses, still finds its fingerprint, and says
// what it did not read.
$huge = '<!DOCTYPE html><html lang="en"><head><title>Big</title></head><body>'
      . str_repeat('<p>Filler sentence that exists only to take up room on the page.</p>', 12000)
      . '<script src="https://cdn.gpteng.co/gptengineer.js"></script></body></html>';
ok(strlen($huge) > SiteAnalyzer::MAX_SCAN, 'the oversized fixture really is oversized');

$started = microtime(true);
$rh = (new SiteAnalyzer('https://big.example.com/', $huge))->analyze();
$elapsed = microtime(true) - $started;
$ah = $rh->toArray();

ok($rh->hasFingerprint(), 'fingerprints are still found in an oversized page');
ok(!empty($ah['stats']['scanTruncated']), 'the report records that scanning was bounded');
ok(strpos(implode(' ', $ah['notes']), 'first') !== false, 'and says so in the notes');
ok($elapsed < 10.0, 'an oversized page analyses in reasonable time', sprintf('%.1fs', $elapsed));

// ----------------------------------------------------------------- markup

group('Page markup');

$css = (string) file_get_contents(dirname(__DIR__) . '/assets/css/site.css');

// The .ladder list has three children across two columns. Both the term and
// the description must be pinned to column 2, or the description auto-places
// into the 2.5rem counter column and wraps one word per line. This shipped
// once; it does not get to ship twice.
preg_match('~\.ladder strong \{([^}]*)\}~', $css, $strongRule);
preg_match('~\.ladder span \{([^}]*)\}~s', $css, $spanRule);
ok(isset($strongRule[1]) && strpos($strongRule[1], 'grid-column: 2') !== false,
   '.ladder strong is pinned to the content column');
ok(isset($spanRule[1]) && strpos($spanRule[1], 'grid-column: 2') !== false,
   '.ladder span is pinned to the content column');

// Every grid in the stylesheet: count declared columns against the children
// the markup puts in it, so this class of bug is caught generally.
$page = (string) file_get_contents(dirname(__DIR__) . '/index.php');
ok(substr_count($page, 'class="ladder"') === 1, 'the ladder list is where the test expects it');
preg_match('~<ol class="ladder">(.*?)</ol>~s', $page, $ladderHtml);
// The count is not the point — the invariant is. Each rung puts three children
// into a two-column grid, so a rung whose description is missing, or a stray
// extra element, is what actually breaks the layout.
$items = isset($ladderHtml[1]) ? substr_count($ladderHtml[1], '<li>') : 0;
ok($items >= 5, 'the ladder describes the evidence hierarchy', (string) $items . ' rungs');
ok(substr_count((string) ($ladderHtml[1] ?? ''), '<span>') === $items,
   'every rung has exactly one description span');
ok(substr_count((string) ($ladderHtml[1] ?? ''), '<strong>') === $items,
   'every rung has exactly one term');

ok(strpos($page, 'A Landfall studio product') !== false, 'the studio credit is in the footer');
ok(strpos($css, '.colophon .studio') !== false, 'the studio credit is styled');

// The certificate is a finding handed to a third party. It states the limits of
// the method; it does not editorialise about the tool's own provenance.
$certSrc = (string) file_get_contents(dirname(__DIR__) . '/lib/Certificate.php');
$selfReferential = preg_match('~(?:was itself|its own detector|cannot tell which half|AI-generated, and)~i', $certSrc);
ok($selfReferential === 0, 'the certificate carries no self-referential provenance claim');

// Social preview card.
$social = dirname(__DIR__) . '/assets/img/social-preview.png';
ok(is_readable($social), 'the social preview exists (run tools/build-social.php)');
if (is_readable($social)) {
    $dim = getimagesize($social);
    ok($dim !== false && $dim[0] === 1280 && $dim[1] === 640,
       'the social preview is 1280x640',
       $dim ? "{$dim[0]}x{$dim[1]}" : 'unreadable');
}
ok(strpos($page, 'og:image') !== false, 'the page advertises a link-preview image');

// ------------------------------------------------------------ determinism

group('Version-independent ordering');

// Sorts are stable only from PHP 8.0, and most weights are shared by several
// signals. An unbroken tie orders arbitrarily on 7.4, which silently produced a
// different docs/SIGNALS.md there and turned CI red against a doc generated on
// 8.x. Every ordering that reaches a file or a certificate needs a total order.

$idsOf = function (Report $r) {
    $out = array();
    foreach ($r->toArray()['signals'] as $s) {
        $out[] = $s['id'];
    }
    return implode(',', $out);
};

// Same signals, opposite insertion order: the output order must not move.
$tied = array('cd.lazy_names', 'cd.console_noise', 'cd.helper_pileup', 'cd.todo_placeholders', 'ct.placeholder_copy');
$forward = new Report('code', 'forward');
foreach ($tied as $id) {
    $forward->flag($id, array('x'));
}
$backward = new Report('code', 'backward');
foreach (array_reverse($tied) as $id) {
    $backward->flag($id, array('x'));
}
ok($idsOf($forward) === $idsOf($backward),
   'equal-weight signals sort identically whatever order they fired in',
   $idsOf($forward) . '  vs  ' . $idsOf($backward));

// The generated doc must be reproducible from the catalogue, not merely equal
// to whatever the last machine happened to write.
$check = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/tools/gen-signals-doc.php') . ' --check';
exec($check . ' 2>&1', $checkOut, $checkStatus);
ok($checkStatus === 0, 'docs/SIGNALS.md is current (run tools/gen-signals-doc.php)', implode(' ', $checkOut));

// And the generator's own tie-break must survive its input being reordered.
$order = function (array $catalog) {
    $rows = array();
    foreach ($catalog as $id => $m) {
        $m['id'] = $id;
        $rows[$id] = $m;
    }
    uasort($rows, function ($a, $b) {
        if ($a['weight'] === $b['weight']) {
            return strcmp($a['id'], $b['id']);
        }
        return $b['weight'] <=> $a['weight'];
    });
    return implode(',', array_keys($rows));
};
ok($order(Catalog::all()) === $order(array_reverse(Catalog::all(), true)),
   'the doc ordering is identical when the catalogue is reversed');

// ------------------------------------------------------------------ catalog

group('Catalogue integrity');

$dupes = array();
foreach (Catalog::all() as $id => $meta) {
    ok2($meta, $id, $dupes);
}
function ok2(array $meta, string $id, array &$seen): void
{
    static $cats = null;
    if ($cats === null) $cats = Catalog::categories();

    $problems = array();
    if (!isset($cats[$meta['category']])) $problems[] = 'unknown category';
    if (!in_array($meta['direction'], array('ai', 'human'), true)) $problems[] = 'bad direction';
    if ($meta['weight'] <= 0) $problems[] = 'non-positive weight';
    if (trim($meta['label']) === '' || trim($meta['detail']) === '') $problems[] = 'missing copy';
    if (strlen($meta['detail']) < 40) $problems[] = 'detail too short to be useful';

    ok(empty($problems), 'catalogue entry ' . $id, implode(', ', $problems));
}

// --------------------------------------------------------------------- done

echo "\n", str_repeat('─', 52), "\n";
printf("  %d passed, %d failed\n\n", $passed, $failed);

exit($failed > 0 ? 1 : 0);
