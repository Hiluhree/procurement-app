<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$pdo = db();
$tab = $_GET['tab'] ?? 'requisitions';
if (!in_array($tab, ['requisitions', 'approved', 'pending', 'approved_lpos'], true)) {
    $tab = 'requisitions';
}

if ($_SESSION['user_role'] === 'requester') {
    $all_stmt = $pdo->prepare("SELECT r.*, u.name AS applicant_name,
            (SELECT COALESCE(SUM(qty*unit_cost),0) FROM requisition_items WHERE requisition_id = r.id) AS total
        FROM requisitions r JOIN users u ON u.id = r.applicant_id
        WHERE r.applicant_id = ? ORDER BY r.id DESC");
    $all_stmt->execute([$_SESSION['user_id']]);
} else {
    $all_stmt = $pdo->query("SELECT r.*, u.name AS applicant_name,
            (SELECT COALESCE(SUM(qty*unit_cost),0) FROM requisition_items WHERE requisition_id = r.id) AS total
        FROM requisitions r JOIN users u ON u.id = r.applicant_id
        ORDER BY r.id DESC");
}
$requisitions = $all_stmt->fetchAll();

$approved_stmt = $pdo->prepare("SELECT r.*, u.name AS applicant_name,
        (SELECT COALESCE(SUM(qty*unit_cost),0) FROM requisition_items WHERE requisition_id = r.id) AS total
    FROM requisitions r JOIN users u ON u.id = r.applicant_id
    WHERE r.status = 'approved'
    ORDER BY r.id DESC");
$approved_stmt->execute();
$approved_requisitions = $approved_stmt->fetchAll();

$pending_stmt = $pdo->prepare("SELECT r.*, u.name AS applicant_name,
        (SELECT COALESCE(SUM(qty*unit_cost),0) FROM requisition_items WHERE requisition_id = r.id) AS total
    FROM requisitions r JOIN users u ON u.id = r.applicant_id
    WHERE r.status != 'approved'
    ORDER BY r.id DESC");
$pending_stmt->execute();
$pending_requisitions = $pending_stmt->fetchAll();

$lpo_stmt = $pdo->query("SELECT l.*, s.name AS supplier_name,
        (SELECT COALESCE(SUM(qty*unit_price),0) FROM lpo_items WHERE lpo_id = l.id) AS total
    FROM lpos l JOIN suppliers s ON s.id = l.supplier_id
    WHERE l.status = 'approved'
    ORDER BY l.id DESC");
$approved_lpos = $lpo_stmt->fetchAll();

$pageTitle = 'Requisitions';
$activeNav = 'requisitions';
require __DIR__ . '/includes/header.php';
?>

<style>
  .tab-container { display:flex; border-bottom:1px solid #ddd; margin-bottom:20px; gap:0; }
  .tab-button { padding:10px 20px; cursor:pointer; border:none; background:none; color:#666; font-size:14px; border-bottom:2px solid transparent; }
  .tab-button.active { color:#333; border-bottom-color:#C9AA35; font-weight:bold; }
  .tab-content { display:none; }
  .tab-content.active { display:block; }
</style>

<div class="toolbar">
  <div>
    <h1 class="page-title">Requisitions</h1>
    <p class="page-sub" style="margin-bottom:0;">Purchase / Service Request Forms</p>
  </div>
  <a href="<?= BASE_PATH ?>/requisition_new.php" class="btn gold">+ New Requisition</a>
</div>

<!-- Tabs -->
<div class="tab-container">
  <button class="tab-button <?= $tab === 'requisitions' ? 'active' : '' ?>" onclick="switchTab('requisitions')">All Requisitions (<?= count($requisitions) ?>)</button>
  <button class="tab-button <?= $tab === 'approved' ? 'active' : '' ?>" onclick="switchTab('approved')">Approved (<?= count($approved_requisitions) ?>)</button>
  <button class="tab-button <?= $tab === 'pending' ? 'active' : '' ?>" onclick="switchTab('pending')">Pending (<?= count($pending_requisitions) ?>)</button>
  <button class="tab-button <?= $tab === 'approved_lpos' ? 'active' : '' ?>" onclick="switchTab('approved_lpos')">Approved LPOs (<?= count($approved_lpos) ?>)</button>
</div>

<!-- All Requisitions Tab -->
<div id="requisitions" class="tab-content <?= $tab === 'requisitions' ? 'active' : '' ?>">
  <div class="card">
    <?php if (!$requisitions): ?>
      <div class="empty"><div class="big">&#128203;</div>No requisitions yet. Raise one to start the procurement flow.</div>
    <?php else: ?>
      <div class="card-body" style="padding:0;">
      <table class="list">
        <thead><tr><th>No.</th><th>Department</th><th>Applicant</th><th>Date</th><th>Total</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($requisitions as $r): ?>
          <tr class="rowlink">
            <td colspan="6" style="padding:0;">
              <a class="rowlink" href="<?= BASE_PATH ?>/requisition_view.php?id=<?= (int)$r['id'] ?>" style="display:grid;grid-template-columns:120px 1fr 1fr 120px 130px 160px;padding:10px;">
                <span><?= e($r['req_no']) ?></span>
                <span><?= e($r['department']) ?></span>
                <span><?= e($r['applicant_name']) ?></span>
                <span><?= format_date($r['date_raised']) ?></span>
                <span><?= $r['total'] > 0 ? money($r['total']) : '&mdash;' ?></span>
                <span><?= status_badge($r['status']) ?></span>
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Approved Requisitions Tab -->
<div id="approved" class="tab-content <?= $tab === 'approved' ? 'active' : '' ?>">
  <div class="card">
    <?php if (!$approved_requisitions): ?>
      <div class="empty"><div class="big">&#128203;</div>No approved requisitions yet.</div>
    <?php else: ?>
      <div class="card-body" style="padding:0;">
      <table class="list">
        <thead><tr><th>No.</th><th>Department</th><th>Applicant</th><th>Date</th><th>Total</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($approved_requisitions as $r): ?>
          <tr class="rowlink">
            <td colspan="6" style="padding:0;">
              <a class="rowlink" href="<?= BASE_PATH ?>/requisition_view.php?id=<?= (int)$r['id'] ?>" style="display:grid;grid-template-columns:120px 1fr 1fr 120px 130px 160px;padding:10px;">
                <span><?= e($r['req_no']) ?></span>
                <span><?= e($r['department']) ?></span>
                <span><?= e($r['applicant_name']) ?></span>
                <span><?= format_date($r['date_raised']) ?></span>
                <span><?= $r['total'] > 0 ? money($r['total']) : '&mdash;' ?></span>
                <span><?= status_badge($r['status']) ?></span>
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Pending Requisitions Tab -->
<div id="pending" class="tab-content <?= $tab === 'pending' ? 'active' : '' ?>">
  <div class="card">
    <?php if (!$pending_requisitions): ?>
      <div class="empty"><div class="big">&#128203;</div>No pending requisitions.</div>
    <?php else: ?>
      <div class="card-body" style="padding:0;">
      <table class="list">
        <thead><tr><th>No.</th><th>Department</th><th>Applicant</th><th>Date</th><th>Total</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($pending_requisitions as $r): ?>
          <tr class="rowlink">
            <td colspan="6" style="padding:0;">
              <a class="rowlink" href="<?= BASE_PATH ?>/requisition_view.php?id=<?= (int)$r['id'] ?>" style="display:grid;grid-template-columns:120px 1fr 1fr 120px 130px 160px;padding:10px;">
                <span><?= e($r['req_no']) ?></span>
                <span><?= e($r['department']) ?></span>
                <span><?= e($r['applicant_name']) ?></span>
                <span><?= format_date($r['date_raised']) ?></span>
                <span><?= $r['total'] > 0 ? money($r['total']) : '&mdash;' ?></span>
                <span><?= status_badge($r['status']) ?></span>
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Approved LPOs Tab -->
<div id="approved_lpos" class="tab-content <?= $tab === 'approved_lpos' ? 'active' : '' ?>">
  <div class="card">
    <?php if (!$approved_lpos): ?>
      <div class="empty"><div class="big">&#128230;</div>No approved LPOs yet.</div>
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
        <?php foreach ($approved_lpos as $l): ?>
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
</div>

<script>
function switchTab(tabName) {
  const contents = document.querySelectorAll('.tab-content');
  contents.forEach(c => c.classList.remove('active'));
  const buttons = document.querySelectorAll('.tab-button');
  buttons.forEach(b => b.classList.remove('active'));
  const target = document.getElementById(tabName);
  if (target) {
    target.classList.add('active');
    const tabBtn = document.querySelector('.tab-button[onclick*="' + tabName + '"]');
    if (tabBtn) tabBtn.classList.add('active');
  }
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
