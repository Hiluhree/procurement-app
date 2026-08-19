<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$pdo = db();
$errors = [];
$departments = $pdo->query('SELECT * FROM departments ORDER BY name')->fetchAll();
$catalogItems = $pdo->query('SELECT * FROM items ORDER BY name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $department   = trim($_POST['department'] ?? '');
    $dateRaised   = $_POST['date_raised'] ?? today();
    $dateRequired = $_POST['date_required'] ?? null;
    $purpose      = trim($_POST['purpose'] ?? '');
    $descs        = $_POST['desc'] ?? [];
    $units        = $_POST['unit'] ?? [];
    $qtys         = $_POST['qty'] ?? [];
    $costs        = $_POST['cost'] ?? [];

    if ($department === '') $errors[] = 'Department/section is required.';

    $specs      = $_POST['spec'] ?? [];
    $items = [];
    foreach ($descs as $i => $d) {
        $d = trim($d);
        if ($d === '') continue;
        $items[] = [
            'desc' => $d,
            'spec' => trim($specs[$i] ?? ''),
            'unit' => trim($units[$i] ?? ''),
            'qty'  => (float)($qtys[$i] ?? 0),
            'cost' => (float)($costs[$i] ?? 0),
        ];
    }
    if (!$items) $errors[] = 'Add at least one item or service.';

    if (!$errors) {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO requisitions (req_no, department, applicant_id, date_raised, date_required, purpose, status) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute(['TEMP', $department, $_SESSION['user_id'], $dateRaised, $dateRequired ?: null, $purpose, 'pending_procurement']);
        $id = (int)$pdo->lastInsertId();

        $reqNo = doc_no_from_id('REQ', $id);
        $pdo->prepare('UPDATE requisitions SET req_no = ? WHERE id = ?')->execute([$reqNo, $id]);

        $itemStmt = $pdo->prepare('INSERT INTO requisition_items (requisition_id, description, specification, unit, qty, unit_cost) VALUES (?,?,?,?,?,?)');
        foreach ($items as $it) {
            $itemStmt->execute([$id, $it['desc'], $it['spec'] ?: null, $it['unit'], $it['qty'], $it['cost']]);
        }
        $pdo->commit();

        flash("Requisition $reqNo submitted for approval.");
        redirect('/requisition_view.php?id=' . $id);
    }
}

$pageTitle = 'New Requisition';
$activeNav = 'requisitions';
require __DIR__ . '/includes/header.php';
?>

<div class="back-link-wrap"><a class="back-link" href="<?= BASE_PATH ?>/requisitions.php">&larr; Back to Requisitions</a></div>

<h1 class="page-title" style="margin-top:14px;">New Purchase / Service Request</h1>
<p class="page-sub">Fields mirror the paper Form 1A used at Sunshine Secondary School.</p>

<?php foreach ($errors as $err): ?><div class="banner-error"><?= e($err) ?></div><?php endforeach; ?>

