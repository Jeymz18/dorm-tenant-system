<?php
if (!defined('BASE_URL')) { http_response_code(403); exit('Direct access not permitted.'); }
/**
 * includes/sidebar.php
 * Renders ONLY the nav links for the current session's role. An admin
 * session never sees tenant links (and vice versa) because the other
 * branch simply never runs — there's no hiding-via-CSS involved.
 * The real access control still happens in require_role() on each
 * page; this is just what makes the UI match that.
 *
 * Admin navigation stays at one level. Module pages render their own
 * persistent horizontal tabs below the page header.
 */
$role = current_role();
$here = $_SERVER['REQUEST_URI'] ?? '';

// [icon, module label, default page, related pages]
$adminLinks = [
  ['<i class="bi bi-person-gear"></i>', 'User Management', '/admin/users.php', ['/admin/users.php', '/admin/credentials.php', '/admin/manage-tenants.php']],
  ['<i class="bi bi-people-fill"></i>', 'Tenant Management', '/admin/tenants.php', ['/admin/tenants.php', '/admin/tenant-status.php', '/admin/checkinout.php']],
  ['<i class="bi bi-door-open-fill"></i>', 'Property Management', '/admin/rooms.php', ['/admin/rooms.php']],
  ['<i class="bi bi-wallet2"></i>', 'Payments & Contracts', '/admin/payments.php', ['/admin/payments.php']],
  ['<i class="bi bi-tools"></i>', 'Maintenance', '/admin/maintenance.php', ['/admin/maintenance.php']],
  ['<i class="bi bi-bell-fill"></i>', 'Notifications', '/admin/notifications.php', ['/admin/notifications.php']],
  ['<i class="bi bi-bar-chart-line-fill"></i>', 'Reports & Analytics', '/admin/reports.php', ['/admin/reports.php']],
];

