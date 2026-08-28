<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/AdminAuth.php';
require_once dirname(__DIR__) . '/lib/ApiKeys.php';
require_once dirname(__DIR__) . '/lib/UsageLog.php';
require_once dirname(__DIR__) . '/lib/AdminUi.php';
require_once dirname(__DIR__) . '/lib/Chart.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');

AdminAuth::requireLogin();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$pdo = Db::connect();

$key = null;
$count = 0;
$hosts = array();
$daily = array();
$modes = array();
$error = '';

if ($pdo === null) {
    $error = 'No database configured.';
} elseif ($id <= 0) {
    $error = 'No key specified.';
} else {
    try {
        $key = ApiKeys::find($pdo, $id);
        if ($key === null) {
            $error = 'No such key.';
        } else {
            $count = UsageLog::countForKey($pdo, $id, 30);
            $hosts = UsageLog::topHosts($pdo, 30, $id, 20);
            $daily = UsageLog::keyDaily($pdo, $id, 30);
            $modes = UsageLog::keyModes($pdo, $id, 30);
        }
    } catch (Throwable $e) {
        $error = 'A query failed: ' . $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin — Vibe Code Detector</title>
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
<link rel="icon" href="../assets/img/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="../assets/css/site.css?v=<?= h(VCD_VERSION) ?>">
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= h(VCD_VERSION) ?>">
</head>
<body>
<main class="shell admin-shell">
  <div class="admin-head">
    <h1><?= $key !== null ? h((string) $key['name']) : 'Key' ?></h1>
    <a href="index.php">&larr; All keys</a>
  </div>

  <?php if ($error !== ''): ?>
    <p class="error-box"><?= h($error) ?></p>
  <?php else: ?>
    <p class="hint">
      <?= empty($key['revoked_at']) ? 'Active' : 'Revoked ' . h((string) $key['revoked_at']) ?>
      &middot; created <?= h((string) $key['created_at']) ?>
    </p>

    <section class="admin-section">
      <h2>Usage, last 30 days</h2>
      <div class="stat-row">
        <div class="stat"><span class="n"><?= AdminUi::count((int) $count) ?></span><span class="l">Requests with this key</span></div>
      </div>

      <?php $keyChart = Chart::series($daily, 'Requests with this key, per day', 'requests'); ?>
      <?php if ($keyChart !== '' && $count > 0): ?>
        <figure class="chart-figure">
          <?= $keyChart ?>
          <figcaption><span class="key key-bar"></span> requests with this key per day</figcaption>
        </figure>
      <?php endif; ?>

      <?php if ($count > 0 && !empty($modes)): ?>
        <h3 class="admin-sub">What it asks for</h3>
        <figure class="chart-figure"><?= Chart::share(UsageLog::modeSlices($modes), 'requests') ?></figure>
      <?php endif; ?>

      <?php if (empty($hosts)): ?>
        <p class="hint">No websites recorded against this key in the last 30 days. Pasted
           code and git history are counted above but never listed here &mdash; they carry
           no website to attribute a request to.</p>
      <?php else: ?>
        <?php
          $hostMax = 0;
          foreach ($hosts as $row) {
              $hostMax = max($hostMax, (int) $row['n']);
          }
        ?>
        <h3 class="admin-sub">What it points at</h3>
        <div class="table-scroll">
          <table class="admin-table">
            <thead><tr><th>Website</th><th class="num">Analyses</th></tr></thead>
            <tbody>
              <?php foreach ($hosts as $row): ?>
                <tr>
                  <td class="with-bar">
                    <span class="bar" style="width:<?= h(Chart::barWidth((int) $row['n'], $hostMax)) ?>"></span>
                    <span class="bar-label">
                      <a href="website.php?host=<?= h(rawurlencode((string) $row['target_host'])) ?>&amp;days=30"><?= h((string) $row['target_host']) ?></a>
                    </span>
                  </td>
                  <td class="num"><?= AdminUi::count((int) $row['n']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</main>
</body>
</html>
