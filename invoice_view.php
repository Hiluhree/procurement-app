<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_role(['procurement', 'finance', 'admin']);

$pdo = db();
$id = (int)($_GET['id'] ?? 0);

$pdo->exec('CREATE TABLE IF NOT EXISTS invoice_payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT UNSIGNED NOT NULL,
    amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    payment_method VARCHAR(50) DEFAULT NULL,
    payment_reference VARCHAR(120) DEFAULT NULL,
    transaction_code VARCHAR(120) DEFAULT NULL,
    payment_notes TEXT DEFAULT NULL,
    paid_date DATE NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$invoiceColumns = $pdo->query('SHOW COLUMNS FROM invoices')->fetchAll(PDO::FETCH_COLUMN);
foreach ([
    ['payment_method', 'VARCHAR(50) DEFAULT NULL'],
    ['payment_reference', 'VARCHAR(120) DEFAULT NULL'],
    ['transaction_code', 'VARCHAR(120) DEFAULT NULL'],
    ['payment_notes', 'TEXT DEFAULT NULL'],
] as [$column, $definition]) {
    if (!in_array($column, $invoiceColumns, true)) {
        $pdo->exec('ALTER TABLE invoices ADD COLUMN ' . $column . ' ' . $definition);
    }
}

$stmt = $pdo->prepare('SELECT i.*, s.name AS supplier_name, g.grn_no, g.id AS grn_id, l.lpo_no FROM invoices i
    JOIN suppliers s ON s.id = i.supplier_id JOIN grns g ON g.id = i.grn_id JOIN lpos l ON l.id = i.lpo_id
    WHERE i.id = ?');
$stmt->execute([$id]);
$inv = $stmt->fetch();
if (!$inv) { http_response_code(404); die('Invoice not found.'); }

$itemStmt = $pdo->prepare('SELECT * FROM grn_items WHERE grn_id = ? ORDER BY id');
$itemStmt->execute([(int)$inv['grn_id']]);
$invoiceItems = $itemStmt->fetchAll();
$deliveredTotal = 0.0;
foreach ($invoiceItems as $item) {
    $deliveredTotal += (float)$item['qty_received'] * (float)$item['unit_price'];
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'mark_paid') {
        if (!in_array($_SESSION['user_role'], ['finance', 'admin'], true)) {
            $error = 'Only Finance can mark an invoice as paid.';
        } else {
            $paidDate = trim((string)($_POST['paid_date'] ?? '')) ?: today();
            $amountPaid = (float)($_POST['amount'] ?? 0);
            $paymentMethod = trim((string)($_POST['payment_method'] ?? ''));
            $paymentReference = trim((string)($_POST['payment_reference'] ?? ''));
            $transactionCode = trim((string)($_POST['transaction_code'] ?? ''));
            $paymentNotes = trim((string)($_POST['payment_notes'] ?? ''));

            $paymentTotal = (float)$pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM invoice_payments WHERE invoice_id = ?')->execute([$id]);
            $existingPaid = (float)$pdo->query('SELECT COALESCE(SUM(amount),0) FROM invoice_payments WHERE invoice_id = ' . (int)$id)->fetchColumn();
            $remaining = max(0, (float)$inv['net_payable'] - $existingPaid);

            if ($paymentMethod === '') {
                $error = 'Please select a payment method.';
            } elseif ($amountPaid <= 0) {
                $error = 'Payment amount must be greater than zero.';
            } elseif ($amountPaid > $remaining + 0.005) {
                $error = 'Payment amount cannot exceed the remaining balance of ' . money($remaining) . '.';
            } else {
                $stmtInsert = $pdo->prepare('INSERT INTO invoice_payments (invoice_id, amount, payment_method, payment_reference, transaction_code, payment_notes, paid_date) VALUES (?,?,?,?,?,?,?)');
                $stmtInsert->execute([$id, $amountPaid, $paymentMethod, $paymentReference !== '' ? $paymentReference : null, $transactionCode !== '' ? $transactionCode : null, $paymentNotes !== '' ? $paymentNotes : null, $paidDate]);

                $newTotal = (float)$pdo->query('SELECT COALESCE(SUM(amount),0) FROM invoice_payments WHERE invoice_id = ' . (int)$id)->fetchColumn();
                $newStatus = $newTotal <= 0 ? 'pending_payment' : ($newTotal >= ((float)$inv['net_payable'] - 0.005) ? 'paid' : 'partially_paid');
                $newPaidDate = $newStatus === 'paid' ? $paidDate : null;
                $pdo->prepare("UPDATE invoices SET status=?, paid_date=? WHERE id=?")->execute([$newStatus, $newPaidDate, $id]);

                flash('Payment of ' . money($amountPaid) . ' recorded for invoice ' . $inv['inv_no'] . '.');
                redirect('/invoice_view.php?id=' . $id);
            }
        }
    }
    $stmt->execute([$id]);
    $inv = $stmt->fetch();
}

$paidTotal = (float)$pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM invoice_payments WHERE invoice_id = ?')->execute([$id]);
$paidTotal = (float)$pdo->query('SELECT COALESCE(SUM(amount),0) FROM invoice_payments WHERE invoice_id = ' . (int)$id)->fetchColumn();
$remainingBalance = max(0, (float)$inv['net_payable'] - $paidTotal);
$effectiveStatus = $paidTotal <= 0 ? 'pending_payment' : ($paidTotal >= ((float)$inv['net_payable'] - 0.005) ? 'paid' : 'partially_paid');
$paymentRows = $pdo->prepare('SELECT * FROM invoice_payments WHERE invoice_id = ? ORDER BY paid_date DESC, id DESC');
$paymentRows->execute([$id]);
$paymentRows = $paymentRows->fetchAll();

$pageTitle = $inv['inv_no'];
$activeNav = 'invoices';
require __DIR__ . '/includes/header.php';
?>

<div class="no-print"><a class="back-link" href="<?= BASE_PATH ?>/invoices.php">&larr; Back to Invoices</a></div>

<div class="toolbar" style="margin-top:14px;">
  <div>
    <div class="kicker">Supplier Invoice</div>
    <h1 class="page-title" style="margin-bottom:4px;"><?= e($inv['inv_no']) ?></h1>
    <?= status_badge($effectiveStatus) ?>
  </div>
  <div class="no-print">
    <button onclick="window.print()" class="btn secondary sm">Print</button>
    <?php if ($remainingBalance > 0 && in_array($_SESSION['user_role'], ['finance','admin'], true)): ?>
      <form method="post" style="display:inline-block;max-width:420px;vertical-align:top;" onsubmit="return confirm('Record this payment and update the supplier balance?');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="mark_paid">
        <div style="display:grid;gap:10px;min-width:320px;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px;box-shadow:0 4px 12px rgba(15,23,42,0.04);">
          <div>
            <label for="amount" style="display:block;font-size:12px;font-weight:600;color:#475569;margin-bottom:6px;">Payment Amount</label>
            <input id="amount" type="number" step="0.01" min="0.01" max="<?= e(number_format((float)$remainingBalance, 2, '.', '')) ?>" name="amount" value="<?= e($_POST['amount'] ?? number_format((float)$remainingBalance, 2, '.', '')) ?>" required style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;">
            <div style="font-size:11px;color:var(--muted);margin-top:4px;">Remaining balance: <?= money($remainingBalance) ?></div>
          </div>
          <div>
            <label for="paid_date" style="display:block;font-size:12px;font-weight:600;color:#475569;margin-bottom:6px;">Payment Date</label>
            <input id="paid_date" type="date" name="paid_date" value="<?= e($_POST['paid_date'] ?? today()) ?>" required style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;">
          </div>
          <div>
            <label for="payment_method" style="display:block;font-size:12px;font-weight:600;color:#475569;margin-bottom:6px;">Payment Method</label>
            <select id="payment_method" name="payment_method" required style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;">
              <option value="">Select method</option>
              <option value="bank_transfer" <?= (($_POST['payment_method'] ?? '') === 'bank_transfer') ? 'selected' : '' ?>>Bank Transfer</option>
              <option value="cash" <?= (($_POST['payment_method'] ?? '') === 'cash') ? 'selected' : '' ?>>Cash</option>
              <option value="cheque" <?= (($_POST['payment_method'] ?? '') === 'cheque') ? 'selected' : '' ?>>Cheque</option>
              <option value="mpesa" <?= (($_POST['payment_method'] ?? '') === 'mpesa') ? 'selected' : '' ?>>M-Pesa</option>
            </select>
          </div>
          <div>
            <label for="payment_reference" style="display:block;font-size:12px;font-weight:600;color:#475569;margin-bottom:6px;">Reference / Cheque / EFT No.</label>
            <input id="payment_reference" type="text" name="payment_reference" value="<?= e($_POST['payment_reference'] ?? '') ?>" placeholder="e.g. EFT-1024 / CHQ-435" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;">
          </div>
          <div>
            <label for="transaction_code" style="display:block;font-size:12px;font-weight:600;color:#475569;margin-bottom:6px;">Transaction Code</label>
            <input id="transaction_code" type="text" name="transaction_code" value="<?= e($_POST['transaction_code'] ?? '') ?>" placeholder="e.g. KNDB123456789" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;">
          </div>
          <div>
            <label for="payment_notes" style="display:block;font-size:12px;font-weight:600;color:#475569;margin-bottom:6px;">Notes</label>
            <textarea id="payment_notes" name="payment_notes" rows="2" placeholder="Additional payment notes" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;resize:vertical;"><?= e($_POST['payment_notes'] ?? '') ?></textarea>
          </div>
          <button type="submit" class="btn gold sm">Record Payment</button>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($error): ?><div class="banner-error no-print"><?= e($error) ?></div><?php endif; ?>

<div class="doc-preview">
  <div class="doc-letterhead">
    <div class="sname"><?= e(SCHOOL_NAME) ?></div>
    <div class="saddr"><?= e(SCHOOL_ADDRESS) ?></div>
    <div class="fname">Invoice / Payment Summary</div>
  </div>
  <div class="doc-meta">
    <span><strong>Ref:</strong> <?= e($inv['inv_no']) ?></span>
    <span><strong>Supplier:</strong> <?= e($inv['supplier_name']) ?></span>
    <span><strong>GRN:</strong> <?= e($inv['grn_no']) ?></span>
    <span><strong>LPO:</strong> <?= e($inv['lpo_no']) ?></span>
    <span><strong>Date:</strong> <?= format_date($inv['date']) ?></span>
  </div>
  <?php if ($inv['supplier_invoice_no']): ?><p style="font-size:12.5px;"><strong>Supplier Invoice No:</strong> <?= e($inv['supplier_invoice_no']) ?></p><?php endif; ?>
  <table class="doc-table">
    <thead><tr><th>No.</th><th>Item Description</th><th>Unit</th><th>Qty Received</th><th>Unit Price</th><th>Amount</th></tr></thead>
    <tbody>
      <?php foreach ($invoiceItems as $ix => $item): $lineTotal = (float)$item['qty_received'] * (float)$item['unit_price']; ?>
        <tr>
          <td><?= $ix + 1 ?></td>
          <td><?= e($item['description']) ?></td>
          <td><?= e($item['unit']) ?></td>
          <td><?= rtrim(rtrim(number_format((float)$item['qty_received'], 2), '0'), '.') ?></td>
          <td><?= money($item['unit_price']) ?></td>
          <td><?= money($lineTotal) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$invoiceItems): ?>
        <tr><td colspan="6" style="text-align:center;color:var(--muted);">No delivery items recorded for this invoice.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>

  <div style="margin-top:10px;text-align:right;font-size:12.5px;font-weight:600;color:var(--navy);">
    Total Delivered Value: <?= money($deliveredTotal) ?>
  </div>

  <table class="doc-table" style="margin-top:14px;">
    <tbody>
      <tr><td>Invoice Amount</td><td style="text-align:right;"><?= money($inv['amount']) ?></td></tr>
      <tr><td>Withholding Tax (2% — VAT suppliers)</td><td style="text-align:right;"><?= $inv['vat_registered'] ? '- ' . money($inv['wht_amount']) : '—' ?></td></tr>
      <tr class="total-row"><td>Net Payable to Supplier</td><td style="text-align:right;"><?= money($inv['net_payable']) ?></td></tr>
    </tbody>
  </table>
  <div style="margin-top:18px;padding:12px 14px;border:1px solid #dcfce7;background:#f0fdf4;border-radius:10px;">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;font-size:12.5px;">
      <div><strong>Total Invoiced:</strong> <?= money($inv['net_payable']) ?></div>
      <div><strong>Paid to Date:</strong> <?= money($paidTotal) ?></div>
      <div><strong>Balance Remaining:</strong> <?= money($remainingBalance) ?></div>
    </div>
  </div>

  <?php if ($paymentRows): ?>
    <div style="margin-top:18px;">
      <div class="kicker" style="margin-bottom:8px;">Payment History</div>
      <table class="list">
        <thead><tr><th>Date</th><th>Amount</th><th>Method</th><th>Reference</th><th>Transaction Code</th><th>Notes</th></tr></thead>
        <tbody>
          <?php foreach ($paymentRows as $payment): ?>
            <tr>
              <td style="padding:10px;"><?= e(format_date($payment['paid_date'])) ?></td>
              <td style="padding:10px;"><?= money($payment['amount']) ?></td>
              <td style="padding:10px;"><?= e($payment['payment_method'] ? ucwords(str_replace('_', ' ', $payment['payment_method'])) : '—') ?></td>
              <td style="padding:10px;"><?= e($payment['payment_reference'] ?: '—') ?></td>
              <td style="padding:10px;"><?= e($payment['transaction_code'] ?: '—') ?></td>
              <td style="padding:10px;"><?= e($payment['payment_notes'] ?: '—') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