$tenantLinks = [
    ['<i class="bi bi-house-door-fill"></i>', 'Home', '/tenant/dashboard.php'],
    ['<i class="bi bi-file-earmark-text"></i>', 'Services', '/tenant/services.php'],
    ['<i class="bi bi-credit-card-fill"></i>', 'Payments', '/tenant/payments.php'],
    ['<i class="bi bi-tools"></i>', 'Maintenance', '/tenant/maintenance.php'],
    ['<i class="bi bi-bell-fill"></i>', 'Notifications', '/tenant/notifications.php'],
    ['<i class="bi bi-person-fill"></i>', 'Profile', '/tenant/profile.php'],
];
?>
<nav class="sidebar no-scrollbar" id="sidebar">
  <div class="sidebar-brand">
    <span class="brand-icon"><i class="bi bi-mortarboard-fill"></i></span>
    <div class="sidebar-brand-text"><div class="sidebar-brand-title">Dorm Tenant</div><div class="sidebar-brand-subtitle">Management System</div></div>
  </div>

  <?php if (is_admin_role($role)): ?>
  <div class="topbar-toggle d-lg-none">
    <a class="pill-active" href="<?= BASE_URL ?>/admin/dashboard.php">Admin Dashboard</a>
  </div>
  <?php elseif ($role === 'tenant'): ?>
  <div class="topbar-toggle d-lg-none">
    <a class="pill-active" href="<?= BASE_URL ?>/tenant/dashboard.php">Tenant App</a>
  </div>
  <?php endif; ?>

  <div class="sidebar-links">
  <?php if (is_admin_role($role)): ?>
    <div class="sidebar-section">Overview</div>
    <a class="sidebar-link <?= active('/admin/dashboard.php') ?>" href="<?= BASE_URL ?>/admin/dashboard.php"><span class="icon-wrapper nav-icon"><i class="bi bi-grid-1x2-fill"></i></span> Dashboard</a>
    <?php $sectionLabels = [0 => 'Management', 3 => 'Finance', 4 => 'Operations', 6 => 'Reports']; ?>
    <?php foreach ($adminLinks as $index => [$icon, $label, $page, $relatedPages]):
        $moduleActive = false;
        foreach ($relatedPages as $relatedPage) {
            if (strpos($here, $relatedPage) !== false) { $moduleActive = true; break; }
        }
        if (isset($sectionLabels[$index])): ?><div class="sidebar-section"><?= clean($sectionLabels[$index]) ?></div><?php endif;
    ?>
      <a class="sidebar-link <?= $moduleActive ? 'active' : '' ?>" href="<?= BASE_URL . $page ?>"><span class="icon-wrapper nav-icon"><?= $icon ?></span> <?= clean($label) ?><?php if ($label === 'Tenant Management'): ?><span class="nav-unread-badge hidden" id="nav-badge-tenants" data-badge-type="badge-tenants" data-endpoint="<?= BASE_URL ?>/api/get_unread_notifications.php" aria-label="Pending tenants">0</span><?php elseif ($label === 'Payments & Contracts'): ?><span class="nav-unread-badge hidden" id="nav-badge-payments" data-badge-type="badge-payments" data-endpoint="<?= BASE_URL ?>/api/get_unread_notifications.php" aria-label="Pending payments">0</span><?php elseif ($label === 'Maintenance'): ?><span class="nav-unread-badge hidden" id="nav-badge-maintenance" data-badge-type="badge-maintenance" data-endpoint="<?= BASE_URL ?>/api/get_unread_notifications.php" aria-label="Pending maintenance">0</span><?php elseif ($label === 'Notifications'): ?><span class="nav-unread-dot hidden" id="nav-dot-notifications" data-badge-type="dot-notifications" aria-hidden="true"></span><span class="nav-unread-badge hidden" id="nav-badge-notifications" data-badge-type="badge-notifications" data-endpoint="<?= BASE_URL ?>/api/get_unread_notifications.php" aria-label="Unread notifications">0</span><?php endif; ?></a>
    <?php endforeach; ?>

  <?php elseif ($role === 'tenant'): ?>
    <?php foreach ($tenantLinks as [$icon, $label, $page]): ?>
      <a class="sidebar-link <?= active($page) ?>" href="<?= BASE_URL . $page ?>"><span class="icon-wrapper nav-icon"><?= $icon ?></span> <?= clean($label) ?><?php if ($label === 'Payments'): ?><span class="nav-unread-badge hidden" id="nav-badge-payments" data-badge-type="badge-payments" data-endpoint="<?= BASE_URL ?>/api/get_unread_notifications.php" aria-label="Pending payments">0</span><?php elseif ($label === 'Maintenance'): ?><span class="nav-unread-badge hidden" id="nav-badge-maintenance" data-badge-type="badge-maintenance" data-endpoint="<?= BASE_URL ?>/api/get_unread_notifications.php" aria-label="Pending maintenance">0</span><?php elseif ($label === 'Notifications'): ?><span class="nav-unread-dot hidden" id="nav-dot-notifications" data-badge-type="dot-notifications" aria-hidden="true"></span><span class="nav-unread-badge hidden" id="nav-badge-notifications" data-badge-type="badge-notifications" data-endpoint="<?= BASE_URL ?>/api/get_unread_notifications.php" aria-label="Unread notifications">0</span><?php endif; ?></a>
    <?php endforeach; ?>
  <?php endif; ?>
  </div>

  <div class="sidebar-user">
    <div class="user-avatar"><?= clean(strtoupper(substr($_SESSION['first_name'] ?? '?', 0, 1))) ?></div>
    <div class="user-meta">
      <div class="user-name"><?= clean(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?></div>
      <div class="user-role"><?= clean(ucfirst($role ?? '')) ?></div>
    </div>
  </div>
  <form method="post" action="<?= BASE_URL ?>/auth/logout.php" class="sidebar-logout-form"><?= csrf_field() ?><button type="submit" class="btn-logout"><i class="bi bi-box-arrow-right"></i> Log Out</button></form>
</nav>
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>
