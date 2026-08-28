<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/AdminAuth.php';
require_once dirname(__DIR__) . '/lib/VisitLog.php';
require_once dirname(__DIR__) . '/lib/Chart.php';
require_once dirname(__DIR__) . '/lib/AdminUi.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');

AdminAuth::requireLogin();

/** Windows the panel offers. Anything else in the query string is ignored. */
$ranges = array(7, 30, 90);
$days = isset($_GET['days']) ? (int) $_GET['days'] : 30;
if (!in_array($days, $ranges, true)) {
    $days = 30;
}
$withBots = !empty($_GET['bots']);

$pdo = Db::connect();
$error = '';
$summary = array('views' => 0, 'visitors' => 0, 'bots' => 0, 'busiest' => null);
$daily = array();
$paths = array();
$referrers = array();
$devices = array();
$hours = array();
$weekdays = array();
$pruned = 0;

if ($pdo === null) {
    $error = 'No database configured, so nothing is being recorded. See docs/ADMIN.md.';
} else {
    try {
        Db::ensureSchema($pdo);
        // The retention window is enforced here rather than on the logging
        // path: a DELETE in front of every visitor to keep a ninety-day window
        // accurate to the minute is a bad trade.
        $pruned = VisitLog::prune($pdo);

        $summary   = VisitLog::summary($pdo, $days);
        $daily     = VisitLog::daily($pdo, $days, $withBots);
        $paths     = VisitLog::topPaths($pdo, $days, 20, $withBots);
        $referrers = VisitLog::topReferrers($pdo, $days, 15);
        $devices   = VisitLog::byDevice($pdo, $days);
        $hours     = VisitLog::byHour($pdo, $days);
        $weekdays  = VisitLog::byWeekday($pdo, $days, $withBots);
    } catch (Throwable $e) {
        $error = 'Connected to the database, but a query failed: ' . $e->getMessage();
    }
}

$logging = Db::option('log_visits', true);

