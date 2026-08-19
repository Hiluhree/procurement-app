<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$pdo = db();
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT g.*, l.lpo_no, s.name AS supplier_name FROM grns g
    JOIN lpos l ON l.id = g.lpo_id JOIN suppliers s ON s.id = l.supplier_id WHERE g.id = ?');
$stmt->execute([$id]);
$grn = $stmt->fetch();
if (!$grn) { http_response_code(404); die('GRN not found.'); }

$itemStmt = $pdo->prepare('SELECT * FROM grn_items WHERE grn_id = ?');
$itemStmt->execute([$id]);
$items = $itemStmt->fetchAll();

$existingInv = $pdo->prepare('SELECT id, inv_no FROM invoices WHERE grn_id = ?');
$existingInv->execute([$id]);
$existingInv = $existingInv->fetch();

$pageTitle = $grn['grn_no'];
$activeNav = 'grns';
require __DIR__ . '/includes/header.php';
?>

<div class="no-print"><a class="back-link" href="<?= BASE_PATH ?>/grns.php">&larr; Back to Goods Received</a></div>

<div class="toolbar" style="margin-top:14px;">
  <div>
    <div class="kicker">Goods Received Note</div>
    <h1 class="page-title" style="margin-bottom:4px;"><?= e($grn['grn_no']) ?></h1>
  </div>
  <div class="no-print">
    <button onclick="window.print()" class="btn secondary sm">Print</button>
    <?php if (!$existingInv && in_array($_SESSION['user_role'], ['procurement','admin'], true)): ?>
      <a href="<?= BASE_PATH ?>/invoice_new.php?grn_id=<?= $id ?>" class="btn gold sm">Record Invoice &rarr;</a>
    <?php elseif ($existingInv): ?>
      <a href="<?= BASE_PATH ?>/invoice_view.php?id=<?= (int)$existingInv['id'] ?>" class="btn secondary sm">View Invoice</a>
    <?php endif; ?>
  </div>
</div>

<div class="doc-preview">
  <div class="doc-letterhead">
    <div class="sname"><?= e(SCHOOL_NAME) ?></div>
    <div class="saddr"><?= e(SCHOOL_ADDRESS) ?></div>
    <div class="fname">Goods Received Note</div>
  </div>
  <div class="doc-meta">
    <span><strong>GRN No:</strong> <?= e($grn['grn_no']) ?></span>
    <span><strong>Section:</strong> <?= e($grn['section'] ?: '—') ?></span>
    <span><strong>Source:</strong> <?= e($grn['supplier_name']) ?></span>
    <span><strong>LPO No:</strong> <?= e($grn['lpo_no']) ?></span>
    <span><strong>Date:</strong> <?= format_date($grn['date']) ?></span>
  </div>
  <table class="doc-table">
    <thead><tr><th>No.</th><th>Item Description</th><th>Unit</th><th>Qty Ordered</th><th>Qty Received</th><th>Store / Dispensed To</th></tr></thead>
    <tbody>
      <?php foreach ($items as $i => $it): ?>
      <tr>
        <td><?= $i + 1 ?></td><td><?= e($it['description']) ?></td><td><?= e($it['unit']) ?></td>
        <td><?= rtrim(rtrim(number_format($it['qty_ordered'], 2), '0'), '.') ?></td>
        <td><?= rtrim(rtrim(number_format($it['qty_received'], 2), '0'), '.') ?></td>
        <td><?= e($it['destination'] ?: '—') ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <p style="font-size:12px;color:var(--muted);margin-top:14px;">The goods and quantities received have been taken on charge as in the above ledger. Received by: <?= e($grn['received_by'] ?: '—') ?></p>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
