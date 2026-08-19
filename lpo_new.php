<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_role(['procurement', 'admin']);

$pdo = db();
$reqId = (int)($_GET['requisition_id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM requisitions WHERE id = ?');
$stmt->execute([$reqId]);
$req = $stmt->fetch();
if (!$req) { http_response_code(404); die('Requisition not found.'); }
if ($req['status'] !== 'approved') { die('This requisition has not yet been fully approved.'); }

$already = $pdo->prepare('SELECT id FROM lpos WHERE requisition_id = ?');
$already->execute([$reqId]);
if ($already->fetch()) { 
    die('An LPO already exists for this requisition.'); 
}

$itemStmt = $pdo->prepare('SELECT * FROM requisition_items WHERE requisition_id = ?');
$itemStmt->execute([$reqId]);
$reqItems = $itemStmt->fetchAll();

$suppliers = $pdo->query('SELECT * FROM suppliers ORDER BY name')->fetchAll();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $supplierId = (int)($_POST['supplier_id'] ?? 0);
    $date = $_POST['date'] ?? today();
    $descs = $_POST['desc'] ?? [];
    $units = $_POST['unit'] ?? [];
    $qtys  = $_POST['qty'] ?? [];
    $prices= $_POST['price'] ?? [];

    if (!$supplierId) $errors[] = 'Please select a supplier.';

    $items = [];
    foreach ($descs as $i => $d) {
        $d = trim($d);
        if ($d === '') continue;
        $items[] = ['desc'=>$d, 'unit'=>trim($units[$i]??''), 'qty'=>(float)($qtys[$i]??0), 'price'=>(float)($prices[$i]??0)];
    }
    if (!$items) $errors[] = 'Add at least one item.';

    if (!$errors) {
        $me = current_user();
        $pdo->beginTransaction();
        
        $check_lpo = $pdo->prepare('SELECT id FROM lpos WHERE requisition_id = ?');
        $check_lpo->execute([$reqId]);
        if ($check_lpo->fetch()) {
            $pdo->rollBack();
            die('An LPO already exists for this requisition.');
        }
        
        $stmt = $pdo->prepare('INSERT INTO lpos (lpo_no, requisition_id, supplier_id, date, status, prepared_by) VALUES (?,?,?,?,?,?)');
        $stmt->execute(['TEMP', $reqId, $supplierId, $date, 'pending_finance', $me['id']]);
        $lpoId = (int)$pdo->lastInsertId();

        $lpoNo = doc_no_from_id('LPO', $lpoId);
        $pdo->prepare('UPDATE lpos SET lpo_no = ? WHERE id = ?')->execute([$lpoNo, $lpoId]);

        $itemIns = $pdo->prepare('INSERT INTO lpo_items (lpo_id, description, unit, qty, unit_price) VALUES (?,?,?,?,?)');
        foreach ($items as $it) {
            $itemIns->execute([$lpoId, $it['desc'], $it['unit'], $it['qty'], $it['price']]);
        }

        // Procurement stage is auto-approved at LPO creation (the preparer's own signature)
        $sig = $me['signature_path'];
        $approvalIns = $pdo->prepare('INSERT INTO approvals (document_type, document_id, role, status, acted_by, acted_by_name, signature_path) VALUES (?,?,?,?,?,?,?)');
        $approvalIns->execute(['lpo', $lpoId, 'procurement', 'approved', $me['id'], $me['name'], $sig]);

        $pdo->commit();
        flash("LPO $lpoNo created and sent for Finance authorisation.");
        redirect('/lpo_view.php?id=' . $lpoId);
    }
}

$pageTitle = 'New Purchase Order';
$activeNav = 'lpos';
require __DIR__ . '/includes/header.php';
?>

<a class="back-link no-print" href="<?= BASE_PATH ?>/requisition_view.php?id=<?= $reqId ?>">&larr; Back to <?= e($req['req_no']) ?></a>

<h1 class="page-title" style="margin-top:14px;">New Local Purchase Order</h1>
<p class="page-sub">From requisition <?= e($req['req_no']) ?> (<?= e($req['department']) ?>)</p>

<?php foreach ($errors as $err): ?><div class="banner-error"><?= e($err) ?></div><?php endforeach; ?>

<?php if (empty(current_user()['signature_path'])): ?>
  <div class="banner-note">You haven't uploaded a signature/stamp yet — <a href="<?= BASE_PATH ?>/signatories.php">upload one</a> so it can be applied when this LPO is prepared.</div>
<?php endif; ?>

<form method="post">
  <?= csrf_field() ?>
  <div class="card">
    <div class="card-body">
      <label class="field-label">Supplier</label>
      <select name="supplier_id" required>
        <option value="">Select supplier&hellip;</option>
        <?php foreach ($suppliers as $s): ?>
          <option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?><?= $s['vat_registered'] ? ' (VAT registered)' : '' ?></option>
        <?php endforeach; ?>
      </select>
      <p style="font-size:12px;margin-top:6px;"><a href="<?= BASE_PATH ?>/suppliers.php" class="back-link">+ Add a new supplier</a></p>

      <label class="field-label">Date</label>
      <input type="date" name="date" value="<?= today() ?>">
    </div>
  </div>

  <div class="card">
    <div class="card-header"><span class="htitle">Items (from requisition — set item pricing from RFQ)</span></div>
    <div class="card-body">
      <table class="items-edit" style="width:100%;">
        <thead><tr>
          <th style="width:40%">Description</th><th style="width:14%">Unit</th><th style="width:12%">Qty</th>
          <th style="width:16%">Unit Price</th><th style="width:14%">Line Total</th><th style="width:4%"></th>
        </tr></thead>
        <tbody id="items-body" data-row-template="row-template">
          <?php foreach ($reqItems as $it): ?>
          <tr>
            <td><input type="text" name="desc[]" value="<?= e($it['description']) ?>"></td>
            <td><input type="text" name="unit[]" value="<?= e($it['unit']) ?>"></td>
            <td><input type="number" step="0.01" min="0" name="qty[]" value="<?= e($it['qty']) ?>" data-qty></td>
            <td><input type="number" step="0.01" min="0" name="price[]" value="<?= e($it['unit_cost'] ?? 0) ?>" placeholder="0.00" data-price></td>
            <td style="padding:8px 6px;font-size:13px;">KSh <span data-line-total><?= e(($it['qty'] ?? 0) * ($it['unit_cost'] ?? 0)) ?></span></td>
            <td><button type="button" class="item-remove" data-remove-row>&times;</button></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <template id="row-template">
        <tr>
          <td><input type="text" name="desc[]"></td>
          <td><input type="text" name="unit[]"></td>
          <td><input type="number" step="0.01" min="0" name="qty[]" data-qty></td>
          <td><input type="number" step="0.01" min="0" name="price[]" data-price></td>
          <td style="padding:8px 6px;font-size:13px;">KSh <span data-line-total>0.00</span></td>
          <td><button type="button" class="item-remove" data-remove-row>&times;</button></td>
        </tr>
      </template>
      <button type="button" class="btn secondary sm" style="margin-top:8px;" data-add-row="items-body">+ Add line item</button>
      <p style="text-align:right;font-size:13.5px;margin-top:14px;"><strong>Grand Total: KSh <span data-grand-total>0.00</span></strong></p>
    </div>
  </div>

  <button type="submit" class="btn gold">Create LPO</button>
  <a href="<?= BASE_PATH ?>/requisition_view.php?id=<?= $reqId ?>" class="btn secondary">Cancel</a>
</form>

<script src="<?= BASE_PATH ?>/assets/js/items.js"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