$maxPathViews = 0;
foreach ($paths as $row) {
    $maxPathViews = max($maxPathViews, (int) $row['views']);
}
$maxReferrerViews = 0;
foreach ($referrers as $row) {
    $maxReferrerViews = max($maxReferrerViews, (int) $row['views']);
}
$deviceTotal = array_sum($devices);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Traffic — Vibe Code Detector</title>
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
<link rel="icon" href="../assets/img/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="../assets/css/site.css?v=<?= h(VCD_VERSION) ?>">
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= h(VCD_VERSION) ?>">
</head>
<body>
<main class="shell admin-shell">
  <div class="admin-head">
    <h1>Traffic</h1>
    <a href="index.php">&larr; Back to admin</a>
  </div>

  <?= AdminUi::nav('visits') ?>

  <?php if ($error !== ''): ?>
    <p class="error-box"><?= h($error) ?></p>
  <?php endif; ?>

  <?php if ($pdo !== null): ?>

    <?php if (!$logging): ?>
      <div class="no-db">
        <p><strong>Visit logging is switched off.</strong></p>
        <p>This installation has <code>'log_visits' =&gt; false</code> in
           <code>data/db-config.php</code>. Anything below is history from before
           it was turned off, and no new visit is being recorded.</p>
      </div>
    <?php endif; ?>

    <div class="range-bar">
      <span class="range-label">Window</span>
      <?php foreach ($ranges as $r): ?>
        <a class="range<?= $r === $days ? ' is-on' : '' ?>"
           href="?days=<?= (int) $r ?><?= $withBots ? '&amp;bots=1' : '' ?>"><?= (int) $r ?> days</a>
      <?php endforeach; ?>
      <span class="range-sep">·</span>
      <a class="range<?= $withBots ? ' is-on' : '' ?>"
         href="?days=<?= (int) $days ?><?= $withBots ? '' : '&amp;bots=1' ?>">
        <?= $withBots ? 'Counting bots' : 'People only' ?>
      </a>
    </div>

    <section class="admin-section">
      <h2>Last <?= (int) $days ?> days</h2>
      <div class="stat-row">
        <div class="stat"><span class="n"><?= AdminUi::count((int) $summary['views']) ?></span><span class="l">Page views</span></div>
        <div class="stat"><span class="n"><?= AdminUi::count((int) $summary['visitors']) ?></span><span class="l">Daily visitors</span></div>
        <div class="stat"><span class="n"><?= AdminUi::count((int) $summary['bots']) ?></span><span class="l">Bot hits</span></div>
        <div class="stat">
          <span class="n"><?= $summary['busiest'] !== null ? AdminUi::count((int) $summary['busiest']['views']) : '—' ?></span>
          <span class="l"><?= $summary['busiest'] !== null ? h(gmdate('j M', (int) strtotime($summary['busiest']['day'] . ' UTC'))) . ', busiest day' : 'Busiest day' ?></span>
        </div>
      </div>

      <?php $chart = Chart::daily($daily); ?>
      <?php if ($chart !== '' && $summary['views'] > 0): ?>
        <figure class="chart-figure">
          <?= $chart ?>
          <figcaption>
            <span class="key key-bar"></span> page views
            <span class="key key-line"></span> distinct visitors that day
            <?php if ($withBots): ?> · bots included<?php endif; ?>
          </figcaption>
        </figure>
      <?php else: ?>
        <p class="hint">Nothing recorded in this window yet. Visits appear here from the
           moment the database exists — reload a public page and come back.</p>
      <?php endif; ?>
    </section>

    <?php if ($summary['views'] > 0): ?>
      <section class="admin-section">
        <h2>When people come, UTC</h2>
        <?php $hourChart = Chart::hours($hours); ?>
        <?php if ($hourChart !== ''): ?>
          <figure class="chart-figure">
            <?= $hourChart ?>
            <figcaption>Views by hour of day, added up across the window. Bots excluded
              from this one whatever the switch above says &mdash; a crawler on a schedule
              draws a shape that says nothing about when people are here.</figcaption>
          </figure>
        <?php else: ?>
          <p class="hint">Not enough traffic yet to show a shape.</p>
        <?php endif; ?>

        <?php $weekChart = Chart::weekdays($weekdays, 'Views by day of the week'); ?>
        <?php if ($weekChart !== ''): ?>
          <h3 class="admin-sub">Which days</h3>
          <figure class="chart-figure">
            <?= $weekChart ?>
            <figcaption>The companion to the chart above: a weekday average hides a
              Saturday that is half of everything<?php if ($withBots): ?>. Bots
              included<?php endif; ?>.</figcaption>
          </figure>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <section class="admin-section">
      <h2>Pages</h2>
      <?php if (empty($paths)): ?>
        <p class="hint">No page views recorded in this window.</p>
      <?php else: ?>
        <table class="admin-table">
          <thead><tr><th>Path</th><th class="num">Views</th><th class="num">Visitors</th></tr></thead>
          <tbody>
            <?php foreach ($paths as $row): ?>
              <tr>
                <td class="with-bar">
                  <span class="bar" style="width:<?= h(Chart::barWidth((int) $row['views'], $maxPathViews)) ?>"></span>
                  <span class="bar-label"><?= h((string) $row['path']) ?></span>
                </td>
                <td class="num"><?= AdminUi::count((int) $row['views']) ?></td>
                <td class="num"><?= AdminUi::count((int) $row['visitors']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>

    <section class="admin-section">
      <h2>Where they came from</h2>
      <?php if (empty($referrers)): ?>
        <p class="hint">No external referrers in this window. People arriving with no
           referrer — typed, bookmarked, or from an app that strips it — are the
           usual case and are not listed here.</p>
      <?php else: ?>
        <table class="admin-table">
          <thead><tr><th>Site</th><th class="num">Views</th></tr></thead>
          <tbody>
            <?php foreach ($referrers as $row): ?>
              <tr>
                <td class="with-bar">
                  <span class="bar" style="width:<?= h(Chart::barWidth((int) $row['views'], $maxReferrerViews)) ?>"></span>
                  <span class="bar-label"><?= h((string) $row['referer_host']) ?></span>
                </td>
                <td class="num"><?= AdminUi::count((int) $row['views']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>

    <section class="admin-section">
      <h2>What they came with</h2>
      <?php if ($deviceTotal === 0): ?>
        <p class="hint">Nothing recorded in this window.</p>
      <?php else: ?>
        <?php
          $deviceSlices = array();
          foreach ($devices as $device => $n) {
              if ($n > 0) {
                  $deviceSlices[] = array('label' => ucfirst((string) $device), 'n' => (int) $n);
              }
          }
        ?>
        <figure class="chart-figure"><?= Chart::share($deviceSlices, 'hits') ?></figure>

        <table class="admin-table">
          <thead><tr><th>Client</th><th class="num">Hits</th><th class="num">Share</th></tr></thead>
          <tbody>
            <?php foreach ($devices as $device => $n): ?>
              <tr>
                <td class="with-bar">
                  <span class="bar" style="width:<?= h(Chart::barWidth((int) $n, $deviceTotal)) ?>"></span>
                  <span class="bar-label"><?= h(ucfirst((string) $device)) ?></span>
                </td>
                <td class="num"><?= AdminUi::count((int) $n) ?></td>
                <td class="num"><?= $deviceTotal > 0 ? (int) round($n / $deviceTotal * 100) : 0 ?>%</td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <p class="hint">A user agent is a claim, not a fact. Crawlers that identify
           themselves are counted as bots; ones that pretend to be a browser are
           indistinguishable from a browser by anything measurable here.</p>
      <?php endif; ?>
    </section>

    <section class="admin-section">
      <h2>What is being kept</h2>
      <p class="hint">
        Each row is a path, a timestamp, the referring site's host, a coarse client
        class, and a visitor token — an HMAC of address and user agent salted with
        this installation's secret <em>and today's date</em>. It counts people within
        a day and is useless for recognising anyone across two. No address, no cookie,
        no session, and never the query string, which is where the URL somebody asked
        about would be. Rows older than
        <?= (int) Db::option('visit_retention_days', VisitLog::DEFAULT_RETENTION_DAYS) ?>
        days are deleted when this page loads<?= $pruned > 0 ? ' — ' . Num::exact($pruned) . ' just now' : '' ?>.
      </p>
    </section>

  <?php endif; ?>
</main>
</body>
</html>
