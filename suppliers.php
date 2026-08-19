<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_role(['procurement', 'admin']);

$pdo = db();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = trim($_POST['name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $vat = isset($_POST['vat_registered']) ? 1 : 0;

    if ($name === '') $errors[] = 'Supplier name is required.';

    if (!$errors) {
        $stmt = $pdo->prepare('INSERT INTO suppliers (name, address, phone, email, vat_registered) VALUES (?,?,?,?,?)');
        $stmt->execute([$name, $address, $phone, $email, $vat]);
        flash("Supplier \"$name\" added.");
        redirect('/suppliers.php');
    }
}

$suppliers = $pdo->query('SELECT * FROM suppliers ORDER BY name')->fetchAll();

$pageTitle = 'Suppliers';
$activeNav = 'suppliers';
require __DIR__ . '/includes/header.php';
?>

<h1 class="page-title">Suppliers</h1>
<p class="page-sub">Maintained supplier list used when preparing Purchase Orders</p>

<?php foreach ($errors as $err): ?><div class="banner-error"><?= e($err) ?></div><?php endforeach; ?>

<div class="card">
  <div class="card-header"><span class="htitle">Add supplier</span></div>
  <div class="card-body">
    <form method="post">
      <?= csrf_field() ?>
      <label class="field-label">Supplier Name</label>
      <input type="text" name="name" required>
      <label class="field-label">Address</label>
      <input type="text" name="address">
      <label class="field-label">Phone</label>
      <input type="text" name="phone">
      <label class="field-label">Email</label>
      <input type="text" name="email">
      <label style="display:flex;align-items:center;gap:8px;margin-top:14px;font-size:13px;font-weight:600;color:var(--navy);">
        <input type="checkbox" name="vat_registered"> VAT registered
      </label>
      <button type="submit" class="btn gold" style="margin-top:14px;">Add Supplier</button>
    </form>
  </div>
</div>

<div class="card">
  <?php if (!$suppliers): ?>
    <div class="empty"><div class="big">&#127970;</div>No suppliers added yet.</div>
  <?php else: ?>
    <div class="card-body" style="padding:0;">
    <table class="list">
      <thead><tr><th>Name</th><th>Phone</th><th>Email</th><th>VAT Registered</th></tr></thead>
      <tbody>
      <?php foreach ($suppliers as $s): ?>
        <tr>
          <td style="padding:10px;"><?= e($s['name']) ?></td>
          <td style="padding:10px;"><?= e($s['phone'] ?: '—') ?></td>
          <td style="padding:10px;"><?= e($s['email'] ?: '—') ?></td>
          <td style="padding:10px;"><?= $s['vat_registered'] ? '<span class="badge green">Yes</span>' : '<span class="badge gray">No</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
