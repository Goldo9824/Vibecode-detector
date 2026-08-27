<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/Brand.php';
require_once __DIR__ . '/lib/VisitLog.php';
require_once __DIR__ . '/lib/Seo.php';
require_once __DIR__ . '/lib/Num.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Counted only when this installation has a database configured, and switched
// off with 'log_visits' => false in data/db-config.php. Called here rather
// than from bootstrap so that every page which counts a visit says so in its
// own first lines — see lib/VisitLog.php for what a row does and does not hold.
VisitLog::record('/catalogue');

/**
 * Every signal the detector can fire, on the site rather than in a repository.
 *
 * This page used to be a link to docs/SIGNALS.md on GitHub, which meant that
 * the one document explaining what the number is made of lived somewhere else,
 * behind somebody else's login wall for anyone at work, and rendered as a wall
 * of markdown tables. The catalogue is the argument this whole tool makes; it
 * belongs on the tool.
 *
 * Everything below is read out of lib/Catalog.php and lib/Report.php at render
 * time. There is no copy of the weights on this page — a page that restated
 * them would be wrong within a month, and being wrong about its own weights is
 * the one thing a detector cannot afford.
 */
$categories = Catalog::categories();
$blurbs     = Catalog::blurbs();
$all        = Catalog::all();

/** @var array<string,array<string,array<string,mixed>>> category => id => signal */
$grouped = array();
foreach (Catalog::order() as $cat) {
    $grouped[$cat] = array();
}
foreach ($all as $id => $signal) {
    $cat = (string) $signal['category'];
    if (!isset($grouped[$cat])) {
        $grouped[$cat] = array();
    }
    $grouped[$cat][$id] = $signal;
}

// Heaviest first inside each family, then by id so that two signals of equal
// weight do not swap places between PHP versions (sorts are only stable from
// 8.0, and this page's anchors are linked to from the results view).
foreach ($grouped as $cat => $signals) {
    uasort($signals, function ($a, $b) {
        $wa = abs((float) $a['weight']);
        $wb = abs((float) $b['weight']);
        if ($wa === $wb) {
            return strcmp((string) $a['label'], (string) $b['label']);
        }
        return $wb <=> $wa;
    });
    $grouped[$cat] = $signals;
}

/** An anchor for a category, matching the ones docs/SIGNALS.md generates. */
function cat_anchor(string $label): string
{
    return strtolower(str_replace(' ', '-', $label));
}

