<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$pdo = db();
$stmt = $pdo->query("SELECT l.*, s.name AS supplier_name,
        (SELECT COALESCE(SUM(qty*unit_price),0) FROM lpo_items WHERE lpo_id = l.id) AS total
    FROM lpos l JOIN suppliers s ON s.id = l.supplier_id
    ORDER BY l.id DESC");
$lpos = $stmt->fetchAll();

$pageTitle = 'Purchase Orders';
$activeNav = 'lpos';
require __DIR__ . '/includes/header.php';
?>

<h1 class="page-title">Purchase Orders (LPO)</h1>
<p class="page-sub">Prepared by Procurement, authorised by Finance, approved by Principal</p>

<div class="card">
  <?php if (!$lpos): ?>
    <div class="empty"><div class="big">&#128230;</div>No LPOs yet. Approve a requisition, then create an LPO from it.</div>
  <?php else: ?>
    <div class="card-body" style="padding:0;">
    <table class="list lpo-list">
      <colgroup>
        <col style="width:12%;">
        <col style="width:28%;">
        <col style="width:14%;">
        <col style="width:14%;">
        <col style="width:32%;">
      </colgroup>
      <thead><tr><th>No.</th><th>Supplier</th><th>Date</th><th class="amount-header">Total</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($lpos as $l): ?>
        <tr class="lpo-row">
          <td class="lpo-cell"><a href="<?= BASE_PATH ?>/lpo_view.php?id=<?= (int)$l['id'] ?>"><?= e($l['lpo_no']) ?></a></td>
          <td class="lpo-cell"><a href="<?= BASE_PATH ?>/lpo_view.php?id=<?= (int)$l['id'] ?>"><?= e($l['supplier_name']) ?></a></td>
          <td class="lpo-cell"><a href="<?= BASE_PATH ?>/lpo_view.php?id=<?= (int)$l['id'] ?>"><?= format_date($l['date']) ?></a></td>
          <td class="lpo-cell amount-cell"><a href="<?= BASE_PATH ?>/lpo_view.php?id=<?= (int)$l['id'] ?>"><?= money($l['total']) ?></a></td>
          <td class="lpo-cell"><a href="<?= BASE_PATH ?>/lpo_view.php?id=<?= (int)$l['id'] ?>"><?= $l['sent_to_supplier'] ? status_badge('sent_to_supplier') : status_badge($l['status']) ?></a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
