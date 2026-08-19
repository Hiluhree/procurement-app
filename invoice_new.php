<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_role(['procurement', 'admin']);

$pdo = db();
$grnId = (int)($_GET['grn_id'] ?? 0);

$stmt = $pdo->prepare('SELECT g.*, l.id AS lpo_id, l.lpo_no, s.id AS supplier_id, s.name AS supplier_name, s.vat_registered
    FROM grns g JOIN lpos l ON l.id = g.lpo_id JOIN suppliers s ON s.id = l.supplier_id WHERE g.id = ?');
$stmt->execute([$grnId]);
$grn = $stmt->fetch();
if (!$grn) { http_response_code(404); die('GRN not found.'); }

$already = $pdo->prepare('SELECT id FROM invoices WHERE grn_id = ?');
$already->execute([$grnId]);
if ($already->fetch()) { die('An invoice has already been recorded for this GRN.'); }

$itemTotal = $pdo->prepare('SELECT COALESCE(SUM(qty_received*unit_price),0) FROM grn_items WHERE grn_id = ?');
$itemTotal->execute([$grnId]);
$suggestedAmount = (float)$itemTotal->fetchColumn();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $supplierInvoiceNo = trim($_POST['supplier_invoice_no'] ?? '');
    $amount = (float)($_POST['amount'] ?? 0);
    $vat = isset($_POST['vat_registered']) ? 1 : 0;
    $date = $_POST['date'] ?? today();

    if ($amount <= 0) $errors[] = 'Please enter a valid invoice amount.';

    if (!$errors) {
        $wht = $vat ? round($amount * WHT_RATE, 2) : 0;
        $net = $amount - $wht;
        $me = current_user();

        $stmt = $pdo->prepare('INSERT INTO invoices (inv_no, grn_id, lpo_id, supplier_id, supplier_invoice_no, amount, vat_registered, wht_amount, net_payable, status, date, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute(['TEMP', $grnId, $grn['lpo_id'], $grn['supplier_id'], $supplierInvoiceNo, $amount, $vat, $wht, $net, 'pending_payment', $date, $me['id']]);
        $invId = (int)$pdo->lastInsertId();

        $invNo = doc_no_from_id('INV', $invId);
        $pdo->prepare('UPDATE invoices SET inv_no = ? WHERE id = ?')->execute([$invNo, $invId]);
        
        // Send notification to Finance
        notify_invoice_submitted($pdo, $invId);

        flash("Invoice $invNo recorded and sent to Finance for payment.");
        redirect('/invoice_view.php?id=' . $invId);
    }
}

$pageTitle = 'Record Invoice';
$activeNav = 'invoices';
require __DIR__ . '/includes/header.php';
?>

<a class="back-link no-print" href="<?= BASE_PATH ?>/grn_view.php?id=<?= $grnId ?>">&larr; Back to <?= e($grn['grn_no']) ?></a>

<h1 class="page-title" style="margin-top:14px;">Record Supplier Invoice</h1>
<p class="page-sub">From GRN <?= e($grn['grn_no']) ?> &middot; Supplier: <?= e($grn['supplier_name']) ?></p>

<?php foreach ($errors as $err): ?><div class="banner-error"><?= e($err) ?></div><?php endforeach; ?>

<form method="post">
  <?= csrf_field() ?>
  <div class="card">
    <div class="card-body">
      <label class="field-label">Supplier Invoice No.</label>
      <input type="text" name="supplier_invoice_no" placeholder="e.g. 73926" value="<?= e($_POST['supplier_invoice_no'] ?? '') ?>">

      <label class="field-label">Invoice Amount (KSh)</label>
      <input type="number" step="0.01" min="0" id="amount" name="amount" value="<?= e($_POST['amount'] ?? number_format($suggestedAmount, 2, '.', '')) ?>" oninput="recalc()">

      <label style="display:flex;align-items:center;gap:8px;margin-top:16px;font-size:13px;font-weight:600;color:var(--navy);">
        <input type="checkbox" id="vat" name="vat_registered" <?= $grn['vat_registered'] ? 'checked' : '' ?> onchange="recalc()">
        Supplier is VAT-registered (deduct 2% withholding tax)
      </label>

      <label class="field-label">Date</label>
      <input type="date" name="date" value="<?= today() ?>">
    </div>
  </div>

  <div class="card">
    <div class="card-body">
      <table class="doc-table">
        <tbody>
          <tr><td>Invoice Amount</td><td style="text-align:right;">KSh <span id="out-amount">0.00</span></td></tr>
          <tr><td>Less: Withholding Tax (2%)</td><td style="text-align:right;">KSh <span id="out-wht">0.00</span></td></tr>
          <tr class="total-row"><td>Net Payable to Supplier</td><td style="text-align:right;">KSh <span id="out-net">0.00</span></td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <button type="submit" class="btn gold">Save Invoice</button>
  <a href="<?= BASE_PATH ?>/grn_view.php?id=<?= $grnId ?>" class="btn secondary">Cancel</a>
</form>

<script>
function recalc(){
  const amount = parseFloat(document.getElementById('amount').value) || 0;
  const vat = document.getElementById('vat').checked;
  const wht = vat ? amount * 0.02 : 0;
  const net = amount - wht;
  document.getElementById('out-amount').textContent = amount.toLocaleString('en-KE',{minimumFractionDigits:2,maximumFractionDigits:2});
  document.getElementById('out-wht').textContent = wht.toLocaleString('en-KE',{minimumFractionDigits:2,maximumFractionDigits:2});
  document.getElementById('out-net').textContent = net.toLocaleString('en-KE',{minimumFractionDigits:2,maximumFractionDigits:2});
}
recalc();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
