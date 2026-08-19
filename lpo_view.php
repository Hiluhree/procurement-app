<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$pdo = db();
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT l.*, s.name AS supplier_name, s.address AS supplier_address, s.phone AS supplier_phone, s.email AS supplier_email, r.req_no
    FROM lpos l JOIN suppliers s ON s.id = l.supplier_id JOIN requisitions r ON r.id = l.requisition_id
    WHERE l.id = ?');
$stmt->execute([$id]);
$lpo = $stmt->fetch();
if (!$lpo) { http_response_code(404); die('LPO not found.'); }

$itemStmt = $pdo->prepare('SELECT li.*, COALESCE(li.unit_price, 0) AS unit_price FROM lpo_items li WHERE li.lpo_id = ?');
$itemStmt->execute([$id]);
$items = $itemStmt->fetchAll();
$grandTotal = array_reduce($items, fn($c, $it) => $c + (float)$it['qty'] * (float)($it['unit_price'] ?? 0), 0);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $me = current_user();

    if ($action === 'send_to_supplier') {
        if ($lpo['status'] !== 'approved') {
            $error = 'The LPO must be fully approved before it can be sent to the supplier.';
        } elseif (!in_array($_SESSION['user_role'], ['procurement','admin'], true)) {
            $error = 'Only Procurement can mark an LPO as sent to the supplier.';
        } else {
            $pdo->prepare('UPDATE lpos SET sent_to_supplier = 1 WHERE id = ?')->execute([$id]);
            flash('LPO ' . $lpo['lpo_no'] . ' marked as sent to supplier.');
            redirect('/lpo_view.php?id=' . $id);
        }
    } else {
        $pendingRole = current_pending_role($lpo['status']);
        if (!$pendingRole || $_SESSION['user_role'] !== $pendingRole) {
            $error = 'This LPO is not awaiting your action.';
        } elseif ($action === 'approve') {
            if (empty($me['signature_path'])) {
                $error = 'Please upload your signature/stamp under "My Signature" before approving.';
            } else {
                $newStatus = next_status_after($pendingRole, 'lpo');
                $pdo->beginTransaction();
                $ins = $pdo->prepare('INSERT INTO approvals (document_type, document_id, role, status, acted_by, acted_by_name, signature_path) VALUES (?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE status=VALUES(status), acted_by=VALUES(acted_by), acted_by_name=VALUES(acted_by_name), signature_path=VALUES(signature_path), acted_at=CURRENT_TIMESTAMP, reason=NULL');
                $ins->execute(['lpo', $id, $pendingRole, 'approved', $me['id'], $me['name'], $me['signature_path']]);
                $pdo->prepare('UPDATE lpos SET status = ? WHERE id = ?')->execute([$newStatus, $id]);
                $pdo->commit();
                
                // Send notifications for workflow transitions
                notify_lpo_approvers($pdo, $id, $newStatus, $me['id']);
                
                flash('LPO ' . $lpo['lpo_no'] . ' approved.');
                redirect('/lpo_view.php?id=' . $id);
            }
        } elseif ($action === 'reject') {
            $reason = trim($_POST['reason'] ?? '');
            if ($reason === '') {
                $error = 'Please provide a reason for rejection.';
            } else {
                $pdo->beginTransaction();
                $ins = $pdo->prepare('INSERT INTO approvals (document_type, document_id, role, status, acted_by, acted_by_name, reason) VALUES (?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE status=VALUES(status), acted_by=VALUES(acted_by), acted_by_name=VALUES(acted_by_name), reason=VALUES(reason), acted_at=CURRENT_TIMESTAMP, signature_path=NULL');
                $ins->execute(['lpo', $id, $pendingRole, 'rejected', $me['id'], $me['name'], $reason]);
                $pdo->prepare('UPDATE lpos SET status = ? WHERE id = ?')->execute(['rejected', $id]);
                $pdo->commit();
                flash('LPO ' . $lpo['lpo_no'] . ' rejected.', 'error');
                redirect('/lpo_view.php?id=' . $id);
            }
        }
    }
    $stmt->execute([$id]);
    $lpo = $stmt->fetch();
}

$approvals = get_approvals($pdo, 'lpo', $id);
$pendingRole = current_pending_role($lpo['status']);

$existingGrn = $pdo->prepare('SELECT id, grn_no FROM grns WHERE lpo_id = ?');
$existingGrn->execute([$id]);
$existingGrn = $existingGrn->fetch();

