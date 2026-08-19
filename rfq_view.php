<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_role(['procurement', 'admin']);

$pdo = db();
$rfq_id = (int)($_GET['id'] ?? 0);
$errors = [];
$success_msg = null;

if (!$rfq_id) {
    flash('RFQ not found.', 'error');
    redirect('/rfqs.php');
}

// Get RFQ
$rfq_stmt = $pdo->prepare('SELECT * FROM rfqs WHERE id = ?');
$rfq_stmt->execute([$rfq_id]);
$rfq = $rfq_stmt->fetch();

if (!$rfq) {
    flash('RFQ not found.', 'error');
    redirect('/rfqs.php');
}

// Get requisition details if linked
$req_details = null;
if ($rfq['requisition_id']) {
    $req_stmt = $pdo->prepare('SELECT * FROM requisitions WHERE id = ?');
    $req_stmt->execute([$rfq['requisition_id']]);
    $req_details = $req_stmt->fetch();
}

// Get RFQ items
$items_stmt = $pdo->prepare('SELECT * FROM rfq_items WHERE rfq_id = ?');
$items_stmt->execute([$rfq_id]);
$rfq_items = $items_stmt->fetchAll();

// Get invited suppliers
$suppliers_stmt = $pdo->prepare('
    SELECT rs.*, s.name, s.email, s.phone
    FROM rfq_suppliers rs
    JOIN suppliers s ON rs.supplier_id = s.id
    WHERE rs.rfq_id = ?
    ORDER BY rs.invitation_date DESC
');
$suppliers_stmt->execute([$rfq_id]);
$invited_suppliers = $suppliers_stmt->fetchAll();

// Get quotations
$quotations_stmt = $pdo->prepare('
    SELECT q.*, s.name as supplier_name,
           COUNT(DISTINCT qi.id) as item_count,
           SUM(qi.total_price) as total_quoted
    FROM quotations q
    JOIN suppliers s ON q.supplier_id = s.id
    LEFT JOIN quotation_items qi ON q.id = qi.quotation_id
    WHERE q.rfq_id = ?
    GROUP BY q.id
    ORDER BY q.created_at DESC
');
$quotations_stmt->execute([$rfq_id]);
$quotations = $quotations_stmt->fetchAll();

// Get existing awards
$awards_stmt = $pdo->prepare('
    SELECT ra.*, s.name as supplier_name
    FROM rfq_awards ra
    JOIN suppliers s ON s.id = ra.supplier_id
    WHERE ra.rfq_id = ?
    ORDER BY ra.rfq_item_id ASC
');
$awards_stmt->execute([$rfq_id]);
$awards = $awards_stmt->fetchAll();

// Get item-level quotes for evaluation
$item_quotes_stmt = $pdo->prepare('
    SELECT qi.rfq_item_id, qi.description, qi.unit, qi.qty_offered, qi.unit_price, qi.total_price,
           q.id as quotation_id, q.supplier_id, s.name as supplier_name
    FROM quotation_items qi
    JOIN quotations q ON q.id = qi.quotation_id
    JOIN suppliers s ON s.id = q.supplier_id
    WHERE q.rfq_id = ? AND q.status = "submitted"
    ORDER BY qi.rfq_item_id ASC, qi.unit_price ASC
');
$item_quotes_stmt->execute([$rfq_id]);
$item_quotes = $item_quotes_stmt->fetchAll();

// Group item quotes by rfq_item_id
$item_quotes_by_item = [];
foreach ($item_quotes as $quote) {
    $item_quotes_by_item[$quote['rfq_item_id']][] = $quote;
}

// Count awarded items
$awarded_count = 0;
$awarded_items = [];
foreach ($awards as $award) {
    $awarded_items[$award['rfq_item_id']] = $award;
    $awarded_count++;
}

// Get all suppliers not yet invited
$all_suppliers = $pdo->query('
    SELECT s.* FROM suppliers s
    WHERE s.id NOT IN (SELECT supplier_id FROM rfq_suppliers WHERE rfq_id = ' . $rfq_id . ')
    ORDER BY s.name
')->fetchAll();

// Handle adding supplier
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verify_csrf();
    
    if ($_POST['action'] === 'add_supplier') {
        $supplier_id = (int)($_POST['supplier_id'] ?? 0);
        
        if ($supplier_id) {
            try {
                $check_stmt = $pdo->prepare('SELECT id FROM rfq_suppliers WHERE rfq_id = ? AND supplier_id = ?');
                $check_stmt->execute([$rfq_id, $supplier_id]);
                
                if ($check_stmt->fetch()) {
                    $errors[] = 'This supplier is already added to the RFQ.';
                } else {
                    $token = bin2hex(random_bytes(32));
                    $add_stmt = $pdo->prepare('INSERT INTO rfq_suppliers (rfq_id, supplier_id, token) VALUES (?, ?, ?)');
                    $add_stmt->execute([$rfq_id, $supplier_id, $token]);
                    $success_msg = 'Supplier added. You can now send invitation.';
                }
            } catch (PDOException $e) {
                $errors[] = 'Error adding supplier: ' . $e->getMessage();
            }
        } else {
            $errors[] = 'Please select a supplier.';
        }
    } elseif ($_POST['action'] === 'issue_rfq') {
        $check_suppliers = $pdo->prepare('SELECT COUNT(*) as count FROM rfq_suppliers WHERE rfq_id = ?');
        $check_suppliers->execute([$rfq_id]);
        $result = $check_suppliers->fetch();
        
        if ($result['count'] == 0) {
            $errors[] = 'Please add at least one supplier before issuing the RFQ.';
        } else {
            try {
                $update_stmt = $pdo->prepare('UPDATE rfqs SET status = ? WHERE id = ?');
                $update_stmt->execute(['issued', $rfq_id]);
                flash('RFQ ' . rfq_no($rfq_id) . ' issued. Suppliers can now submit quotations.');
                redirect('/rfq_view.php?id=' . $rfq_id);
            } catch (PDOException $e) {
                $errors[] = 'Error issuing RFQ: ' . $e->getMessage();
            }
        }
    } elseif ($_POST['action'] === 'send_invitations') {
        if ($rfq['status'] !== 'issued') {
            $errors[] = 'RFQ must be issued before sending invitations.';
        } else {
            try {
                $update_stmt = $pdo->prepare('UPDATE rfq_suppliers SET invitation_sent = 1 WHERE rfq_id = ?');
                $update_stmt->execute([$rfq_id]);
                
                foreach ($invited_suppliers as $supplier) {
                    $token = $supplier['token'];
                    if (!$token) {
                        $token = bin2hex(random_bytes(32));
                        $pdo->prepare('UPDATE rfq_suppliers SET token = ? WHERE rfq_id = ? AND supplier_id = ?')->execute([$token, $rfq_id, $supplier['supplier_id']]);
                    }
                    
                    if ($supplier['email']) {
                        $link = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . BASE_PATH . '/quotation_public.php?token=' . $token;
                        $subject = 'Invitation to Quote: ' . rfq_no($rfq_id);
                        $htmlBody = "
                            <h2>Request for Quotation</h2>
                            <p>Hello {$supplier['name']},</p>
                            <p>You have been invited to submit a quotation for <strong>" . rfq_no($rfq_id) . "</strong>.</p>
                            <p>Click the link below to submit your quotation:</p>
                            <p><a href=\"$link\">$link</a></p>
                            <p><strong>RFQ Number:</strong> " . rfq_no($rfq_id) . "</p>
                            <p><strong>Status:</strong> Issued</p>
                        ";
                        send_email_notification(
                            $pdo,
                            $supplier['email'],
                            null,
                            $subject,
                            $htmlBody,
                            'rfq_invitation_sent',
                            'rfq',
                            $rfq_id,
                            rfq_no($rfq_id)
                        );
                    }
                }
                
                flash('Invitations sent to ' . count($invited_suppliers) . ' supplier(s).');
                redirect('/rfq_view.php?id=' . $rfq_id);
            } catch (PDOException $e) {
                $errors[] = 'Error sending invitations: ' . $e->getMessage();
            }
        }
    } elseif ($_POST['action'] === 'award_item') {
        if ($rfq['status'] !== 'issued' && $rfq['status'] !== 'evaluating') {
            $errors[] = 'RFQ must be issued or evaluating to award items.';
        } else {
            $item_id = (int)($_POST['rfq_item_id'] ?? 0);
            $quotation_id = (int)($_POST['quotation_id'] ?? 0);
            $supplier_id = (int)($_POST['supplier_id'] ?? 0);
            $qty = (float)($_POST['qty_awarded'] ?? 0);
            $unit_price = (float)($_POST['unit_price'] ?? 0);
            
            if (!$item_id || !$quotation_id || !$supplier_id || $qty <= 0 || $unit_price < 0) {
                $errors[] = 'Invalid award data.';
            } else {
                try {
                    $pdo->beginTransaction();
                    
                    $lock_stmt = $pdo->prepare('SELECT id FROM rfq_awards WHERE rfq_id = ? AND rfq_item_id = ? FOR UPDATE');
                    $lock_stmt->execute([$rfq_id, $item_id]);
                    $existing = $lock_stmt->fetch();
                    
                    if ($existing) {
                        $pdo->rollBack();
                        $errors[] = 'This item has already been awarded.';
                    } else {
                        $pdo->prepare('UPDATE quotations SET status = "awarded" WHERE id = ?')->execute([$quotation_id]);
                        $pdo->prepare('UPDATE rfqs SET status = "evaluating" WHERE id = ? AND status = "issued"')->execute([$rfq_id]);
                        $pdo->prepare('INSERT INTO rfq_awards (rfq_id, rfq_item_id, quotation_id, supplier_id, qty_awarded, unit_price) VALUES (?,?,?,?,?,?)')->execute([$rfq_id, $item_id, $quotation_id, $supplier_id, $qty, $unit_price]);
                        $pdo->commit();
                        flash('Item awarded successfully.', 'success');
                        redirect('/rfq_view.php?id=' . $rfq_id);
                    }
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    $errors[] = 'Error awarding item: ' . $e->getMessage();
                }
            }
        }
    } elseif ($_POST['action'] === 'generate_lpos') {
        try {
            $pdo->beginTransaction();
            
            $lock_stmt = $pdo->prepare('SELECT status FROM rfqs WHERE id = ? FOR UPDATE');
            $lock_stmt->execute([$rfq_id]);
            $locked_rfq = $lock_stmt->fetch();
            
            if (!$locked_rfq || $locked_rfq['status'] !== 'evaluating') {
                $pdo->rollBack();
                $errors[] = 'RFQ must be in evaluating status to generate LPOs.';
            } else {
                $unfinished = $pdo->prepare('SELECT COUNT(*) as cnt FROM rfq_items WHERE rfq_id = ? AND id NOT IN (SELECT rfq_item_id FROM rfq_awards WHERE rfq_id = ?)');
                $unfinished->execute([$rfq_id, $rfq_id]);
                if ($unfinished->fetch()['cnt'] > 0) {
                    $pdo->rollBack();
                    $errors[] = 'All items must be awarded before generating LPOs.';
                } else {
                    $me = current_user();
                    $awards_by_supplier = [];
                    foreach ($awards as $award) {
                        $awards_by_supplier[$award['supplier_id']][] = $award;
                    }
                    foreach ($awards_by_supplier as $supplier_id => $supplier_awards) {
                        $lpo_stmt = $pdo->prepare('INSERT INTO lpos (lpo_no, requisition_id, supplier_id, date, status, prepared_by) VALUES (?,?,?,?,?,?)');
                        $lpo_stmt->execute(['TEMP', $rfq['requisition_id'], $supplier_id, today(), 'pending_finance', $me['id']]);
                        $lpo_id = (int)$pdo->lastInsertId();
                        $lpo_no = doc_no_from_id('LPO', $lpo_id);
                        $pdo->prepare('UPDATE lpos SET lpo_no = ? WHERE id = ?')->execute([$lpo_no, $lpo_id]);
                        
                        $itemIns = $pdo->prepare('INSERT INTO lpo_items (lpo_id, description, unit, qty, unit_price) VALUES (?,?,?,?,?)');
                        foreach ($supplier_awards as $award) {
                            $itemIns->execute([$lpo_id, $award['description'], $award['unit'], $award['qty_awarded'], $award['unit_price']]);
                        }
                        $sig = $me['signature_path'];
                        $approvalIns = $pdo->prepare('INSERT INTO approvals (document_type, document_id, role, status, acted_by, acted_by_name, signature_path) VALUES (?,?,?,?,?,?,?)');
                        $approvalIns->execute(['lpo', $lpo_id, 'procurement', 'approved', $me['id'], $me['name'], $sig]);
                    }
                    $pdo->prepare('UPDATE rfqs SET status = "awarded" WHERE id = ?')->execute([$rfq_id]);
                    $pdo->commit();
                    flash('LPO(s) generated successfully. RFQ marked as awarded.', 'success');
                    redirect('/rfq_view.php?id=' . $rfq_id);
                }
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = 'Error generating LPOs: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'RFQ Details';
$activeNav = 'rfqs';
require __DIR__ . '/includes/header.php';
?>

<style>
  .tab-container { display:flex; border-bottom:1px solid #ddd; margin-bottom:20px; gap:0; }
  .tab-button { padding:10px 20px; cursor:pointer; border:none; background:none; color:#666; font-size:14px; border-bottom:2px solid transparent; }
  .tab-button.active { color:#333; border-bottom-color:#C9AA35; font-weight:bold; }
  .tab-content { display:none; }
  .tab-content.active { display:block; }
</style>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
  <div>
    <h1 class="page-title">RFQ <?= e(rfq_no($rfq_id)) ?></h1>
    <p class="page-sub"><?= rfq_status_badge($rfq['status']) ?></p>
  </div>
  <div>
    <?php if ($rfq['status'] === 'draft' && !empty($invited_suppliers)): ?>
      <form method="post" style="display:inline;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="issue_rfq">
        <button type="submit" class="btn gold">Issue RFQ to Suppliers</button>
      </form>
    <?php endif; ?>
    <?php if ($rfq['status'] === 'issued' && !empty($invited_suppliers)): ?>
      <form method="post" style="display:inline;" onsubmit="return confirm('Send invitation emails to all invited suppliers?');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="send_invitations">
        <button type="submit" class="btn">Send Invitations</button>
      </form>
    <?php endif; ?>
    <?php if (($rfq['status'] === 'issued' || $rfq['status'] === 'evaluating') && !empty($quotations)): ?>
      <a href="#evaluation" class="btn gold" onclick="switchTab('evaluation')">Evaluate & Award Items</a>
    <?php endif; ?>
    <a href="<?= BASE_PATH ?>/rfqs.php" class="btn">Back</a>
  </div>
</div>

<?php foreach ($errors as $err): ?>
  <div class="banner-error"><?= e($err) ?></div>
<?php endforeach; ?>

<?php if ($success_msg): ?>
  <div class="banner-success"><?= e($success_msg) ?></div>
<?php endif; ?>

<!-- Tabs -->
<div class="tab-container">
  <button class="tab-button active" onclick="switchTab('overview')">Overview</button>
  <button class="tab-button" onclick="switchTab('suppliers')">Suppliers (<?= count($invited_suppliers) ?>)</button>
  <button class="tab-button" onclick="switchTab('quotations')">Quotations (<?= count($quotations) ?>)</button>
  <?php if (!empty($quotations) && ($rfq['status'] === 'issued' || $rfq['status'] === 'evaluating')): ?>
    <button class="tab-button" onclick="switchTab('evaluation')">Evaluate (<?= $awarded_count ?>/<?= count($rfq_items) ?>)</button>
  <?php endif; ?>
</div>

<!-- Overview Tab -->
<div id="overview" class="tab-content active">
  <div class="card">
    <div class="card-header"><span class="htitle">RFQ Information</span></div>
    <div class="card-body">
      <table style="width:100%; font-size:13px;">
        <tr>
          <td style="width:25%; color:#666;">RFQ Number</td>
          <td style="font-weight:bold;"><?= e(rfq_no($rfq_id)) ?></td>
        </tr>
        <tr style="background:#f9f9f9;">
          <td style="color:#666;">Status</td>
          <td><?= rfq_status_badge($rfq['status']) ?></td>
        </tr>
        <tr>
          <td style="color:#666;">Created</td>
          <td><?= format_date($rfq['date_created']) ?></td>
        </tr>
        <?php if ($rfq['date_required']): ?>
        <tr style="background:#f9f9f9;">
          <td style="color:#666;">Date Required</td>
          <td><?= format_date($rfq['date_required']) ?></td>
        </tr>
        <?php endif; ?>
        <?php if ($rfq['notes']): ?>
        <tr>
          <td style="color:#666;">Notes</td>
          <td><?= e($rfq['notes']) ?></td>
        </tr>
        <?php endif; ?>
      </table>
    </div>
  </div>
  
  <?php if ($req_details): ?>
  <div class="card">
    <div class="card-header"><span class="htitle">Linked Requisition</span></div>
    <div class="card-body">
      <p><strong>REQ-<?= str_pad($req_details['id'], 4, '0', STR_PAD_LEFT) ?></strong> — 
         <?= e($req_details['department']) ?> (<?= format_date($req_details['date_raised']) ?>)</p>
      <p style="color:#666; margin:10px 0 0 0; font-size:13px;">
        Purpose: <?= e($req_details['purpose']) ?>
      </p>
    </div>
  </div>
  <?php endif; ?>
  
  <div class="card">
    <div class="card-header"><span class="htitle">Items Requested</span></div>
    <div class="card-body" style="padding:0;">
      <table class="list">
        <thead>
          <tr>
            <th>Description</th>
            <th>Specification</th>
            <th>Unit</th>
            <th style="text-align:right;">Qty</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rfq_items as $item): ?>
          <tr>
            <td style="padding:10px;"><?= e($item['description']) ?></td>
            <td style="padding:10px; font-size:12px; color:#666;"><?= e($item['specification'] ?: '—') ?></td>
            <td style="padding:10px;"><?= e($item['unit']) ?></td>
            <td style="padding:10px; text-align:right;">
              <strong><?= number_format($item['qty'], 0) ?></strong>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Suppliers Tab -->
<div id="suppliers" class="tab-content">
  <div class="card">
    <div class="card-header"><span class="htitle">Add Supplier</span></div>
    <div class="card-body">
      <?php if (empty($all_suppliers)): ?>
        <p style="color:#999;">All suppliers have been added. <a href="<?= BASE_PATH ?>/suppliers.php">Create new suppliers</a></p>
      <?php else: ?>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="add_supplier">
          <div style="display:flex; gap:10px;">
            <select name="supplier_id" required style="flex:1;">
              <option value="">Select supplier...</option>
              <?php foreach ($all_suppliers as $s): ?>
                <option value="<?= $s['id'] ?>"><?= e($s['name']) ?> (<?= e($s['email'] ?: 'no email') ?>)</option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn gold">Add Supplier</button>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>
  
  <?php if (!empty($invited_suppliers)): ?>
  <div class="card">
    <div class="card-header"><span class="htitle">Invited Suppliers (<?= count($invited_suppliers) ?>)</span></div>
    <div class="card-body" style="padding:0;">
      <table class="list">
        <thead>
          <tr>
            <th>Supplier</th>
            <th>Email</th>
            <th>Phone</th>
            <th>Status</th>
            <th>Invited</th>
            <th>Quotation Link</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($invited_suppliers as $s): ?>
          <tr>
            <td style="padding:10px;"><?= e($s['name']) ?></td>
            <td style="padding:10px; font-size:12px;"><?= e($s['email'] ?: '—') ?></td>
            <td style="padding:10px; font-size:12px;"><?= e($s['phone'] ?: '—') ?></td>
            <td style="padding:10px;">
              <?php 
                $quote_stmt = $pdo->prepare('SELECT status FROM quotations WHERE rfq_id = ? AND supplier_id = ?');
                $quote_stmt->execute([$rfq_id, $s['supplier_id']]);
                $quote = $quote_stmt->fetch();
                echo $quote ? rfq_status_badge($quote['status']) : '<span class="badge gray">No quote yet</span>';
              ?>
            </td>
            <td style="padding:10px; font-size:12px;">
              <?php if ($s['invitation_sent']): ?>
                <span class="badge green">Sent</span>
              <?php else: ?>
                <span class="badge gray">Not sent</span>
              <?php endif; ?>
            </td>
            <td style="padding:10px; font-size:12px;">
              <?php if ($s['token']): ?>
                <a href="<?= BASE_PATH ?>/quotation_public.php?token=<?= e($s['token']) ?>" target="_blank" class="link">Open Link</a>
              <?php else: ?>
                <span style="color:#999;">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Quotations Tab -->
<div id="quotations" class="tab-content">
  <?php if (!empty($invited_suppliers) && ($rfq['status'] === 'issued' || $rfq['status'] === 'evaluating')): ?>
    <div class="card" style="margin-bottom:20px;">
      <div class="card-body">
        <a href="<?= BASE_PATH ?>/quotation_new.php?rfq_id=<?= $rfq_id ?>" class="btn gold">+ Submit New Quotation</a>
        <span style="color:#666; margin-left:10px; font-size:13px;">Enter supplier pricing for this RFQ</span>
      </div>
    </div>
  <?php endif; ?>
  <?php if (empty($quotations)): ?>
    <div class="card">
      <div class="card-body" style="color:#999; text-align:center; padding:40px;">
        No quotations received yet.
      </div>
    </div>
  <?php else: ?>
    <div class="card">
      <div class="card-header"><span class="htitle">Supplier Quotations (<?= count($quotations) ?>)</span></div>
      <div class="card-body" style="padding:0;">
        <table class="list">
          <thead>
            <tr>
              <th>Supplier</th>
              <th>Reference</th>
              <th>Items</th>
              <th style="text-align:right;">Total Price</th>
              <th>Delivery Days</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($quotations as $q): ?>
            <tr>
              <td style="padding:10px;"><?= e($q['supplier_name']) ?></td>
              <td style="padding:10px; font-size:12px;"><?= e($q['supplier_reference'] ?: '—') ?></td>
              <td style="padding:10px;"><?= $q['item_count'] ?? 0 ?></td>
              <td style="padding:10px; text-align:right;">
                <strong><?= money($q['total_quoted'] ?? 0) ?></strong>
              </td>
              <td style="padding:10px;" align="center">
                <?= $q['delivery_days'] ? $q['delivery_days'] . ' days' : '—' ?>
              </td>
              <td style="padding:10px;"><?= rfq_status_badge($q['status']) ?></td>
              <td style="padding:10px;">
                <a href="<?= BASE_PATH ?>/quotation_view.php?id=<?= $q['id'] ?>" class="link">View</a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
</div>

<!-- Evaluation Tab -->
<?php if (!empty($quotations) && ($rfq['status'] === 'issued' || $rfq['status'] === 'evaluating')): ?>
<div id="evaluation" class="tab-content">
  <div class="card">
    <div class="card-header"><span class="htitle">Item-by-Item Evaluation</span></div>
    <div class="card-body" style="padding:0;">
      <table class="list">
        <thead>
          <tr>
            <th>Item</th>
            <th>Unit</th>
            <th style="text-align:right;">RFQ Qty</th>
            <?php foreach ($quotations as $q): ?>
              <th style="text-align:right;"><?= e($q['supplier_name']) ?></th>
            <?php endforeach; ?>
            <th>Award To</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rfq_items as $item): ?>
            <?php
              $award = $awarded_items[$item['id']] ?? null;
              $quotes_for_item = $item_quotes_by_item[$item['id']] ?? [];
              $lowest_price = null;
              if (!empty($quotes_for_item)) {
                  $lowest_price = $quotes_for_item[0]['unit_price'];
              }
            ?>
            <tr>
              <td style="padding:10px;"><?= e($item['description']) ?></td>
              <td style="padding:10px;"><?= e($item['unit'] ?: '—') ?></td>
              <td style="padding:10px; text-align:right;"><?= number_format($item['qty'], 0) ?></td>
              <?php foreach ($quotations as $q): ?>
                <?php
                  $quote_for_supplier = null;
                  foreach ($quotes_for_item as $qf) {
                      if ($qf['supplier_id'] == $q['supplier_id']) {
                          $quote_for_supplier = $qf;
                          break;
                      }
                  }
                ?>
                <td style="padding:10px; text-align:right;">
                  <?php if ($quote_for_supplier): ?>
                    <strong><?= money($quote_for_supplier['unit_price']) ?></strong>
                    <?php if ($lowest_price !== null && $quote_for_supplier['unit_price'] == $lowest_price): ?>
                      <span class="badge green" style="margin-left:4px;">Lowest</span>
                    <?php endif; ?>
                  <?php else: ?>
                    <span style="color:#999;">—</span>
                  <?php endif; ?>
                </td>
              <?php endforeach; ?>
              <td style="padding:10px;">
                <?php if ($award): ?>
                  <span class="badge green">Awarded to <?= e($award['supplier_name']) ?></span>
                <?php elseif (!empty($quotes_for_item)): ?>
                  <form method="post" style="display:inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="award_item">
                    <input type="hidden" name="rfq_item_id" value="<?= (int)$item['id'] ?>">
                    <select name="quotation_id" required style="width:auto; padding:4px; font-size:12px;">
                      <?php foreach ($quotes_for_item as $qf): ?>
                        <option value="<?= (int)$qf['quotation_id'] ?>" data-price="<?= e($qf['unit_price']) ?>" data-supplier="<?= e($qf['supplier_id']) ?>">
                          <?= e($qf['supplier_name']) ?> — <?= money($qf['unit_price']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="supplier_id" value="<?= (int)$quotes_for_item[0]['supplier_id'] ?>">
                    <input type="hidden" name="qty_awarded" value="<?= e($item['qty']) ?>">
                    <input type="hidden" name="unit_price" value="<?= e($quotes_for_item[0]['unit_price']) ?>">
                    <button type="submit" class="btn gold sm" style="margin-left:4px;">Award</button>
                  </form>
                <?php else: ?>
                  <span style="color:#999;">No quotes</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php if ($awarded_count === count($rfq_items) && count($rfq_items) > 0): ?>
    <div class="card">
      <div class="card-body">
        <form method="post" style="display:inline;" onsubmit="return confirm('Generate LPOs for all awarded items? This will create one LPO per supplier.');">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="generate_lpos">
          <button type="submit" class="btn gold">Generate LPOs</button>
        </form>
        <span style="color:#666; margin-left:10px;">All items have been awarded. Click to generate LPOs.</span>
      </div>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

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

document.querySelectorAll('.tab-content select[name="quotation_id"]').forEach(select => {
  select.addEventListener('change', function() {
    const option = this.options[this.selectedIndex];
    const form = this.closest('form');
    form.querySelector('input[name="supplier_id"]').value = option.dataset.supplier || '';
    form.querySelector('input[name="unit_price"]').value = option.dataset.price || '';
  });
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
