<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/Brand.php';

header('X-Content-Type-Options: nosniff');

$payload = isset($_GET['p']) ? (string) $_GET['p'] : '';
$sig     = isset($_GET['s']) ? (string) $_GET['s'] : '';
$cert    = ($payload !== '' && $sig !== '') ? vcd_cert_open($payload, $sig) : null;
$checked = ($payload !== '' || $sig !== '');

$subjectKinds = array(
    'url'  => 'live page',
    'site' => 'whole site',
    'code' => 'pasted source file',
    'git'  => 'repository history',
);

$verdicts = array(
    'builder_identified' => 'Built by an AI site builder',
    'very_likely_ai'     => 'Very likely AI-generated',
    'likely_ai'          => 'Likely AI-generated',
    'leaning_ai'         => 'Possibly AI-assisted',
    'inconclusive'       => 'Inconclusive',
    'leaning_human'      => 'Probably hand-written',
    'likely_human'       => 'Likely hand-written',
);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Verify a certificate — Vibe Code Detector</title>
<meta name="description" content="Check that a Vibe Code Detector certificate says what it was issued saying.">
<meta name="robots" content="noindex">
<link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/css/site.css?v=<?= h(VCD_VERSION) ?>">
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
      <a href="<?= h(VCD_REPO_URL) ?>" rel="noopener">Source</a>
    </nav>
  </div>
</header>

<main>
  <div class="shell">
    <section class="hero">
      <h1>Verify a certificate</h1>
      <p>Certificates are signed by the server that issued them. Paste the full verification link from the PDF, or open the link directly, and this will say whether it still holds.</p>
    </section>

    <?php if ($checked && $cert === null): ?>
      <div class="panel">
        <div class="verdict">
          <div class="score is-ai">&#10007;</div>
          <div>
            <p class="eyebrow">Result</p>
            <h2>This certificate does not verify</h2>
            <p>The signature does not match its contents. Either the link was altered after the certificate was issued, or it was issued by a different installation of this tool.</p>
          </div>
        </div>
      </div>
    <?php elseif ($cert !== null): ?>
      <div class="panel">
        <div class="subject"><?= h((string) $cert['t']) ?></div>
        <div class="verdict">
          <div class="score <?= (int) $cert['s'] >= 70 ? 'is-ai' : ((int) $cert['s'] >= 55 ? 'is-mixed' : ((int) $cert['s'] >= 42 ? 'is-unknown' : 'is-human')) ?>">
            <?= (int) $cert['s'] ?><span>%</span>
          </div>
          <div>
            <p class="eyebrow">Genuine certificate</p>
            <h2><?= h($verdicts[$cert['c']] ?? 'Inconclusive') ?></h2>
            <p>Issued <?= h(gmdate('j F Y \a\t H:i', strtotime((string) $cert['d']) ?: time())) ?> UTC for a
               <?= h($subjectKinds[$cert['m']] ?? 'subject') ?>, with
               <?= count((array) ($cert['g'] ?? array())) ?> signal(s) on the record.</p>
            <div class="meta-row">
              <span>Certificate <?= h((string) ($cert['id'] ?? '')) ?></span>
              <span>Confidence: <?= h(ucfirst((string) ($cert['f'] ?? 'low'))) ?></span>
            </div>
          </div>
        </div>
        <div class="notes">
          <p class="eyebrow">What this confirms</p>
          <ul>
            <li>That this tool produced this reading for this subject at this time, and that nobody has edited it since.</li>
            <li>It does not confirm that the reading is <em>correct</em>. Automated detection of AI-generated source performs near chance in peer-reviewed benchmarks, and this certificate is worth exactly what the evidence behind it is worth.</li>
          </ul>
        </div>
      </div>
    <?php endif; ?>

    <section class="band">
      <div class="prose">
        <form method="get" action="verify.php" id="verify-form">
          <label class="field" for="link">Paste a verification link</label>
          <input type="text" id="link" name="link" spellcheck="false" placeholder="<?= h(vcd_site_url()) ?>/verify.php?p=…&amp;s=…">
          <div class="actions">
            <button class="btn" type="submit">Check it</button>
          </div>
        </form>
        <p class="hint">There is no lookup happening here and no record to search: nothing about an analysis is ever stored. Verification is purely a signature check on the link itself.</p>
      </div>
    </section>
  </div>
</main>

<footer class="colophon">
  <div class="shell colophon-grid">
    <div><p><strong>Vibe Code Detector</strong> &mdash; free, open source, stores nothing.</p></div>
    <div class="links">
      <a href="./">Analyse something &rarr;</a>
      <a href="<?= h(VCD_REPO_URL) ?>" rel="noopener">Source and issue tracker &rarr;</a>
    </div>
  </div>
</footer>

<script>
// Splitting a pasted link is the browser's job, not the server's.
document.getElementById('verify-form').addEventListener('submit', function (e) {
  var raw = document.getElementById('link').value.trim();
  if (!raw) return;
  e.preventDefault();
  var query = raw.indexOf('?') >= 0 ? raw.slice(raw.indexOf('?') + 1) : raw;
  var params = new URLSearchParams(query);
  if (params.get('p') && params.get('s')) {
    window.location.href = 'verify.php?p=' + encodeURIComponent(params.get('p')) +
                           '&s=' + encodeURIComponent(params.get('s'));
  } else {
    window.location.href = 'verify.php?p=invalid&s=invalid';
  }
});
</script>
</body>
</html>
