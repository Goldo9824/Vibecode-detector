<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/AdminAuth.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');

if (AdminAuth::isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!vcd_rate_limit('admin-login', 10, 900)) {
        $error = 'Too many attempts. Wait a while before trying again.';
    } else {
        $password = isset($_POST['password']) ? (string) $_POST['password'] : '';
        if (AdminAuth::attempt($password)) {
            header('Location: index.php');
            exit;
        }
        $error = 'Wrong password.';
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
<main class="shell admin-shell narrow">
  <h1>Admin</h1>
  <?php if ($error !== ''): ?>
    <p class="error-box"><?= h($error) ?></p>
  <?php endif; ?>
  <form method="post" novalidate>
    <label class="field" for="password">Password</label>
    <input type="password" id="password" name="password" autocomplete="current-password" autofocus required>
    <div class="actions">
      <button class="btn" type="submit">Log in</button>
    </div>
  </form>
</main>
</body>
</html>
