<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_role(['procurement', 'finance', 'admin']);

$pdo = db();
$invoices = $pdo->query("SELECT i.*, s.name AS supplier_name, g.grn_no,
    COALESCE((SELECT SUM(amount) FROM invoice_payments WHERE invoice_id = i.id), 0) AS paid_total
    FROM invoices i
    JOIN suppliers s ON s.id = i.supplier_id JOIN grns g ON g.id = i.grn_id ORDER BY i.id DESC")->fetchAll();

foreach ($invoices as $idx => $invoice) {
    $paidTotal = (float)($invoice['paid_total'] ?? 0);
    $netPayable = (float)($invoice['net_payable'] ?? 0);
    if ($paidTotal <= 0) {
        $invoices[$idx]['status'] = 'pending_payment';
    } elseif ($paidTotal >= $netPayable - 0.005) {
        $invoices[$idx]['status'] = 'paid';
    } else {
        $invoices[$idx]['status'] = 'partially_paid';
    }
}

$pageTitle = 'Invoices & Payment';
$activeNav = 'invoices';
require __DIR__ . '/includes/header.php';
?>

<h1 class="page-title">Invoices &amp; Payment</h1>
<p class="page-sub">Recorded by Procurement for Finance to process payment. VAT-registered suppliers attract 2% withholding tax.</p>

<div class="card">
  <?php if (!$invoices): ?>
    <div class="empty"><div class="big">&#129534;</div>No invoices recorded yet. Record one from a Goods Received Note.</div>
  <?php else: ?>
    <div class="card-body" style="padding:0;">
    <table class="list invoice-list">
      <colgroup>
        <col style="width:12%;">
        <col style="width:22%;">
        <col style="width:14%;">
        <col style="width:14%;">
        <col style="width:12%;">
        <col style="width:16%;">
        <col style="width:10%;">
      </colgroup>
      <thead><tr><th>No.</th><th>Supplier</th><th>GRN</th><th class="amount-header">Amount</th><th class="amount-header">WHT (2%)</th><th class="amount-header">Net Payable</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($invoices as $i): ?>
        <tr class="invoice-row">
          <td class="invoice-cell"><a href="<?= BASE_PATH ?>/invoice_view.php?id=<?= (int)$i['id'] ?>"><?= e($i['inv_no']) ?></a></td>
          <td class="invoice-cell"><a href="<?= BASE_PATH ?>/invoice_view.php?id=<?= (int)$i['id'] ?>"><?= e($i['supplier_name']) ?></a></td>
          <td class="invoice-cell"><a href="<?= BASE_PATH ?>/invoice_view.php?id=<?= (int)$i['id'] ?>"><?= e($i['grn_no']) ?></a></td>
          <td class="invoice-cell amount-cell"><a href="<?= BASE_PATH ?>/invoice_view.php?id=<?= (int)$i['id'] ?>"><?= money($i['amount']) ?></a></td>
          <td class="invoice-cell amount-cell"><a href="<?= BASE_PATH ?>/invoice_view.php?id=<?= (int)$i['id'] ?>"><?= $i['vat_registered'] ? money($i['wht_amount']) : '—' ?></a></td>
          <td class="invoice-cell amount-cell"><a href="<?= BASE_PATH ?>/invoice_view.php?id=<?= (int)$i['id'] ?>"><?= money($i['net_payable']) ?></a></td>
          <td class="invoice-cell"><a href="<?= BASE_PATH ?>/invoice_view.php?id=<?= (int)$i['id'] ?>"><?= status_badge($i['status']) ?></a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
