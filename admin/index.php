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

$pdo = Db::connect();
$dbError = '';
if ($pdo !== null) {
    try {
        Db::ensureSchema($pdo);
    } catch (Throwable $e) {
        $dbError = 'Connected to the database, but could not set up its tables: ' . $e->getMessage();
        $pdo = null;
    }
}

$formError = '';

if ($pdo !== null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $token = isset($_POST['csrf']) ? (string) $_POST['csrf'] : '';
    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

    if (!AdminAuth::checkCsrf($token)) {
        $formError = 'That form expired. Try again.';
    } elseif ($action === 'create_key') {
        $name = trim(isset($_POST['name']) ? (string) $_POST['name'] : '');
        if ($name === '') {
            $formError = 'Give the key a name first — who or what it is for.';
        } elseif (strlen($name) > 190) {
            $formError = 'That name is too long.';
        } else {
            $key = ApiKeys::create($pdo, $name);
            AdminAuth::setFlash(array('newKey' => $key, 'newKeyName' => $name));
            header('Location: index.php');
            exit;
        }
    } elseif ($action === 'revoke_key') {
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if ($id > 0) {
            ApiKeys::revoke($pdo, $id);
        }
        header('Location: index.php');
        exit;
    }
}

$flash = AdminAuth::takeFlash();
$csrf = AdminAuth::csrfToken();

$keys = array();
$totalsByMode = array('url' => 0, 'site' => 0, 'code' => 0, 'git' => 0);
$totalCount = 0;
$topHosts = array();

if ($pdo !== null) {
    try {
        $keys = ApiKeys::all($pdo);
        $totalsByMode = UsageLog::totalsByMode($pdo, 30);
        $totalCount = UsageLog::totalCount($pdo, 30);
        $topHosts = UsageLog::topHosts($pdo, 30, null, 20);
    } catch (Throwable $e) {
        $dbError = 'Connected to the database, but a query failed: ' . $e->getMessage();
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
    <h1>Admin</h1>
    <a href="logout.php">Log out</a>
  </div>

  <?php if ($pdo === null): ?>
    <div class="no-db">
      <p><strong>No database configured yet.</strong></p>
      <?php if ($dbError !== ''): ?>
        <p><?= h($dbError) ?></p>
      <?php endif; ?>
      <p>Create <code>data/db-config.php</code> on this server, then reload this page —
         it creates its own tables the first time it can connect. See
         <code>docs/ADMIN.md</code> for the exact file to paste in.</p>
      <p>The key-authenticated API and the manual <code>data/api-keys.txt</code> file
         keep working with no database at all; this panel is only needed for named,
         individually revocable keys and usage stats.</p>
    </div>
  <?php else: ?>

    <?php if ($dbError !== ''): ?>
      <p class="error-box"><?= h($dbError) ?></p>
    <?php endif; ?>

    <?php if ($flash !== null && !empty($flash['newKey'])): ?>
      <div class="flash">
        <p><strong>Key created for “<?= h((string) $flash['newKeyName']) ?>”.</strong>
           Copy it now — it will not be shown again:</p>
        <code><?= h((string) $flash['newKey']) ?></code>
      </div>
    <?php endif; ?>

    <?php if ($formError !== ''): ?>
      <p class="error-box"><?= h($formError) ?></p>
    <?php endif; ?>

    <section class="admin-section">
      <h2>API keys</h2>

      <form method="post" class="add-key-form">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="create_key">
        <div>
          <label class="field" for="name">New key — name</label>
          <input type="text" id="name" name="name" placeholder="e.g. Jane, or “monitoring script”" maxlength="190" required>
        </div>
        <div class="actions" style="margin-top:0">
          <button class="btn" type="submit">Create key</button>
        </div>
      </form>

      <br>

      <?php if (empty($keys)): ?>
        <p class="hint">No keys yet. Create one above to hand out access to <code>api/website.php</code>.</p>
      <?php else: ?>
        <table class="admin-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Status</th>
              <th>Created</th>
              <th>Last used</th>
              <th class="num">Uses</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($keys as $k): $active = empty($k['revoked_at']); ?>
              <tr>
                <td><?= h((string) $k['name']) ?></td>
                <td><span class="badge <?= $active ? 'is-active' : 'is-revoked' ?>"><?= $active ? 'Active' : 'Revoked' ?></span></td>
                <td><?= h((string) $k['created_at']) ?></td>
                <td><?= h($k['last_used'] !== null ? (string) $k['last_used'] : '—') ?></td>
                <td class="num"><?= (int) $k['uses'] ?></td>
                <td>
                  <a href="key.php?id=<?= (int) $k['id'] ?>">View usage</a>
                  <?php if ($active): ?>
                    &nbsp;·&nbsp;
                    <form method="post" class="inline-form" onsubmit="return confirm('Remove this key? It will stop working immediately.');">
                      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                      <input type="hidden" name="action" value="revoke_key">
                      <input type="hidden" name="id" value="<?= (int) $k['id'] ?>">
                      <button type="submit" class="btn-quiet" style="border:none;padding:0;text-decoration:underline;background:none;font:inherit;text-transform:none;letter-spacing:normal;cursor:pointer;color:var(--red);">Remove</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>

    <section class="admin-section">
      <h2>Usage, last 30 days</h2>
      <div class="stat-row">
        <div class="stat"><span class="n"><?= (int) $totalCount ?></span><span class="l">Total analyses</span></div>
        <div class="stat"><span class="n"><?= (int) $totalsByMode['url'] ?></span><span class="l">Live page</span></div>
        <div class="stat"><span class="n"><?= (int) $totalsByMode['site'] ?></span><span class="l">Whole site</span></div>
        <div class="stat"><span class="n"><?= (int) $totalsByMode['code'] ?></span><span class="l">Pasted code</span></div>
        <div class="stat"><span class="n"><?= (int) $totalsByMode['git'] ?></span><span class="l">Git history</span></div>
      </div>

      <?php if (empty($topHosts)): ?>
        <p class="hint">No live-page or whole-site checks in the last 30 days yet.</p>
      <?php else: ?>
        <table class="admin-table">
          <thead><tr><th>Website</th><th class="num">Analyses</th></tr></thead>
          <tbody>
            <?php foreach ($topHosts as $row): ?>
              <tr>
                <td><?= h((string) $row['target_host']) ?></td>
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
