<?php
require_once __DIR__ . '/../config/app.php';
require_role('admin');

$pageTitle = 'Reports & Analytics';
include __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/module_tabs.php';
require_once __DIR__ . '/../includes/page_header.php';

$categories = [
    'occupancy'   => ['title' => 'Occupancy Reports',   'reports' => [
        ['icon' => '<i class="bi bi-door-open-fill"></i>', 'title' => 'Monthly Occupancy Report', 'desc' => 'Detailed occupancy statistics, vacancy rates, and room turnover for the current month.'],
    ]],
    'payment'     => ['title' => 'Payment Reports',     'reports' => [
        ['icon' => '<i class="bi bi-wallet2"></i>', 'title' => 'Monthly Payment Report', 'desc' => 'Payment collection summary, outstanding balances, and revenue breakdown.'],
    ]],
    'tenant'      => ['title' => 'Tenant Reports',      'reports' => [
        ['icon' => '<i class="bi bi-people-fill"></i>', 'title' => 'Tenant Directory', 'desc' => 'Complete list of all current tenants with contact information and room assignments.'],
    ]],
    'maintenance' => ['title' => 'Maintenance Reports', 'reports' => [
        ['icon' => '<i class="bi bi-wrench-adjustable"></i>', 'title' => 'Maintenance Summary Report', 'desc' => 'Overview of all maintenance requests, completion rates, and pending tasks.'],
    ]],
];
?>
<?php render_page_header('bi-bar-chart-line-fill', 'Reports & Analytics', 'Generate and export comprehensive reports for property management.'); ?>
<?php render_module_tabs([
  ['key' => 'all', 'label' => 'All Reports', 'href' => '/admin/reports.php#all-reports', 'target' => 'all-reports'],
  ['key' => 'occupancy', 'label' => 'Occupancy', 'href' => '/admin/reports.php#occupancy', 'target' => 'occupancy'],
  ['key' => 'payment', 'label' => 'Payments', 'href' => '/admin/reports.php#payment', 'target' => 'payment'],
  ['key' => 'tenant', 'label' => 'Tenants', 'href' => '/admin/reports.php#tenant', 'target' => 'tenant'],
  ['key' => 'maintenance', 'label' => 'Maintenance', 'href' => '/admin/reports.php#maintenance', 'target' => 'maintenance'],
], 'all'); ?>

<div class="report-category-grid anchor-target" id="all-reports">
  <?php foreach ($categories as $key => $cat): ?>
    <section class="report-category anchor-target" id="<?= $key ?>">
      <div class="panel-header"><h2><?= clean($cat['title']) ?></h2><span class="selection-state badge badge-maroon" hidden>Selected</span></div>
      <?php foreach ($cat['reports'] as $r): ?>
        <div class="report-card">
          <div class="report-card-top">
            <div class="icon-wrapper icon-box report-card-icon"><?= $r['icon'] ?></div>
            <div><h3><?= clean($r['title']) ?></h3><p><?= clean($r['desc']) ?></p></div>
          </div>
          <div class="report-card-actions">
            <a href="<?= BASE_URL ?>/admin/report_print.php?type=<?= $key ?>&mode=view" class="btn btn-action-primary js-review-btn" data-review-url="<?= BASE_URL ?>/admin/report_print.php?type=<?= $key ?>&mode=view"><i class="bi bi-eye-fill"></i> Review</a>
            <a href="<?= BASE_URL ?>/admin/report_print.php?type=<?= $key ?>" target="_blank" class="btn btn-sm btn-action-outline"><i class="bi bi-printer-fill"></i> Print</a>
            <a href="<?= BASE_URL ?>/admin/report_export.php?type=<?= $key ?>" class="btn btn-sm btn-action-outline"><i class="bi bi-file-earmark-pdf-fill"></i> Export PDF</a>
          </div>
        </div>
      <?php endforeach; ?>
    </section>
  <?php endforeach; ?>
</div>

<div class="report-review-overlay" id="reportReviewOverlay" hidden>
  <div class="report-review-panel">
    <div class="report-review-header">
      <button type="button" class="report-review-close" id="reportReviewClose" aria-label="Close review">&times;</button>
    </div>
    <iframe class="report-review-frame" id="reportReviewFrame" src="about:blank" title="Report review"></iframe>
  </div>
</div>

<?php
$extraScripts = "<script>
(function () {
  var overlay = document.getElementById('reportReviewOverlay');
  var frame = document.getElementById('reportReviewFrame');
  var closeBtn = document.getElementById('reportReviewClose');
  function openReview(url) {
    frame.src = url;
    overlay.hidden = false;
    document.body.style.overflow = 'hidden';
  }
  function closeReview() {
    overlay.hidden = true;
    frame.src = 'about:blank';
    document.body.style.overflow = '';
  }
  document.querySelectorAll('.js-review-btn').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      openReview(btn.dataset.reviewUrl);
    });
  });
  closeBtn.addEventListener('click', closeReview);
  overlay.addEventListener('click', function (e) {
    if (e.target === overlay) closeReview();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !overlay.hidden) closeReview();
  });
})();
</script>";
include __DIR__ . '/../includes/footer.php';

?>
<script>
(function () {
  // Highlight Active Report Category from Hash / Module Tabs
  function highlightCategory() {
    var hash = window.location.hash.replace('#', '');
    var categories = document.querySelectorAll('.report-category');
    
    categories.forEach(function (cat) {
      if (hash === 'all-reports' || hash === '' || cat.id === hash) {
        cat.classList.add('selected');
      } else {
        cat.classList.remove('selected');
      }
    });
  }

  window.addEventListener('hashchange', highlightCategory);
  highlightCategory();
})();
</script>