<form method="post">
  <?= csrf_field() ?>
  <div class="card">
    <div class="card-body">
      <label class="field-label">Originating Department / Section</label>
      <select name="department" required>
        <option value="">Select department&hellip;</option>
        <?php foreach ($departments as $d): ?>
          <option value="<?= e($d['name']) ?>" <?= (isset($_POST['department']) && $_POST['department'] === $d['name']) ? 'selected' : '' ?>><?= e($d['name']) ?></option>
        <?php endforeach; ?>
      </select>

      <label class="field-label">Date Raised</label>
      <input type="date" name="date_raised" value="<?= e($_POST['date_raised'] ?? today()) ?>">

      <label class="field-label">Date Required</label>
      <input type="date" name="date_required" value="<?= e($_POST['date_required'] ?? '') ?>">

      <label class="field-label">Purpose (brief explanation)</label>
      <textarea name="purpose" rows="2"><?= e($_POST['purpose'] ?? '') ?></textarea>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><span class="htitle">Items / Services</span></div>
    <div class="card-body">
      <table class="items-edit" style="width:100%;">
        <thead><tr>
          <th style="width:22%">Item Name</th>
          <th style="width:22%">Specification</th>
          <th style="width:14%">Unit</th>
          <th style="width:12%">Qty</th>
          <th style="width:16%">Unit Cost</th>
          <th style="width:10%">Line Total</th>
          <th style="width:4%"></th>
        </tr></thead>
        <tbody id="items-body" data-row-template="row-template">
          <tr>
            <td>
              <select name="desc[]" class="item-select">
                <option value="">Select item&hellip;</option>
                <?php foreach ($catalogItems as $it): ?>
                  <option value="<?= e($it['name']) ?>" data-unit="<?= e($it['unit'] ?? '') ?>"><?= e($it['name']) ?><?= $it['unit'] ? ' — ' . e($it['unit']) : '' ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td><input type="text" name="spec[]" placeholder="e.g. 800g pack"></td>
            <td><input type="text" name="unit[]" placeholder="Pcs" readonly></td>
            <td><input type="number" step="0.01" min="0" name="qty[]" data-qty></td>
            <td><input type="number" step="0.01" min="0" name="cost[]" data-price></td>
            <td style="padding:8px 6px;font-size:13px;">KSh <span data-line-total>0.00</span></td>
            <td><button type="button" class="item-remove" data-remove-row>&times;</button></td>
          </tr>
        </tbody>
      </table>
      <template id="row-template">
        <tr>
          <td>
            <select name="desc[]" class="item-select">
              <option value="">Select item&hellip;</option>
              <?php foreach ($catalogItems as $it): ?>
                <option value="<?= e($it['name']) ?>" data-unit="<?= e($it['unit'] ?? '') ?>"><?= e($it['name']) ?><?= $it['unit'] ? ' — ' . e($it['unit']) : '' ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td><input type="text" name="spec[]" placeholder="e.g. 800g pack"></td>
          <td><input type="text" name="unit[]" placeholder="Pcs" readonly></td>
          <td><input type="number" step="0.01" min="0" name="qty[]" data-qty></td>
          <td><input type="number" step="0.01" min="0" name="cost[]" data-price></td>
          <td style="padding:8px 6px;font-size:13px;">KSh <span data-line-total>0.00</span></td>
          <td><button type="button" class="item-remove" data-remove-row>&times;</button></td>
        </tr>
      </template>
      <button type="button" class="btn secondary sm" style="margin-top:8px;" data-add-row="items-body">+ Add line item</button>
      <p style="text-align:right;font-size:13.5px;margin-top:14px;"><strong>Grand Total: KSh <span data-grand-total>0.00</span></strong></p>
    </div>
  </div>

  <button type="submit" class="btn gold">Submit Requisition</button>
  <a href="<?= BASE_PATH ?>/requisitions.php" class="btn secondary">Cancel</a>
</form>

<script src="<?= BASE_PATH ?>/assets/js/items.js"></script>
<script>
function setupItemSelectHandlers() {
    var tbody = document.getElementById('items-body');
    if (!tbody) return;
    
    var selects = tbody.querySelectorAll('.item-select');
    selects.forEach(function(sel) {
        sel.addEventListener('change', function() {
            var tr = sel.closest('tr');
            var opt = sel.options[sel.selectedIndex];
            var unit = opt.getAttribute('data-unit') || '';
            var unitInput = tr.querySelector('input[name="unit[]"]');
            if (unitInput) {
                unitInput.value = unit;
            }
        });
    });
}

// Call on page load
document.addEventListener('DOMContentLoaded', function() {
    setupItemSelectHandlers();
});

// Also call when new rows are added
var addRowBtn = document.querySelector('[data-add-row]');
if (addRowBtn) {
    addRowBtn.addEventListener('click', function() {
        setTimeout(setupItemSelectHandlers, 0);
    });
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
