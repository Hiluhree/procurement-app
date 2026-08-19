<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$pdo = db();
$me = current_user();

$counts = [
    'requisitions' => $pdo->query("SELECT COUNT(*) FROM requisitions")->fetchColumn(),
    'req_approved' => $pdo->query("SELECT COUNT(*) FROM requisitions WHERE status='approved'")->fetchColumn(),
    'lpos'         => $pdo->query("SELECT COUNT(*) FROM lpos")->fetchColumn(),
    'grns'         => $pdo->query("SELECT COUNT(*) FROM grns")->fetchColumn(),
    'inv_pending'  => $pdo->query("SELECT COUNT(*) FROM invoices WHERE status='pending_payment'")->fetchColumn(),
];

// items awaiting the current user's role action
$pendingReq = [];
$pendingLpo = [];
if (in_array($_SESSION['user_role'], APPROVAL_ROLES, true)) {
    $statusForRole = [
        'procurement' => 'pending_procurement',
        'finance'     => 'pending_finance',
        'principal'   => 'pending_principal',
    ][$_SESSION['user_role']];

    $stmt = $pdo->prepare("SELECT r.*, u.name AS applicant_name FROM requisitions r JOIN users u ON u.id=r.applicant_id WHERE r.status = ? ORDER BY r.created_at DESC LIMIT 6");
    $stmt->execute([$statusForRole]);
    $pendingReq = $stmt->fetchAll();

    if ($_SESSION['user_role'] !== 'procurement') {
        $stmt = $pdo->prepare("SELECT l.*, s.name AS supplier_name FROM lpos l JOIN suppliers s ON s.id=l.supplier_id WHERE l.status = ? ORDER BY l.created_at DESC LIMIT 6");
        $stmt->execute([$statusForRole]);
        $pendingLpo = $stmt->fetchAll();
    }
}

$hasSignature = !empty($me['signature_path']);

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
require __DIR__ . '/includes/header.php';
?>

<h1 class="page-title">Overview</h1>
<p class="page-sub">Requisition &rarr; RFQ/LPO &rarr; Delivery &rarr; Goods Receipt &rarr; Invoice &amp; Payment</p>

<div class="stat-grid">
  <div class="stat-box"><div class="num"><?= (int)$counts['requisitions'] ?></div><div class="lbl">Requisitions</div></div>
  <div class="stat-box"><div class="num"><?= (int)$counts['req_approved'] ?></div><div class="lbl">Approved Reqs</div></div>
  <div class="stat-box"><div class="num"><?= (int)$counts['lpos'] ?></div><div class="lbl">Purchase Orders</div></div>
  <div class="stat-box"><div class="num"><?= (int)$counts['grns'] ?></div><div class="lbl">Goods Received Notes</div></div>
  <div class="stat-box"><div class="num"><?= (int)$counts['inv_pending'] ?></div><div class="lbl">Invoices Awaiting Payment</div></div>
</div>

<?php if (in_array($_SESSION['user_role'], APPROVAL_ROLES, true) && !$hasSignature): ?>
  <div class="banner-note">
    &#9888; You haven't uploaded a signature/stamp yet. <a href="<?= BASE_PATH ?>/signatories.php">Upload one now</a> — it's applied automatically whenever you approve a document.
  </div>
<?php endif; ?>

<?php if ($pendingReq): ?>
<div class="card">
  <div class="card-header"><span class="htitle">Requisitions awaiting your approval</span></div>
  <div class="card-body">
    <table class="list">
      <thead><tr><th>No.</th><th>Department</th><th>Applicant</th><th>Date</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($pendingReq as $r): ?>
        <tr>
          <td><?= e($r['req_no']) ?></td>
          <td><?= e($r['department']) ?></td>
          <td><?= e($r['applicant_name']) ?></td>
          <td><?= format_date($r['date_raised']) ?></td>
          <td><a class="btn secondary sm" href="<?= BASE_PATH ?>/requisition_view.php?id=<?= (int)$r['id'] ?>">Review</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($pendingLpo): ?>
<div class="card">
  <div class="card-header"><span class="htitle">Purchase Orders awaiting your approval</span></div>
  <div class="card-body">
    <table class="list">
      <thead><tr><th>No.</th><th>Supplier</th><th>Date</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($pendingLpo as $l): ?>
        <tr>
          <td><?= e($l['lpo_no']) ?></td>
          <td><?= e($l['supplier_name']) ?></td>
          <td><?= format_date($l['date']) ?></td>
          <td><a class="btn secondary sm" href="<?= BASE_PATH ?>/lpo_view.php?id=<?= (int)$l['id'] ?>">Review</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header"><span class="htitle">Workflow</span></div>
  <div class="card-body">
    <div class="stage-strip">
      <span class="stage-chip">1. Requisition raised</span><span class="stage-arrow">&rarr;</span>
      <span class="stage-chip">2. Approve: Procurement &rarr; Finance &rarr; Principal</span><span class="stage-arrow">&rarr;</span>
      <span class="stage-chip">3. RFQ to suppliers</span><span class="stage-arrow">&rarr;</span>
      <span class="stage-chip">4. LPO prepared, authorized, approved</span><span class="stage-arrow">&rarr;</span>
      <span class="stage-chip">5. Sent to supplier</span><span class="stage-arrow">&rarr;</span>
      <span class="stage-chip">6. Goods received &amp; stored/dispensed</span><span class="stage-arrow">&rarr;</span>
      <span class="stage-chip">7. Invoice recorded (WHT if VAT) &rarr; paid</span>
    </div>
    <a class="btn gold" href="<?= BASE_PATH ?>/requisition_new.php">+ Raise a Requisition</a>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
