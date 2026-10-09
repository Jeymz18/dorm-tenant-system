<?php
if (!defined('BASE_URL')) { http_response_code(403); exit('Direct access not permitted.'); }

/**
 * Shared page header: breadcrumb, icon, title, subtitle, and optional action.
 */
function render_page_header(
  string $icon,
  string $title,
  string $subtitle,
  ?string $actionHtml = null,
  string $portalLabel = 'Admin Portal',
  string $portalPath = '/admin/dashboard.php'
): void
{
    ?>
  <div class="breadcrumb-trail"><a class="breadcrumb-parent" href="<?= clean(BASE_URL . $portalPath) ?>"><?= clean($portalLabel) ?></a> <i class="bi bi-chevron-right"></i> <span class="breadcrumb-current"><?= clean($title) ?></span></div>
    <div class="page-header">
      <div class="page-header-main">
        <span class="icon-wrapper icon-box page-icon-avatar"><i class="bi <?= clean($icon) ?>"></i></span>
        <div>
          <div class="page-title-row"><h1><?= clean($title) ?></h1></div>
          <p class="text-muted"><?= clean($subtitle) ?></p>
        </div>
      </div>
      <?php if ($actionHtml): ?><div class="page-header-action"><?= $actionHtml ?></div><?php endif; ?>
    </div>
    <?php
}
