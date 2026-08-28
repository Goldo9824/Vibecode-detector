<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/AdminAuth.php';
require_once dirname(__DIR__) . '/lib/Feedback.php';
require_once dirname(__DIR__) . '/lib/UsageLog.php';
require_once dirname(__DIR__) . '/lib/Chart.php';
require_once dirname(__DIR__) . '/lib/AdminUi.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');

AdminAuth::requireLogin();

/**
 * What people say when a reading looks wrong to them.
 *
 * The number this page exists to produce is not "how many complaints" — it is
 * *where on the scale* they are. A detector whose reports all sit in one band
 * has a threshold in the wrong place and can be fixed; one whose reports are
 * spread evenly is being argued with, which is a different problem and not
 * necessarily this tool's.
 */

$ranges = array(7, 30, 90, 365);
$days = isset($_GET['days']) ? (int) $_GET['days'] : 30;
if (!in_array($days, $ranges, true)) {
    $days = 30;
}

$only = isset($_GET['dir']) ? (string) $_GET['dir'] : '';
if (!in_array($only, Feedback::DIRECTIONS, true)) {
    $only = '';
}

$pdo = Db::connect();
$error = '';
$summary = array('n' => 0, 'too_high' => 0, 'too_low' => 0, 'about_right' => 0,
                 'hosts' => 0, 'avg_high' => null, 'avg_low' => null, 'last' => null);
$daily = array();
$bands = array();
$byMode = array();
$byTruth = array();
$targets = array();
$reports = array();
$rates = array();

if ($pdo === null) {
    $error = 'No database configured, so no report can be filed and none is being kept. See docs/ADMIN.md.';
} else {
    try {
        Db::ensureSchema($pdo);

        $summary = Feedback::summary($pdo, $days);
        $daily   = Feedback::daily($pdo, $days);
        $bands   = Feedback::byBand($pdo, $days);
        $byMode  = Feedback::byMode($pdo, $days);
        $byTruth = Feedback::byTruth($pdo, $days);
        $targets = Feedback::topTargets($pdo, $days, 15);
        $reports = Feedback::recent($pdo, $days, 60, $only);
        // Reports on their own say nothing: five about a mode used five
        // thousand times is a mode that works.
        $rates   = Feedback::rates($byMode, UsageLog::totalsByMode($pdo, $days));
    } catch (Throwable $e) {
        $error = 'Connected to the database, but a query failed: ' . $e->getMessage();
    }
}

$bandLabels = Feedback::bandLabels();
$bandMax = 0;
foreach ($bands as $row) {
    $bandMax = max($bandMax, (int) $row['n']);
}
$targetMax = 0;
foreach ($targets as $row) {
    $targetMax = max($targetMax, $row['n']);
}

$directionSlices = array();
foreach (array('too_high', 'too_low', 'about_right') as $direction) {
    if ($summary[$direction] > 0) {
        $directionSlices[] = array('label' => Feedback::directionLabel($direction), 'n' => (int) $summary[$direction]);
    }
}

