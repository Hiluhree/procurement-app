<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_role(['procurement', 'admin']);

$pdo = db();

// Get all RFQs
$rfqs = $pdo->query('
    SELECT r.*, 
           COUNT(DISTINCT rs.supplier_id) as supplier_count,
           COUNT(DISTINCT q.id) as quotation_count
    FROM rfqs r
    LEFT JOIN rfq_suppliers rs ON r.id = rs.rfq_id
    LEFT JOIN quotations q ON r.id = q.rfq_id
    GROUP BY r.id
    ORDER BY r.created_at DESC
')->fetchAll();

$pageTitle = 'Request for Quotation (RFQ)';
$activeNav = 'rfqs';
require __DIR__ . '/includes/header.php';
?>

<h1 class="page-title">Request for Quotation</h1>
<p class="page-sub">Manage supplier RFQ process and quotation evaluation</p>

<div class="card" style="margin-bottom:20px;">
  <div class="card-body">
    <a href="<?= BASE_PATH ?>/rfq_new.php" class="btn gold">+ Create New RFQ</a>
    <span style="color:#666; margin-left:10px;">Create RFQ from approved requisitions</span>
  </div>
</div>

<?php if (empty($rfqs)): ?>
  <div class="card">
    <div class="card-body" style="color:#999; text-align:center; padding:40px;">
      No RFQs yet. <a href="<?= BASE_PATH ?>/rfq_new.php">Create one</a> from an approved requisition.
    </div>
  </div>
<?php else: ?>
  <div class="card">
    <div class="card-body" style="padding:0;">
      <table class="list">
        <thead>
          <tr>
            <th>RFQ No.</th>
            <th>Requisition</th>
            <th>Status</th>
            <th>Suppliers Invited</th>
            <th>Quotations</th>
            <th>Created</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rfqs as $rfq): ?>
          <tr>
            <td style="padding:10px;"><strong><?= e(rfq_no($rfq['id'])) ?></strong></td>
            <td style="padding:10px;">
              <?php if ($rfq['requisition_id']): ?>
                REQ-<?= str_pad($rfq['requisition_id'], 4, '0', STR_PAD_LEFT) ?>
              <?php else: ?>
                <em style="color:#999;">—</em>
              <?php endif; ?>
            </td>
            <td style="padding:10px;"><?= rfq_status_badge($rfq['status']) ?></td>
            <td style="padding:10px;" align="center">
              <strong><?= $rfq['supplier_count'] ?></strong>
            </td>
            <td style="padding:10px;" align="center">
              <strong><?= $rfq['quotation_count'] ?></strong>
            </td>
            <td style="padding:10px;"><?= format_date($rfq['date_created']) ?></td>
            <td style="padding:10px;">
               <a href="<?= BASE_PATH ?>/rfq_view.php?id=<?= $rfq['id'] ?>" class="link">View</a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