$aiCount = 0;
$humanCount = 0;
foreach ($all as $signal) {
    if (($signal['direction'] ?? '') === 'human') {
        $humanCount++;
    } else {
        $aiCount++;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?= Seo::head(array(
  'title'       => 'The signal catalogue — every tell the detector reads',
  'description' => sprintf(
      'All %d signals this detector can fire, grouped by family, with the weight each one carries and why it earns it. Platform fingerprints, repository history, structure, code style, content, security and aesthetics.',
      count($all)
  ),
  'path'        => '/catalogue.php',
  'type'        => 'article',
  'socialTitle' => 'The signal catalogue',
  'socialDescription' => sprintf('%d signals, what each one means, and what it is worth.', count($all)),
  'jsonLd'      => array(
    Seo::siteSchema(),
    array(
      '@context'         => 'https://schema.org',
      '@type'            => 'Article',
      'headline'         => 'The signal catalogue',
      'description'      => sprintf('Every signal the Vibe Code Detector can fire, with the weight it carries and the reasoning behind that weight. %d signals across %d families of evidence.', count($all), count($categories)),
      'mainEntityOfPage' => Seo::url('/catalogue.php'),
      'inLanguage'       => 'en',
      'isPartOf'         => array('@type' => 'WebSite', 'name' => Seo::SITE_NAME, 'url' => Seo::url('/')),
    ),
    array(
      '@context'        => 'https://schema.org',
      '@type'           => 'BreadcrumbList',
      'itemListElement' => array(
        array('@type' => 'ListItem', 'position' => 1, 'name' => 'Vibe Code Detector', 'item' => Seo::url('/')),
        array('@type' => 'ListItem', 'position' => 2, 'name' => 'The signal catalogue', 'item' => Seo::url('/catalogue.php')),
      ),
    ),
  ),
)) ?>
<link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/css/site.css?v=<?= h(VCD_VERSION) ?>">
<link rel="stylesheet" href="assets/css/catalogue.css?v=<?= h(VCD_VERSION) ?>">
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
      <a href="signs.php">Visual signs</a>
      <a href="./#method">Method</a>
      <a href="./#limits">Limits</a>
      <a href="verify.php">Verify</a>
    </nav>
  </div>
</header>

<main>
  <div class="shell">

    <section class="hero">
      <p class="eyebrow">Reference</p>
      <h1>The signal catalogue</h1>
      <p>Every one of the <?= h(Num::exact(count($all))) ?> things this detector knows how to look for, what each one means, and what it is worth. <?= h(Num::exact($aiCount)) ?> point at generation; <?= h(Num::exact($humanCount)) ?> point the other way and subtract.</p>
      <p class="disclaimer">Weights are in <strong>log-odds</strong>, which is what makes them summable. Scoring starts from a prior of <?= h(number_format(Report::PRIOR_LOGIT, 1)) ?> &mdash; the assumption that a given subject is <em>not</em> generated &mdash; adds the weight of everything found, and pushes the total through a logistic curve to get a percentage. As a rough guide: 0.7 doubles the odds, and 4.5 ends the argument.</p>
    </section>

    <nav class="cat-index" aria-label="Families of evidence">
      <ol>
        <?php foreach ($grouped as $cat => $signals): ?>
          <?php if (!$signals) continue; ?>
          <li>
            <a href="#<?= h(cat_anchor($categories[$cat])) ?>">
              <span><?= h(Num::exact(count($signals))) ?></span> <?= h($categories[$cat]) ?>
            </a>
          </li>
        <?php endforeach; ?>
      </ol>
    </nav>

    <?php foreach ($grouped as $cat => $signals): ?>
    <?php if (!$signals) continue; ?>
    <section class="cat" id="<?= h(cat_anchor($categories[$cat])) ?>">
      <div class="cat-head">
        <h2><?= h($categories[$cat]) ?></h2>
        <p class="cat-blurb"><?= h($blurbs[$cat] ?? '') ?></p>
        <p class="cat-cap">
          <?php if ($cat === Catalog::CAT_FINGERPRINT): ?>
            <strong>No ceiling.</strong> A fingerprint is an identification, not an accumulation, and nothing here holds it back.
          <?php elseif (isset(Report::CATEGORY_CAPS[$cat])): ?>
            <strong>Ceiling: <?= h(number_format((float) Report::CATEGORY_CAPS[$cat], 1)) ?>.</strong>
            However many of these fire, together they can never contribute more than that &mdash; signals inside one family are not independent, and counting them as though they were would let a handful of weak tells outweigh a hard one.
          <?php endif; ?>
        </p>
      </div>

      <ol class="sig-list">
        <?php foreach ($signals as $id => $signal): ?>
        <?php $human = (($signal['direction'] ?? '') === 'human'); ?>
        <li class="sig<?= $human ? ' is-human' : '' ?>" id="<?= h($id) ?>">
          <div class="sig-head">
            <code class="sig-id"><?= h($id) ?></code>
            <h3><?= h($signal['label']) ?></h3>
            <span class="sig-weight" title="<?= $human ? 'subtracts from the score' : 'adds to the score' ?>">
              <?= $human ? '&minus;' : '+' ?><?= h(number_format(abs((float) $signal['weight']), 2)) ?>
            </span>
            <span class="sig-strength"><?= h(Catalog::strengthOf((float) $signal['weight'])) ?></span>
          </div>
          <p><?= h($signal['detail']) ?></p>
        </li>
        <?php endforeach; ?>
      </ol>
    </section>
    <?php endforeach; ?>

    <section class="band" id="rules">
      <div class="prose">
        <p class="eyebrow">Guardrails</p>
        <h2>Rules the scoring will not break</h2>
        <p>The weights above decide what evidence is worth. These decide what any amount of it can add up to, and they are enforced in the scorer rather than described here &mdash; the numbers in this list are read out of <code>lib/Report.php</code> as the page renders.</p>
        <ul>
          <li><strong>Aesthetics are capped as a group at <?= h(number_format(Report::AESTHETIC_CAP, 1)) ?>.</strong> A page with nothing but visual tells cannot pass 55%, however purple or however neon it is.</li>
          <li><strong>Every family has a ceiling</strong>, listed against each one above. Fingerprints are the only exception, because they are identifications rather than inferences.</li>
          <li><strong>Inference stops at <?= h(Num::exact(Report::INFERENCE_CEIL)) ?>%.</strong> Without a fingerprint, no reading passes it, however many families agree. Several families near their ceilings must not add up to the same number a builder naming itself would produce.</li>
          <li><strong>Nothing reaches 0% or 100%</strong> &mdash; the scale runs <?= h(Num::exact(Report::FLOOR)) ?>% to <?= h(Num::exact(Report::CEIL)) ?>%. Certainty is not on the menu.</li>
          <li><strong>Repetition is bounded.</strong> How often a tell fired is part of the reading &mdash; one comment restating the line below it is a coincidence, thirteen is a habit &mdash; but a count can lift a signal by at most half again its weight, it works inside the family ceilings rather than around them, and it never applies to a fingerprint.</li>
          <li><strong>Human signals are first-class.</strong> They are weighted on the same scale, subtract from the total, and are read from the same evidence.</li>
          <li><strong>Short input is discounted rather than guessed at.</strong> Where &ldquo;short&rdquo; means nothing to read, not a short document.</li>
        </ul>
      </div>
    </section>

    <section class="band">
      <div class="prose">
        <p class="eyebrow">A warning about this list</p>
        <h2>A catalogue is not a proof</h2>
        <p>Reading down this page it is easy to come away with the impression that <?= h(Num::exact(count($all))) ?> signals must add up to certainty. They do not, and the guardrails above exist because they do not. Peer-reviewed benchmarks put off-the-shelf detection of AI-generated source at around chance; every stylistic tell here weakens as models improve and as human developers adopt the same conventions; and a formatter erases most of the code-style family in one pass.</p>
        <p>What the catalogue is for is reading the evidence rather than the number. Every signal that fires on a result page carries the excerpt that triggered it, in the code it was found in, with the count. This page is the other half of that: what the thing you are looking at is called, and why it was thought worth anything at all.</p>

        <h3>This page scores <span id="catalogue-score">97%</span></h3>
        <p>The worst reading anything on this site has ever produced, and it is this page &mdash; the reference explaining how the reading works. It is made of nothing but the words the detector looks for. It names two builders in full, and a builder&rsquo;s name is a fingerprint, and a fingerprint has no ceiling; it quotes neon hex values, placeholder contact details and the exact phrasings of house-voice marketing copy, because quoting them is what a catalogue of them is.</p>
        <p>Nothing has been done about it, for the same reason the <a href="signs.php#pill">field guide</a> is left failing its own test: a detector that quietly exempts one address is a detector that lies about one address, and you would have no way of knowing which. It cannot tell describing from doing. Neither can a person reading a screenshot, which is the entire argument this page has been making.</p>

        <p><a href="./">Run something through the detector &rarr;</a> or read <a href="signs.php">the visual field guide &rarr;</a></p>
      </div>
    </section>

    <section class="band">
      <div class="prose">
        <p class="eyebrow">Changing it</p>
        <h2>Where these live</h2>
        <p>Every signal on this page is one entry in <code>lib/Catalog.php</code>, which is the single source for the label, the weight, the family and the wording you have just read. This page renders it, the results view renders it, the PDF certificate renders it, and <a href="<?= h(VCD_REPO_URL) ?>/blob/main/docs/SIGNALS.md" rel="noopener">docs/SIGNALS.md</a> is generated from it so that it cannot drift.</p>
        <p>Adding one means an entry in the catalogue, a detector that fires it, a fixture case in <code>tests/</code>, and an argument for the weight. Disagreeing with one is worth an issue: the weights are judgements, and judgements published without their reasoning are worth exactly nothing. <a href="<?= h(VCD_REPO_URL) ?>/issues/new?template=false_positive.yml" rel="noopener">Report a wrong reading &rarr;</a></p>
      </div>
    </section>

  </div>
</main>

<footer class="colophon">
  <div class="shell colophon-grid">
    <div>
      <p><strong>Vibe Code Detector</strong> is free, open source, and keeps nothing.</p>
      <p><a href="./#provenance">Half of this site was vibecoded</a>, and it scores 73% on its own detector.</p>
    </div>
    <div class="links">
      <a href="./">Analyse something &rarr;</a>
      <a href="signs.php">The visual field guide &rarr;</a>
      <a href="<?= h(VCD_REPO_URL) ?>" rel="noopener">Source and issue tracker &rarr;</a>
      <a href="verify.php">Verify a certificate &rarr;</a>
      <span class="studio">A Landfall studio product</span>
    </div>
  </div>
</footer>

</body>
</html>