$modeSlices = array();
foreach ($byMode as $row) {
    $modeSlices[] = array('label' => AdminUi::modeLabel($row['mode']), 'n' => $row['n']);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reports — Vibe Code Detector</title>
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
<link rel="icon" href="../assets/img/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="../assets/css/site.css?v=<?= h(VCD_VERSION) ?>">
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= h(VCD_VERSION) ?>">
</head>
<body>
<main class="shell admin-shell wide">
  <div class="admin-head">
    <h1>Reports</h1>
    <a href="index.php">&larr; Back to admin</a>
  </div>

  <?= AdminUi::nav('feedback') ?>

  <?php if ($error !== ''): ?>
    <p class="error-box"><?= h($error) ?></p>
  <?php endif; ?>

  <?php if ($pdo !== null): ?>

    <div class="range-bar">
      <span class="range-label">Window</span>
      <?php foreach ($ranges as $r): ?>
        <a class="range<?= $r === $days ? ' is-on' : '' ?>"
           href="?days=<?= (int) $r ?><?= $only !== '' ? '&amp;dir=' . h($only) : '' ?>">
          <?= $r === 365 ? 'A year' : (int) $r . ' days' ?>
        </a>
      <?php endforeach; ?>
      <span class="range-sep">·</span>
      <span class="range-label">Showing</span>
      <a class="range<?= $only === '' ? ' is-on' : '' ?>" href="?days=<?= (int) $days ?>">All</a>
      <?php foreach (Feedback::DIRECTIONS as $direction): ?>
        <a class="range<?= $only === $direction ? ' is-on' : '' ?>"
           href="?days=<?= (int) $days ?>&amp;dir=<?= h($direction) ?>"><?= h(Feedback::directionLabel($direction)) ?></a>
      <?php endforeach; ?>
    </div>

    <section class="admin-section">
      <h2>Last <?= $days === 365 ? 'year' : (int) $days . ' days' ?></h2>
      <div class="stat-row">
        <div class="stat"><span class="n"><?= AdminUi::count((int) $summary['n']) ?></span><span class="l">Reports</span></div>
        <div class="stat"><span class="n"><?= AdminUi::count((int) $summary['too_high']) ?></span><span class="l">Score too high</span></div>
        <div class="stat"><span class="n"><?= AdminUi::count((int) $summary['too_low']) ?></span><span class="l">Score too low</span></div>
        <div class="stat"><span class="n"><?= AdminUi::count((int) $summary['about_right']) ?></span><span class="l">About right</span></div>
        <div class="stat">
          <span class="n is-date"><?= h($summary['last'] !== null ? AdminUi::ago($summary['last']) : 'never') ?></span>
          <span class="l">Most recent</span>
        </div>
      </div>

      <?php if ($summary['n'] === 0): ?>
        <p class="hint">Nothing reported in this window. The block under every result on the
           front page is what fills this table &mdash; it appears there only when a database
           is configured, which it is.</p>
      <?php else: ?>
        <?php
          $lean = $summary['too_high'] - $summary['too_low'];
        ?>
        <p class="hint">
          <?php if ($summary['avg_high'] !== null): ?>
            The readings called too high averaged <?= (int) $summary['avg_high'] ?>%<?php
              ?><?php if ($summary['avg_low'] !== null): ?>, the ones called too low averaged
              <?= (int) $summary['avg_low'] ?>%<?php endif; ?>.
          <?php elseif ($summary['avg_low'] !== null): ?>
            The readings called too low averaged <?= (int) $summary['avg_low'] ?>%.
          <?php endif; ?>
          <?php if ($lean > 0): ?>
            On balance people say this tool reads high.
          <?php elseif ($lean < 0): ?>
            On balance people say this tool reads low.
          <?php else: ?>
            The two directions cancel out, which is what a scale with no systematic
            bias looks like &mdash; or what too few reports look like.
          <?php endif; ?>
          Reports are self-selected: a reader who agrees with a reading has no reason
          to say so, and the &ldquo;about right&rdquo; button is only a partial answer
          to that.
        </p>

        <?php $daysChart = Chart::stack(
                $daily,
                array('too_high', 'too_low', 'about_right'),
                array('too_high' => 'too high', 'too_low' => 'too low', 'about_right' => 'about right'),
                'Reports per day'
              ); ?>
        <?php if ($daysChart !== ''): ?>
          <figure class="chart-figure">
            <?= $daysChart ?>
            <figcaption>
              <?= Chart::key(0, 'score too high') ?>
              <?= Chart::key(1, 'score too low') ?>
              <?= Chart::key(2, 'about right') ?>
            </figcaption>
          </figure>
        <?php endif; ?>

        <?php $dirChart = Chart::share($directionSlices, 'reports'); ?>
        <?php if ($dirChart !== ''): ?>
          <h3 class="admin-sub">Which way</h3>
          <figure class="chart-figure"><?= $dirChart ?></figure>
        <?php endif; ?>
      <?php endif; ?>
    </section>

    <?php if ($summary['n'] > 0): ?>
      <section class="admin-section">
        <h2>Where on the scale</h2>
        <div class="table-scroll">
          <table class="admin-table">
            <thead>
              <tr><th>Band</th><th class="num">Reports</th><th class="num">Too high</th><th class="num">Too low</th><th class="num">About right</th></tr>
            </thead>
            <tbody>
              <?php foreach ($bandLabels as $band => $label): $row = $bands[$band]; ?>
                <tr>
                  <td class="with-bar">
                    <span class="bar" style="width:<?= h(Chart::barWidth((int) $row['n'], $bandMax)) ?>"></span>
                    <span class="bar-label"><?= h($label) ?></span>
                  </td>
                  <td class="num"><?= AdminUi::count((int) $row['n']) ?></td>
                  <td class="num"><?= AdminUi::count((int) $row['too_high']) ?></td>
                  <td class="num"><?= AdminUi::count((int) $row['too_low']) ?></td>
                  <td class="num"><?= AdminUi::count((int) $row['about_right']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p class="hint">The four bands the meter on the front page is painted in. Reports
           bunched into one of them are a threshold in the wrong place; reports spread
           evenly across all four are a disagreement about the whole idea, which no
           threshold fixes.</p>
      </section>

      <section class="admin-section">
        <h2>How often a reading is disputed</h2>
        <div class="table-scroll">
          <table class="admin-table">
            <thead><tr><th>Mode</th><th class="num">Reports</th><th class="num">Analyses</th><th class="num">Per 100</th></tr></thead>
            <tbody>
              <?php foreach ($rates as $row): ?>
                <tr>
                  <td><?= h(AdminUi::modeLabel($row['mode'])) ?></td>
                  <td class="num"><?= AdminUi::count($row['reports']) ?></td>
                  <td class="num"><?= AdminUi::count($row['analyses']) ?></td>
                  <td class="num"><?= $row['analyses'] > 0 ? h(number_format($row['per100'], 1)) : '—' ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p class="hint">The rate is the figure worth reading, not the count: a mode used
           ten times as often collects ten times the complaints without being any worse.
           Both columns are for this window, so a mode whose analyses predate it can show
           more reports than analyses.</p>

        <?php $modeChart = Chart::share($modeSlices, 'reports'); ?>
        <?php if ($modeChart !== ''): ?>
          <figure class="chart-figure"><?= $modeChart ?></figure>
        <?php endif; ?>
      </section>

      <?php if (!empty($byTruth)): ?>
        <section class="admin-section">
          <h2>What people say it really is</h2>
          <figure class="chart-figure"><?= Chart::share($byTruth, 'reports') ?></figure>
          <p class="hint">Volunteered, unverifiable, and still the most interesting column
             here: somebody reporting their own repository knows something no analysis of
             it can.</p>
        </section>
      <?php endif; ?>

      <?php if (!empty($targets)): ?>
        <section class="admin-section">
          <h2>Most-reported websites</h2>
          <div class="table-scroll">
            <table class="admin-table">
              <thead><tr><th>Website</th><th class="num">Reports</th><th class="num">Too high</th><th class="num">Too low</th><th class="num">Avg score</th></tr></thead>
              <tbody>
                <?php foreach ($targets as $row): ?>
                  <tr>
                    <td class="with-bar">
                      <span class="bar" style="width:<?= h(Chart::barWidth($row['n'], $targetMax)) ?>"></span>
                      <span class="bar-label">
                        <a href="website.php?host=<?= h(rawurlencode($row['target_host'])) ?>&amp;days=0"><?= h($row['target_host']) ?></a>
                      </span>
                    </td>
                    <td class="num"><?= AdminUi::count($row['n']) ?></td>
                    <td class="num"><?= AdminUi::count($row['too_high']) ?></td>
                    <td class="num"><?= AdminUi::count($row['too_low']) ?></td>
                    <td class="num"><?= AdminUi::score($row['avg']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <p class="hint">Pasted code and pasted git logs have no address to group by and
             are never listed here &mdash; what was pasted is not written down anywhere.
             Their reports are in the table below.</p>
        </section>
      <?php endif; ?>
    <?php endif; ?>

    <section class="admin-section">
      <h2>The reports<?= $only !== '' ? ' &mdash; ' . h(strtolower(Feedback::directionLabel($only))) : '' ?></h2>
      <?php if (empty($reports)): ?>
        <p class="hint">Nothing here in this window.</p>
      <?php else: ?>
        <div class="table-scroll">
          <table class="admin-table">
            <thead>
              <tr><th>When</th><th class="num">Read</th><th>Says</th><th>Really is</th><th>Subject</th><th>Note</th></tr>
            </thead>
            <tbody>
              <?php foreach ($reports as $row): ?>
                <tr>
                  <td class="when" title="<?= h(AdminUi::when($row['created_at'])) ?>"><?= h(AdminUi::ago($row['created_at'])) ?></td>
                  <td class="num"><?= AdminUi::score($row['score']) ?></td>
                  <td><?= h(Feedback::directionLabel($row['direction'])) ?></td>
                  <td><?= h(Feedback::truthLabel($row['truth'])) ?></td>
                  <td class="wrap-any">
                    <?= h(AdminUi::modeLabel($row['mode'])) ?>
                    <?php if ($row['target'] !== null && $row['target'] !== ''): ?>
                      &middot; <?= h($row['target']) ?>
                    <?php endif; ?>
                  </td>
                  <td class="note"><?= h($row['comment'] !== null ? $row['comment'] : '') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

    <section class="admin-section">
      <h2>What is being kept</h2>
      <p class="hint">
        One row per reading somebody disagreed with: the reading itself &mdash; its mode,
        its address where it has one, its score and its verdict &mdash; which way the
        reader says it is wrong, what they say the subject really is, and their note if
        they left one. Nothing about who they are: no address, no cookie, no session,
        and no field to leave an email in, because there is nowhere for an answer to go
        and the form says so rather than implying one is coming.
      </p>
      <p class="hint">
        A report is only accepted with the certificate its reading was issued with,
        which is a signature over the mode, address, score and verdict. That is
        provenance rather than authentication: it means every row disputes a reading
        this site actually produced, at the number it actually gave. The certificate id
        is unique per reading, so a reader who changes their mind replaces their report
        instead of voting twice.
      </p>
    </section>

  <?php endif; ?>
</main>
</body>
</html>
