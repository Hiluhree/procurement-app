<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_role(['procurement', 'admin']);

$pdo = db();
$quotation_id = (int)($_GET['id'] ?? 0);

if (!$quotation_id) {
    flash('Quotation not found.', 'error');
    redirect('/rfqs.php');
}

// Get quotation
$quote_stmt = $pdo->prepare('
    SELECT q.*, s.name as supplier_name, r.id as rfq_id
    FROM quotations q
    JOIN suppliers s ON q.supplier_id = s.id
    JOIN rfqs r ON q.rfq_id = r.id
    WHERE q.id = ?
');
$quote_stmt->execute([$quotation_id]);
$quotation = $quote_stmt->fetch();

if (!$quotation) {
    flash('Quotation not found.', 'error');
    redirect('/rfqs.php');
}

// Get awarded items for this supplier/quotation
$awarded_items_stmt = $pdo->prepare('
    SELECT ra.*, ri.description as rfq_description, ri.qty as rfq_qty, ri.unit as rfq_unit
    FROM rfq_awards ra
    JOIN rfq_items ri ON ri.id = ra.rfq_item_id
    WHERE ra.quotation_id = ?
    ORDER BY ra.rfq_item_id ASC
');
$awarded_items_stmt->execute([$quotation_id]);
$awarded_items = $awarded_items_stmt->fetchAll();

// Handle award/reject actions
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    
    if ($action === 'reject') {
        try {
            $update_stmt = $pdo->prepare('UPDATE quotations SET status = ? WHERE id = ?');
            $update_stmt->execute(['rejected', $quotation_id]);
            
            flash('Quotation rejected.', 'success');
            redirect('/rfq_view.php?id=' . $quotation['rfq_id']);
        } catch (PDOException $e) {
            $errors[] = 'Error rejecting quotation: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Quotation Details';
$activeNav = 'rfqs';
require __DIR__ . '/includes/header.php';
?>

<style>
  .comparison-table { width:100%; border-collapse:collapse; font-size:13px; }
  .comparison-table th { background:#f0f0f0; padding:8px; text-align:left; border-bottom:1px solid #ddd; font-weight:bold; }
  .comparison-table td { padding:10px; border-bottom:1px solid #eee; }
  .comparison-table tr:nth-child(even) { background:#fafafa; }
  .price-highlight { background:#fff9e6; font-weight:bold; }
</style>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
  <div>
    <h1 class="page-title">Quotation <?= quotation_no($quotation_id) ?></h1>
    <p class="page-sub">From <?= e($quotation['supplier_name']) ?> — <?= rfq_status_badge($quotation['status']) ?></p>
  </div>
  <div>
    <?php if ($quotation['status'] === 'submitted'): ?>
      <form method="post" style="display:inline;" onsubmit="return confirm('Reject this quotation?');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reject">
        <button type="submit" class="btn">Reject</button>
      </form>
    <?php endif; ?>
    <a href="<?= BASE_PATH ?>/rfq_view.php?id=<?= $quotation['rfq_id'] ?>" class="btn">Back</a>
  </div>
</div>

<?php foreach ($errors as $err): ?>
  <div class="banner-error"><?= e($err) ?></div>
<?php endforeach; ?>

<div class="card">
  <div class="card-header"><span class="htitle">Quotation Information</span></div>
  <div class="card-body">
    <table style="width:100%; font-size:13px;">
      <tr>
        <td style="width:25%; color:#666;">Supplier</td>
        <td style="font-weight:bold;"><?= e($quotation['supplier_name']) ?></td>
      </tr>
      <tr style="background:#f9f9f9;">
        <td style="color:#666;">Quotation Date</td>
        <td><?= format_date($quotation['quotation_date']) ?></td>
      </tr>
      <tr>
        <td style="color:#666;">Supplier Reference</td>
        <td><?= e($quotation['supplier_reference'] ?: '—') ?></td>
      </tr>
      <tr style="background:#f9f9f9;">
        <td style="color:#666;">Delivery (days)</td>
        <td><?= $quotation['delivery_days'] ?? '—' ?></td>
      </tr>
      <?php if ($quotation['notes']): ?>
      <tr>
        <td style="color:#666;">Notes</td>
        <td><?= e($quotation['notes']) ?></td>
      </tr>
      <?php endif; ?>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-header"><span class="htitle">Awarded Items</span></div>
  <div class="card-body" style="padding:0; overflow-x:auto;">
    <?php if (empty($awarded_items)): ?>
      <div class="card-body" style="color:#999; text-align:center; padding:40px;">
        No items have been awarded from this quotation yet.
      </div>
    <?php else: ?>
      <table class="comparison-table">
        <thead>
          <tr>
            <th>Item</th>
            <th>RFQ Qty</th>
            <th style="text-align:right;">Awarded Qty</th>
            <th style="text-align:right;">Unit Price</th>
            <th style="text-align:right;">Line Total</th>
          </tr>
        </thead>
        <tbody>
        <?php 
          $total = 0;
          foreach ($awarded_items as $item):
            $item_total = $item['qty_awarded'] * $item['unit_price'];
            $total += $item_total;
        ?>
          <tr>
            <td>
              <strong><?= e($item['rfq_description']) ?></strong><br>
              <span style="color:#999; font-size:11px;"><?= e($item['rfq_unit']) ?></span>
            </td>
            <td style="text-align:center;">
              <?= number_format($item['rfq_qty'], 0) ?>
            </td>
            <td style="text-align:center;">
              <?= number_format($item['qty_awarded'], 0) ?>
            </td>
            <td style="text-align:right;" class="price-highlight">
              <?= money($item['unit_price']) ?>
            </td>
            <td style="text-align:right;" class="price-highlight">
              <?= money($item_total) ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr style="font-weight:bold; background:#f0f0f0; border-top:2px solid #ddd;">
            <td colspan="4" style="padding:10px; text-align:right;">Total Awarded Amount:</td>
            <td style="padding:10px; text-align:right; color:#C9AA35;">
              <?= money($total) ?>
            </td>
          </tr>
        </tfoot>
      </table>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
