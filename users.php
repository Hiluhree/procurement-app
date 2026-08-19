<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_role(['admin']);

$pdo = db();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? '';
    $department = trim($_POST['department'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $phone_number = trim($_POST['phone_number'] ?? '');

    $validRoles = ['requester', 'procurement', 'finance', 'principal', 'admin'];
    if ($name === '') $errors[] = 'Name is required.';
    if ($username === '') $errors[] = 'Username is required.';
    if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
    if (!in_array($role, $validRoles, true)) $errors[] = 'Please select a valid role.';
    if ($phone_number && !preg_match('/^[\d\s\-\+\(\)]+$/', $phone_number)) $errors[] = 'Phone number format is invalid.';

    if (!$errors) {
        $exists = $pdo->prepare('SELECT id FROM users WHERE username = ?');
        $exists->execute([$username]);
        if ($exists->fetch()) {
            $errors[] = 'That username is already taken.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO users (name, username, password_hash, role, department, title, phone_number) VALUES (?,?,?,?,?,?,?)');
            $stmt->execute([$name, $username, password_hash($password, PASSWORD_BCRYPT), $role, $department, $title, $phone_number ?: null]);
            flash("User \"$name\" created.");
            redirect('/users.php');
        }
    }
}

$users = $pdo->query('SELECT * FROM users ORDER BY role, name')->fetchAll();

$pageTitle = 'Users';
$activeNav = 'users';
require __DIR__ . '/includes/header.php';
?>

<h1 class="page-title">Users</h1>
<p class="page-sub">Manage staff accounts and their procurement roles</p>

<?php foreach ($errors as $err): ?><div class="banner-error"><?= e($err) ?></div><?php endforeach; ?>

<div class="card">
  <div class="card-header"><span class="htitle">Add user</span></div>
  <div class="card-body">
    <form method="post">
      <?= csrf_field() ?>
      <label class="field-label">Full Name</label>
      <input type="text" name="name" required>
      <label class="field-label">Username</label>
      <input type="text" name="username" required>
      <label class="field-label">Password</label>
      <input type="password" name="password" minlength="8" required>
      <label class="field-label">Role</label>
      <select name="role" required>
        <option value="">Select role&hellip;</option>
        <option value="requester">Requester (raises requisitions)</option>
        <option value="procurement">Procurement</option>
        <option value="finance">Finance</option>
        <option value="principal">Principal</option>
        <option value="admin">Administrator</option>
      </select>
      <label class="field-label">Department</label>
      <input type="text" name="department">
      <label class="field-label">Title (printed on approvals)</label>
      <input type="text" name="title" placeholder="e.g. Procurement Officer">
      <label class="field-label">Phone Number (required for SMS approvals)</label>
      <input type="tel" name="phone_number" placeholder="e.g. +254 712 345 678">
      <button type="submit" class="btn gold" style="margin-top:14px;">Create User</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-body" style="padding:0;">
    <table class="list">
      <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Phone</th><th>Signature</th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td style="padding:10px;"><?= e($u['name']) ?></td>
          <td style="padding:10px;"><?= e($u['username']) ?></td>
          <td style="padding:10px;"><?= e(role_label($u['role'])) ?></td>
          <td style="padding:10px;"><?= e($u['phone_number'] ?: '—') ?></td>
          <td style="padding:10px;"><?= $u['signature_path'] ? '<span class="badge green">Uploaded</span>' : '<span class="badge gray">None</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
