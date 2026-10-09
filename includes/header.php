<?php if (!defined('BASE_URL')) { http_response_code(403); exit('Direct access not permitted.'); } ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= isset($pageTitle) ? clean($pageTitle) . ' · ' : '' ?><?= SITE_NAME ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/style.css?v=<?= @filemtime(__DIR__ . '/../assets/css/style.css') ?: '1' ?>" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/custom-select.css?v=<?= @filemtime(__DIR__ . '/../assets/css/custom-select.css') ?: '1' ?>" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/app-theme.css?v=<?= @filemtime(__DIR__ . '/../assets/css/app-theme.css') ?: '1' ?>" rel="stylesheet">
</head>
<body>
<?php if (is_logged_in()): ?>
<div class="app-shell">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <main class="app-content">
    <div class="mobile-topbar d-lg-none">
      <button class="btn-menu-toggle" id="menuToggle" type="button" aria-label="Open menu"><i class="bi bi-list"></i></button>
      <span class="mobile-title"><?= is_admin_role() ? 'Admin Portal' : 'Tenant Portal' ?></span>
    </div>
    <?php if ($msg = flash('success')): ?>
      <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?= clean($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endif; ?>
    <?php if ($msg = flash('error')): ?>
      <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?= clean($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endif; ?>
<?php else: ?>
<main>
<?php endif; ?>
