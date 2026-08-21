<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/Brand.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

/**
 * The visual field guide.
 *
 * Every specimen on this page is rendered live rather than screenshotted, so
 * it stays accurate as the conventions move and so you can inspect it. Each
 * one is scoped inside .spec, which is the only place on this site those
 * styles exist — the rest of the page is deliberately built the other way.
 */
$signs = array(
    array(
        'id'     => 'pill',
        'name'   => 'The badge above the headline',
        'signal' => 'ae.hero_pill',
        'tell'   => 'A small rounded pill sitting over the h1. Almost every generated landing page has one, and it almost never says anything that could not have been said in the headline.',
        'look'   => 'A rounded-full container, one line of text, usually a sparkle or a dot, directly above the largest text on the page.',
        'but'    => 'Real products announce real things. A pill that names a version you can go and look at is a pill doing a job.',
    ),
    array(
        'id'     => 'scroll',
        'name'   => 'The scroll indicator',
        'signal' => 'ae.scroll_indicator',
        'tell'   => 'A bouncing chevron or an animated mouse outline at the bottom of the first screen, telling you to do the thing you have known how to do since 1995.',
        'look'   => 'Anything animating on a loop just below the fold, often labelled "scroll" or "explore".',
        'but'    => 'On a full-screen immersive page with no other cue, it is genuinely useful. On a normal page with content visibly cut off, it is decoration wearing the costume of an affordance.',
    ),
    array(
        'id'     => 'gradient-text',
        'name'   => 'The gradient headline',
        'signal' => 'ae.gradient_text',
        'tell'   => 'A heading painted with a clipped background gradient instead of a colour. Nearly unheard of in hand-built pages before 2023, near-universal in generated ones since.',
        'look'   => 'Two or three words of the headline in a different, usually purple-to-blue, colour ramp.',
        'but'    => 'A brand with a real gradient in its identity will use it here. The tell is a gradient that appears once, on the headline, and nowhere else.',
    ),
    array(
        'id'     => 'orbs',
        'name'   => 'Blurred colour behind everything',
        'signal' => 'ae.glow_orbs',
        'tell'   => 'Large soft blobs, heavily blurred, floating behind the opening section. One of the handful of effects a model reaches for when asked to make something feel premium.',
        'look'   => 'Colour that has no edges and belongs to no element, usually two or three of them, usually purple and blue.',
        'but'    => 'Used once, deliberately, as part of a considered palette, this is just a background. The tell is the absence of any other decision.',
    ),
    array(
        'id'     => 'cards',
        'name'   => 'Exactly three feature cards',
        'signal' => 'ae.three_cards',
        'tell'   => 'Three tiles, equal width, an icon each, a two-line description each, at almost exactly the same length. The number three is not a design decision here; it is the median of every landing page ever scraped.',
        'look'   => 'Count the cards. Then count the words in each. Real content is lumpy because real features are not equally interesting.',
        'but'    => 'Some products genuinely have three things worth saying.',
    ),
    array(
        'id'     => 'bento',
        'name'   => 'The bento grid',
        'signal' => 'ae.bento_grid',
        'tell'   => 'Feature tiles of deliberately unequal spans arranged into a mosaic. A real 2023 design idea, now the default answer to "show several features at once".',
        'look'   => 'A grid where one tile is twice as wide, another twice as tall, and the arrangement carries no meaning.',
        'but'    => 'Bento works when tile size maps to importance. The tell is when it does not.',
    ),
    array(
        'id'     => 'marquee',
        'name'   => 'The endless logo strip',
        'signal' => 'ae.logo_marquee',
        'tell'   => 'A "trusted by" band scrolling on a loop. The social proof exists as a component before it exists as a fact.',
        'look'   => 'Logos moving on their own. Then read them: are they companies, or shapes with plausible names?',
        'but'    => 'A company with real customers has a real logo wall, and it usually does not need to move.',
    ),
    array(
        'id'     => 'glass',
        'name'   => 'Frosted glass everywhere',
        'signal' => 'ae.glassmorphism',
        'tell'   => 'Backdrop blur over translucent white, on every card and every bar, regardless of whether there is anything behind it worth blurring.',
        'look'   => 'Panels you can half-see through, stacked on panels you can half-see through.',
        'but'    => 'Over a photograph or a video, blur earns its place. Over a flat colour it is doing nothing but costing a repaint.',
    ),
    array(
        'id'     => 'nav',
        'name'   => 'The floating blurred navbar',
        'signal' => 'ae.floating_nav',
        'tell'   => 'A detached pill-shaped header hovering a little below the top of the page rather than sitting on it.',
        'look'   => 'A header with rounded ends, a gap above it, and a blur behind it.',
        'but'    => 'Nothing is wrong with it. It is just that everyone arrived at it in the same month.',
    ),
    array(
        'id'     => 'emoji',
        'name'   => 'Emoji doing an icon\'s job',
        'signal' => 'ct.emoji_icons',
        'tell'   => 'A rocket for performance, a lightbulb for smart, a sparkle for premium. This one is close to decisive, because emoji cannot inherit colour, adapt to dark mode or scale cleanly — so a page using them as an icon set has not been through design review.',
        'look'   => 'An emoji sitting alone in its own element, where an icon would go.',
        'but'    => 'In running text, in a changelog, in a button label meant to be playful: fine. As the icon system: not fine.',
    ),
    array(
        'id'     => 'testimonials',
        'name'   => 'People who do not exist',
        'signal' => 'ct.generic_names',
        'tell'   => 'Testimonials from the most statistically common names available, with job titles like "Verified User" or "Head of Operations", next to a face that has never had a bad night\'s sleep.',
        'look'   => 'Search the name. Search the quote. If neither exists anywhere else, neither does the customer.',
        'but'    => 'Real testimonials name a company you can look up and say something too specific to be generic.',
    ),
    array(
        'id'     => 'stats',
        'name'   => 'Numbers nobody measured',
        'signal' => 'ct.stat_inflation',
        'tell'   => '10,000+ users. 99.9% uptime. 10x faster. The roundness is the tell — measured figures are rarely this tidy, and none of these is attributed to anything.',
        'look'   => 'Ask what each number would have to be measured against. Then look for the footnote. There is never a footnote.',
        'but'    => 'A sourced number is a claim someone is standing behind. The detector stops flagging these the moment attribution appears.',
    ),
);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Visual signs — Vibe Code Detector</title>
<meta name="description" content="A field guide to the visual tells of a generated web page: the hero pill, the scroll indicator, the gradient headline, the bento grid, and the rest. Each one rendered live, with what it looks like and when it is innocent.">
<link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/css/site.css?v=<?= h(VCD_VERSION) ?>">
<link rel="stylesheet" href="assets/css/specimens.css?v=<?= h(VCD_VERSION) ?>">
<meta property="og:title" content="The visual signs of a generated page">
<meta property="og:description" content="Twelve visual tells, each rendered live, with what it looks like and when it is innocent.">
<meta property="og:type" content="article">
<meta property="og:url" content="<?= h(vcd_site_url()) ?>/signs.php">
<meta property="og:image" content="<?= h(vcd_site_url()) ?>/assets/img/social-preview.png">
<meta name="twitter:card" content="summary_large_image">
</head>
<body>

