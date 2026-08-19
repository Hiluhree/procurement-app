<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_role(['admin']);

$pdo = db();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $stage = $_POST['stage'] ?? '';
    $assigned_user_id = (int)($_POST['assigned_user_id'] ?? 0) ?: null;
    
    if (!$stage) {
        $errors[] = 'Invalid workflow stage.';
    } else {
        try {
            $update_stmt = $pdo->prepare('
                UPDATE workflow_config 
                SET assigned_user_id = ?, updated_by = ?, updated_at = NOW()
                WHERE stage = ?
            ');
            $update_stmt->execute([$assigned_user_id, current_user()['id'], $stage]);
            flash('Workflow configuration updated.');
            redirect('/workflow_config.php');
        } catch (PDOException $e) {
            $errors[] = 'Error updating configuration: ' . $e->getMessage();
        }
    }
}

// Get workflow configuration
$config_stmt = $pdo->query('
    SELECT wc.*, u.name as assigned_user_name
    FROM workflow_config wc
    LEFT JOIN users u ON wc.assigned_user_id = u.id
    ORDER BY wc.sequence_order
');
$workflow_config = $config_stmt->fetchAll();

// Get all users with relevant roles
$users_stmt = $pdo->query('
    SELECT * FROM users 
    WHERE role IN ("procurement", "finance", "principal") AND is_active = 1
    ORDER BY role, name
');
$all_users = $users_stmt->fetchAll();

$pageTitle = 'LPO Workflow Configuration';
$activeNav = 'workflow_config';
require __DIR__ . '/includes/header.php';
?>

<h1 class="page-title">LPO Workflow Configuration</h1>
<p class="page-sub">Assign users to each LPO approval stage</p>

<div style="background:#fff9e6; border:1px solid #ffb84d; border-radius:4px; padding:12px; margin-bottom:20px;">
  <strong style="color:#cc7f00;">ℹ️ Workflow Process:</strong>
  <p style="margin:8px 0 0 0; font-size:13px;">
    Each LPO must be approved through these sequential stages. The assigned user will be notified when an LPO reaches their stage.
  </p>
</div>

<?php foreach ($errors as $err): ?>
  <div class="banner-error"><?= e($err) ?></div>
<?php endforeach; ?>

<div class="card">
  <div class="card-body" style="padding:0;">
    <table class="list">
      <thead>
        <tr>
          <th>Stage</th>
          <th>Description</th>
          <th>Assigned User</th>
          <th>Role</th>
          <th>Last Updated</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($workflow_config as $stage_config): ?>
        <tr>
          <td style="padding:12px; font-weight:bold;">
            <?= ucfirst(str_replace('_', ' ', $stage_config['stage'])) ?>
          </td>
          <td style="padding:12px; font-size:12px; color:#666;">
            <?= e($stage_config['description']) ?>
          </td>
          <td style="padding:12px;">
            <?= $stage_config['assigned_user_name'] ? e($stage_config['assigned_user_name']) : '<em style="color:#999;">Unassigned</em>' ?>
          </td>
          <td style="padding:12px; font-size:12px;">
            <?php if ($stage_config['assigned_user_id']): ?>
              <span class="badge blue"><?= e(role_label($stage_config['assigned_role'])) ?></span>
            <?php else: ?>
              <span class="badge gray">—</span>
            <?php endif; ?>
          </td>
          <td style="padding:12px; font-size:12px;">
            <?= $stage_config['updated_at'] ? format_datetime($stage_config['updated_at']) : '—' ?>
          </td>
          <td style="padding:12px;">
            <button type="button" class="link" onclick="openAssignModal('<?= e($stage_config['stage']) ?>', <?= $stage_config['assigned_user_id'] ?? 'null' ?>)">
              <?= $stage_config['assigned_user_id'] ? 'Change' : 'Assign' ?>
            </button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Assignment Modal -->
<div id="assignModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:999; align-items:center; justify-content:center;">
  <div style="background:white; border-radius:4px; width:90%; max-width:400px; box-shadow:0 4px 12px rgba(0,0,0,0.15);">
    <div style="padding:16px; border-bottom:1px solid #eee;">
      <h2 style="margin:0; font-size:18px;">Assign User to Stage</h2>
    </div>
    <form method="post" style="padding:16px;">
      <?= csrf_field() ?>
      <input type="hidden" id="stageInput" name="stage">
      
      <label class="field-label">Select User</label>
      <select id="userSelect" name="assigned_user_id">
        <option value="">— Unassigned (use default role) —</option>
        <?php
          $grouped = [];
          foreach ($all_users as $user) {
              $role = $user['role'];
              if (!isset($grouped[$role])) $grouped[$role] = [];
              $grouped[$role][] = $user;
          }
          foreach ($grouped as $role => $users):
        ?>
          <optgroup label="<?= e(role_label($role)) ?>">
            <?php foreach ($users as $user): ?>
              <option value="<?= $user['id'] ?>"><?= e($user['name']) ?> (<?= e($user['username']) ?>)</option>
            <?php endforeach; ?>
          </optgroup>
        <?php endforeach; ?>
      </select>
      
      <div style="display:flex; gap:8px; margin-top:20px;">
        <button type="submit" class="btn gold" style="flex:1;">Save Assignment</button>
        <button type="button" class="btn" style="flex:1;" onclick="closeAssignModal()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<div style="margin-top:20px; padding:12px; background:#f5f5f5; border-radius:4px; font-size:13px; color:#666;">
  <strong>Notes:</strong>
  <ul style="margin:8px 0 0 0; padding-left:20px;">
    <li>Leave unassigned to allow any user with that role to approve</li>
    <li>Notifications are sent to the assigned user when an LPO reaches their stage</li>
    <li>Only active users with procurement/finance/principal roles can be assigned</li>
  </ul>
</div>

<script>
function openAssignModal(stage, userId) {
  document.getElementById('stageInput').value = stage;
  document.getElementById('userSelect').value = userId || '';
  document.getElementById('assignModal').style.display = 'flex';
}

function closeAssignModal() {
  document.getElementById('assignModal').style.display = 'none';
}

// Close modal when clicking outside of it
document.getElementById('assignModal').onclick = function(e) {
  if (e.target === this) closeAssignModal();
};
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
