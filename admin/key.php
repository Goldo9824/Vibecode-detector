<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/AdminAuth.php';
require_once dirname(__DIR__) . '/lib/ApiKeys.php';
require_once dirname(__DIR__) . '/lib/UsageLog.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');

AdminAuth::requireLogin();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$pdo = Db::connect();

$key = null;
$count = 0;
$hosts = array();
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
<meta name="robots" content="noindex, nofollow">
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
        <div class="stat"><span class="n"><?= (int) $count ?></span><span class="l">Requests with this key</span></div>
      </div>

      <?php if (empty($hosts)): ?>
        <p class="hint">No requests with this key in the last 30 days.</p>
      <?php else: ?>
        <table class="admin-table">
          <thead><tr><th>Website</th><th class="num">Analyses</th></tr></thead>
          <tbody>
            <?php foreach ($hosts as $row): ?>
              <tr>
                <td><a href="website.php?host=<?= h(rawurlencode((string) $row['target_host'])) ?>&amp;days=30"><?= h((string) $row['target_host']) ?></a></td>
                <td class="num"><?= (int) $row['n'] ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</main>
</body>
</html>