<header class="masthead-wrap">
  <div class="shell masthead">
    <a class="brand" href="./">
      <?= Brand::markSvg(34, 'currentColor', 'var(--red)', 'aria-hidden="true"') ?>
      <span class="brand-name">Vibe Code Detector<span class="brand-tag">reads the tells, shows its working</span></span>
    </a>
    <nav>
      <a href="./">Analyse something</a>
      <a href="./#method">Method</a>
      <a href="./#limits">Limits</a>
      <a href="<?= h(VCD_REPO_URL) ?>" rel="noopener">Source</a>
    </nav>
  </div>
</header>

<main>
  <div class="shell">

    <section class="hero">
      <p class="eyebrow">Field guide</p>
      <h1>The visual signs</h1>
      <p>Twelve things you can spot on a rendered page without opening View Source. Every specimen below is <strong>rendered live</strong>, not screenshotted, so it stays honest as the conventions drift &mdash; and so you can inspect it.</p>
      <p class="disclaimer">None of these proves anything on its own, and the detector weights them accordingly: aesthetic evidence is capped as a group and can never, by itself, push a reading past 55%. They are a reason to look closer. Each entry below says when it is innocent, because usually it is.</p>
    </section>

    <nav class="sign-index" aria-label="The signs">
      <ol>
        <?php foreach ($signs as $i => $sign): ?>
          <li><a href="#<?= h($sign['id']) ?>"><span><?= sprintf('%02d', $i + 1) ?></span> <?= h($sign['name']) ?></a></li>
        <?php endforeach; ?>
      </ol>
    </nav>

    <?php foreach ($signs as $i => $sign): ?>
    <section class="sign" id="<?= h($sign['id']) ?>">
      <div class="sign-head">
        <span class="sign-number"><?= sprintf('%02d', $i + 1) ?></span>
        <h2><?= h($sign['name']) ?></h2>
        <?php $meta = Catalog::get($sign['signal']); ?>
        <?php if ($meta !== null): ?>
          <code class="sign-id" title="<?= h($meta['label']) ?>"><?= h($sign['signal']) ?></code>
        <?php endif; ?>
      </div>

      <div class="sign-body">
        <div class="sign-text">
          <p><?= h($sign['tell']) ?></p>
          <p class="sign-look"><strong>How to spot it.</strong> <?= h($sign['look']) ?></p>
          <p class="sign-but"><strong>When it is innocent.</strong> <?= h($sign['but']) ?></p>
        </div>

        <figure class="spec-frame">
          <figcaption>Specimen &mdash; rendered live</figcaption>
          <div class="spec">
            <?php include __DIR__ . '/specimens/' . $sign['id'] . '.php'; ?>
          </div>
        </figure>
      </div>
    </section>
    <?php endforeach; ?>

    <section class="band">
      <div class="prose">
        <p class="eyebrow">A warning about this page</p>
        <h2>Recognising these is the easy half</h2>
        <p>Everything here is cheap to change. A page carrying all twelve can be stripped of all twelve in an afternoon, and a careful person building by hand can hit six of them by accident because these are also just the conventions of the moment.</p>
        <p>That is why the detector caps this whole family. Visual signs tell you where to look; they do not tell you what you found. The things that actually carry weight are further down: a builder's fingerprint in the markup, the shape of the repository history, whether the same problem got solved three different ways.</p>

        <h3>This page fails its own test</h3>
        <p>Run this page through the detector and it comes back <strong id="signs-score">83%, likely AI-generated</strong> &mdash; because it is full of hero pills, gradient headlines, blurred orbs and invented testimonials. It has no way to tell exhibiting from doing. Neither would a person glancing at a screenshot, which is rather the point of the warning above.</p>
        <p>It is left standing. Special-casing the page would mean a detector that lies about one address, and the false positive teaches more than the fix would.</p>
        <p><a href="./">Run something through the detector &rarr;</a> or read <a href="<?= h(VCD_REPO_URL) ?>/blob/main/docs/SIGNALS.md" rel="noopener">the full catalogue of <?= count(Catalog::all()) ?> signals &rarr;</a></p>
      </div>
    </section>

    <section class="band">
      <div class="prose">
        <p class="eyebrow">Credit</p>
        <h2>Where some of this came from</h2>
        <p>Several of the code-level signals behind this project were sharpened by <a href="https://github.com/ElectroLynx/guide_vibecode" rel="noopener">guide_vibecode</a> by ElectroLynx &mdash; a short French field guide organised around eight signs, with a paired counter-example for each. Its framing of <em>les traces de l'assistant</em>, the conversational replies left sitting in a source file, became one of the strongest code signals here.</p>
        <p>It is MIT-licensed, and it is worth reading. It also opens by admitting it was generated, which is the correct way to publish a guide like this.</p>
      </div>
    </section>

  </div>
</main>

<footer class="colophon">
  <div class="shell colophon-grid">
    <div>
      <p><strong>Vibe Code Detector</strong> is free, open source, and keeps nothing.</p>
      <p><a href="./#provenance">Half of this site was vibecoded</a>, and it scores 55% on its own detector.</p>
    </div>
    <div class="links">
      <a href="./">Analyse something &rarr;</a>
      <a href="<?= h(VCD_REPO_URL) ?>" rel="noopener">Source and issue tracker &rarr;</a>
      <a href="verify.php">Verify a certificate &rarr;</a>
      <span class="studio">A Landfall studio product</span>
    </div>
  </div>
</footer>

</body>
</html>
