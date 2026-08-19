<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$pdo = db();
$me = current_user();
$error = '';

// Admins may manage any approver's signature; everyone else manages only their own.
$targetId = (int)($_GET['user_id'] ?? $me['id']);
if ($targetId !== (int)$me['id'] && $_SESSION['user_role'] !== 'admin') {
    $targetId = (int)$me['id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $postTarget = (int)($_POST['user_id'] ?? $me['id']);
    if ($postTarget !== (int)$me['id'] && $_SESSION['user_role'] !== 'admin') {
        $postTarget = (int)$me['id'];
    }

    if (!empty($_FILES['signature']['name'])) {
        $file = $_FILES['signature'];
        $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif'];
        $mime = mime_content_type($file['tmp_name']);

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error = 'Upload failed. Please try again.';
        } elseif ($file['size'] > MAX_UPLOAD_BYTES) {
            $error = 'Image is too large (max 2MB).';
        } elseif (!isset($allowed[$mime])) {
            $error = 'Please upload a PNG, JPG, or GIF image.';
        } else {
            if (!is_dir(UPLOAD_DIR)) {
                mkdir(UPLOAD_DIR, 0755, true);
            }
            $ext = $allowed[$mime];
            $filename = 'user_' . $postTarget . '_' . time() . '.' . $ext;
            $dest = UPLOAD_DIR . '/' . $filename;
            if (move_uploaded_file($file['tmp_name'], $dest)) {
                $stmt = $pdo->prepare('UPDATE users SET signature_path = ? WHERE id = ?');
                $stmt->execute([UPLOAD_URL . '/' . $filename, $postTarget]);
                flash('Signature/stamp updated.');
                redirect('/signatories.php' . ($postTarget !== (int)$me['id'] ? '?user_id=' . $postTarget : ''));
            } else {
                $error = 'Could not save the uploaded file.';
            }
        }
    }

    if (isset($_POST['remove_signature'])) {
        $stmt = $pdo->prepare('UPDATE users SET signature_path = NULL WHERE id = ?');
        $stmt->execute([$postTarget]);
        flash('Signature/stamp removed.');
        redirect('/signatories.php' . ($postTarget !== (int)$me['id'] ? '?user_id=' . $postTarget : ''));
    }
}

// list of approvers, for admin view
$approvers = [];
if ($_SESSION['user_role'] === 'admin') {
    $approvers = $pdo->query("SELECT * FROM users WHERE role IN ('procurement','finance','principal') ORDER BY role")->fetchAll();
}

$target = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$target->execute([$targetId]);
$target = $target->fetch();

$pageTitle = 'Signatories';
$activeNav = 'signatories';
require __DIR__ . '/includes/header.php';
?>

<h1 class="page-title">Signature / Stamp</h1>
<p class="page-sub">Upload once — it is applied automatically whenever you approve a requisition or purchase order.</p>

<?php if ($error): ?><div class="banner-error"><?= e($error) ?></div><?php endif; ?>

<?php if ($approvers): ?>
<div class="card">
  <div class="card-header"><span class="htitle">Approvers</span></div>
  <div class="card-body">
    <table class="list">
      <thead><tr><th>Name</th><th>Role</th><th>Signature</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($approvers as $a): ?>
        <tr>
          <td><?= e($a['name']) ?></td>
          <td><?= e(role_label($a['role'])) ?></td>
          <td><?= $a['signature_path'] ? '<span class="badge green">Uploaded</span>' : '<span class="badge gray">None</span>' ?></td>
          <td><a class="btn secondary sm" href="?user_id=<?= (int)$a['id'] ?>">Manage</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header"><span class="htitle">Managing: <?= e($target['name']) ?> (<?= e(role_label($target['role'])) ?>)</span></div>
  <div class="card-body">
    <div class="signatory-card">
      <div class="sig-preview">
        <?php if ($target['signature_path']): ?>
          <img src="<?= e($target['signature_path']) ?>" alt="signature">
        <?php else: ?>
          <div class="none">No stamp<br>uploaded</div>
        <?php endif; ?>
      </div>
      <div style="flex:1;min-width:200px;">
        <div style="font-size:12px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:700;"><?= e($target['title'] ?: role_label($target['role'])) ?></div>
        <form method="post" enctype="multipart/form-data" style="margin-top:8px;">
          <?= csrf_field() ?>
          <input type="hidden" name="user_id" value="<?= (int)$target['id'] ?>">
          <input type="file" name="signature" accept="image/png,image/jpeg,image/gif" required>
          <button type="submit" class="btn gold sm" style="margin-top:8px;"><?= $target['signature_path'] ? 'Replace image' : 'Upload image' ?></button>
        </form>
        <?php if ($target['signature_path']): ?>
          <form method="post" style="margin-top:6px;" onsubmit="return confirm('Remove this signature/stamp?');">
            <?= csrf_field() ?>
            <input type="hidden" name="user_id" value="<?= (int)$target['id'] ?>">
            <button type="submit" name="remove_signature" value="1" class="btn red sm">Remove</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
    <p style="font-size:12px;color:var(--muted);margin-top:10px;">Recommended: a scanned signature or office stamp on a plain background, PNG or JPG, under 2MB.</p>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
