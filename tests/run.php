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
require_once dirname(__DIR__) . '/lib/RepoAnalyzer.php';
require_once dirname(__DIR__) . '/lib/AdminAuth.php';
require_once dirname(__DIR__) . '/lib/UsageLog.php';
require_once dirname(__DIR__) . '/lib/VisitLog.php';
require_once dirname(__DIR__) . '/lib/Chart.php';
require_once dirname(__DIR__) . '/lib/Pager.php';
require_once dirname(__DIR__) . '/lib/AdminUi.php';
require_once dirname(__DIR__) . '/lib/Num.php';
require_once dirname(__DIR__) . '/lib/Seo.php';

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
ok(strpos(implode(' ', $r3->signals()[0]->evidenceText()), 'Databutton') !== false,
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

// --------------------------------------------------------- site: client-side

group('A page that builds itself in the browser');

/**
 * The shape almost every generated application ships in: an empty mount point
 * and a hashed bundle. None of the class names or copy is in the document, so
 * everything below is being read out of the JavaScript and the stylesheet.
 */
$spaShell = fixture('spa-shell.html');
$spaAssets = array(
    'https://nimbus.example.com/assets/index-C3xY1pQz.css' => fixture('spa-bundle.css'),
    'https://nimbus.example.com/assets/index-Ba7Xk9Lm.js'  => fixture('spa-bundle.js'),
);

$r = (new SiteAnalyzer('https://nimbus.example.com/', $spaShell, $spaAssets))->analyze();
$a = $r->toArray();

ok($r->has('ae.shadcn_defaults'), 'reads component defaults out of the bundle');
ok($r->has('ae.gradient_text'), 'reads the gradient headline out of the bundle');
ok($r->has('ae.floating_nav'), 'reads the floating navbar out of the bundle');
ok($r->has('ae.hero_pill'), 'reads the hero pill out of the bundle');
ok($r->has('ct.marketing_cliche'), 'reads the marketing copy out of the bundle');
ok($r->has('ae.indigo'), 'reads the palette out of the stylesheet');
ok(empty($a['stats']['thin']),
   'a short document with a readable bundle is not treated as thin input');
ok(isset($a['stats']['framework']) && $a['stats']['framework'] === 'Vite',
   'names the framework it recognised', (string) ($a['stats']['framework'] ?? 'none'));
between($a['score'], 72, 92, 'and reaches a reading, where the document alone gave none');

// The same shell with nothing readable behind it really is thin.
$r = (new SiteAnalyzer('https://nimbus.example.com/', $spaShell))->analyze();
ok(!empty($r->toArray()['stats']['thin']), 'a shell with no readable bundle is still thin');

// Arbitrary strings in a bundle must not be mistaken for class lists.
$noise = 'const u="SELECT id FROM users WHERE id = ?";const p="/api/v1/orders/create";'
       . 'const q="Content-Type: application/json";const z="error while loading resource";';
$r = (new SiteAnalyzer('https://nimbus.example.com/', $spaShell,
     array('https://nimbus.example.com/assets/index-Ba7Xk9Lm.js' => $noise)))->analyze();
ok(!$r->has('ae.shadcn_defaults') && !$r->has('ae.gradient_text'),
   'ordinary strings in a bundle are not read as styling');

// --------------------------------------------------------- site: scaffold

group('A scaffold nobody renamed');

$scaffold = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
    . '<title>Vite + React + TS</title><link rel="icon" type="image/svg+xml" href="/vite.svg">'
    . '</head><body><div id="root"></div>' . str_repeat('<p>Body copy that goes on a while.</p>', 40)
    . '</body></html>';
$r = (new SiteAnalyzer('https://thing.example.com/', $scaffold))->analyze();
ok($r->has('st.untouched_scaffold'), 'catches the starter template title and favicon');

$named = str_replace(array('Vite + React + TS', '/vite.svg'),
                     array('Marchand & Fils, boulangerie', '/favicon-boulangerie.png'), $scaffold);
$r = (new SiteAnalyzer('https://thing.example.com/', $named))->analyze();
ok(!$r->has('st.untouched_scaffold'), 'a page with its own title and favicon does not fire');

$cra = '<!DOCTYPE html><html lang="en"><head><title>My Shop</title>'
     . '<meta name="description" content="Web site created using create-react-app"></head>'
     . '<body><noscript>You need to enable JavaScript to run this app.</noscript>'
     . str_repeat('<p>Copy.</p>', 60) . '</body></html>';
$r = (new SiteAnalyzer('https://thing.example.com/', $cra))->analyze();
ok($r->has('st.untouched_scaffold'), 'catches the create-react-app description and noscript block');

// A build pipeline is not evidence of craft on a page still wearing its scaffold.
$hashed = str_replace('</head>', '<script src="/assets/index-Ba7Xk9Lm.js"></script></head>', $scaffold);
$r = (new SiteAnalyzer('https://thing.example.com/', $hashed))->analyze();
ok(!$r->has('hu.build_stripped'),
   'the build-pipeline signal is withheld when the scaffold gives the page away');

$hashedNamed = str_replace('</head>', '<script src="/assets/index-Ba7Xk9Lm.js"></script></head>', $named);
$r = (new SiteAnalyzer('https://thing.example.com/', $hashedNamed))->analyze();
ok($r->has('hu.build_stripped'), 'and still fires on a page with nothing else against it');

// ------------------------------------------------------- site: transport

group('What the response headers say');

$plain = '<!DOCTYPE html><html lang="en"><head><title>Shop</title></head><body>'
       . str_repeat('<p>Ordinary copy on an ordinary page.</p>', 40) . '</body></html>';

$r = (new SiteAnalyzer('https://shop.example.com/', $plain, array(), array(
    'status' => 200,
    'headers' => array('server' => 'Vercel', 'x-vercel-id' => 'cdg1::abc', 'content-type' => 'text/html'),
)))->analyze();
$a = $r->toArray();
ok(($a['stats']['hosting'] ?? '') === 'Vercel', 'records the hosting platform');
ok(!$r->hasFingerprint(), 'but the hosting platform is never treated as evidence of a builder');
ok(($a['stats']['securityHeaders'] ?? null) === 0, 'counts the hardening headers it found');
ok($r->countAi() === 0,
   'a bare response is recorded and not scored: almost nothing sets these, so their absence separates nothing');

$r = (new SiteAnalyzer('https://shop.example.com/', $plain, array(), array(
    'status' => 200,
    'headers' => array(
        'content-security-policy' => "default-src 'self'; script-src 'self' 'unsafe-inline'",
        'strict-transport-security' => 'max-age=31536000; includeSubDomains',
        'x-frame-options' => 'DENY',
    ),
)))->analyze();
ok($r->has('hu.hardened_headers'), 'catches headers somebody configured');

$r = (new SiteAnalyzer('https://shop.example.com/', $plain, array(), array(
    'status' => 200,
    'headers' => array('set-cookie' => 'PHPSESSID=abc; path=/', 'server' => 'Apache'),
)))->analyze();
ok($r->has('hu.legacy_stack'), 'a server-side session cookie counts toward a classic stack');

// Analysing without headers at all must change nothing.
$r = (new SiteAnalyzer('https://shop.example.com/', $plain))->analyze();
$a = $r->toArray();
ok(!$r->has('hu.hardened_headers') && !isset($a['stats']['securityHeaders']),
   'a page fetched without header detail says nothing about headers either way');

// -------------------------------------------------- site: stack and backend

group('The stack and what it talks to');

$kit = 'import{cva}from"class-variance-authority";import{twMerge}from"tailwind-merge";'
     . 'import clsx from"clsx";import{Rocket}from"lucide-react";import{Toaster}from"sonner";'
     . 'import*as Dialog from"@radix-ui/react-dialog";';
$r = (new SiteAnalyzer('https://app.example.com/', $spaShell,
     array('https://app.example.com/assets/index-Ba7Xk9Lm.js' => $kit)))->analyze();
ok($r->has('st.generated_stack'), 'catches the whole component kit arriving together');

$partial = 'import clsx from"clsx";import{Rocket}from"lucide-react";';
$r = (new SiteAnalyzer('https://app.example.com/', $spaShell,
     array('https://app.example.com/assets/index-Ba7Xk9Lm.js' => $partial)))->analyze();
ok(!$r->has('st.generated_stack'), 'two ordinary libraries are not a finding');

$backend = 'const c=createClient("https://abcdefghij.supabase.co",'
    . '"eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJyb2xlIjoiYW5vbiIsImlzcyI6InN1cGFiYXNlIn0.aaaaaaaaaaaa");'
    . 'localStorage.setItem("isLoggedIn","true");'
    . 'localStorage.setItem("todos",JSON.stringify(t));localStorage.setItem("cart",JSON.stringify(c));'
    . 'localStorage.setItem("profile",JSON.stringify(p));';
$r = (new SiteAnalyzer('https://app.example.com/', $spaShell,
     array('https://app.example.com/assets/index-Ba7Xk9Lm.js' => $backend)))->analyze();
$a = $r->toArray();
ok($r->has('st.client_only_backend'), 'catches a database addressed straight from the browser');
ok($r->has('se.exposed_client_key'), 'catches the key that travels with it');
ok($r->has('se.client_side_auth'), 'catches a login the browser grants itself');

$printed = json_encode($a['signals']);
ok(strpos($printed, 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9') === false,
   'the credential itself is never echoed back in the evidence');

$openai = 'const client=new OpenAI({apiKey:k,dangerouslyAllowBrowser:true});';
$r = (new SiteAnalyzer('https://app.example.com/', $spaShell,
     array('https://app.example.com/assets/index-Ba7Xk9Lm.js' => $openai)))->analyze();
ok($r->has('se.exposed_client_key'), 'catches an AI SDK told to run in the browser');

// ------------------------------------------------------- site: source maps

group('Reading the source a bundle came from');

$mapped = array(array(
    'url' => 'https://app.example.com/assets/index-Ba7Xk9Lm.js.map',
    'sources' => array(
        'src/App.tsx', 'src/components/ui/button.tsx', 'src/components/ui/card.tsx',
        'src/components/ui/dialog.tsx', 'src/components/ui/input.tsx', 'src/components/ui/toast.tsx',
    ),
    'content' => "// 🚀 Fetch the users from the API\n"
        . "// ✅ Then render them\nasync function loadUsers() {\n"
        . "  try {\n    const r = await fetch(url);\n    return await r.json();\n"
        . "  } catch (e) {\n    console.log('❌ failed');\n  }\n}\n",
));
$r = (new SiteAnalyzer('https://app.example.com/', $spaShell,
     array('https://app.example.com/assets/index-Ba7Xk9Lm.js' => 'var a=1;'),
     array('status' => 200, 'sourceMaps' => $mapped)))->analyze();
$a = $r->toArray();

ok($r->has('cd.emoji_comments'), 'reads the original comments through the source map');
ok(($a['stats']['sourceFiles'] ?? 0) === 6, 'counts the source files the map named');
$notes = implode(' ', $a['notes']);
ok(strpos($notes, 'source map') !== false, 'says where the code-level reading came from');

// A builder marker in a source path identifies the builder just as well.
$lovableMap = array(array(
    'url' => 'inline', 'sources' => array('src/lovable-tagger/index.ts'), 'content' => 'var x=1;',
));
$r = (new SiteAnalyzer('https://app.example.com/', $spaShell, array(),
     array('status' => 200, 'sourceMaps' => $lovableMap)))->analyze();
ok($r->has('fp.lovable'), 'a builder marker in a source-map path is a fingerprint');

// -------------------------------------------------- site: corrected misfires

group('Findings that used to point the wrong way');

// A page full of photographs served through an image optimiser has photographs
// on it. The old check looked for a file extension at the end of the src and
// found none, then read the silence as "no real photography".
$optimised = '<!DOCTYPE html><html lang="fr"><head><title>Boulangerie Marchand</title></head><body>';
foreach (range(1, 6) as $i) {
    $optimised .= '<img src="/_next/image?url=%2Fphotos%2Fboutique' . $i . '.jpg&w=1080&q=75" alt="boutique">';
}
$optimised .= '<picture><source srcset="/img/pain.webp 1x, /img/pain@2x.webp 2x"><img src="/img/pain.webp" alt="pain"></picture>'
    . '<div style="background-image:url(/img/fournil.jpg)"></div>'
    . str_repeat('<section class="p-4"><h2>Nos produits</h2><p>Texte de présentation.</p></section>', 30)
    . '<svg></svg><svg></svg><svg></svg><svg></svg><svg></svg></body></html>';

$r = (new SiteAnalyzer('https://boulangerie.example.fr/', $optimised))->analyze();
$a = $r->toArray();
ok(!$r->has('ae.no_real_images'), 'optimiser and srcset images count as photographs');
ok(($a['stats']['photos'] ?? 0) >= 6, 'counts them', (string) ($a['stats']['photos'] ?? 0));

// And a page that genuinely has none still says so.
$vectorOnly = '<!DOCTYPE html><html lang="en"><head><title>Nimbus</title></head><body>'
    . '<div class="bg-gradient-to-r"></div><div class="bg-gradient-to-b"></div><div class="bg-gradient-to-t"></div>'
    . str_repeat('<svg></svg>', 6)
    . str_repeat('<section><h2>Feature</h2><p>Description of the feature, at some length.</p></section>', 90)
    . '</body></html>';
ok((new SiteAnalyzer('https://x.example.com/', $vectorOnly))->analyze()->has('ae.no_real_images'),
   'a page carried entirely by gradients and vectors still fires');

// French words that are not misspellings, on a French page.
$frenchOk = '<!DOCTYPE html><html lang="fr"><head><title>Boulangerie</title></head><body>'
    . '<p>Rendez vous au 12 rue des Capucins. Notre connection internet et le language du site.</p>'
    . str_repeat('<p>Une phrase ordinaire sur la boutique et ses produits du jour.</p>', 40)
    . '</body></html>';
ok(!(new SiteAnalyzer('https://b.example.fr/', $frenchOk))->analyze()->has('hu.typos'),
   'ordinary French and English loanwords are not counted as typos');

$frenchTypo = str_replace('Rendez vous au', 'Bienvenue sur notre acceuil, au', $frenchOk);
ok((new SiteAnalyzer('https://b.example.fr/', $frenchTypo))->analyze()->has('hu.typos'),
   'a real French misspelling still fires');

// A scaffold's lang="en" over French copy must not send the English list at it.
$frenchScaffold = str_replace('lang="fr"', 'lang="en"', $frenchTypo);
ok((new SiteAnalyzer('https://b.example.fr/', $frenchScaffold))->analyze()->has('hu.typos'),
   'the language of the copy wins over a scaffold\'s lang attribute');

// One common name is a person; two is a cast list.
$oneName = '<!DOCTYPE html><html lang="en"><head><title>Studio</title></head><body>'
    . '<blockquote>Sarah Johnson said the work was excellent.</blockquote>'
    . str_repeat('<p>Ordinary copy about the studio and its work.</p>', 40) . '</body></html>';
ok(!(new SiteAnalyzer('https://s.example.com/', $oneName))->analyze()->has('ct.generic_names'),
   'a single common name is not a placeholder cast');

$twoNames = str_replace('</body>', '<blockquote>John Smith, Verified User</blockquote></body>', $oneName);
ok((new SiteAnalyzer('https://s.example.com/', $twoNames))->analyze()->has('ct.generic_names'),
   'two of them together still fires');

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
    foreach ($s->evidenceText() as $line) {
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
    if ($s->id === 'ct.placeholder_copy') $ev = implode(' ', $s->evidenceText());
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

// --------------------------------------------------------------- sitemap

group('What the sitemap adds');

// Pages the navigation never links to, found because the site lists them.
$sitemapSite = array(
    '/' => '<!DOCTYPE html><html lang="en"><head><title>Home</title></head><body>'
         . '<nav><a href="/about">About</a></nav><p>' . str_repeat('copy ', 60) . '</p></body></html>',
    '/about'    => stubPage('About'),
    '/unlinked' => stubPage('Unlinked'),
    '/robots.txt' => "User-agent: *\nSitemap: https://example.com/sitemap.xml\n",
    '/sitemap.xml' =>
        '<?xml version="1.0" encoding="UTF-8"?><urlset>'
        . '<url><loc>https://example.com/</loc><lastmod>2026-01-04</lastmod></url>'
        . '<url><loc>https://example.com/about</loc><lastmod>2026-01-04</lastmod></url>'
        . '<url><loc>https://example.com/unlinked</loc><lastmod>2026-01-04</lastmod></url>'
        . '<url><loc>https://example.com/x1</loc><lastmod>2026-01-04</lastmod></url>'
        . '<url><loc>https://example.com/x2</loc><lastmod>2026-01-04</lastmod></url>'
        . '<url><loc>https://example.com/x3</loc><lastmod>2026-01-04</lastmod></url>'
        . '</urlset>',
);
$crawler = new Crawler(new StubFetcher($sitemapSite));
$pages = $crawler->crawl('https://example.com/', 10);
$paths = array();
foreach ($pages as $page) {
    $paths[] = (string) parse_url($page['url'], PHP_URL_PATH);
}
ok(in_array('/unlinked', $paths, true), 'reads a page only the sitemap knows about',
   implode(' ', $paths));

$sm = $crawler->sitemap();
ok($sm['urls'] === 6, 'counts what the sitemap listed', (string) $sm['urls']);

// A crawl spends its seconds on pages, not on one page's original source.
$stub = new StubFetcher($sitemapSite);
(new Crawler($stub))->crawl('https://example.com/', 3);
$mapsFlag = new ReflectionProperty('Fetcher', 'readSourceMaps');
$mapsFlag->setAccessible(true);
ok($mapsFlag->getValue($stub) === false, 'a crawl turns source-map reading off');
ok($mapsFlag->getValue(new Fetcher()) === true, 'and single-page mode leaves it on');

// Every page stamped with the same day: the sitemap was written in one pass.
$r = (new SiteSurvey('https://example.com/', pageSet(array('/' => stubPage('Home'))), array(), $sm))->analyze();
ok($r->has('xs.sitemap_one_pass'), 'one lastmod across the whole site is a finding');

// A spread of dates is the opposite finding.
$grownMap = array('urls' => 9, 'lastmods' => array(
    '2024-02-11', '2024-05-30', '2024-09-02', '2025-01-19', '2025-04-07',
    '2025-08-23', '2026-01-15', '2026-03-30',
));
$r = (new SiteSurvey('https://example.com/', pageSet(array('/' => stubPage('Home'))), array(), $grownMap))->analyze();
ok($r->has('xs.sitemap_history'), 'dates spread over months read as a worked-on site');
ok(!$r->has('xs.sitemap_one_pass'), 'and not as a generated one');

// A sitemap with no dates at all says the same thing as one date.
$undated = array('urls' => 12, 'lastmods' => array());
$r = (new SiteSurvey('https://example.com/', pageSet(array('/' => stubPage('Home'))), array(), $undated))->analyze();
ok($r->has('xs.sitemap_one_pass'), 'a sitemap with no lastmod column at all also fires');

// Too small to say anything.
$tiny = array('urls' => 3, 'lastmods' => array('2026-01-04'));
$r = (new SiteSurvey('https://example.com/', pageSet(array('/' => stubPage('Home'))), array(), $tiny))->analyze();
ok(!$r->has('xs.sitemap_one_pass') && !$r->has('xs.sitemap_history'),
   'a three-page sitemap is not evidence either way');

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
    if ($s->id === 'cd.assistant_chatter') $ev = implode(' | ', $s->evidenceText());
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

// ------------------------------------------------------- github repository

group('Reading a GitHub repository');

/**
 * A repository in memory.
 *
 * Every request this class would otherwise make is one of sixty an hour that
 * the whole installation shares, so a test suite that made them would be a
 * test suite that could only be run a handful of times a day. The endpoint
 * methods are substituted and nothing else is: the parsing, the budgets, the
 * analysis and every guard in RepoAnalyzer run exactly as they do in
 * production.
 */
class StubGitHub extends GitHub
{
    /** @var array<string,mixed> */
    public $meta = array();
    /** @var array<int,array<string,mixed>> newest first */
    public $log = array();
    /** @var array<int,array{path:string,size:int}> */
    public $treePaths = array();
    /** @var array<string,string> */
    public $blobs = array();
    /** @var array<string,mixed>|null */
    public $opening = null;
    /** @var int */
    public $fileReads = 0;

    public function repository(): array
    {
        return $this->meta + array('full_name' => $this->fullName(), 'default_branch' => 'main');
    }

    public function recentCommits(): array
    {
        return array('commits' => array_slice($this->log, 0, self::COMMITS_PER_PAGE), 'pages' => 1);
    }

    public function commitPage(int $page): array
    {
        return array();
    }

    public function commit(string $sha): ?array
    {
        return $this->opening;
    }

    public function tree(string $ref): array
    {
        return array('paths' => $this->treePaths, 'truncated' => false);
    }

    public function file(string $ref, string $path): ?string
    {
        $this->fileReads++;
        return isset($this->blobs[$path]) ? $this->blobs[$path] : null;
    }
}

/** @return array<string,mixed> one commit in the shape the API returns it */
function commitJson(string $sha, int $ts, string $author, string $subject): array
{
    return array(
        'sha'    => $sha,
        'commit' => array(
            'author'  => array('name' => $author, 'date' => gmdate('c', $ts)),
            'message' => $subject,
        ),
    );
}

/** @param array<int,string> $paths @return array<int,array{path:string,size:int}> */
function treeOf(array $paths, int $size = 3000): array
{
    $out = array();
    foreach ($paths as $path) {
        $out[] = array('path' => $path, 'size' => $size);
    }
    return $out;
}

// --- the owner/name parser -------------------------------------------------

foreach (array(
    'https://github.com/vercel/next.js'                 => 'vercel/next.js',
    'github.com/vercel/next.js/'                        => 'vercel/next.js',
    'https://www.github.com/vercel/next.js.git'         => 'vercel/next.js',
    'https://github.com/vercel/next.js/tree/canary/src' => 'vercel/next.js',
    'https://github.com/vercel/next.js/blob/main/a.js'  => 'vercel/next.js',
    'git@github.com:vercel/next.js.git'                 => 'vercel/next.js',
    'vercel/next.js'                                    => 'vercel/next.js',
    '  vercel/next.js?tab=readme  '                     => 'vercel/next.js',
) as $input => $expected) {
    list($o, $n) = GitHub::parse($input);
    ok($o . '/' . $n === $expected, 'parses ' . trim($input), $o . '/' . $n);
}

foreach (array('', 'vercel', 'https://gitlab.com/a/b/c/d/e', 'https://github.com/../../etc/passwd', str_repeat('a', 500)) as $bad) {
    $refused = false;
    try {
        GitHub::parse($bad);
    } catch (RepoError $e) {
        $refused = true;
    }
    ok($refused, 'refuses ' . ($bad === '' ? 'an empty repository name' : substr($bad, 0, 40)));
}

// Another forge, pasted in good faith, gets told which one this reads rather
// than being silently looked up on GitHub under a name that means nothing there.
$elsewhere = '';
try {
    GitHub::parse('https://gitlab.com/group/project');
} catch (RepoError $e) {
    $elsewhere = $e->getMessage();
}
ok(strpos($elsewhere, 'gitlab.com is not GitHub') === 0,
   'a repository on another forge is refused by name', $elsewhere);

ok(GitHub::lastPage('<https://api.github.com/x?page=2>; rel="next", <https://api.github.com/x?page=47>; rel="last"') === 47,
   'reads the commit count from the Link header');
ok(GitHub::lastPage('') === 1, 'no Link header means one page');

// --- a generated repository ------------------------------------------------

$stub = new StubGitHub('someone', 'ai-saas-starter');
$stub->meta = array(
    'description'      => 'A modern SaaS starter',
    'default_branch'   => 'main',
    'created_at'       => '2026-02-01T10:00:00Z',
    'pushed_at'        => '2026-02-01T16:20:00Z',
    'language'         => 'TypeScript',
    'stargazers_count' => 0,
);

$g0 = strtotime('2026-02-01 10:00:00 UTC');
$stub->log = array(
    commitJson('a70000000000', $g0 + 21600, 'Alex Doe', 'fix build error'),
    commitJson('a60000000000', $g0 + 21000, 'Alex Doe', 'fix missing import'),
    commitJson('a50000000000', $g0 + 20400, 'Alex Doe', 'fix typo'),
    commitJson('a40000000000', $g0 + 9000,  'Alex Doe', 'implement authentication flow with JWT and refresh tokens'),
    commitJson('a30000000000', $g0 + 5400,  'Alex Doe', 'add dashboard page with charts and responsive layout'),
    commitJson('a20000000000', $g0 + 1800,  'Alex Doe', 'add landing page with hero and pricing section'),
    commitJson('a10000000000', $g0,         'Alex Doe', 'initial commit'),
);
$stub->opening = array(
    'sha'    => 'a10000000000',
    'stats'  => array('additions' => 4820, 'deletions' => 0),
    'files'  => array_fill(0, 24, array('filename' => 'x')),
    'commit' => array('message' => "initial commit"),
);
$stub->treePaths = treeOf(array_merge(
    array('CLAUDE.md', '.cursorrules', 'README.md', 'package.json', '.env',
          'IMPLEMENTATION_SUMMARY.md', 'PHASE_2_COMPLETE.md', 'FIXES_APPLIED.md'),
    array_map(function ($i) { return 'src/components/Component' . $i . '.tsx'; }, range(1, 30))
));
$stub->blobs = array(
    'package.json' => json_encode(array(
        'name' => 'vite-project',
        'dependencies' => array(
            'react' => '^18', 'react-dom' => '^18', 'lucide-react' => '^0.4', 'clsx' => '^2',
            'tailwind-merge' => '^2', 'class-variance-authority' => '^0.7', 'sonner' => '^1',
            '@radix-ui/react-dialog' => '^1', '@radix-ui/react-slot' => '^1', '@radix-ui/react-toast' => '^1',
            '@radix-ui/react-tabs' => '^1', '@radix-ui/react-label' => '^2', '@radix-ui/react-select' => '^2',
        ),
        'devDependencies' => array('vite' => '^5', 'lovable-tagger' => '^1.1'),
    ), JSON_PRETTY_PRINT),
    'README.md' => "# ✨ AI SaaS Starter\n\n"
        . str_repeat("A modern, production-ready starter. ", 12) . "\n\n"
        . "## 🚀 Features\n\n"
        . "- **⚡ Blazing Fast** - built on Vite\n"
        . "- **🔒 Secure by Default** - auth included\n"
        . "- **📱 Fully Responsive** - works everywhere\n"
        . "- **🎨 Beautiful UI** - shadcn components\n\n"
        . "## 🛠 Getting Started\n\nnpm install\n\n## 📦 Tech Stack\n\nReact, Vite\n\n"
        . "## 🤝 Contributing\n\nPRs welcome!\n\n## 📄 License\n\nMIT\n\nMade with ❤️ by the team\n",
);

$rr = (new RepoAnalyzer($stub))->analyze();
$ra = $rr->toArray();

ok($ra['mode'] === 'repo', 'reports the repository mode');
ok($ra['target'] === 'github.com/someone/ai-saas-starter', 'names the repository as the subject', $ra['target']);
ok($rr->has('fp.lovable'), 'reads a builder fingerprint out of the manifest');
ok($rr->has('rp.agent_config'), 'catches an assistant configuration committed with the code');
ok($rr->has('rp.session_docs'), 'catches the pile of session summaries');
ok($rr->has('rp.readme_generated'), 'catches a README made of the standard sections');
ok($rr->has('rp.no_tests'), 'catches a substantial tree with nothing tested');
ok($rr->has('se.committed_secrets'), 'catches a committed .env');
ok($rr->has('st.untouched_scaffold'), 'catches the package nobody renamed');
ok($rr->has('st.generated_stack'), 'catches the whole component kit arriving at once');
ok($rr->has('rp.dependency_soup'), 'catches dependencies declared and never locked');
ok($rr->has('gh.big_bang'), 'catches a repository that arrived fully formed');
ok($rr->has('gh.micro_fix_trail'), 'catches the trail of one-line fixes');
ok($rr->has('gh.prompt_messages'), 'catches commit messages that read like the prompt');
between($ra['score'], 90, 97, 'a repository with a fingerprint in it scores at the top');
ok($ra['stats']['commits'] === 7, 'counts the commits it read', (string) $ra['stats']['commits']);
ok($ra['stats']['files'] === 38, 'counts the files in the tree', (string) $ra['stats']['files']);

// --- a hand-written repository ---------------------------------------------

$human = new StubGitHub('dana', 'ledger');
$human->meta = array(
    'description'      => 'Double-entry bookkeeping for small co-ops',
    'default_branch'   => 'main',
    'created_at'       => '2021-06-14T08:00:00Z',
    'pushed_at'        => '2026-01-20T09:00:00Z',
    'language'         => 'Python',
    'stargazers_count' => 240,
);
$day = 86400;
$h0 = strtotime('2025-09-02 09:14:00 UTC');
$human->log = array();
$subjects = array(
    'fix rounding on partial refunds (#412)', 'Merge pull request #410 from dana/vat-rates',
    'ugh, timezone again', 'revert "cache the rate table"', 'cache the rate table',
    'bump minimum python to 3.9', 'add regression test for the 2019 import bug',
    'tidy the settings loader', 'ACC-88 correct the closing balance', 'oops, wrong sign',
);
foreach ($subjects as $i => $subject) {
    $author = ($i % 3 === 0) ? 'Dana Cole' : (($i % 3 === 1) ? 'Ravi Patel' : 'Jo Mensah');
    $human->log[] = commitJson(sprintf('%012x', 0xb0000 + $i), $h0 + ($i * 4 * $day), $author, $subject);
}
$human->log = array_reverse($human->log);
$human->opening = array(
    'sha'    => sprintf('%012x', 0xb0000),
    'stats'  => array('additions' => 62, 'deletions' => 0),
    'files'  => array(array('filename' => 'ledger.py')),
    'commit' => array('message' => 'start on the ledger'),
);
$human->treePaths = array_merge(
    treeOf(array('README.md', 'CHANGELOG.md', 'CONTRIBUTING.md', 'CODE_OF_CONDUCT.md',
                 '.github/ISSUE_TEMPLATE/bug.yml', '.github/workflows/ci.yml')),
    treeOf(array_map(function ($i) { return 'ledger/module' . $i . '.py'; }, range(1, 30))),
    treeOf(array_map(function ($i) { return 'tests/test_module' . $i . '.py'; }, range(1, 12)))
);
$human->blobs = array('README.md' => "# ledger\n\n" . str_repeat("Bookkeeping for co-operatives that file their own returns. ", 20));

$hr = (new RepoAnalyzer($human))->analyze();
$ha = $hr->toArray();

ok($hr->has('rp.project_furniture'), 'reads the furniture a used project acquires');
ok($hr->has('rp.tests_present'), 'reads a test suite in proportion to the code');
ok($hr->has('gh.multiple_authors'), 'reads collaboration out of the commit authors');
ok($hr->has('gh.steady_cadence'), 'reads work spread across real time');
ok($hr->has('gh.human_mess'), 'reads audible frustration in the messages');
ok(!$hr->has('rp.agent_config'), 'invents no assistant configuration');
ok(!$hr->has('rp.no_tests'), 'does not claim an absence it can see is not there');
ok(!$hr->has('se.committed_secrets'), 'invents no committed secrets');
ok(!$hr->has('gh.big_bang'), 'a small opening commit is not a big bang');
ok($ha['score'] < 40, 'a repository that was worked on reads as human', (string) $ha['score']);

// A merge commit's own number is generated by GitHub, not typed by a person,
// so it must not be read as somebody referencing a ticket.
$merges = new GitAnalyzer(implode("\n", array(
    'aa1111111111|' . $h0 . '|Dana|Merge pull request #12 from dana/x',
    'bb2222222222|' . ($h0 + 3600) . '|Dana|Merge pull request #13 from dana/y',
    'cc3333333333|' . ($h0 + 7200) . '|Dana|Merge pull request #14 from dana/z',
    'dd4444444444|' . ($h0 + 9000) . '|Dana|tidy the settings loader',
)));
$mr = $merges->analyze();
ok(count($merges->commits()) === 4, 'the merge fixture parses', (string) count($merges->commits()));
ok(!$mr->has('gh.issue_refs'),
   'a run of merge commits is not a run of ticket references');
ok($mr->has('gh.merges_and_reverts'), 'merges are still read as merges');

// A number somebody typed still counts, even in a log that also has merges.
$typed = new GitAnalyzer(implode("\n", array(
    'aa1111111111|' . $h0 . '|Dana|Merge pull request #12 from dana/x',
    'bb2222222222|' . ($h0 + 3600) . '|Dana|fix the closing balance (#31)',
    'cc3333333333|' . ($h0 + 7200) . '|Dana|ACC-88 correct the VAT rate',
)));
ok($typed->analyze()->has('gh.issue_refs'), 'a number a person typed is still a ticket reference');

// --- what it does when there is nothing to read ----------------------------

$thin = new StubGitHub('nobody', 'sketch');
$thin->meta = array('default_branch' => 'main', 'created_at' => '2026-01-01T00:00:00Z');
$thin->log = array(commitJson('c10000000000', strtotime('2026-01-01 12:00:00 UTC'), 'Nobody', 'first'));
$thin->treePaths = treeOf(array('index.html'), 600);

$tr = (new RepoAnalyzer($thin))->analyze();
$ta = $tr->toArray();
ok(count($ta['signals']) === 0 || $ta['confidence']['level'] === 'insufficient',
   'one commit and one file is not a reading');
ok($ta['score'] >= Report::FLOOR && $ta['score'] <= Report::CEIL, 'the score stays inside the scale');

// The file budget is a budget: a large tree must not become a large number of
// downloads, however many candidates it has.
$big = new StubGitHub('someone', 'monorepo');
$big->meta = array('default_branch' => 'main', 'created_at' => '2026-01-01T00:00:00Z');
$big->log = array(commitJson('d10000000000', strtotime('2026-01-02 12:00:00 UTC'), 'Someone', 'work'));
$big->treePaths = treeOf(array_map(function ($i) { return 'src/file' . $i . '.ts'; }, range(1, 400)), 9000);
foreach ($big->treePaths as $entry) {
    $big->blobs[$entry['path']] = str_repeat("export const value = 1;\n", 200);
}
(new RepoAnalyzer($big))->analyze();
ok($big->fileReads <= RepoAnalyzer::MAX_CODE_FILES + 2,
   'a four-hundred-file repository is still read in a handful of requests', (string) $big->fileReads);

// A credential must not survive a redirect to another host. Exercised through
// the header assembly and the host comparison the redirect loop uses, because
// the loop itself cannot run here without a server to redirect from.
$hdr = new ReflectionMethod('Fetcher', 'requestHeaders');
$hdr->setAccessible(true);
$assembled = $hdr->invoke(null, array('Authorization' => 'Bearer secret', 'Accept' => 'application/vnd.github+json'));
ok(in_array('Authorization: Bearer secret', $assembled, true), 'a caller header is sent');
ok(in_array('Accept: application/vnd.github+json', $assembled, true), 'a caller Accept replaces the default');
ok(count($assembled) === 2, 'and does not arrive alongside the default it replaced', (string) count($assembled));

$injected = $hdr->invoke(null, array("X-Bad
X-Injected" => 'yes', 'X-Fine' => "a
b"));
ok(count($injected) === 1 && strpos($injected[0], 'Accept:') === 0,
   'a header with a newline in either half is dropped rather than sent');

$hostOf = new ReflectionMethod('Fetcher', 'hostOf');
$hostOf->setAccessible(true);
ok($hostOf->invoke(null, 'https://API.github.com/repos/a/b') === 'api.github.com',
   'the host comparison that guards the credential is case-insensitive');
ok($hostOf->invoke(null, 'https://api.github.com.evil.example/x') !== $hostOf->invoke(null, 'https://api.github.com/x'),
   'and is not fooled by a lookalike suffix');

ok(GitHub::MAX_REQUESTS <= 10,
   'one scan cannot spend a sixth of the hourly GitHub allowance', (string) GitHub::MAX_REQUESTS);
ok(RepoAnalyzer::TIME_BUDGET <= 25,
   'a repository read leaves room to render inside a 30s request', (string) RepoAnalyzer::TIME_BUDGET);
ok(VCD_LIMIT_REPO[0] * GitHub::MAX_REQUESTS <= 100,
   'one visitor cannot spend more than the hourly allowance in a single window',
   sprintf('%d requests', VCD_LIMIT_REPO[0] * GitHub::MAX_REQUESTS));


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

// Neon, and a gradient covering the page rather than an element on it. Both
// live on their own fixture: neither is present on the one above, and a check
// that fires on a page which does not have the thing is worth nothing.
$neon = '<!DOCTYPE html><html lang="en"><head><title>Cyberflux</title>'
    . '<style>body{background:linear-gradient(135deg,#0b0f2b,#1a0b2e 50%,#2b0b3a)}'
    . '.title{color:#00ffff;text-shadow:0 0 20px #00ffff}'
    . '.cta{background:#ff00ff;box-shadow:0 0 30px #ff00ff}'
    . '.tag{color:#39ff14}</style></head><body>'
    . '<h1 class="title">Enter the grid</h1><a class="cta">Jack in</a>'
    . str_repeat('<p>Ordinary body copy for length here.</p>', 20)
    . '</body></html>';
$rn2 = (new SiteAnalyzer('https://cyberflux.example.com/', $neon))->analyze();
ok($rn2->has('ae.neon_palette'), 'catches neon colours and the glow behind them');
ok($rn2->has('ae.gradient_background'), 'catches a gradient on the page ground itself');

// The same two, built out of utilities instead of declarations.
$neonTw = '<!DOCTYPE html><html lang="en"><head><title>Grid</title></head>'
    . '<body class="min-h-screen bg-gradient-to-b from-slate-900 to-indigo-950">'
    . '<section class="min-h-screen bg-gradient-to-br from-fuchsia-500 to-cyan-400">'
    . '<p class="text-cyan-400 shadow-[0_0_40px_rgba(0,255,255,0.6)]">glow</p>'
    . '<span class="text-fuchsia-400"></span><span class="border-lime-400"></span><span class="bg-cyan-300"></span>'
    . '</section>' . str_repeat('<p>Ordinary body copy for length here.</p>', 20) . '</body></html>';
$rn3 = (new SiteAnalyzer('https://grid.example.com/', $neonTw))->analyze();
ok($rn3->has('ae.neon_palette'), 'catches the same palette as utility classes');
ok($rn3->has('ae.gradient_background'), 'catches a full-height surface carrying a gradient');

// A gradient clipped to a headline is the headline tell, not the background one.
$clipped = '<!DOCTYPE html><html lang="en"><head><title>x</title></head><body>'
    . '<h1 class="bg-gradient-to-r from-indigo-500 to-violet-500 bg-clip-text text-transparent">Ship faster</h1>'
    . str_repeat('<p>Ordinary body copy for length here.</p>', 20) . '</body></html>';
$rc = (new SiteAnalyzer('https://clip.example.com/', $clipped))->analyze();
ok($rc->has('ae.gradient_text'), 'the gradient headline still fires on its own');
ok(!$rc->has('ae.gradient_background'), 'a headline gradient is not a background gradient');

// Six aesthetic signals, and the group cap still holds the score down.
ok($r->countAi(true) === 0 || $r->score() <= 55 || $r->countAi(true) > 0,
   'aesthetic signals are counted');
$aestheticOnly = new Report('url', 'x');
foreach (array('ae.hero_pill', 'ae.scroll_indicator', 'ae.glow_orbs', 'ae.bento_grid',
               'ae.logo_marquee', 'ae.floating_nav', 'ae.indigo', 'ae.gradient_text',
               'ae.neon_palette', 'ae.gradient_background') as $id) {
    $aestheticOnly->flag($id, array('x'));
}
ok($aestheticOnly->score() <= 55,
   'ten visual signals together still cannot pass 55%', (string) $aestheticOnly->score());

// A restrained page must not collect them by accident.
$plain = '<!DOCTYPE html><html lang="en"><head><title>Shop</title></head><body>'
    . '<header><a href="/">Shop</a></header><h1>Pain au levain</h1>'
    . str_repeat('<p>Ordinary body copy that goes on for a while.</p>', 20)
    . '</body></html>';
$rp = (new SiteAnalyzer('https://shop.example.fr/', $plain))->analyze();
foreach (array('ae.hero_pill', 'ae.scroll_indicator', 'ae.glow_orbs', 'ae.bento_grid',
               'ae.logo_marquee', 'ae.floating_nav', 'ae.neon_palette', 'ae.gradient_background') as $id) {
    ok(!$rp->has($id), 'a plain page does not trip ' . $id);
}

// One saturated accent is a colour, not a palette decision.
$oneAccent = '<!DOCTYPE html><html lang="en"><head><title>Shop</title>'
    . '<style>body{background:#fff}.badge{color:#00ffff}</style></head><body>'
    . '<h1>Pain au levain</h1>'
    . str_repeat('<p>Ordinary body copy that goes on for a while.</p>', 20) . '</body></html>';
ok(!(new SiteAnalyzer('https://one.example.fr/', $oneAccent))->analyze()->has('ae.neon_palette'),
   'a single cyan accent is not a neon palette');

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

// Inference, however much of it converges, stops short of identification.
$everything = new Report('url', 'everything');
foreach (array('st.untouched_scaffold', 'st.generated_stack', 'st.client_only_backend', 'st.spa_shell',
               'se.exposed_client_key', 'se.client_side_auth', 'se.insecure_defaults',
               'ct.marketing_cliche', 'ct.generic_names', 'ct.stat_inflation',
               'ae.indigo', 'ae.gradient_text', 'ae.hero_pill', 'ae.glow_orbs', 'ae.lucide') as $id) {
    $everything->flag($id, array('x'));
}
ok($everything->score() <= Report::INFERENCE_CEIL,
   'fifteen converging inferential signals still stop below the fingerprint band',
   (string) $everything->score());
ok($everything->score() < $fingerprinted->score(),
   'and still score below one builder naming itself',
   $everything->score() . ' vs ' . $fingerprinted->score());
ok($everything->score() >= 85, 'but they do produce a strong reading', (string) $everything->score());

$alsoPrinted = new Report('url', 'also-printed');
foreach (array('st.untouched_scaffold', 'se.exposed_client_key', 'fp.lovable') as $id) {
    $alsoPrinted->flag($id, array('x'));
}
ok($alsoPrinted->score() > Report::INFERENCE_CEIL,
   'a fingerprint lifts the same reading past the ceiling',
   (string) $alsoPrinted->score());

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

// ----------------------------------------------------- fetcher: what it reads

group('Choosing what to read and how to read it');

$rank = new ReflectionMethod('Fetcher', 'assetRank');
$rank->setAccessible(true);
$order = array(
    'https://x.example.com/js/gtm.js',
    'https://x.example.com/assets/index-Ba7Xk9Lm.js',
    'https://x.example.com/assets/index-C3xY1pQz.css',
    'https://x.example.com/js/cookie-consent.js',
);
$ranked = array();
foreach ($order as $u) {
    $ranked[$u] = $rank->invoke($f, $u);
}
arsort($ranked);
$best = array_keys($ranked);
ok(substr($best[0], -4) === '.css', 'the stylesheet is read first', $best[0]);
ok(strpos($best[1], 'index-Ba7Xk9Lm.js') !== false, 'then the application bundle', $best[1]);
ok($ranked['https://x.example.com/js/gtm.js'] < 0
   && $ranked['https://x.example.com/js/cookie-consent.js'] < 0,
   'analytics and consent scripts go to the back of the queue');

$parse = new ReflectionMethod('Fetcher', 'parseHeaders');
$parse->setAccessible(true);
$h = $parse->invoke($f, "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\nX-Vercel-Id: cdg1::abc\r\n"
                      . "Set-Cookie: a=1\r\nSet-Cookie: b=2\r\n");
ok(($h['content-type'] ?? '') === 'text/html', 'header names come back lowercased');
ok(($h['set-cookie'] ?? '') === 'a=1, b=2', 'repeated headers are joined rather than lost',
   (string) ($h['set-cookie'] ?? 'missing'));

// Only the last block: an early-hints or continue response is not the response.
$h = $parse->invoke($f, "HTTP/1.1 103 Early Hints\r\nLink: </a.css>\r\n\r\nHTTP/1.1 200 OK\r\nServer: real\r\n");
ok(($h['server'] ?? '') === 'real' && !isset($h['link']),
   'only the final header block is kept');

$readMap = new ReflectionMethod('Fetcher', 'readMap');
$readMap->setAccessible(true);
$map = $readMap->invoke($f, 'https://x.example.com/a.js.map', json_encode(array(
    'version' => 3,
    'sources' => array('src/App.tsx', 'node_modules/react/index.js', '../src/lib/utils.ts'),
    'sourcesContent' => array('// mine', '// somebody else\'s', '// also mine'),
)));
ok(is_array($map) && count($map['sources']) === 2, 'dependencies are left out of the reading',
   is_array($map) ? implode(',', $map['sources']) : 'null');
ok(is_array($map) && strpos($map['content'], "somebody else") === false,
   'and so is their source');
ok($readMap->invoke($f, 'u', '{"version":3}') === null, 'a map with no sources is not a map');
ok($readMap->invoke($f, 'u', 'not json at all') === null, 'and neither is anything unparseable');

$dataUri = new ReflectionMethod('Fetcher', 'decodeDataUri');
$dataUri->setAccessible(true);
ok($dataUri->invoke($f, 'data:application/json;base64,' . base64_encode('{"a":1}')) === '{"a":1}',
   'an inline base64 source map is decoded without a second request');
ok($dataUri->invoke($f, 'data:application/json,%7B%22a%22%3A1%7D') === '{"a":1}',
   'and so is a percent-encoded one');

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

// ------------------------------------------------------------ api key auth

group('API key auth');

$keyFile = VCD_DATA . '/api-keys.txt';
$hadKeyFile = is_file($keyFile);
$priorKeyFile = $hadKeyFile ? (string) file_get_contents($keyFile) : null;
$keysWritable = is_dir(VCD_DATA) && is_writable(VCD_DATA);

if ($keysWritable) {
    file_put_contents($keyFile, "# note for a human\n\nsk_test_first\nsk_test_second\n");

    ok(vcd_api_keys() === array('sk_test_first', 'sk_test_second'),
       'blank lines and #-comments are skipped when reading the key file');
    ok(vcd_api_key_valid('sk_test_first'), 'a key present in the file validates');
    ok(vcd_api_key_valid('sk_test_second'), 'so does a second key on its own line');
    ok(!vcd_api_key_valid('sk_test_wrong'), 'a key not in the file is rejected');
    ok(!vcd_api_key_valid(''), 'an empty key is rejected outright');
    ok(!vcd_api_key_valid('# note for a human'), 'a comment line is never itself a valid key');

    unlink($keyFile);
    ok(vcd_api_keys() === array(), 'no configured keys once the file is gone');
    ok(!vcd_api_key_valid('sk_test_first'), 'and nothing validates once the file is gone');

    if ($priorKeyFile !== null) {
        file_put_contents($keyFile, $priorKeyFile);
    }
} else {
    ok(vcd_api_keys() === array(), 'an unreadable data directory yields no keys, not an error');
}

$_SERVER['HTTP_X_API_KEY'] = ' sk_from_header ';
ok(vcd_request_api_key() === 'sk_from_header', 'the API key is read from the X-Api-Key header, trimmed');
unset($_SERVER['HTTP_X_API_KEY']);
ok(vcd_request_api_key() === '', 'no header means no key');

// The rate limiter has to be keyable by something other than the caller's
// IP, so that one API key shares one budget across every machine using it.
if ($keysWritable) {
    $bucket = 'apitest' . bin2hex(random_bytes(4));
    $allowed = 0;
    for ($i = 0; $i < 8; $i++) {
        if (vcd_rate_limit($bucket, 5, 600, 'key:sk_shared')) {
            $allowed++;
        }
    }
    ok($allowed === 5, 'a rate limit keyed by API key is enforced independently of IP', $allowed . ' of 8 allowed');
    foreach ((array) glob(VCD_DATA . '/rate/' . $bucket . '-*.txt') as $tmp) {
        @unlink($tmp);
    }
}

ok(count(VCD_LIMIT_API_URL) === 2 && VCD_LIMIT_API_URL[0] > VCD_LIMIT_URL[0],
   'the API budget is a sane [count, seconds] pair and roomier than the anonymous UI limit',
   implode('/', VCD_LIMIT_API_URL) . ' vs ' . implode('/', VCD_LIMIT_URL));

// -------------------------------------------------- optional database layer

group('Database and admin panel (optional)');

// The database is additive: a fresh checkout with no data/db-config.php must
// behave exactly as if this whole feature did not exist.
$hadDbConfig = is_file(VCD_DATA . '/db-config.php');
if (!$hadDbConfig) {
    ok(Db::connect() === null, 'with no db-config.php, Db::connect() returns null rather than throwing');
    ok(!Db::available(), 'and Db::available() agrees');

    // Usage logging must be a no-op, not a crash, when there is nowhere to log to.
    $threw = false;
    try {
        UsageLog::record('ui', null, 'url', 'https://example.com/');
    } catch (Throwable $e) {
        $threw = true;
    }
    ok(!$threw, 'UsageLog::record() is silent with no database configured');
}

$k1 = ApiKeys::generate();
$k2 = ApiKeys::generate();
ok(strpos($k1, 'vcd_') === 0, 'a generated API key has a recognisable prefix', $k1);
ok($k1 !== $k2, 'two generated keys are not the same');
ok(strlen($k1) === strlen('vcd_') + 48, 'a generated key has the expected length (24 random bytes, hex-encoded)');

// Admin auth must fail closed: with no data/admin-password.php, nothing logs in.
$hadAdminPassword = is_file(VCD_DATA . '/admin-password.php');
if (!$hadAdminPassword) {
    ok(!AdminAuth::attempt('anything'), 'with no admin-password.php, every password is rejected');
    ok(!AdminAuth::attempt(''), 'including an empty one');
}

// ------------------------------------------------------------ visit logging

group('Counting visits without identifying anyone');

// The visitor token is the whole privacy question in one function: it has to
// count two people as two, and it must not recognise anybody tomorrow.
$ua = 'Mozilla/5.0 (Macintosh) AppleWebKit/537.36 Chrome/120 Safari/537.36';
$monday = VisitLog::visitorToken($ua, '198.51.100.7', '2026-08-17');

ok($monday === VisitLog::visitorToken($ua, '198.51.100.7', '2026-08-17'),
   'the same visitor on the same day counts once');
ok($monday !== VisitLog::visitorToken($ua, '198.51.100.8', '2026-08-17'),
   'a different address is a different visitor');
ok($monday !== VisitLog::visitorToken('curl/8.4.0', '198.51.100.7', '2026-08-17'),
   'so is a different client from the same address');
ok($monday !== VisitLog::visitorToken($ua, '198.51.100.7', '2026-08-18'),
   'and the same visitor tomorrow is nobody the table has seen before');
ok(strlen($monday) === 16 && ctype_xdigit($monday), 'the token is a short hex digest', $monday);
ok(strpos($monday, '198.51.100.7') === false && stripos($monday, 'chrome') === false,
   'and carries none of its input');

// The query string is where a URL somebody asked about ends up, and where a
// certificate payload ends up. Neither belongs in a table about page views.
ok(VisitLog::normalisePath('/verify?p=eyJhbGciOi&s=abc') === '/verify',
   'the query string is dropped, not trimmed', VisitLog::normalisePath('/verify?p=x&s=y'));
ok(VisitLog::normalisePath('/index.php') === '/', 'the index resolves to the root');
ok(VisitLog::normalisePath('signs') === '/signs', 'a bare path is anchored');
ok(strlen(VisitLog::normalisePath('/' . str_repeat('a', 400))) === 255, 'and a silly one is cut');

// A full referrer carries the search somebody typed. The host does not.
ok(VisitLog::refererHost('https://news.ycombinator.com/item?id=123') === 'news.ycombinator.com',
   'a referrer is reduced to its host');
ok(VisitLog::refererHost('') === null, 'no referrer is no referrer');
ok(VisitLog::refererHost('not a url') === null, 'and neither is nonsense');
$selfHost = (string) parse_url(vcd_site_url(), PHP_URL_HOST);
ok(VisitLog::refererHost('https://' . $selfHost . '/signs') === null,
   'arriving from another page of this site is navigation, not a referral');

ok(VisitLog::deviceOf('Mozilla/5.0 (compatible; Googlebot/2.1)') === 'bot', 'Googlebot is a bot');
ok(VisitLog::deviceOf('ClaudeBot/1.0') === 'bot', 'so is ClaudeBot');
ok(VisitLog::deviceOf('curl/8.4.0') === 'bot', 'and so is curl');
ok(VisitLog::deviceOf('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Safari/604.1') === 'mobile', 'an iPhone is mobile');
ok(VisitLog::deviceOf('Mozilla/5.0 (iPad; CPU OS 17_0) Safari/604.1') === 'tablet', 'an iPad is a tablet');
ok(VisitLog::deviceOf($ua) === 'desktop', 'a desktop browser is a desktop');
ok(VisitLog::deviceOf('') === 'other', 'and nothing at all is nothing at all');

// The chart is drawn day by day, so the days have to be there — including the
// ones nothing happened on. A window that silently drops its quiet days draws
// a fortnight of silence as a short fortnight rather than a quiet one.
$now = (int) strtotime('2026-08-22 09:15:00 UTC');
$filled = VisitLog::fillDays(array('2026-08-20' => array('views' => 12, 'visitors' => 9)), 7, $now);
ok(count($filled) === 7, 'a seven-day window has seven days in it', (string) count($filled));
ok($filled[0]['day'] === '2026-08-16', 'starting seven days ago, today included', $filled[0]['day']);
ok($filled[6]['day'] === '2026-08-22', 'and ending today', $filled[6]['day']);
ok($filled[4]['views'] === 12 && $filled[4]['visitors'] === 9, 'the day with traffic keeps its numbers');
ok($filled[5]['views'] === 0 && $filled[3]['views'] === 0, 'and the quiet days are zeroes, not gaps');

$days = array();
foreach ($filled as $row) {
    $days[] = $row['day'];
}
ok($days === array_unique($days), 'no day appears twice');
$sorted = $days;
sort($sorted);
ok($days === $sorted, 'and they come out oldest first');

// Same contract as UsageLog: with no database, recording is a silent no-op.
if (!is_file(VCD_DATA . '/db-config.php')) {
    $threw = false;
    try {
        VisitLog::record('/');
    } catch (Throwable $e) {
        $threw = true;
    }
    ok(!$threw, 'VisitLog::record() is silent with no database configured');
    ok(Db::option('log_visits', true) === true, 'and an unset option falls back to its default');
}

// --------------------------------------------------------------- charts

group('Charts drawn without a chart library');

$series = array();
foreach (range(1, 30) as $i) {
    $series[] = array(
        'day'      => gmdate('Y-m-d', strtotime('2026-08-01 UTC') + ($i - 1) * 86400),
        'views'    => $i * 3,
        'visitors' => $i,
    );
}
$svg = Chart::daily($series);
ok(strpos($svg, '<svg') === 0, 'produces an SVG element');
ok(substr_count($svg, '<rect') === 30, 'one column per day', (string) substr_count($svg, '<rect'));
ok(strpos($svg, 'role="img"') !== false && strpos($svg, '<title>') !== false,
   'carries a text alternative rather than being a picture of a number');
ok(strpos($svg, 'viewBox') !== false && strpos($svg, 'width="720px"') === false,
   'scales with its container rather than being pinned to a pixel size');
ok(strpos($svg, 'class="c-bar"') !== false && strpos($svg, 'fill="#') === false,
   'takes its colour from the stylesheet, so it follows the theme');

// A day with nothing in it has to draw as nothing, not as a full-height bar.
$quiet = array(
    array('day' => '2026-08-01', 'views' => 0, 'visitors' => 0),
    array('day' => '2026-08-02', 'views' => 0, 'visitors' => 0),
);
$svgQuiet = Chart::daily($quiet);
ok(strpos($svgQuiet, '<rect') === false, 'an empty window draws no columns at all');
ok(Chart::daily(array()) === '', 'and no data draws nothing');

$hours = array_fill(0, 24, 0);
$hours[9] = 40;
$hours[14] = 10;
$hourSvg = Chart::hours($hours);
ok(substr_count($hourSvg, '<rect') === 2, 'the hour chart draws only the hours with traffic');
ok(Chart::hours(array_fill(0, 24, 0)) === '', 'and nothing when nothing happened');

ok(Chart::barWidth(5, 10) === '50.0%', 'in-table bars are a percentage of the biggest row');
ok(Chart::barWidth(0, 10) === '0%', 'zero is zero');
ok(Chart::barWidth(5, 0) === '0%', 'and nothing is divided by nothing');
ok(Chart::barWidth(20, 10) === '100.0%', 'a row bigger than the maximum is still one bar wide');

// Text from the outside — a path, a referrer host — is escaped on the way in.
$evil = array(array('day' => '2026-08-01', 'views' => 3, 'visitors' => 1));
$svgEvil = Chart::daily($evil, '<script>alert(1)</script>');
ok(strpos($svgEvil, '<script>') === false, 'a label cannot inject markup into the chart');

// The single-series chart is the same picture with the line taken off, for a
// quantity that has no second quantity to draw beside it.
$single = array();
foreach (range(1, 30) as $i) {
    $single[] = array('day' => gmdate('Y-m-d', strtotime('2026-08-01 UTC') + ($i - 1) * 86400), 'n' => $i % 4);
}
$seriesSvg = Chart::series($single, 'Analyses per day for example.com');
ok(strpos($seriesSvg, '<svg') === 0, 'the single-series chart produces an SVG element');
ok(strpos($seriesSvg, '<polyline') === false, 'and draws no line, because there is nothing to compare');
$drawn = 0;
foreach ($single as $row) {
    if ($row['n'] > 0) {
        $drawn++;
    }
}
ok(substr_count($seriesSvg, '<rect') === $drawn, 'a day with nothing in it draws nothing',
   substr_count($seriesSvg, '<rect') . ' columns for ' . $drawn . ' busy days');
ok(strpos($seriesSvg, 'class="c-bar"') !== false && strpos($seriesSvg, 'fill="#') === false,
   'and it takes its colour from the stylesheet like every other chart');
ok(Chart::series(array()) === '', 'no data draws nothing');

// An axis reading "1, 1, 0" says the scale is broken rather than that the
// traffic is small: the halfway label rounds to the top one and is dropped.
$tiny = array(array('day' => '2026-08-01', 'n' => 1), array('day' => '2026-08-02', 'n' => 0));
preg_match_all('~class="c-axis" text-anchor="end">(\d+)<~', Chart::series($tiny), $ticks);
ok($ticks[1] === array_unique($ticks[1]), 'the axis never labels two gridlines with the same number',
   implode(',', $ticks[1]));
$big = array(array('day' => '2026-08-01', 'n' => 40), array('day' => '2026-08-02', 'n' => 10));
preg_match_all('~class="c-axis" text-anchor="end">(\d+)<~', Chart::series($big), $bigTicks);
ok($bigTicks[1] === array('0', '20', '40'), 'and a scale with room for three still gets three',
   implode(',', $bigTicks[1]));
// An axis is read sideways at nine pixels, so it gets the same shortening as
// every other counter — five digits on a gridline is false precision.
$tall = array(array('day' => '2026-08-01', 'n' => 17925), array('day' => '2026-08-02', 'n' => 4));
preg_match_all('~class="c-axis" text-anchor="end">([^<]*)<~', Chart::series($tall), $tallTicks);
ok(in_array('17k', $tallTicks[1], true), 'a tall axis is labelled in thousands',
   implode(',', $tallTicks[1]));
ok(!in_array('17925', $tallTicks[1], true), 'and never spells the whole number out',
   implode(',', $tallTicks[1]));

$quietDaily = array(array('day' => '2026-08-01', 'views' => 1, 'visitors' => 1));
preg_match_all('~class="c-axis" text-anchor="end">(\d+)<~', Chart::daily($quietDaily), $dailyTicks);
ok($dailyTicks[1] === array_unique($dailyTicks[1]), 'the traffic chart gets the same treatment',
   implode(',', $dailyTicks[1]));

// A bucket carries its own label, and it has to reach the tooltip.
$labelled = array(array('day' => '2026-08-01', 'n' => 5, 'label' => '1 Aug – 7 Aug'));
ok(strpos(Chart::series($labelled), '1 Aug – 7 Aug — 5 analyses') !== false,
   'a week-long column says which week it is', Chart::series($labelled));
$flat = array(array('day' => '2026-08-01', 'n' => 0), array('day' => '2026-08-02', 'n' => 0));
ok(strpos(Chart::series($flat), '<rect') === false, 'and a flat zero window is not a full-height bar');
ok(strpos(Chart::series($single, '<script>alert(1)</script>'), '<script>') === false,
   'a label cannot inject markup here either');

// --------------------------------------------------- paging a long list

group('Paging a long list');

// The two things that go wrong with pagination are both arithmetic: a last
// page one row short, and a strip of numbers that runs off the screen.
ok(Pager::totalPages(0, 40) === 1, 'an empty list is page 1 of 1');
ok(Pager::totalPages(40, 40) === 1, 'exactly one page full is one page');
ok(Pager::totalPages(41, 40) === 2, 'and one more row is a second page');
ok(Pager::totalPages(400, 40) === 10, 'four hundred websites is ten pages');
ok(Pager::totalPages(-5, 40) === 1, 'a negative count is still one page');

ok(Pager::clamp(0, 10) === 1, 'a page number below the list is the first page');
ok(Pager::clamp(99, 10) === 10, 'a stale bookmark past the end is the last page, not an error');
ok(Pager::clamp(4, 10) === 4, 'and a real page number is left alone');
ok(Pager::clamp(3, 0) === 1, 'an empty list has nowhere to go but page one');

ok(Pager::offset(1, 40) === 0, 'page one starts at the top');
ok(Pager::offset(3, 40) === 80, 'page three skips the first eighty');
ok(Pager::offset(0, 40) === 0, 'and there is no negative offset');

// Short lists get every number; long ones get the ends, the middle, and gaps.
ok(Pager::window(1, 5) === array(1, 2, 3, 4, 5), 'five pages are all shown',
   implode(',', Pager::window(1, 5)));
$mid = Pager::window(20, 40);
ok($mid[0] === 1 && $mid[count($mid) - 1] === 40, 'the first and last page are always reachable',
   implode(',', $mid));
ok(in_array(0, $mid, true), 'with gaps rather than forty numbers', implode(',', $mid));
ok(in_array(20, $mid, true) && in_array(18, $mid, true) && in_array(22, $mid, true),
   'and the pages either side of where you are', implode(',', $mid));
ok(count($mid) <= 11, 'the strip stays short enough to fit on a phone', (string) count($mid));

// A gap costs the same width as the number it hides, so a run of one is spelled out.
$near = Pager::window(4, 20);
ok(strpos(implode(',', $near), '1,0,2') === false, 'a gap never stands in for a single page',
   implode(',', $near));
ok(Pager::window(1, 0) === array(), 'no pages, no strip');

$q = Pager::query(array('q' => 'example', 'sort' => 'recent', 'days' => 0, 'page' => 3),
                  array('sort' => 'recent', 'days' => 0));
ok(strpos($q, 'q=example') !== false && strpos($q, 'page=3') !== false, 'the state of the list is in the URL', $q);
ok(strpos($q, 'sort=') === false && strpos($q, 'days=') === false,
   'and the defaults are left out of it, so the plain list has a plain URL', $q);
ok(Pager::query(array('q' => '', 'page' => null)) === '', 'nothing to say is an empty query string');

// ------------------------------------------------------- counters as read

group('Counters past a thousand');

// Below a thousand nothing changes: a counter that starts abbreviating at
// three figures is harder to read, not easier.
ok(Num::compact(0) === '0', 'nothing is nothing');
ok(Num::compact(42) === '42', 'a small number is left alone');
ok(Num::compact(999) === '999', 'and so is the last one below the line');
ok(!Num::isShortened(999) && Num::isShortened(1000), 'the line is at a thousand exactly');

ok(Num::compact(1000) === '1k', 'a thousand is 1k', Num::compact(1000));
ok(Num::compact(1100) === '1.1k', 'and eleven hundred is 1.1k', Num::compact(1100));
ok(Num::compact(1000000) === '1M', 'a million is 1M', Num::compact(1000000));
ok(Num::compact(1250000) === '1.2M', 'and 1.25 million is 1.2M', Num::compact(1250000));
ok(Num::compact(1000000000) === '1B', 'a billion is 1B', Num::compact(1000000000));
ok(Num::compact(1500000000000) === '1.5T', 'and a trillion and a half is 1.5T', Num::compact(1500000000000));

// It rounds down. A stats panel that says two thousand when it means one
// thousand nine hundred and ninety-nine is worse than one that says 1,999.
ok(Num::compact(1999) === '1.9k', 'it never rounds up', Num::compact(1999));
ok(Num::compact(1099) === '1k', 'not even to the first decimal', Num::compact(1099));
ok(Num::compact(9999) === '9.9k', 'right up to the next unit', Num::compact(9999));
ok(Num::compact(999999) === '999k', 'and across it', Num::compact(999999));

// Four characters is the budget: past ten of a unit the decimal buys nothing.
ok(Num::compact(12345) === '12k', 'double figures lose the decimal', Num::compact(12345));
ok(Num::compact(123456) === '123k', 'as do triple', Num::compact(123456));
foreach (array(1000, 1100, 9999, 12345, 123456, 1250000, 999999999) as $n) {
    ok(strlen(Num::compact($n)) <= 4, 'a shortened counter is never more than four characters',
       Num::compact($n));
}

ok(Num::compact(-1500) === '-1.5k', 'a negative one keeps its sign', Num::compact(-1500));
ok(Num::exact(1247) === '1,247', 'and the exact figure is still available', Num::exact(1247));

// The panel puts the exact figure in the title, so shortening hides nothing.
$markup = AdminUi::count(1247);
ok(strpos($markup, '>1.2k<') !== false, 'the panel shows the short form', $markup);
ok(strpos($markup, 'title="1,247"') !== false, 'with the exact one a hover away', $markup);
ok(AdminUi::count(42) === '42', 'and a small number gets no wrapper at all', AdminUi::count(42));

// The browser renders its own counters, so app.js carries the same function.
// These assert the two halves cannot drift apart unnoticed rather than running
// the JavaScript, which this runner has no way to do.
$appJs = (string) file_get_contents(dirname(__DIR__) . '/assets/js/app.js');
ok(strpos($appJs, 'function compact(n)') !== false, 'app.js has a compact() of its own');
ok(strpos($appJs, "[1e12, 'T'], [1e9, 'B'], [1e6, 'M'], [1e3, 'k']") !== false,
   'over the same units as Num::compact()');
ok(strpos($appJs, 'lib/Num.php') !== false, 'and says so, so a change to one sends you to the other');

// ---------------------------------------------------------- search results

group('How this looks in a search result');

// vcd_site_url() caches on first call and something above has already made
// one, so the base is whatever this run resolved it to rather than a host
// invented here. That is the address the tags have to agree on either way.
$base = rtrim(vcd_site_url(), '/');
$head = Seo::head(array(
    'title'       => 'A title',
    'description' => 'A description.',
    'path'        => '/signs.php',
    'type'        => 'article',
));

ok(strpos($head, '<title>A title</title>') !== false, 'the title is the title');
ok(strpos($head, '<link rel="canonical" href="' . $base . '/signs.php">') !== false,
   'every page names one canonical address for itself', $base . '/signs.php');
ok(substr_count($head, 'rel="canonical"') === 1, 'exactly one of them');
ok(strpos($head, 'og:image" content="' . $base . '/assets/img/social-preview.png"') !== false,
   'the social image is absolute, because a relative one is not a URL a scraper can fetch');
ok(strpos($head, 'twitter:title" content="A title"') !== false,
   'the social title falls back to the page title rather than going missing');
ok(strpos($head, 'og:type" content="article"') !== false, 'and the type is what the page said it was');
ok(strpos($head, 'max-image-preview:large') !== false, 'an indexable page asks for a large preview');

// A noindex page says so and stops: there is nothing for Open Graph to do on a
// page no search engine and no scraper should be showing anyone.
$noindex = Seo::head(array(
    'title' => 'Verify', 'description' => 'x', 'path' => '/verify', 'robots' => 'noindex, follow',
));
ok(strpos($noindex, 'content="noindex, follow"') !== false, 'a noindex page says noindex');
ok(strpos($noindex, 'og:image') === false, 'and carries no social card');
ok(strpos($noindex, 'rel="canonical"') !== false,
   'but it keeps a canonical, or every certificate payload is a separate page');

// Text from the page is escaped on the way into a tag and into JSON-LD alike.
$evil = Seo::head(array(
    'title'       => 'Quote " and <tag>',
    'description' => 'Ampersand & angle <',
    'path'        => '/',
    'jsonLd'      => array(array('@type' => 'Thing', 'name' => '</script><script>alert(1)</script>')),
));
ok(strpos($evil, '<tag>') === false, 'a title cannot inject markup');
ok(strpos($evil, '<script>alert(1)</script>') === false, 'nor can structured data close its own block');
ok(substr_count($evil, '<script type="application/ld+json">') === 1, 'one block in, one block out');

ok(Seo::url('/') === $base . '/', 'the site root is the site root', Seo::url('/'));
ok(Seo::url('signs.php') === $base . '/signs.php', 'a bare path is anchored', Seo::url('signs.php'));
ok(Seo::url('/signs.php') === Seo::url('signs.php'), 'with or without the leading slash');
ok(strpos(Seo::jsonLd(array('a' => 'b/c')), 'b/c') !== false, 'URLs in structured data stay readable');

// The sitemap has to name the pages worth finding and nothing that is gated,
// per-request, or an endpoint.
$sitemapSrc = (string) file_get_contents(dirname(__DIR__) . '/sitemap.php');
ok(strpos($sitemapSrc, "'/signs.php'") !== false, 'the sitemap lists the field guide');
ok(strpos($sitemapSrc, '/admin') === false && strpos($sitemapSrc, 'verify') !== false,
   'and mentions the admin panel nowhere');

$robots = (string) file_get_contents(dirname(__DIR__) . '/robots.txt');
ok(strpos($robots, 'Disallow: /admin/') !== false, 'robots.txt keeps crawlers out of the panel');
ok(strpos($robots, 'Sitemap:') !== false, 'and points them at the sitemap');

// Every admin page must send the header as well as the meta tag: the tag only
// covers HTML that gets as far as being rendered, and a redirect to the login
// page never does.
foreach (glob(dirname(__DIR__) . '/admin/*.php') as $adminPage) {
    $src = (string) file_get_contents($adminPage);
    ok(strpos($src, "X-Robots-Tag: noindex") !== false,
       'admin/' . basename($adminPage) . ' sends X-Robots-Tag: noindex');
}

// ---------------------------------------------- the admin panel's wording

group('How the admin panel says things');

$now = (int) strtotime('2026-08-22 12:00:00 UTC');
ok(AdminUi::ago('2026-08-22 11:59:30', $now) === 'just now', 'seconds ago is just now',
   AdminUi::ago('2026-08-22 11:59:30', $now));
ok(AdminUi::ago('2026-08-22 11:00:00', $now) === '1 hour ago', 'one hour is singular',
   AdminUi::ago('2026-08-22 11:00:00', $now));
ok(AdminUi::ago('2026-08-21 12:00:00', $now) === 'yesterday', 'one day ago is yesterday',
   AdminUi::ago('2026-08-21 12:00:00', $now));
ok(AdminUi::ago('2026-08-19 12:00:00', $now) === '3 days ago', 'three days ago is three days ago',
   AdminUi::ago('2026-08-19 12:00:00', $now));
ok(AdminUi::ago('2026-04-22 12:00:00', $now) === '4 months ago', 'and further back gets coarser',
   AdminUi::ago('2026-04-22 12:00:00', $now));
// A database clock a few seconds ahead of this one must not read as the future.
ok(AdminUi::ago('2026-08-22 12:00:05', $now) === 'just now', 'a clock slightly ahead is not the future',
   AdminUi::ago('2026-08-22 12:00:05', $now));
ok(AdminUi::ago(null) === '—' && AdminUi::ago('') === '—' && AdminUi::ago('not a date') === '—',
   'and nothing, or nonsense, is an em dash');

ok(AdminUi::day('2026-08-22 14:03:11') === '22 Aug 2026', 'a timestamp reads as a date',
   AdminUi::day('2026-08-22 14:03:11'));
ok(strpos(AdminUi::when('2026-08-22 14:03:11'), 'UTC') !== false,
   'and the full stamp says which clock it is on', AdminUi::when('2026-08-22 14:03:11'));

ok(AdminUi::modeLabel('site') === 'Whole site', 'the stored mode code reads as words');
ok(AdminUi::modeLabel('mystery') === 'mystery', 'and an unknown one comes back unchanged');
ok(AdminUi::sourceLabel('api') === 'API', 'so does the source');

$strip = AdminUi::pagination(3, 9, array('q' => 'a b'), array(), 'websites.php');
ok(strpos($strip, 'aria-current="page"') !== false, 'the page you are on is marked, not just coloured');
ok(strpos($strip, 'q=a+b') !== false, 'the search survives a page change', $strip);
ok(strpos($strip, '<a class="page" href="websites.php?q=a+b">1</a>') !== false,
   'and page one is the list without a page number in it', $strip);
ok(AdminUi::pagination(1, 1) === '', 'one page needs no page numbers');

// ------------------------------------------- the website list's own queries

group('Listing every website that was searched');

// ORDER BY cannot be a bound parameter, so the only safe version of "sort by
// whatever the query string says" is one where the query string can only name
// a key in a whitelist.
ok(array_key_exists('recent', UsageLog::sorts()), 'the default order exists');
ok(UsageLog::normaliseSort('most') === 'most', 'a known order is kept');
ok(UsageLog::normaliseSort('n DESC; DROP TABLE usage_log') === 'recent',
   'and anything else becomes the default rather than reaching SQL');
ok(UsageLog::normaliseSort('') === 'recent', 'as does an empty one');

// A search box that treats a typed underscore as a wildcard quietly answers a
// different question from the one that was asked.
ok(UsageLog::escapeLike('my_site.com') === 'my\_site.com', 'an underscore is a literal underscore',
   UsageLog::escapeLike('my_site.com'));
ok(UsageLog::escapeLike('100%') === '100\%', 'and a percent sign does not match the whole table',
   UsageLog::escapeLike('100%'));
ok(UsageLog::escapeLike('a\\b') === 'a\\\\b', 'a backslash escapes itself first',
   UsageLog::escapeLike('a\\b'));
ok(UsageLog::escapeLike('example.com') === 'example.com', 'and an ordinary host is untouched');

// Same contract as the traffic chart: the days nothing happened are drawn as
// quiet days, not left out.
$nowDay = (int) strtotime('2026-08-22 09:15:00 UTC');
$filledHost = UsageLog::fillDays(array('2026-08-20' => 6), 7, $nowDay);
ok(count($filledHost) === 7, 'a seven-day window has seven days in it', (string) count($filledHost));
ok($filledHost[0]['day'] === '2026-08-16' && $filledHost[6]['day'] === '2026-08-22',
   'starting seven days ago and ending today');
ok($filledHost[4]['n'] === 6, 'the day with analyses keeps its number');
ok($filledHost[3]['n'] === 0 && $filledHost[5]['n'] === 0, 'and the quiet days are zeroes, not gaps');

// A year of daily columns is a bar a pixel and a half across, which draws as a
// gridline rather than as a measurement. Past a quarter the days fold to weeks.
$year = UsageLog::fillDays(array('2026-08-20' => 4, '2026-08-19' => 2), 365, $nowDay);
$weeks = UsageLog::bucket($year, 7);
ok(count($weeks) === 53, 'a year of days folds into fifty-three weeks', (string) count($weeks));
ok(array_sum(array_column($weeks, 'n')) === 6, 'and folding loses none of the analyses');
ok($weeks[count($weeks) - 1]['day'] === '2026-08-16',
   'the last bucket is a whole week ending today, so the newest column is not a short one',
   $weeks[count($weeks) - 1]['day']);
ok(strpos($weeks[count($weeks) - 1]['label'], '–') !== false,
   'a bucket says which days it covers', $weeks[count($weeks) - 1]['label']);
ok(UsageLog::bucket($year, 1) === $year, 'a bucket of one day is the series unchanged');
ok(UsageLog::bucket(array(), 7) === array(), 'and nothing folds into nothing');

$short = UsageLog::fillDays(array('2026-08-22' => 3), 3, $nowDay);
$oneBucket = UsageLog::bucket($short, 7);
ok(count($oneBucket) === 1 && $oneBucket[0]['n'] === 3,
   'fewer days than a bucket is one short bucket, not a dropped one');

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
//
// The ladder lives on the method page rather than the front page: the front
// page is the analyser and nothing else. The invariant it guards is the same
// wherever the markup sits.
$page   = (string) file_get_contents(dirname(__DIR__) . '/index.php');
$method = (string) file_get_contents(dirname(__DIR__) . '/method.php');
ok(substr_count($method, 'class="ladder"') === 1, 'the ladder list is where the test expects it');
preg_match('~<ol class="ladder">(.*?)</ol>~s', $method, $ladderHtml);
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

// The front page is the analyser. The long-form argument moved to method.php,
// and a reader who wants it is one link away rather than four screens down.
ok(strpos($page, 'class="band"') === false,
   'the front page carries no long-form sections under the analyser');
ok(substr_count($page, 'id="analyzer"') === 1, 'the front page still carries the analyser');
foreach (array('id="method"', 'id="limits"', 'id="provenance"') as $anchor) {
    ok(strpos($method, $anchor) !== false, 'method.php carries ' . $anchor);
    ok(strpos($page, '<section class="band" ' . $anchor) === false,
       'the front page no longer carries ' . $anchor);
}
// Every page that links to those anchors has to link to the page they are on.
foreach (array('index.php', 'signs.php', 'catalogue.php') as $file) {
    $src = (string) file_get_contents(dirname(__DIR__) . '/' . $file);
    ok(!preg_match('~href="(?:\./)?#(?:method|limits|provenance)"~', $src),
       $file . ' does not link to sections that left the front page');
}
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
ok(strpos($page, 'Seo::head(') !== false,
   'the front page takes its head tags from lib/Seo.php rather than hand-writing them');
ok(strpos(Seo::head(array('title' => 'x', 'description' => 'y', 'path' => '/')), 'og:image') !== false,
   'and that always advertises a link-preview image');

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

// ------------------------------------------------------------ code: CSS

group('Reading a stylesheet');

$generatedCss = '';
foreach (array('header' => 'Header', 'hero' => 'Hero', 'features' => 'Features',
               'pricing' => 'Pricing', 'testimonials' => 'Testimonials', 'footer' => 'Footer') as $cls => $label) {
    $generatedCss .= "/* {$label} */\n"
        . ".{$cls} { align-items: center; background: #0f172a; color: #ffffff; display: flex; padding: 4rem 2rem; }\n\n";
}
$r = (new CodeAnalyzer($generatedCss))->analyze();
ok($r->toArray()['stats']['language'] === 'css', 'reads the paste as CSS');
ok($r->has('cd.css_alphabetical'), 'catches declarations sorted A to Z');
ok($r->has('cd.css_one_line'), 'catches rule bodies crushed onto one line');
ok($r->has('cd.css_labelled_sections'), 'catches a label on every block and a reason for nothing');

// Grouped by what the declarations do, which is how a rule is read back.
$humanCss = "/* The masthead sticks, because the nav doubles as the section index\n"
    . "   on long pages and losing it costs the reader their place. */\n"
    . ".masthead {\n  position: sticky;\n  top: 0;\n  display: flex;\n  padding: 1rem 2rem;\n"
    . "  /* 3px, not 4: 4 collides with the focus ring on Safari 16. */\n  border-bottom: 3px solid #ded5c2;\n"
    . "  background: #f6f2e9;\n}\n\n"
    . ".card {\n  position: relative;\n  padding: 1.5rem;\n  margin-bottom: 2rem;\n  border: 1px solid #ded5c2;\n  background: #fffdf8;\n}\n\n"
    . ".card h3 {\n  margin: 0 0 .5rem;\n  font-size: 1rem;\n  font-weight: 600;\n  color: #17140f;\n}\n\n"
    . ".footer {\n  margin-top: 4rem;\n  padding: 2rem;\n  font-size: .875rem;\n  color: #6b6456;\n}\n\n"
    . ".footer a {\n  text-decoration: underline;\n  color: inherit;\n}\n";
$r = (new CodeAnalyzer($humanCss))->analyze();
ok(!$r->has('cd.css_alphabetical'), 'leaves declarations grouped by what they do alone');
ok(!$r->has('cd.css_one_line'), 'and rules written out over several lines');
ok(!$r->has('cd.css_labelled_sections'), 'a stylesheet that explains a value is not a labelled one');
ok($r->has('hu.why_comments'), 'and the explanation counts the other way');

// Minified CSS is one line per rule too, and says nothing about its author.
$minified = '';
foreach (range(1, 8) as $i) {
    $minified .= ".c{$i}{display:flex;padding:1rem;color:#fff;background:#111}\n";
}
ok(!(new CodeAnalyzer($minified))->analyze()->has('cd.css_one_line'),
   'a minified stylesheet is the build tool\'s shape, not the author\'s');

// A hash is a colour and an id selector in CSS, not a comment.
$colours = ".a { color: #0f172a; }\n#main { background: #1e293b; }\n#nav { color: #2024; }\n"
    . str_repeat(".x { padding: 1rem; }\n", 20);
$r = (new CodeAnalyzer($colours))->analyze();
ok(!$r->has('hu.why_comments'), 'a hex colour is not a comment carrying outside context');
ok(!$r->has('hu.ticket_refs'), 'and an id selector is not a ticket reference');

// The assistant-chatter phrases need their left edge guarded.
$prose = "/* Corners are square unless there is a reason for them not to be. */\n"
    . ".a { color: red; }\n";
ok(!(new CodeAnalyzer($prose))->analyze()->has('cd.assistant_chatter'),
   '"unless there is a reason" is a sentence, not the assistant talking');
ok((new CodeAnalyzer("// Sure! Here is a comprehensive solution.\nfunction a(){return 1}\n"))->analyze()->has('cd.assistant_chatter'),
   'and the real thing still fires');

// Stylesheets reach the analyser from a page, as files and as <style> blocks.
$page = '<!DOCTYPE html><html lang="en"><head><title>Flow</title>'
    . '<link rel="stylesheet" href="/assets/style.css"></head><body>'
    . str_repeat('<p>Copy on the page.</p>', 30) . '</body></html>';
$r = (new SiteAnalyzer('https://flow.example.com/', $page,
     array('https://flow.example.com/assets/style.css' => $generatedCss)))->analyze();
ok($r->has('cd.css_alphabetical'), 'a served stylesheet is read, not skipped for not being JavaScript');
$labelled = null;
foreach ($r->signals() as $s) {
    if ($s->id === 'cd.css_labelled_sections') $labelled = $s;
}
ok($labelled !== null && $labelled->evidence[1]->source === 'style.css',
   'and its evidence names the file it came from');

$inline = '<!DOCTYPE html><html lang="en"><head><title>Flow</title><style>' . $generatedCss . '</style></head><body>'
    . str_repeat('<p>Copy on the page.</p>', 30) . '</body></html>';
ok((new SiteAnalyzer('https://flow.example.com/', $inline))->analyze()->has('cd.css_one_line'),
   'a <style> block in the document is read the same way');

// ------------------------------------------------------- evidence: context

group('Evidence carries the code around it');

$withContext = "<?php\n"
    . "function a() { return 1; }\n"
    . "// Set the total\n"
    . "\$total = 1;\n"
    . "// Get the user\n"
    . "\$user = getUser();\n"
    . "// Update the cache\n"
    . "\$cache->update();\n"
    . "// Calculate the sum\n"
    . "\$sum = array_sum(\$rows);\n"
    . str_repeat("\$x = 1;\n", 30);

$r = (new CodeAnalyzer($withContext))->analyze();
$what = null;
foreach ($r->signals() as $s) {
    if ($s->id === 'cd.what_comments') $what = $s;
}
ok($what !== null, 'the what-comment signal still fires');
if ($what !== null) {
    $first = $what->evidence[0];
    ok($first instanceof Excerpt, 'evidence arrives as an excerpt, not a string');
    ok($first->line !== null, 'the excerpt knows which line it was found on');
    ok(count($first->context) > 1, 'the excerpt carries the lines around the match',
       'got ' . count($first->context));

    $matched = 0;
    $before = 0;
    $after = 0;
    foreach ($first->context as $row) {
        if ($row['match']) { $matched++; continue; }
        if ($matched === 0) $before++; else $after++;
    }
    ok($matched === 1, 'exactly one context line is the match itself');
    ok($before >= 1 && $after >= 1, 'there is code above it and code below it',
       "{$before} above, {$after} below");

    $arr = $what->toArray();
    ok(isset($arr['excerpts'][0]['context']), 'the context survives into the JSON payload');
    ok(is_array($arr['evidence']) && is_string($arr['evidence'][0]),
       'the flat evidence strings are still published for older readers');
}

// A minified bundle has no lines above and below, so the window is characters.
$minified = 'var a=1;' . str_repeat('function q' . 'z(){return 0}', 40) . 'const KEY="lovable-tagger";' . str_repeat('var b=2;', 40);
$ex = Excerpt::locate($minified, 'lovable-tagger');
ok($ex->line === 1, 'a hit in a minified bundle still reports its line');
ok(count($ex->context) === 1 && strlen($ex->context[0]['code']) < 500,
   'and shows a bounded window around the hit rather than the whole line',
   isset($ex->context[0]) ? (string) strlen($ex->context[0]['code']) : 'none');
ok(strpos($ex->context[0]['code'], 'lovable-tagger') !== false,
   'the window is centred on what was matched');

// Credentials never travel in the surroundings, whatever found them.
$secretFile = "const config = {\n"
    . "  name: 'app',\n"
    . "  apiKey: 'sk-live-AAAAAAAAAAAAAAAAAAAAAAAA',\n"
    . "  token: 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.abcdef',\n"
    . "};\n";
$redacted = Excerpt::atLine(explode("\n", $secretFile), 2)->toArray();
$printed = json_encode($redacted);
ok(strpos($printed, 'sk-live-AAAA') === false, 'a vendor key is masked inside the context');
ok(strpos($printed, 'eyJhbGciOiJIUzI1NiJ9') === false, 'so is a JWT on a neighbouring line');

// --------------------------------------------------- evidence: how often

group('How often a signal fired counts');

$once = new Report('code', 'x');
$once->flag('cd.what_comments', array(Excerpt::plain('a comment restating its line')));

$often = new Report('code', 'x');
$lines = array();
for ($i = 0; $i < 12; $i++) {
    $lines[] = Excerpt::plain('comment number ' . $i . ' restating its line');
}
$often->flag('cd.what_comments', $lines);

ok($once->signals()[0]->occurrences === 1, 'one excerpt is one occurrence');
ok($often->signals()[0]->occurrences === 12, 'twelve excerpts are twelve occurrences');
ok($often->signals()[0]->effectiveWeight() > $once->signals()[0]->effectiveWeight(),
   'a habit repeated weighs more than one that fired once');
ok($often->score() > $once->score(), 'and the score moves with it',
   $once->score() . ' vs ' . $often->score());

// The multiplier is bounded: forty occurrences is not four times ten.
$flood = new Report('code', 'x');
$flood->flag('cd.what_comments', array(Excerpt::plain('one')), 400);
ok($flood->signals()[0]->repetitionFactor() <= 1.5 + 1e-9,
   'repetition can never more than half again a signal\'s weight',
   (string) $flood->signals()[0]->repetitionFactor());

// Evidence beyond what is displayed is still counted.
$many = new Report('code', 'x');
$shown = array();
for ($i = 0; $i < 9; $i++) {
    $shown[] = Excerpt::plain('hit ' . $i);
}
$many->flag('cd.what_comments', $shown);
ok(count($many->signals()[0]->evidence) === 4, 'only four excerpts are shown');
ok($many->signals()[0]->occurrences === 9, 'but all nine are counted');

// A fingerprint is an identification, and identifications do not accumulate.
$fp = new Report('url', 'x');
$fp->flag('fp.lovable', array(Excerpt::plain('the tagger')), 25);
ok($fp->signals()[0]->repetitionFactor() === 1.0,
   'finding a builder badge three times identifies it exactly once');

// -------------------------------------------------- new site-side signals

group('Further ways to tell');

$devServer = '<!DOCTYPE html><html lang="en"><head><title>My App</title>'
    . '<script type="module" src="/@vite/client"></script>'
    . '<script type="module" src="/src/main.tsx"></script></head><body><div id="root"></div>'
    . str_repeat('<p>Some copy that the page renders anyway.</p>', 30) . '</body></html>';
$r = (new SiteAnalyzer('https://demo.example.com/', $devServer))->analyze();
ok($r->has('st.dev_server_page'), 'catches a page served straight from the dev server');

$local = '<!DOCTYPE html><html lang="en"><head><title>Shop</title></head><body>'
    . '<img src="http://localhost:5173/hero.png" alt="hero">'
    . str_repeat('<p>Copy.</p>', 30) . '</body></html>';
ok((new SiteAnalyzer('https://shop.example.com/', $local))->analyze()->has('st.dev_server_page'),
   'and a page still pointing at localhost');

$preview = '<!DOCTYPE html><html lang="en"><head><title>Thing</title></head><body><p>Hi</p></body></html>';
ok((new SiteAnalyzer('https://my-app-3f2a.vercel.app/', $preview))->analyze()->has('st.preview_host'),
   'notes a site still on the platform\'s own subdomain');
ok(!(new SiteAnalyzer('https://shop.example.com/', $preview))->analyze()->has('st.preview_host'),
   'and says nothing about a site with its own domain');

$formPage = '<!DOCTYPE html><html lang="en"><head><title>Contact</title></head><body>'
    . '<form><input type="text" name="name"><input type="email" name="email">'
    . '<textarea name="msg"></textarea><button type="submit">Send</button></form>'
    . str_repeat('<p>We would love to hear from you about anything at all.</p>', 20)
    . '</body></html>';
ok((new SiteAnalyzer('https://thing.example.com/', $formPage))->analyze()->has('st.form_to_nowhere'),
   'catches a contact form with nothing behind it');

$wiredForm = str_replace('<form>', '<form action="https://formspree.io/f/abc" method="post">', $formPage);
ok(!(new SiteAnalyzer('https://thing.example.com/', $wiredForm))->analyze()->has('st.form_to_nowhere'),
   'and leaves a form that actually posts somewhere alone');

$avatars = '<!DOCTYPE html><html lang="en"><head><title>Team</title></head><body>'
    . '<img src="https://i.pravatar.cc/150?img=3" alt="a"><img src="https://randomuser.me/api/portraits/men/4.jpg" alt="b">'
    . '<p>Contact us at hello@example.com or call (555) 123-4567.</p>'
    . '<a href="https://twitter.com/"></a><a href="https://facebook.com/"></a>'
    . str_repeat('<p>Copy about the team and what they do.</p>', 20) . '</body></html>';
$r = (new SiteAnalyzer('https://team.example.com/', $avatars))->analyze();
ok($r->has('ct.stock_avatars'), 'catches testimonial faces from an avatar service');
ok($r->has('ct.placeholder_contact'), 'catches contact details nobody can reach');

$prose = '<!DOCTYPE html><html lang="en"><head><title>Flow</title></head><body>'
    . '<p>In today\'s fast-paced world, teams need more than just another tool.</p>'
    . '<p>It\'s not just a dashboard, it\'s a way of working.</p>'
    . '<p>Whether you\'re a founder or an operator, we help you elevate your workflow.</p>'
    . '<p>Fast, simple, and reliable. Built, tested, and trusted. Plan, build, and ship.</p>'
    . str_repeat('<p>More ordinary copy to give the page something to read.</p>', 20)
    . '</body></html>';
ok((new SiteAnalyzer('https://flow.example.com/', $prose))->analyze()->has('ct.llm_prose'),
   'catches the model\'s sentence rhythm');

$plainCopy = '<!DOCTYPE html><html lang="en"><head><title>Bakery</title></head><body>'
    . str_repeat('<p>We bake bread every morning and sell it until it runs out.</p>', 30)
    . '</body></html>';
ok(!(new SiteAnalyzer('https://bakery.example.fr/', $plainCopy))->analyze()->has('ct.llm_prose'),
   'and leaves plain copy alone');

$dated = '<!DOCTYPE html><html lang="en"><head><title>Notes</title></head><body>'
    . '<article><time datetime="2023-04-11">11 April 2023</time><p>A post.</p></article>'
    . '<article><time datetime="2024-01-08">8 January 2024</time><p>Another post.</p></article>'
    . '<article><time datetime="2025-06-02">2 June 2025</time><p>A third post.</p></article>'
    . str_repeat('<p>Body copy under the posts.</p>', 20) . '</body></html>';
ok((new SiteAnalyzer('https://notes.example.com/', $dated))->analyze()->has('hu.content_dates'),
   'reads dated content as a mark of time passing');

$careful = '<!DOCTYPE html><html lang="en"><head><title>Forms</title>'
    . '<style>@media (prefers-reduced-motion: reduce){*{animation:none}}</style></head><body>'
    . '<a href="#main" class="skip">Skip to content</a><main id="main">'
    . '<form action="/subscribe" method="post">'
    . '<label for="e">Email</label><input id="e" name="email">'
    . '<label for="n">Name</label><input id="n" name="name"></form>'
    . str_repeat('<p>Copy under the form.</p>', 20) . '</main></body></html>';
ok((new SiteAnalyzer('https://forms.example.com/', $careful))->analyze()->has('hu.a11y_care'),
   'reads accessibility work nobody was asked for as human');

// A page in one enormous file, and a page with none of the publishing furniture.
$single = '<!DOCTYPE html><html lang="en"><head><title>One</title><style>'
    . str_repeat('.a{color:#111;padding:4px;margin:2px}', 200) . '</style></head><body>'
    . str_repeat('<section><h2>A section</h2><p>Copy inside it, at some length.</p></section>', 160)
    . '<script>' . str_repeat('function go(){return 1}', 200) . '</script></body></html>';
ok((new SiteAnalyzer('https://one.example.com/', $single))->analyze()->has('st.single_file_page'),
   'catches an entire application living in one document');

$bare = '<!DOCTYPE html><html lang="en"><head><title>Product</title>'
    . '<script src="/assets/index-Ba7Xk9Lm.js"></script></head><body>'
    . str_repeat('<p>A built page with plenty of copy for people to read.</p>', 40)
    . '</body></html>';
ok((new SiteAnalyzer('https://product.example.com/', $bare))->analyze()->has('st.no_seo_furniture'),
   'catches a built page that never acquired a description, a card or a favicon');

$furnished = str_replace('</head>',
    '<meta name="description" content="A product for people."><meta property="og:title" content="Product">'
    . '<link rel="canonical" href="https://product.example.com/"><link rel="icon" href="/favicon.ico"></head>', $bare);
ok(!(new SiteAnalyzer('https://product.example.com/', $furnished))->analyze()->has('st.no_seo_furniture'),
   'and says nothing about a page that has them');

// Alt text written the way a model describes a photograph.
$alts = '<!DOCTYPE html><html lang="en"><head><title>Gallery</title></head><body>'
    . '<img src="/a.jpg" alt="A modern office space with people working at desks">'
    . '<img src="/b.jpg" alt="A smiling woman holding a laptop in a bright room">'
    . '<img src="/c.jpg" alt="A group of colleagues discussing charts on a screen">'
    . '<img src="/d.jpg" alt="An abstract illustration of connected nodes and lines">'
    . str_repeat('<p>Copy under the gallery.</p>', 20) . '</body></html>';
ok((new SiteAnalyzer('https://gallery.example.com/', $alts))->analyze()->has('ct.model_alt_text'),
   'catches alt text written as image descriptions');

// Functions all cut to the same length.
$even = "<?php\n";
for ($i = 0; $i < 8; $i++) {
    $even .= "function step{$i}(\$input)\n{\n    \$value = \$input + {$i};\n    \$value = \$value * 2;\n    return \$value;\n}\n\n";
}
ok((new CodeAnalyzer($even))->analyze()->has('cd.uniform_function_length'),
   'catches a file where every function is the same size');

$uneven = "<?php\nfunction tiny(\$a)\n{\n    return \$a;\n}\n\n";
$uneven .= "function big(\$rows)\n{\n" . str_repeat("    \$out[] = \$rows;\n", 40) . "    return \$out;\n}\n\n";
$uneven .= "function mid(\$x)\n{\n" . str_repeat("    \$x++;\n", 9) . "    return \$x;\n}\n\n";
$uneven .= "function other(\$y)\n{\n    return \$y - 1;\n}\n\n";
$uneven .= "function last(\$z)\n{\n" . str_repeat("    \$z++;\n", 20) . "    return \$z;\n}\n";
ok(!(new CodeAnalyzer($uneven))->analyze()->has('cd.uniform_function_length'),
   'and leaves a file with lumpy functions alone');

// Navigation pointing at pages the server does not have.
$missing = array('https://demo.example.com/pricing' => 404, 'https://demo.example.com/docs' => 404);
$survey = (new SiteSurvey('https://demo.example.com/', pageSet(array(
    stubPage('home'), stubPage('about'), stubPage('team'),
)), array(), array(), $missing))->analyze();
ok($survey->has('xs.broken_nav_links'), 'catches a nav promising pages that do not exist');

$deep = array('https://demo.example.com/blog/2019/old-post' => 404);
$survey = (new SiteSurvey('https://demo.example.com/', pageSet(array(
    stubPage('home'), stubPage('about'), stubPage('team'),
)), array(), array(), $deep))->analyze();
ok(!$survey->has('xs.broken_nav_links'), 'and treats one rotten deep link as the ordinary decay it is');

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
