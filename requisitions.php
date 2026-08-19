<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$pdo = db();

// Requesters see only their own; approvers/admin see all.
if ($_SESSION['user_role'] === 'requester') {
    $stmt = $pdo->prepare("SELECT r.*, u.name AS applicant_name,
            (SELECT COALESCE(SUM(qty*unit_cost),0) FROM requisition_items WHERE requisition_id = r.id) AS total
        FROM requisitions r JOIN users u ON u.id = r.applicant_id
        WHERE r.applicant_id = ? ORDER BY r.id DESC");
    $stmt->execute([$_SESSION['user_id']]);
} else {
    $stmt = $pdo->query("SELECT r.*, u.name AS applicant_name,
            (SELECT COALESCE(SUM(qty*unit_cost),0) FROM requisition_items WHERE requisition_id = r.id) AS total
        FROM requisitions r JOIN users u ON u.id = r.applicant_id
        ORDER BY r.id DESC");
}
$requisitions = $stmt->fetchAll();

$pageTitle = 'Requisitions';
$activeNav = 'requisitions';
require __DIR__ . '/includes/header.php';
?>

<div class="toolbar">
  <div>
    <h1 class="page-title">Requisitions</h1>
    <p class="page-sub" style="margin-bottom:0;">Purchase / Service Request Forms</p>
  </div>
  <a href="<?= BASE_PATH ?>/requisition_new.php" class="btn gold">+ New Requisition</a>
</div>

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

<?php require __DIR__ . '/includes/footer.php'; ?>
