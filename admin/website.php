<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/AdminAuth.php';
require_once dirname(__DIR__) . '/lib/UsageLog.php';
require_once dirname(__DIR__) . '/lib/AdminUi.php';
require_once dirname(__DIR__) . '/lib/Pager.php';
require_once dirname(__DIR__) . '/lib/Chart.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');

AdminAuth::requireLogin();

/**
 * Everything recorded about one website: how often it has been checked, in
 * what way, through which door, and when.
 *
 * Reached by clicking a host in admin/websites.php. The host arrives in the
 * query string and is only ever used as a bound parameter — it is a value from
 * the log, not a path or a name to build SQL out of.
 */

/** The same windows the list offers, so moving between the two keeps your place. */
$ranges = array(0 => 'All time', 7 => '7 days', 30 => '30 days', 90 => '90 days');
$days = isset($_GET['days']) ? (int) $_GET['days'] : 0;
if (!array_key_exists($days, $ranges)) {
    $days = 0;
}

$host = isset($_GET['host']) ? trim((string) $_GET['host']) : '';
if (strlen($host) > 255) {
    $host = substr($host, 0, 255);
}

/**
 * How much of the recent past the chart covers when the window is "all time",
 * and the point past which it stops drawing one column per day.
 */
const VCD_CHART_MAX_DAYS = 365;
const VCD_CHART_MIN_DAYS = 14;
const VCD_CHART_DAILY_UP_TO = 90;

$pdo = Db::connect();
$error = '';
$summary = array('n' => 0, 'modes' => 0, 'keys' => 0, 'pages' => 0, 'first_seen' => null, 'last_seen' => null);
$daily = array();
$chartDays = 30;
$busiestDay = null;
$bucketDays = 1;
$modes = array();
$sources = array();
$callers = array();
$recent = array();

if ($pdo === null) {
    $error = 'No database configured, so nothing is being recorded. See docs/ADMIN.md.';
} elseif ($host === '') {
    $error = 'No website specified.';
} else {
    try {
        Db::ensureSchema($pdo);
        $summary = UsageLog::hostSummary($pdo, $host, $days);

        if ($summary['n'] === 0) {
            $error = 'Nothing recorded for “' . $host . '”'
                   . ($days > 0 ? ' in the last ' . $days . ' days.' : '.');
        } else {
            // On "all time" the chart still has to end somewhere, or one site
            // checked once in 2019 draws two thousand empty columns. It covers
            // from the first analysis to today, up to a year.
            $chartDays = $days > 0 ? $days : VCD_CHART_MAX_DAYS;
            if ($days === 0 && $summary['first_seen'] !== null) {
                $since = strtotime((string) $summary['first_seen'] . ' UTC');
                if ($since !== false) {
                    $spanned = (int) floor((time() - $since) / 86400) + 1;
                    $chartDays = max(VCD_CHART_MIN_DAYS, min(VCD_CHART_MAX_DAYS, $spanned));
                }
            }

            // Past a quarter, a column per day is a bar a pixel wide. Weeks fit.
            $bucketDays = $chartDays > VCD_CHART_DAILY_UP_TO ? 7 : 1;

            $busiestDay = UsageLog::hostBusiestDay($pdo, $host, $days);
            $daily      = UsageLog::bucket(UsageLog::hostDaily($pdo, $host, $chartDays), $bucketDays);
            $modes      = UsageLog::hostModes($pdo, $host, $days);
            $sources    = UsageLog::hostSources($pdo, $host, $days);
            $callers    = UsageLog::hostCallers($pdo, $host, $days);
            $recent     = UsageLog::hostRecent($pdo, $host, $days, 25);
        }
    } catch (Throwable $e) {
        $error = 'Connected to the database, but a query failed: ' . $e->getMessage();
    }
}

