<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if (isset($_SESSION['user_id'])) {
    redirect('/index.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } elseif (attempt_login($username, $password)) {
        redirect('/index.php');
    } else {
        $error = 'Invalid username or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in — <?= e(SCHOOL_NAME) ?></title>
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/style.css">
</head>
<body>
<div class="login-wrap">
  <div style="text-align:center;margin-bottom:22px;">
    <div class="crest" style="margin:0 auto 10px auto;">SSS</div>
    <div style="font-family:Georgia,serif;font-weight:700;font-size:18px;color:var(--navy);"><?= e(SCHOOL_NAME) ?></div>
    <div style="font-size:11px;letter-spacing:1.5px;color:var(--gold);text-transform:uppercase;margin-top:2px;">Procurement System</div>
  </div>
  <div class="login-card">
    <?php if ($error): ?>
      <div class="banner-error"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="post" novalidate>
      <label class="field-label">Username</label>
      <input type="text" name="username" value="<?= e($_POST['username'] ?? '') ?>" autofocus required>
      <label class="field-label">Password</label>
      <input type="password" name="password" required>
      <button type="submit" class="btn gold" style="width:100%;margin-top:20px;">Sign In</button>
    </form>
  </div>
  <p style="text-align:center;font-size:11.5px;color:var(--muted);margin-top:16px;">
    Demo accounts (password: <code>password123</code>): admin, procurement, finance, principal, ekimutai
  </p>
</div>
</body>
</html>
