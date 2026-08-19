<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$pdo = db();
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT r.*, u.name AS applicant_name FROM requisitions r JOIN users u ON u.id = r.applicant_id WHERE r.id = ?');
$stmt->execute([$id]);
$req = $stmt->fetch();
if (!$req) { http_response_code(404); die('Requisition not found.'); }

// Requesters may only view their own requisitions
if ($_SESSION['user_role'] === 'requester' && (int)$req['applicant_id'] !== (int)$_SESSION['user_id']) {
    http_response_code(403); die('You do not have permission to view this requisition.');
}

$itemStmt = $pdo->prepare('SELECT * FROM requisition_items WHERE requisition_id = ?');
$itemStmt->execute([$id]);
$items = $itemStmt->fetchAll();
$grandTotal = array_reduce($items, fn($c, $it) => $c + $it['qty'] * $it['unit_cost'], 0);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $pendingRole = current_pending_role($req['status']);
    $me = current_user();

    if ($action === 'update_specs' && in_array($_SESSION['user_role'], ['procurement', 'admin'], true)) {
        // Only allow specification updates before procurement approval
        if ($req['status'] !== 'pending_procurement') {
            $error = 'Specifications cannot be changed after procurement approval. Please reopen the requisition if changes are needed.';
        } else {
            // Update specifications - only procurement/admin can do this
            $specs = $_POST['spec'] ?? [];
            foreach ($specs as $itemId => $spec) {
                $spec = trim($spec);
                $pdo->prepare('UPDATE requisition_items SET specification = ? WHERE id = ? AND requisition_id = ?')
                    ->execute([$spec ?: null, (int)$itemId, $id]);
            }
            flash('Specifications updated.');
            redirect('/requisition_view.php?id=' . $id);
        }
    } elseif (!$pendingRole || $_SESSION['user_role'] !== $pendingRole) {
        $error = 'This requisition is not awaiting your action.';
    } elseif ($action === 'approve') {
        if (empty($me['signature_path'])) {
            $error = 'Please upload your signature/stamp under "My Signature" before approving.';
        } else {
            $newStatus = next_status_after($pendingRole, 'requisition');
            $pdo->beginTransaction();
            $ins = $pdo->prepare('INSERT INTO approvals (document_type, document_id, role, status, acted_by, acted_by_name, signature_path) VALUES (?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE status=VALUES(status), acted_by=VALUES(acted_by), acted_by_name=VALUES(acted_by_name), signature_path=VALUES(signature_path), acted_at=CURRENT_TIMESTAMP, reason=NULL');
            $ins->execute(['requisition', $id, $pendingRole, 'approved', $me['id'], $me['name'], $me['signature_path']]);
            $pdo->prepare('UPDATE requisitions SET status = ? WHERE id = ?')->execute([$newStatus, $id]);
            $pdo->commit();
            
            // Send notifications for workflow transitions
            if ($newStatus === 'approved') {
                // Final approval - notify requester
                notify_requisition_approved($pdo, $id);
            } else {
                // Notify next approvers in the chain
                notify_requisition_approvers($pdo, $id, $newStatus, $me['id']);
            }
            
            flash('Requisition ' . $req['req_no'] . ' approved.');
            redirect('/requisition_view.php?id=' . $id);
        }
    } elseif ($action === 'reject') {
        $reason = trim($_POST['reason'] ?? '');
        if ($reason === '') {
            $error = 'Please provide a reason for rejection.';
        } else {
            $pdo->beginTransaction();
            $ins = $pdo->prepare('INSERT INTO approvals (document_type, document_id, role, status, acted_by, acted_by_name, reason) VALUES (?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE status=VALUES(status), acted_by=VALUES(acted_by), acted_by_name=VALUES(acted_by_name), reason=VALUES(reason), acted_at=CURRENT_TIMESTAMP, signature_path=NULL');
            $ins->execute(['requisition', $id, $pendingRole, 'rejected', $me['id'], $me['name'], $reason]);
            $pdo->prepare('UPDATE requisitions SET status = ? WHERE id = ?')->execute(['rejected', $id]);
            $pdo->commit();
            flash('Requisition ' . $req['req_no'] . ' rejected.', 'error');
            redirect('/requisition_view.php?id=' . $id);
        }
    }
    // refresh req after any state change attempt
    $stmt->execute([$id]);
    $req = $stmt->fetch();
}

$approvals = get_approvals($pdo, 'requisition', $id);
$pendingRole = current_pending_role($req['status']);

$existingLpo = $pdo->prepare('SELECT id, lpo_no FROM lpos WHERE requisition_id = ?');
$existingLpo->execute([$id]);
$existingLpo = $existingLpo->fetch();

$pageTitle = $req['req_no'];
$activeNav = 'requisitions';
require __DIR__ . '/includes/header.php';
?>

<div class="no-print"><a class="back-link" href="<?= BASE_PATH ?>/requisitions.php">&larr; Back to Requisitions</a></div>

<div class="toolbar" style="margin-top:14px;">
  <div>
    <div class="kicker">Requisition</div>
    <h1 class="page-title" style="margin-bottom:4px;"><?= e($req['req_no']) ?></h1>
    <?= status_badge($req['status']) ?>
  </div>
  <div class="no-print">
    <?php if ($req['status'] === 'approved'): ?>
      <button onclick="window.print()" class="btn secondary sm">Print</button>
    <?php endif; ?>
    <?php if ($req['status'] === 'approved' && !$existingLpo && in_array($_SESSION['user_role'], ['procurement','admin'], true)): ?>
      <a href="<?= BASE_PATH ?>/lpo_new.php?requisition_id=<?= $id ?>" class="btn gold sm">Create LPO &rarr;</a>
    <?php elseif ($existingLpo): ?>
      <a href="<?= BASE_PATH ?>/lpo_view.php?id=<?= (int)$existingLpo['id'] ?>" class="btn secondary sm">View LPO <?= e($existingLpo['lpo_no']) ?></a>
    <?php endif; ?>
  </div>
