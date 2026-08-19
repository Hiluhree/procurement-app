<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_role(['admin', 'procurement']);

$pdo = db();
$errors = [];
$success = '';

$tab = $_GET['tab'] ?? 'items';
if (!in_array($tab, ['items', 'departments', 'stores'], true)) {
    $tab = 'items';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if ($tab === 'items') {
        $name = trim($_POST['item_name'] ?? '');
        $unit = trim($_POST['item_unit'] ?? '');
        if ($name === '') {
            $errors[] = 'Item name is required.';
        } else {
            $pdo->prepare('INSERT INTO items (name, unit) VALUES (?,?)')->execute([$name, $unit ?: null]);
            flash('Item added.');
            redirect('/admin_manage.php?tab=items');
        }
    } elseif ($tab === 'departments') {
        $name = trim($_POST['dept_name'] ?? '');
        if ($name === '') {
            $errors[] = 'Department name is required.';
        } else {
            try {
                $pdo->prepare('INSERT INTO departments (name) VALUES (?)')->execute([$name]);
                flash('Department added.');
                redirect('/admin_manage.php?tab=departments');
            } catch (PDOException $e) {
                $errors[] = 'That department already exists.';
            }
        }
    } else {
        $name = trim($_POST['store_name'] ?? '');
        if ($name === '') {
            $errors[] = 'Store name is required.';
        } else {
            try {
                $pdo->prepare('INSERT INTO stores (name) VALUES (?)')->execute([$name]);
                flash('Store added.');
                redirect('/admin_manage.php?tab=stores');
            } catch (PDOException $e) {
                $errors[] = 'That store already exists.';
            }
        }
    }
}

if (isset($_GET['delete_item'])) {
    $id = (int)($_GET['delete_item'] ?? 0);
    if ($id > 0) {
        $pdo->prepare('DELETE FROM items WHERE id = ?')->execute([$id]);
        flash('Item deleted.');
        redirect('/admin_manage.php?tab=items');
    }
}

if (isset($_GET['delete_department'])) {
    $id = (int)($_GET['delete_department'] ?? 0);
    if ($id > 0) {
        $pdo->prepare('DELETE FROM departments WHERE id = ?')->execute([$id]);
        flash('Department deleted.');
        redirect('/admin_manage.php?tab=departments');
    }
}

if (isset($_GET['delete_store'])) {
    $id = (int)($_GET['delete_store'] ?? 0);
    if ($id > 0) {
        $pdo->prepare('DELETE FROM stores WHERE id = ?')->execute([$id]);
        flash('Store deleted.');
        redirect('/admin_manage.php?tab=stores');
    }
}

$items = $pdo->query('SELECT * FROM items ORDER BY name')->fetchAll();
$departments = $pdo->query('SELECT * FROM departments ORDER BY name')->fetchAll();
$stores = $pdo->query('SELECT * FROM stores ORDER BY name')->fetchAll();

$pageTitle = 'Manage Lists';
$activeNav = 'admin_manage';
require __DIR__ . '/includes/header.php';
?>

<h1 class="page-title">Manage Lists</h1>
<p class="page-sub">Items, departments, and stores available for selection throughout the procurement workflow.</p>

<?php foreach ($errors as $err): ?><div class="banner-error"><?= e($err) ?></div><?php endforeach; ?>

<div class="tab-bar">
  <a href="<?= BASE_PATH ?>/admin_manage.php?tab=items" class="tab <?= $tab === 'items' ? 'active' : '' ?>">Items</a>
  <a href="<?= BASE_PATH ?>/admin_manage.php?tab=departments" class="tab <?= $tab === 'departments' ? 'active' : '' ?>">Departments</a>
  <a href="<?= BASE_PATH ?>/admin_manage.php?tab=stores" class="tab <?= $tab === 'stores' ? 'active' : '' ?>">Stores</a>
</div>

<?php if ($tab === 'items'): ?>
  <div class="card">
    <div class="card-header"><span class="htitle">Add item</span></div>
    <div class="card-body">
      <form method="post">
        <?= csrf_field() ?>
        <label class="field-label">Item name</label>
        <input type="text" name="item_name" placeholder="e.g. Bar soap" required>
        <label class="field-label">Unit</label>
        <input type="text" name="item_unit" placeholder="e.g. Pcs, Litres, Box">
        <button type="submit" class="btn gold" style="margin-top:14px;">Add Item</button>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-body" style="padding:0;">
      <table class="list">
        <thead><tr><th>Name</th><th>Unit</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($items as $it): ?>
            <tr>
              <td style="padding:10px;"><?= e($it['name']) ?></td>
              <td style="padding:10px;"><?= e($it['unit'] ?: '—') ?></td>
              <td style="padding:10px;text-align:right;">
                <a class="btn secondary sm" href="<?= BASE_PATH ?>/admin_manage.php?tab=items&delete_item=<?= (int)$it['id'] ?>" onclick="return confirm('Delete this item?')">Delete</a>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$items): ?>
            <tr><td colspan="3" style="padding:18px;text-align:center;color:var(--muted);">No items yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php elseif ($tab === 'departments'): ?>
  <div class="card">
    <div class="card-header"><span class="htitle">Add department</span></div>
    <div class="card-body">
      <form method="post">
        <?= csrf_field() ?>
        <label class="field-label">Department / Section name</label>
        <input type="text" name="dept_name" placeholder="e.g. House Keeping" required>
        <button type="submit" class="btn gold" style="margin-top:14px;">Add Department</button>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-body" style="padding:0;">
      <table class="list">
        <thead><tr><th>Name</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($departments as $d): ?>
            <tr>
              <td style="padding:10px;"><?= e($d['name']) ?></td>
              <td style="padding:10px;text-align:right;">
                <a class="btn secondary sm" href="<?= BASE_PATH ?>/admin_manage.php?tab=departments&delete_department=<?= (int)$d['id'] ?>" onclick="return confirm('Delete this department?')">Delete</a>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$departments): ?>
            <tr><td colspan="2" style="padding:18px;text-align:center;color:var(--muted);">No departments yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php else: ?>
  <div class="card">
    <div class="card-header"><span class="htitle">Add store</span></div>
    <div class="card-body">
      <form method="post">
        <?= csrf_field() ?>
        <label class="field-label">Store name</label>
        <input type="text" name="store_name" placeholder="e.g. Main Store" required>
        <button type="submit" class="btn gold" style="margin-top:14px;">Add Store</button>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-body" style="padding:0;">
      <table class="list">
        <thead><tr><th>Name</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($stores as $s): ?>
            <tr>
              <td style="padding:10px;"><?= e($s['name']) ?></td>
              <td style="padding:10px;text-align:right;">
                <a class="btn secondary sm" href="<?= BASE_PATH ?>/admin_manage.php?tab=stores&delete_store=<?= (int)$s['id'] ?>" onclick="return confirm('Delete this store?')">Delete</a>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$stores): ?>
            <tr><td colspan="2" style="padding:18px;text-align:center;color:var(--muted);">No stores yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
