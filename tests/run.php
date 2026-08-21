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
$items = isset($ladderHtml[1]) ? substr_count($ladderHtml[1], '<li>') : 0;
ok($items === 5, 'the ladder still has five rungs', (string) $items);
ok(substr_count((string) ($ladderHtml[1] ?? ''), '<span>') === $items,
   'every rung has a description span');

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
