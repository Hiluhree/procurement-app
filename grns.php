<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$pdo = db();
$stmt = $pdo->query("SELECT g.*, l.lpo_no, s.name AS supplier_name,
        (SELECT id FROM invoices WHERE grn_id = g.id) AS invoice_id,
        (SELECT status FROM invoices WHERE grn_id = g.id) AS invoice_status
    FROM grns g JOIN lpos l ON l.id = g.lpo_id JOIN suppliers s ON s.id = l.supplier_id
    ORDER BY g.id DESC");
$grns = $stmt->fetchAll();

$pageTitle = 'Goods Received';
$activeNav = 'grns';
require __DIR__ . '/includes/header.php';
?>

<h1 class="page-title">Goods Received Notes</h1>
<p class="page-sub">Goods received by Procurement and stored or dispensed to the requesting user</p>

<div class="card">
  <?php if (!$grns): ?>
    <div class="empty"><div class="big">&#128666;</div>No goods received yet. Send an LPO to a supplier, then record receipt.</div>
  <?php else: ?>
    <div class="card-body" style="padding:0;">
    <table class="list">
      <thead><tr><th>No.</th><th>From LPO</th><th>Supplier</th><th>Date</th><th>Received By</th><th>Invoice</th></tr></thead>
      <tbody>
      <?php foreach ($grns as $g): ?>
        <tr>
          <td colspan="6" style="padding:0;">
            <a class="rowlink" href="<?= BASE_PATH ?>/grn_view.php?id=<?= (int)$g['id'] ?>" style="display:grid;grid-template-columns:120px 120px 1fr 120px 160px 160px;padding:10px;">
              <span><?= e($g['grn_no']) ?></span>
              <span><?= e($g['lpo_no']) ?></span>
              <span><?= e($g['supplier_name']) ?></span>
              <span><?= format_date($g['date']) ?></span>
              <span><?= e($g['received_by'] ?: '—') ?></span>
              <span><?= $g['invoice_id'] ? status_badge($g['invoice_status']) : '<span class="badge gray">Not recorded</span>' ?></span>
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
