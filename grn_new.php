<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_role(['procurement', 'admin']);

$pdo = db();
$lpoId = (int)($_GET['lpo_id'] ?? 0);

$stmt = $pdo->prepare('SELECT l.*, s.name AS supplier_name FROM lpos l JOIN suppliers s ON s.id = l.supplier_id WHERE l.id = ?');
$stmt->execute([$lpoId]);
$lpo = $stmt->fetch();
if (!$lpo) { http_response_code(404); die('LPO not found.'); }
if (!$lpo['sent_to_supplier']) { die('This LPO has not been sent to the supplier yet.'); }

$already = $pdo->prepare('SELECT id FROM grns WHERE lpo_id = ?');
$already->execute([$lpoId]);
if ($already->fetch()) { die('A Goods Received Note already exists for this LPO.'); }

$itemStmt = $pdo->prepare('SELECT * FROM lpo_items WHERE lpo_id = ?');
$itemStmt->execute([$lpoId]);
$lpoItems = $itemStmt->fetchAll();

$storeOptions = [
    'Main Store',
    'General Store',
    'Science Laboratory Store',
    'Library Store',
    'Administration Store',
    'Hostel Store',
    'Workshop Store',
    'Staff Store',
    'Consumables Store',
];

try {
    $storeRows = $pdo->query('SELECT name FROM stores ORDER BY name')->fetchAll();
    if ($storeRows) {
        $storeOptions = array_values(array_unique(array_merge($storeOptions, array_map(static fn($row) => $row['name'], $storeRows))));
    }
} catch (PDOException $e) {
    // Gracefully fall back for older databases without the stores table.
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $section = trim($_POST['section'] ?? '');
    $receivedBy = trim($_POST['received_by'] ?? '');
    $date = $_POST['date'] ?? today();
    $descs = $_POST['desc'] ?? [];
    $units = $_POST['unit'] ?? [];
    $qtyOrdered = $_POST['qty_ordered'] ?? [];
    $qtyReceived = $_POST['qty_received'] ?? [];
    $prices = $_POST['price'] ?? [];
    $destinations = $_POST['destination'] ?? [];

    if ($receivedBy === '') $errors[] = 'Please enter the name of the receiving officer.';

    if (!$errors) {
        $me = current_user();
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO grns (grn_no, lpo_id, section, received_by, date, created_by) VALUES (?,?,?,?,?,?)');
        $stmt->execute(['TEMP', $lpoId, $section, $receivedBy, $date, $me['id']]);
        $grnId = (int)$pdo->lastInsertId();

        $grnNo = doc_no_from_id('GRN', $grnId);
        $pdo->prepare('UPDATE grns SET grn_no = ? WHERE id = ?')->execute([$grnNo, $grnId]);

        $itemIns = $pdo->prepare('INSERT INTO grn_items (grn_id, description, unit, qty_ordered, qty_received, unit_price, destination) VALUES (?,?,?,?,?,?,?)');
        foreach ($descs as $i => $d) {
            if (trim($d) === '') continue;
            $itemIns->execute([
                $grnId, $d, $units[$i] ?? '', (float)($qtyOrdered[$i] ?? 0),
                (float)($qtyReceived[$i] ?? 0), (float)($prices[$i] ?? 0), trim($destinations[$i] ?? ''),
            ]);
        }
        $pdo->commit();
        flash("Goods Received Note $grnNo recorded.");
        redirect('/grn_view.php?id=' . $grnId);
    }
}

$pageTitle = 'Record Goods Receipt';
$activeNav = 'grns';
require __DIR__ . '/includes/header.php';
?>

<a class="back-link no-print" href="<?= BASE_PATH ?>/lpo_view.php?id=<?= $lpoId ?>">&larr; Back to <?= e($lpo['lpo_no']) ?></a>

<h1 class="page-title" style="margin-top:14px;">Record Goods Receipt</h1>
<p class="page-sub">From LPO <?= e($lpo['lpo_no']) ?> &middot; Supplier: <?= e($lpo['supplier_name']) ?></p>

<?php foreach ($errors as $err): ?><div class="banner-error"><?= e($err) ?></div><?php endforeach; ?>

<form method="post">
  <?= csrf_field() ?>
  <div class="card">
    <div class="card-body">
      <label class="field-label">Store / Section</label>
      <select name="section" required>
        <option value="">Select store or section</option>
        <?php foreach ($storeOptions as $store): ?>
          <option value="<?= e($store) ?>" <?= (($_POST['section'] ?? '') === $store) ? 'selected' : '' ?>><?= e($store) ?></option>
        <?php endforeach; ?>
      </select>
      <label class="field-label">Received By</label>
      <input type="text" name="received_by" placeholder="Name of staff receiving" value="<?= e($_POST['received_by'] ?? '') ?>" required>
      <label class="field-label">Date</label>
      <input type="date" name="date" value="<?= today() ?>">
    </div>
  </div>

  <div class="card">
    <div class="card-header"><span class="htitle">Items</span></div>
    <div class="card-body">
      <table class="items-edit" style="width:100%;">
        <thead><tr>
          <th style="width:28%">Description</th><th style="width:9%">Unit</th>
          <th style="width:11%">Qty Ordered</th><th style="width:11%">Qty Received</th>
          <th style="width:15%">Store / Dispensed To</th><th style="width:0">Unit Price</th>
        </tr></thead>
        <tbody>
          <?php foreach ($lpoItems as $it): ?>
          <tr>
            <td>
              <input type="hidden" name="desc[]" value="<?= e($it['description']) ?>"><?= e($it['description']) ?>
            </td>
            <td><input type="hidden" name="unit[]" value="<?= e($it['unit']) ?>"><?= e($it['unit']) ?></td>
            <td><input type="hidden" name="qty_ordered[]" value="<?= e($it['qty']) ?>"><?= e($it['qty']) ?></td>
            <td><input type="number" step="0.01" min="0" name="qty_received[]" value="<?= e($it['qty']) ?>"></td>
            <td>
              <select name="destination[]">
                <option value="">Select store</option>
                <?php foreach ($storeOptions as $store): ?>
                  <option value="<?= e($store) ?>" <?= (($it['destination'] ?? '') === $store) ? 'selected' : '' ?>><?= e($store) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td><input type="hidden" name="price[]" value="<?= e($it['unit_price']) ?>"></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <button type="submit" class="btn gold">Save Goods Received Note</button>
  <a href="<?= BASE_PATH ?>/lpo_view.php?id=<?= $lpoId ?>" class="btn secondary">Cancel</a>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