</div>

<?php if ($error): ?><div class="banner-error no-print"><?= e($error) ?></div><?php endif; ?>

<div class="doc-preview">
  <div class="doc-letterhead">
    <div class="sname"><?= e(SCHOOL_NAME) ?></div>
    <div class="saddr"><?= e(SCHOOL_ADDRESS) ?> &middot; <?= e(SCHOOL_CONTACT) ?></div>
    <div class="fname">Purchase / Service Request Form</div>
  </div>
  <div class="doc-meta">
    <span><strong>Form No:</strong> <?= e($req['req_no']) ?></span>
    <span><strong>Dept:</strong> <?= e($req['department']) ?></span>
    <span><strong>Applicant:</strong> <?= e($req['applicant_name']) ?></span>
    <span><strong>Date:</strong> <?= format_date($req['date_raised']) ?></span>
  </div>
  <?php if ($req['purpose']): ?><p style="font-size:12.5px;"><strong>Purpose:</strong> <?= e($req['purpose']) ?></p><?php endif; ?>
  <table class="doc-table">
    <thead><tr><th>#</th><th>Item Name</th><th>Specification</th><th>Unit</th><th>Qty</th><th>Unit Cost</th><th>Total</th></tr></thead>
    <tbody>
      <?php foreach ($items as $i => $it): $lineTotal = $it['qty'] * $it['unit_cost']; ?>
      <tr>
        <td><?= $i + 1 ?></td><td><?= e($it['description']) ?></td><td><?= e($it['specification'] ?? '') ?></td><td><?= e($it['unit']) ?></td>
        <td><?= rtrim(rtrim(number_format($it['qty'], 2), '0'), '.') ?></td>
        <td><?= money($it['unit_cost']) ?></td><td><?= money($lineTotal) ?></td>
      </tr>
      <?php endforeach; ?>
      <tr class="total-row"><td colspan="6">TOTAL AMOUNT</td><td><?= money($grandTotal) ?></td></tr>
    </tbody>
  </table>
</div>

<?php if (in_array($_SESSION['user_role'], ['procurement', 'admin'], true) && $req['status'] === 'pending_procurement'): ?>
<div class="card no-print" style="margin-bottom:20px;">
  <div class="card-header"><span class="htitle">Edit Specifications</span></div>
  <div class="card-body">
    <p style="color:#666; margin-bottom:14px; font-size:13px;">Specifications can only be edited while the requisition is pending procurement approval.</p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update_specs">
      <table style="width:100%;margin-bottom:14px;">
        <thead><tr><th>Item Name</th><th>Current Specification</th><th>New Specification</th></tr></thead>
        <tbody>
          <?php foreach ($items as $it): ?>
          <tr>
            <td style="padding:8px;"><?= e($it['description']) ?></td>
            <td style="padding:8px;"><?= e($it['specification'] ?? '') ?></td>
            <td style="padding:8px;"><input type="text" name="spec[<?= (int)$it['id'] ?>]" value="<?= e($it['specification'] ?? '') ?>" placeholder="Update specification"></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <button type="submit" class="btn gold sm">Update Specifications</button>
    </form>
  </div>
</div>
<?php elseif (in_array($_SESSION['user_role'], ['procurement', 'admin'], true) && $req['status'] !== 'pending_procurement'): ?>
<div class="card no-print" style="margin-bottom:20px;">
  <div class="card-body" style="padding:10px 16px;">
    <p style="color:#666; font-size:13px; margin:0;">
      ℹ️ Specifications cannot be edited after procurement approval. If changes are needed, please formally reopen this requisition.
    </p>
  </div>
</div>
<?php endif; ?>

<div class="approval-row">
  <?php foreach (APPROVAL_ROLES as $role): $a = $approvals[$role] ?? null; ?>
    <div class="approval-box">
      <div class="role"><?= e(role_label($role)) ?></div>
      <?php if ($a && $a['status'] === 'approved'): ?>
        <div class="name"><?= e($a['acted_by_name']) ?></div>
        <div class="when"><?= format_datetime($a['acted_at']) ?></div>
        <?php if ($a['signature_path']): ?><img class="stamp" src="<?= e($a['signature_path']) ?>"><?php endif; ?>
      <?php elseif ($a && $a['status'] === 'rejected'): ?>
        <div class="rejected-note">Rejected: <?= e($a['reason']) ?></div>
      <?php elseif ($role === $pendingRole): ?>
        <div class="pending-note">Awaiting action</div>
        <?php if ($_SESSION['user_role'] === $role): ?>
          <form method="post" style="display:inline;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="approve">
            <button type="submit" class="btn gold sm" style="margin-top:8px;margin-right:6px;" onclick="return confirm('Approve this requisition as <?= e(role_label($role)) ?>?');">Approve</button>
          </form>
          <button type="button" class="btn red sm" style="margin-top:8px;" onclick="document.getElementById('reject-box-<?= $role ?>').style.display='block'">Reject</button>
          <div id="reject-box-<?= $role ?>" style="display:none;margin-top:8px;">
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="reject">
              <textarea name="reason" rows="2" placeholder="Reason for rejection" required></textarea>
              <button type="submit" class="btn red sm" style="margin-top:6px;">Confirm Reject</button>
            </form>
          </div>
        <?php else: ?>
          <div class="pending-note">Waiting on <?= e(role_label($role)) ?></div>
        <?php endif; ?>
      <?php else: ?>
        <div class="pending-note">Not yet reached</div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