$modeTotal = array_sum($modes);
$sourceTotal = array_sum($sources);
$callerMax = 0;
foreach ($callers as $caller) {
    $callerMax = max($callerMax, $caller['n']);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $host !== '' ? h($host) . ' — ' : '' ?>Websites — Vibe Code Detector</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="../assets/img/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="../assets/css/site.css?v=<?= h(VCD_VERSION) ?>">
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= h(VCD_VERSION) ?>">
</head>
<body>
<main class="shell admin-shell wide">
  <div class="admin-head">
    <h1><?= $host !== '' ? h($host) : 'Website' ?></h1>
    <a href="<?= h('websites.php' . Pager::query(array('days' => $days), array('days' => 0))) ?>">&larr; All websites</a>
  </div>

  <?php if ($error !== ''): ?>
    <p class="error-box"><?= h($error) ?></p>
    <p class="hint"><a href="websites.php">Back to the full list &rarr;</a></p>
  <?php else: ?>

    <div class="range-bar">
      <span class="range-label">Window</span>
      <?php foreach ($ranges as $value => $label): ?>
        <a class="range<?= $value === $days ? ' is-on' : '' ?>"
           href="<?= h('website.php?host=' . rawurlencode($host) . '&days=' . (int) $value) ?>"><?= h($label) ?></a>
      <?php endforeach; ?>
    </div>

    <section class="admin-section">
      <h2><?= $days > 0 ? 'Last ' . (int) $days . ' days' : 'Everything recorded' ?></h2>
      <div class="stat-row">
        <div class="stat"><span class="n"><?= number_format($summary['n']) ?></span><span class="l">Analyses</span></div>
        <div class="stat"><span class="n"><?= number_format($summary['pages']) ?></span><span class="l">Distinct addresses</span></div>
        <div class="stat">
          <span class="n"><?= $busiestDay !== null ? number_format($busiestDay['n']) : '—' ?></span>
          <span class="l"><?= $busiestDay !== null ? h(AdminUi::day($busiestDay['day'] . ' 00:00:00')) . ', busiest day' : 'Busiest day' ?></span>
        </div>
        <div class="stat"><span class="n is-date"><?= h(AdminUi::day($summary['first_seen'])) ?></span><span class="l">First searched</span></div>
        <div class="stat"><span class="n is-date"><?= h(AdminUi::day($summary['last_seen'])) ?></span><span class="l">Last searched</span></div>
      </div>

      <?php
        $per = $bucketDays > 1 ? 'week' : 'day';
        $chart = Chart::series($daily, 'Analyses per ' . $per . ' for ' . $host, 'analyses');
      ?>
      <?php if ($chart !== ''): ?>
        <figure class="chart-figure">
          <?= $chart ?>
          <figcaption>
            <span class="key key-bar"></span> analyses per <?= h($per) ?>
            <?php if ($days === 0 && $chartDays >= VCD_CHART_MAX_DAYS): ?>
              · the last <?= (int) VCD_CHART_MAX_DAYS ?> days; the totals above cover everything
            <?php endif; ?>
          </figcaption>
        </figure>
      <?php endif; ?>
    </section>

    <section class="admin-section">
      <h2>How it was checked</h2>
      <div class="table-scroll">
        <table class="admin-table">
          <thead><tr><th>Mode</th><th class="num">Analyses</th><th class="num">Share</th></tr></thead>
          <tbody>
            <?php foreach ($modes as $mode => $n): ?>
              <tr>
                <td class="with-bar">
                  <span class="bar" style="width:<?= h(Chart::barWidth((int) $n, $modeTotal)) ?>"></span>
                  <span class="bar-label"><?= h(AdminUi::modeLabel((string) $mode)) ?></span>
                </td>
                <td class="num"><?= number_format((int) $n) ?></td>
                <td class="num"><?= $modeTotal > 0 ? (int) round($n / $modeTotal * 100) : 0 ?>%</td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="admin-section">
      <h2>Where the request came from</h2>
      <div class="table-scroll">
        <table class="admin-table">
          <thead><tr><th>Door</th><th class="num">Analyses</th><th class="num">Share</th></tr></thead>
          <tbody>
            <?php foreach ($sources as $source => $n): ?>
              <tr>
                <td class="with-bar">
                  <span class="bar" style="width:<?= h(Chart::barWidth((int) $n, $sourceTotal)) ?>"></span>
                  <span class="bar-label"><?= h(AdminUi::sourceLabel((string) $source)) ?></span>
                </td>
                <td class="num"><?= number_format((int) $n) ?></td>
                <td class="num"><?= $sourceTotal > 0 ? (int) round($n / $sourceTotal * 100) : 0 ?>%</td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <?php if (!empty($callers)): ?>
      <section class="admin-section">
        <h2>Which key</h2>
        <div class="table-scroll">
          <table class="admin-table">
            <thead><tr><th>Key</th><th class="num">Analyses</th></tr></thead>
            <tbody>
              <?php foreach ($callers as $caller): ?>
                <tr>
                  <td class="with-bar">
                    <span class="bar" style="width:<?= h(Chart::barWidth($caller['n'], $callerMax)) ?>"></span>
                    <span class="bar-label">
                      <?php if ($caller['id'] !== null && $caller['name'] !== ''): ?>
                        <a href="key.php?id=<?= (int) $caller['id'] ?>"><?= h($caller['name']) ?></a>
                      <?php elseif ($caller['id'] !== null): ?>
                        Deleted key #<?= (int) $caller['id'] ?>
                      <?php else: ?>
                        No key — this site, or a key from <code>data/api-keys.txt</code>
                      <?php endif; ?>
                    </span>
                  </td>
                  <td class="num"><?= number_format($caller['n']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
    <?php endif; ?>

    <section class="admin-section">
      <h2>Most recent analyses</h2>
      <?php if (empty($recent)): ?>
        <p class="hint">Nothing in this window.</p>
      <?php else: ?>
        <div class="table-scroll">
          <table class="admin-table">
            <thead><tr><th>When</th><th>Mode</th><th>Door</th><th>Address</th></tr></thead>
            <tbody>
              <?php foreach ($recent as $row): ?>
                <tr>
                  <td class="when" title="<?= h(AdminUi::when($row['created_at'])) ?>"><?= h(AdminUi::ago($row['created_at'])) ?></td>
                  <td><?= h(AdminUi::modeLabel($row['mode'])) ?></td>
                  <td><?= h(AdminUi::sourceLabel($row['source'])) ?><?= $row['key_name'] !== null ? ' · ' . h($row['key_name']) : '' ?></td>
                  <td class="wrap-any"><?= h($row['target'] !== null ? $row['target'] : '—') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p class="hint">The address is what was submitted for analysis. Nothing about who
           submitted it is recorded — no address, no cookie, no session.</p>
      <?php endif; ?>
    </section>

  <?php endif; ?>
</main>
</body>
</html>