$pageTitle = $lpo['lpo_no'];
$activeNav = 'lpos';
require __DIR__ . '/includes/header.php';
?>

<div class="no-print"><a class="back-link" href="<?= BASE_PATH ?>/lpos.php">&larr; Back to Purchase Orders</a></div>

<div class="toolbar" style="margin-top:14px;">
  <div>
    <div class="kicker">Local Purchase Order</div>
    <h1 class="page-title" style="margin-bottom:4px;"><?= e($lpo['lpo_no']) ?></h1>
    <?= $lpo['sent_to_supplier'] ? status_badge('sent_to_supplier') : status_badge($lpo['status']) ?>
  </div>
  <div class="no-print">
    <?php if ($lpo['status'] === 'approved'): ?>
      <button onclick="window.print()" class="btn secondary sm">Print</button>
    <?php endif; ?>
    <?php if ($lpo['status'] === 'approved' && !$lpo['sent_to_supplier'] && in_array($_SESSION['user_role'], ['procurement','admin'], true)): ?>
      <form method="post" style="display:inline;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="send_to_supplier">
        <button type="submit" class="btn gold sm">Send to Supplier</button>
      </form>
    <?php endif; ?>
    <?php if ($lpo['sent_to_supplier'] && !$existingGrn && in_array($_SESSION['user_role'], ['procurement','admin'], true)): ?>
      <a href="<?= BASE_PATH ?>/grn_new.php?lpo_id=<?= $id ?>" class="btn gold sm">Receive Goods &rarr;</a>
    <?php elseif ($existingGrn): ?>
      <a href="<?= BASE_PATH ?>/grn_view.php?id=<?= (int)$existingGrn['id'] ?>" class="btn secondary sm">View GRN <?= e($existingGrn['grn_no']) ?></a>
    <?php endif; ?>
  </div>
</div>

<?php if ($error): ?><div class="banner-error no-print"><?= e($error) ?></div><?php endif; ?>

<div class="doc-preview">
  <div class="doc-letterhead">
    <div class="sname"><?= e(SCHOOL_NAME) ?></div>
    <div class="saddr"><?= e(SCHOOL_ADDRESS) ?> &middot; <?= e(SCHOOL_CONTACT) ?></div>
    <div class="fname">Local Purchase Order</div>
  </div>
  <div class="doc-meta">
    <span><strong>LPO No:</strong> <?= e($lpo['lpo_no']) ?></span>
    <span><strong>Supplier:</strong> <?= e($lpo['supplier_name']) ?></span>
    <span><strong>Date:</strong> <?= format_date($lpo['date']) ?></span>
    <span><strong>Requisition No:</strong> <?= e($lpo['req_no']) ?></span>
  </div>
  <table class="doc-table">
    <thead><tr><th>No.</th><th>Description</th><th>Unit</th><th>Qty</th><th>Unit Price</th><th>Total</th></tr></thead>
    <tbody>
      <?php foreach ($items as $i => $it): $lineTotal = $it['qty'] * $it['unit_price']; ?>
      <tr>
        <td><?= $i + 1 ?></td><td><?= e($it['description']) ?></td><td><?= e($it['unit']) ?></td>
        <td><?= rtrim(rtrim(number_format($it['qty'], 2), '0'), '.') ?></td>
        <td><?= money($it['unit_price']) ?></td><td><?= money($lineTotal) ?></td>
      </tr>
      <?php endforeach; ?>
      <tr class="total-row"><td colspan="5">TOTAL</td><td><?= money($grandTotal) ?></td></tr>
    </tbody>
  </table>
</div>

<div class="approval-row">
  <?php foreach (APPROVAL_ROLES as $role): $a = $approvals[$role] ?? null; ?>
    <div class="approval-box">
      <div class="role"><?= e(role_label($role)) ?><?= $role === 'procurement' ? ' (prepared)' : '' ?></div>
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
            <button type="submit" class="btn gold sm" style="margin-top:8px;margin-right:6px;" onclick="return confirm('Approve this LPO as <?= e(role_label($role)) ?>?');">Approve</button>
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
      <?php elseif (!$a && $role === 'procurement'): ?>
        <div class="pending-note">Not recorded</div>
      <?php else: ?>
        <div class="pending-note">Not yet reached</div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
