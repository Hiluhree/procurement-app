<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_role(['procurement', 'admin']);

$pdo = db();
$rfq_id = (int)($_GET['rfq_id'] ?? 0);
$errors = [];
$success = false;

if (!$rfq_id) {
    flash('RFQ not found.', 'error');
    redirect('/rfqs.php');
}

$rfq_stmt = $pdo->prepare('SELECT * FROM rfqs WHERE id = ?');
$rfq_stmt->execute([$rfq_id]);
$rfq = $rfq_stmt->fetch();

if (!$rfq) {
    flash('RFQ not found.', 'error');
    redirect('/rfqs.php');
}

if ($rfq['status'] === 'awarded' || $rfq['status'] === 'cancelled') {
    flash('Cannot create quotation for a closed or cancelled RFQ.', 'error');
    redirect('/rfq_view.php?id=' . $rfq_id);
}

$items_stmt = $pdo->prepare('SELECT * FROM rfq_items WHERE rfq_id = ?');
$items_stmt->execute([$rfq_id]);
$rfq_items = $items_stmt->fetchAll();

$suppliers_stmt = $pdo->prepare('
    SELECT s.* FROM suppliers s
    JOIN rfq_suppliers rs ON s.id = rs.supplier_id
    WHERE rs.rfq_id = ?
    ORDER BY s.name
');
$suppliers_stmt->execute([$rfq_id]);
$suppliers = $suppliers_stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $supplier_id = (int)($_POST['supplier_id'] ?? 0);
    $quotation_date = trim($_POST['quotation_date'] ?? '');
    $supplier_reference = trim($_POST['supplier_reference'] ?? '');
    $delivery_days = (int)($_POST['delivery_days'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    $qtys = $_POST['qty_offered'] ?? [];
    $prices = $_POST['unit_price'] ?? [];
    $item_notes = $_POST['item_notes'] ?? [];

    if (!$supplier_id) {
        $errors[] = 'Please select a supplier.';
    }

    $valid_supplier = false;
    foreach ($suppliers as $s) {
        if ($s['id'] === $supplier_id) {
            $valid_supplier = true;
            break;
        }
    }
    if (!$valid_supplier) {
        $errors[] = 'Selected supplier is not invited to this RFQ.';
    }

    if (!$quotation_date || strtotime($quotation_date) > strtotime('today')) {
        $errors[] = 'Quotation date cannot be in the future.';
    }

    $items = [];
    foreach ($rfq_items as $i => $rfq_item) {
        $qty = (float)($qtys[$i] ?? 0);
        $price = (float)($prices[$i] ?? 0);
        if ($qty <= 0) {
            $errors[] = "Qty offered must be greater than 0 for item: " . $rfq_item['description'];
        }
        if ($price < 0) {
            $errors[] = "Unit price cannot be negative for item: " . $rfq_item['description'];
        }
        $items[] = [
            'rfq_item_id' => $rfq_item['id'],
            'description' => $rfq_item['description'],
            'unit' => $rfq_item['unit'],
            'qty_offered' => $qty,
            'unit_price' => $price,
            'notes' => trim($item_notes[$i] ?? '')
        ];
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            
            $check_stmt = $pdo->prepare('SELECT id FROM quotations WHERE rfq_id = ? AND supplier_id = ? FOR UPDATE');
            $check_stmt->execute([$rfq_id, $supplier_id]);
            if ($check_stmt->fetch()) {
                $pdo->rollBack();
                $errors[] = 'This supplier has already submitted a quotation for this RFQ.';
            } else {
                $stmt = $pdo->prepare('INSERT INTO quotations (quotation_no, rfq_id, supplier_id, quotation_date, supplier_reference, delivery_days, notes, status) VALUES (?,?,?,?,?,?,?,?)');
                $stmt->execute(['TEMP', $rfq_id, $supplier_id, $quotation_date, $supplier_reference ?: null, $delivery_days ?: null, $notes ?: null, 'submitted']);
                $quotation_id = (int)$pdo->lastInsertId();
                $quotation_no = doc_no_from_id('QT', $quotation_id);
                $pdo->prepare('UPDATE quotations SET quotation_no = ? WHERE id = ?')->execute([$quotation_no, $quotation_id]);

                $itemIns = $pdo->prepare('INSERT INTO quotation_items (quotation_id, rfq_item_id, description, unit, qty_offered, unit_price, notes) VALUES (?,?,?,?,?,?,?)');
                foreach ($items as $it) {
                    $itemIns->execute([$quotation_id, $it['rfq_item_id'], $it['description'], $it['unit'], $it['qty_offered'], $it['unit_price'], $it['notes'] ?: null]);
                }
                $pdo->commit();
                flash("Quotation $quotation_no submitted successfully.");
                redirect('/rfq_view.php?id=' . $rfq_id);
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = 'Error creating quotation: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'New Quotation';
$activeNav = 'rfqs';
require __DIR__ . '/includes/header.php';
?>

<h1 class="page-title">Submit Quotation</h1>
<p class="page-sub">RFQ <?= e(rfq_no($rfq_id)) ?> &middot; <?= e($rfq['status']) ?></p>

<?php foreach ($errors as $err): ?><div class="banner-error"><?= e($err) ?></div><?php endforeach; ?>

<div class="card">
  <div class="card-header"><span class="htitle">Quotation Details</span></div>
  <div class="card-body">
    <form method="post">
      <?= csrf_field() ?>
      <label class="field-label">Supplier <span style="color:red;">*</span></label>
      <select name="supplier_id" required>
        <option value="">Select supplier...</option>
        <?php foreach ($suppliers as $s): ?>
          <option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option>
        <?php endforeach; ?>
      </select>

      <label class="field-label">Quotation Date <span style="color:red;">*</span></label>
      <input type="date" name="quotation_date" value="<?= e(today()) ?>" required>

      <label class="field-label">Supplier Reference</label>
      <input type="text" name="supplier_reference" placeholder="Supplier's reference number">

      <label class="field-label">Delivery Days</label>
      <input type="number" name="delivery_days" min="0" placeholder="e.g. 7">

      <label class="field-label">Notes</label>
      <textarea name="notes" rows="3" placeholder="Any additional notes..."></textarea>
  </div>
</div>

<div class="card">
  <div class="card-header"><span class="htitle">Quoted Items</span></div>
  <div class="card-body" style="padding:0;">
    <table class="list">
      <thead>
        <tr>
          <th>Description</th>
          <th>Unit</th>
          <th style="text-align:right;">RFQ Qty</th>
          <th style="text-align:right;">Qty Offered</th>
          <th style="text-align:right;">Unit Price (KES)</th>
          <th style="text-align:right;">Line Total</th>
          <th>Notes</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rfq_items as $i => $item): ?>
          <tr>
            <td style="padding:10px;"><?= e($item['description']) ?></td>
            <td style="padding:10px;"><?= e($item['unit'] ?: '—') ?></td>
            <td style="padding:10px; text-align:right;"><?= number_format($item['qty'], 0) ?></td>
            <td style="padding:10px; text-align:right;">
              <input type="number" step="0.01" min="0.01" name="qty_offered[]" value="<?= e($item['qty']) ?>" required style="width:90px; text-align:right;">
            </td>
            <td style="padding:10px; text-align:right;">
              <input type="number" step="0.01" min="0" name="unit_price[]" value="0.00" required style="width:110px; text-align:right;">
            </td>
            <td style="padding:10px; text-align:right;" class="price-highlight">KSh <span data-line-total>0.00</span></td>
            <td style="padding:10px;"><input type="text" name="item_notes[]" placeholder="Optional"></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr style="font-weight:bold; background:#f0f0f0; border-top:2px solid #ddd;">
          <td colspan="5" style="padding:10px; text-align:right;">Grand Total:</td>
          <td style="padding:10px; text-align:right; color:#C9AA35;">KSh <span data-grand-total>0.00</span></td>
          <td></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<button type="submit" class="btn gold">Submit Quotation</button>
<a href="<?= BASE_PATH ?>/rfq_view.php?id=<?= $rfq_id ?>" class="btn secondary" style="margin-left:8px;">Cancel</a>
</form>

<script>
(function() {
    const priceInputs = document.querySelectorAll('input[name="unit_price[]"]');
    const qtyInputs = document.querySelectorAll('input[name="qty_offered[]"]');
    const lineTotals = document.querySelectorAll('[data-line-total]');
    const grandTotalEl = document.querySelector('[data-grand-total]');

    function recalc() {
        let total = 0;
        for (let i = 0; i < priceInputs.length; i++) {
            const qty = parseFloat(qtyInputs[i].value) || 0;
            const price = parseFloat(priceInputs[i].value) || 0;
            const line = qty * price;
            lineTotals[i].textContent = line.toLocaleString('en-KE', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            total += line;
        }
        grandTotalEl.textContent = total.toLocaleString('en-KE', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    priceInputs.forEach(el => el.addEventListener('input', recalc));
    qtyInputs.forEach(el => el.addEventListener('input', recalc));
    recalc();
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
