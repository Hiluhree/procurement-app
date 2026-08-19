<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_role(['procurement', 'admin']);

$pdo = db();
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    
    $requisition_id = (int)($_POST['requisition_id'] ?? 0);
    $date_required = trim($_POST['date_required'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    
    if (!$requisition_id) {
        $errors[] = 'Please select a requisition.';
    } else {
        // Verify requisition exists and is approved
        $req_stmt = $pdo->prepare('SELECT * FROM requisitions WHERE id = ? AND status = ?');
        $req_stmt->execute([$requisition_id, 'approved']);
        $requisition = $req_stmt->fetch();
        
        if (!$requisition) {
            $errors[] = 'Selected requisition does not exist or is not approved.';
        }
    }
    
    if ($date_required && strtotime($date_required) < strtotime('today')) {
        $errors[] = 'Date required cannot be in the past.';
    }
    
    if (!$errors) {
        try {
            // Create RFQ
            $create_stmt = $pdo->prepare('
                INSERT INTO rfqs (rfq_no, requisition_id, created_by, date_created, date_required, notes, status)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');
            $create_stmt->execute(['TEMP', $requisition_id, current_user()['id'], today(), $date_required ?: null, $notes ?: null, 'draft']);
            
            $rfq_id = $pdo->lastInsertId();
            $rfq_no = doc_no_from_id('RFQ', $rfq_id);
            $pdo->prepare('UPDATE rfqs SET rfq_no = ? WHERE id = ?')->execute([$rfq_no, $rfq_id]);
            
            // Copy items from requisition to RFQ
            $items_stmt = $pdo->prepare('SELECT * FROM requisition_items WHERE requisition_id = ?');
            $items_stmt->execute([$requisition_id]);
            $items = $items_stmt->fetchAll();
            
            $insert_item_stmt = $pdo->prepare('
                INSERT INTO rfq_items (rfq_id, description, specification, unit, qty)
                VALUES (?, ?, ?, ?, ?)
            ');
            
            foreach ($items as $item) {
                $insert_item_stmt->execute([
                    $rfq_id,
                    $item['description'],
                    $item['specification'],
                    $item['unit'],
                    $item['qty']
                ]);
            }
            
            flash('RFQ ' . rfq_no($rfq_id) . ' created successfully. Now add suppliers to invite.');
            redirect('/rfq_view.php?id=' . $rfq_id);
        } catch (PDOException $e) {
            $errors[] = 'Error creating RFQ: ' . $e->getMessage();
        }
    }
}

// Get approved requisitions without RFQs
$requisitions = $pdo->query('
    SELECT r.* FROM requisitions r
    WHERE r.status = "approved" AND r.id NOT IN (SELECT DISTINCT requisition_id FROM rfqs WHERE requisition_id IS NOT NULL)
    ORDER BY r.req_no DESC
')->fetchAll();

$pageTitle = 'Create RFQ';
$activeNav = 'rfqs';
require __DIR__ . '/includes/header.php';
?>

<h1 class="page-title">Create Request for Quotation</h1>
<p class="page-sub">Create a new RFQ from an approved requisition</p>

<?php foreach ($errors as $err): ?>
  <div class="banner-error"><?= e($err) ?></div>
<?php endforeach; ?>

<?php if (empty($requisitions)): ?>
  <div class="card">
    <div class="card-body" style="color:#999; text-align:center; padding:40px;">
      No approved requisitions available for RFQ.<br>
      <a href="<?= BASE_PATH ?>/requisitions.php">View requisitions</a>
    </div>
  </div>
<?php else: ?>
  <div class="card">
    <div class="card-header"><span class="htitle">RFQ Details</span></div>
    <div class="card-body">
      <form method="post">
        <?= csrf_field() ?>
        
        <label class="field-label">Requisition <span style="color:red;">*</span></label>
        <select name="requisition_id" required>
          <option value="">Select requisition...</option>
          <?php foreach ($requisitions as $r): ?>
            <option value="<?= $r['id'] ?>">
              REQ-<?= str_pad($r['id'], 4, '0', STR_PAD_LEFT) ?> — 
              <?= e($r['department']) ?> (<?= $r['date_raised'] ?>)
            </option>
          <?php endforeach; ?>
        </select>
        
        <label class="field-label">Date Required (optional)</label>
        <input type="date" name="date_required">
        
        <label class="field-label">Notes (optional)</label>
        <textarea name="notes" rows="4" placeholder="Special instructions for quotations..."></textarea>
        
        <button type="submit" class="btn gold" style="margin-top:14px;">Create RFQ</button>
        <a href="<?= BASE_PATH ?>/rfqs.php" class="btn" style="margin-left:8px;">Cancel</a>
      </form>
    </div>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
