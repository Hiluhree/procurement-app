<?php
/**
 * Shared layout header. Include after require_login().
 * Expects $pageTitle (string) and optionally $activeNav (string key) to be set
 * by the including page before this file is required.
 */
require_once __DIR__ . '/functions.php';
$me = current_user();
$role = $_SESSION['user_role'] ?? '';
$activeNav = $activeNav ?? '';

/** Nav items: key => [label, icon, href, roles allowed (empty = all logged-in)] */
$navGroups = [
    'Overview' => [
        'dashboard' => ['Dashboard', '&#9679;', '/index.php', []],
    ],
    'Procurement Workflow' => [
        'requisitions' => ['Requisitions', '&#128203;', '/requisitions.php', []],
        'rfqs'         => ['RFQ & Quotations', '&#128221;', '/rfqs.php', ['procurement','admin']],
        'lpos'         => ['Purchase Orders', '&#128230;', '/lpos.php', []],
        'grns'         => ['Goods Received', '&#128666;', '/grns.php', ['procurement','admin']],
        'invoices'     => ['Invoices & Payment', '&#128179;', '/invoices.php', ['procurement','finance','admin']],
    ],
    'Setup' => [
        'suppliers'      => ['Suppliers', '&#127970;', '/suppliers.php', ['procurement','admin']],
        'signatories'    => ['My Signature', '&#9997;', '/signatories.php', []],
        'users'          => ['Users', '&#128100;', '/users.php', ['admin']],
        'workflow_config' => ['LPO Workflow', '&#9881;', '/workflow_config.php', ['admin']],
        'admin_manage'   => ['Manage Lists', '&#9881;', '/admin_manage.php', ['admin','procurement']],
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle ?? 'Procurement') ?> — <?= e(SCHOOL_NAME) ?></title>
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/style.css">
</head>
<body>
<div class="app-shell">
  <aside class="sidebar">
    <div class="sidebar-brand">
      <div class="crest">SSS</div>
      <div>
        <div class="title">SUNSHINE SECONDARY<br>SCHOOL</div>
        <div class="subtitle">Procurement System</div>
      </div>
    </div>
    <nav class="sidebar-nav">
      <?php foreach ($navGroups as $groupLabel => $navItems): ?>
        <?php
          $visibleNavItems = array_filter($navItems, function ($navItem) use ($role) {
              return empty($navItem[3]) || in_array($role, $navItem[3], true);
          });
          if (empty($visibleNavItems)) continue;
        ?>
        <div class="group-label"><?= e($groupLabel) ?></div>
        <?php foreach ($visibleNavItems as $navKey => [$navLabel, $navIcon, $navHref, $navRoles]): ?>
          <a href="<?= BASE_PATH . $navHref ?>" class="<?= $activeNav === $navKey ? 'active' : '' ?>">
            <span class="ic"><?= $navIcon ?></span><?= e($navLabel) ?>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-user">
      <div class="who"><?= e($me['name'] ?? '') ?></div>
      <div class="role"><?= e(role_label($role)) ?></div>
      <a href="<?= BASE_PATH ?>/logout.php">Log out</a>
    </div>
  </aside>
  <div class="content">
    <main>
      <?php $f = get_flash(); if ($f): ?>
        <div class="banner-<?= $f['type'] === 'error' ? 'error' : 'success' ?>"><?= e($f['msg']) ?></div>
      <?php endif; ?>
