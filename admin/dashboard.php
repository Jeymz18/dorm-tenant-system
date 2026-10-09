<?php
require_once __DIR__ . '/../config/app.php';
require_role('admin');

$db = get_db();
refresh_contract_statuses($db);

// =====================================================================
// PERMANENT TOP KPI ROW (always visible, independent of the sub-tab)
// =====================================================================
$billedThisMonth = (float) $db->query("
    SELECT COALESCE(SUM(payment_amount),0) t FROM payments
    WHERE MONTH(due_date) = MONTH(CURDATE()) AND YEAR(due_date) = YEAR(CURDATE())
")->fetch()['t'];
$billedLastMonth = (float) $db->query("
    SELECT COALESCE(SUM(payment_amount),0) t FROM payments
    WHERE MONTH(due_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))
      AND YEAR(due_date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))
")->fetch()['t'];
$billedGrowth = $billedLastMonth > 0 ? round((($billedThisMonth - $billedLastMonth) / $billedLastMonth) * 100, 1) : null;

$overdueTenantCount = (int) $db->query("SELECT COUNT(DISTINCT tenant_id) c FROM payments WHERE payment_status = 'Overdue'")->fetch()['c'];

$maintByStatus = [];
foreach ($db->query("SELECT status, COUNT(*) c, SUM(priority_level='Urgent') urgent FROM maintenance_requests GROUP BY status") as $row) {
    $maintByStatus[$row['status']] = ['count' => (int) $row['c'], 'urgent' => (int) $row['urgent']];
}
$openCount      = $maintByStatus['Pending']['count'] ?? 0;
$ongoingCount   = $maintByStatus['Ongoing']['count'] ?? 0;
$completedCount = $maintByStatus['Completed']['count'] ?? 0;
$urgentMaintenance = ($maintByStatus['Pending']['urgent'] ?? 0) + ($maintByStatus['Ongoing']['urgent'] ?? 0);
$openMaintenance   = $openCount + $ongoingCount;
$resolvedThisPeriod = (int) $db->query("
    SELECT COUNT(*) c FROM maintenance_requests WHERE status = 'Completed' AND date_resolved >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
")->fetch()['c'];

$pendingRegistrations = (int) $db->query("SELECT COUNT(*) c FROM tenants WHERE approval_status = 'Pending'")->fetch()['c'];

$pending = $db->query("SELECT COUNT(*) c, COALESCE(SUM(payment_amount),0) total FROM payments WHERE payment_status = 'Pending'")->fetch();
$overdue = $db->query("SELECT COUNT(*) c, COALESCE(SUM(payment_amount),0) total FROM payments WHERE payment_status = 'Overdue'")->fetch();
$pendingOverdueTenantCount = (int) $db->query("SELECT COUNT(DISTINCT tenant_id) c FROM payments WHERE payment_status IN ('Pending','Overdue')")->fetch()['c'];
$pendingCount = $pendingRegistrations;

$totalRooms    = (int) $db->query("SELECT COUNT(*) c FROM dorm_rooms")->fetch()['c'];
$occupiedRooms = (int) $db->query("SELECT COUNT(*) c FROM dorm_rooms WHERE status = 'Occupied'")->fetch()['c'];
$vacantRooms   = $totalRooms - $occupiedRooms;
$occupancyRate = $totalRooms > 0 ? round(($occupiedRooms / $totalRooms) * 100) : 0;

$expiringContracts = (int) $db->query(
    "SELECT COUNT(*) c FROM contracts WHERE contract_status IN ('Active','Expiring Soon','Expired') AND contract_end <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)"
)->fetch()['c'];
$expiredContracts = (int) $db->query("SELECT COUNT(*) c FROM contracts WHERE contract_status = 'Expired'")->fetch()['c'];

$activeTab = str_input($_GET, 'tab') ?: 'overview';
if (!in_array($activeTab, ['overview', 'tenants', 'payments', 'maintenance'], true)) {
    $activeTab = 'overview';
}

// =====================================================================
// OVERVIEW TAB DATA
// =====================================================================
$months = [];
for ($i = 6; $i >= 0; $i--) {
    $months[] = date('Y-m', strtotime("-$i month"));
}
$billedByMonth = array_column(
    $db->query("SELECT DATE_FORMAT(due_date,'%Y-%m') ym, SUM(payment_amount) t FROM payments WHERE due_date >= DATE_SUB(CURDATE(), INTERVAL 7 MONTH) GROUP BY ym")->fetchAll(),
    't', 'ym'
);
$collectedByMonth = array_column(
    $db->query("SELECT DATE_FORMAT(payment_date,'%Y-%m') ym, SUM(payment_amount) t FROM payments WHERE payment_status = 'Paid' AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 7 MONTH) GROUP BY ym")->fetchAll(),
    't', 'ym'
);
$chartLabels = [];
$billedSeries = [];
$collectedSeries = [];
foreach ($months as $ym) {
    $chartLabels[]     = date('M Y', strtotime($ym . '-01'));
    $billedSeries[]    = round((float) ($billedByMonth[$ym] ?? 0), 2);
    $collectedSeries[] = round((float) ($collectedByMonth[$ym] ?? 0), 2);
}
$hasRevenueData = array_sum($billedSeries) > 0 || array_sum($collectedSeries) > 0;

$roomTypeBreakdown = $db->query("
    SELECT room_type, COUNT(*) total, SUM(status = 'Occupied') occupied
    FROM dorm_rooms GROUP BY room_type ORDER BY room_type
")->fetchAll();

$allRecentActivity = $db->query("
  SELECT activity_type, description, created_at, performed_by, ip_address, source, event_id
  FROM (
    SELECT a.action AS activity_type, a.details AS description, a.created_at,
         COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), 'System') AS performed_by,
               COALESCE(a.ip_address, '-') AS ip_address, 'Audit Trail' AS source, a.audit_id AS event_id
    FROM audit_logs a
    LEFT JOIN users u ON u.user_id = a.user_id

    UNION ALL

     SELECT l.activity_type, l.description, l.created_at, 'Activity Feed' AS performed_by,
       '-' AS ip_address, 'Activity Log' AS source, l.activity_id AS event_id
    FROM activity_log l
    WHERE NOT EXISTS (
      SELECT 1
      FROM audit_logs a
      WHERE a.created_at BETWEEN DATE_SUB(l.created_at, INTERVAL 10 SECOND) AND DATE_ADD(l.created_at, INTERVAL 10 SECOND)
        AND (
          (a.action = l.activity_type AND a.details = l.description)
          OR (l.activity_type = 'payment_verified' AND a.action = 'payment_status_changed')
          OR (l.activity_type = 'payment_recorded' AND a.action = 'payment_recorded')
        )
    )
  ) AS recent_activity
  ORDER BY created_at DESC, event_id DESC
")->fetchAll();
$recentActivity = array_slice($allRecentActivity, 0, 6);

// =====================================================================
// TENANTS TAB DATA
// =====================================================================
$activeTenantsCount   = (int) $db->query("SELECT COUNT(*) c FROM tenants WHERE status = 'Active'")->fetch()['c'];
$totalApprovedTenants = (int) $db->query("SELECT COUNT(*) c FROM tenants WHERE approval_status = 'Approved'")->fetch()['c'];
$inactiveTenantsCount = (int) $db->query("SELECT COUNT(*) c FROM tenants WHERE status IN ('Checked Out','Evicted')")->fetch()['c'];

$pendingRegRows = $db->query("
    SELECT t.tenant_id, u.first_name, u.last_name, t.date_registered
    FROM tenants t JOIN users u ON u.user_id = t.user_id
    WHERE t.approval_status = 'Pending'
    ORDER BY t.date_registered DESC LIMIT 5
")->fetchAll();
$pendingCount = count($pendingRegRows);

$recentActiveTenants = $db->query("
    SELECT t.tenant_id, u.first_name, u.last_name, t.tenant_type, r.room_number,
           COALESCE((SELECT payment_status FROM payments p WHERE p.tenant_id = t.tenant_id ORDER BY p.due_date DESC LIMIT 1), '—') AS last_payment_status
    FROM tenants t
    JOIN users u ON u.user_id = t.user_id
    LEFT JOIN dorm_rooms r ON r.room_id = t.room_id
    WHERE t.status = 'Active'
    ORDER BY t.date_registered DESC LIMIT 6
")->fetchAll();

$studentCount = 0;
$employeeCount = 0;
foreach ($db->query("SELECT tenant_type, COUNT(*) c FROM tenants WHERE approval_status = 'Approved' GROUP BY tenant_type") as $row) {
    if ($row['tenant_type'] === 'Student') {
        $studentCount = (int) $row['c'];
    } else {
        $employeeCount = (int) $row['c'];
    }
}

$tenantStatusSummary = [
    'Active'   => $activeTenantsCount,
    'Pending'  => $pendingRegistrations,
    'Overdue'  => $overdueTenantCount,
    'Inactive' => $inactiveTenantsCount,
];

// =====================================================================
// PAYMENTS TAB DATA
// =====================================================================
$collectedThisMonth = $db->query("
    SELECT COUNT(*) c, COALESCE(SUM(payment_amount),0) t FROM payments
    WHERE payment_status = 'Paid' AND MONTH(payment_date) = MONTH(CURDATE()) AND YEAR(payment_date) = YEAR(CURDATE())
")->fetch();

$collectedPct = $billedThisMonth > 0 ? round(($collectedThisMonth['t'] / $billedThisMonth) * 100) : 0;
$pendingPct   = $billedThisMonth > 0 ? round(($pending['total'] / $billedThisMonth) * 100) : 0;
$overduePct   = $billedThisMonth > 0 ? max(0, 100 - $collectedPct - $pendingPct) : 0;
$collectionRate = $collectedPct;

$recentPayments = $db->query("
    SELECT p.*, u.first_name, u.last_name, r.room_number
    FROM payments p
    JOIN tenants t ON t.tenant_id = p.tenant_id
    JOIN users u ON u.user_id = t.user_id
    LEFT JOIN dorm_rooms r ON r.room_id = t.room_id
    ORDER BY p.payment_date DESC, p.created_at DESC LIMIT 6
")->fetchAll();

$overdueAccounts = $db->query("
    SELECT p.payment_id, p.payment_amount, u.first_name, u.last_name, r.room_number
    FROM payments p
    JOIN tenants t ON t.tenant_id = p.tenant_id
    JOIN users u ON u.user_id = t.user_id
    LEFT JOIN dorm_rooms r ON r.room_id = t.room_id
    WHERE p.payment_status = 'Overdue'
    ORDER BY p.due_date ASC LIMIT 5
")->fetchAll();

// =====================================================================
// MAINTENANCE TAB DATA
// =====================================================================
$activeMaintenanceRequests = $db->query("
    SELECT m.*, u.first_name, u.last_name, r.room_number
    FROM maintenance_requests m
    JOIN tenants t ON t.tenant_id = m.tenant_id
    JOIN users u ON u.user_id = t.user_id
    JOIN dorm_rooms r ON r.room_id = m.room_id
    WHERE m.status IN ('Pending','Ongoing')
    ORDER BY FIELD(m.priority_level,'Urgent','High','Medium','Low'), m.date_submitted ASC
    LIMIT 8
")->fetchAll();

$categoryTotals = ['Electrical' => 0, 'Plumbing' => 0, 'General' => 0];
foreach ($activeMaintenanceRequests as $r) {
    $categoryTotals[maintenance_category_bucket($r['issue_title'])]++;
}
$categoryTotalCount = max(1, array_sum($categoryTotals));

$pageTitle = 'Admin Dashboard';
include __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/page_header.php';

/**
 * Dynamic Bootstrap Icon helper for Dashboard Recent Activity & Audit Logs
 */
function getSystemActivityIcon(string $activityType): array {
    switch (strtolower($activityType)) {
        case 'registration':
        case 'tenant_registered':
            return [
                'icon' => 'bi-person-plus-fill',
                'text_color' => 'text-primary',
                'bg_color' => 'bg-primary-subtle',
            ];
        case 'approval':
        case 'approved':
            return [
                'icon' => 'bi-check-circle-fill',
                'text_color' => 'text-maroon',
                'bg_color' => 'bg-maroon-subtle',
            ];
        case 'rejection':
        case 'rejected':
            return [
                'icon' => 'bi-x-circle-fill',
                'text_color' => 'text-danger',
                'bg_color' => 'bg-danger-subtle',
            ];
        case 'payment':
        case 'payment_received':
            return [
                'icon' => 'bi-cash-stack',
                'text_color' => 'text-success',
                'bg_color' => 'bg-success-subtle',
            ];
        case 'checkin':
        case 'checkout':
            return [
                'icon' => 'bi-arrow-left-right',
                'text_color' => 'text-info',
                'bg_color' => 'bg-info-subtle',
            ];
        case 'maintenance':
        case 'repair_request':
            return [
                'icon' => 'bi-wrench-adjustable',
                'text_color' => 'text-warning',
                'bg_color' => 'bg-warning-subtle',
            ];
        default:
            return [
                'icon' => 'bi-bell-fill',
                'text_color' => 'text-secondary',
                'bg_color' => 'bg-light',
            ];
    }
}
?>
<?php render_page_header(
    'bi-grid-1x2-fill',
    'Admin Dashboard',
    date('l, F j, Y'),
    '<button type="button" class="btn-icon" aria-label="Refresh dashboard" onclick="window.location.reload();"><i class="bi bi-arrow-clockwise"></i></button>'
); ?>

<div class="stat-grid dashboard-kpis">
  <div class="stat-card h-100">
    <div class="stat-card-body d-flex flex-column justify-content-between h-100">
      <div>
        <div class="stat-label">Monthly Revenue</div>
        <div class="stat-value"><?= peso($billedThisMonth) ?></div>
        <div class="stat-sub">Total billed · <?= date('F Y') ?></div>
      </div>
      <?php if ($billedGrowth !== null): ?>
        <span class="badge badge-<?= $billedGrowth >= 0 ? 'success' : 'danger' ?> mt-3 d-inline-block"><i class="bi bi-arrow-<?= $billedGrowth >= 0 ? 'up' : 'down' ?>"></i> <?= abs($billedGrowth) ?>% vs last month</span>
      <?php endif; ?>
    </div>
    <div class="icon-wrapper icon-circle stat-icon stat-icon-green"><i class="bi bi-graph-up-arrow"></i></div>
  </div>
  <div class="stat-card h-100">
    <div class="stat-card-body d-flex flex-column justify-content-between h-100">
      <div>
        <div class="stat-label">Overdue Payments</div>
        <div class="stat-value"><?= $overdueTenantCount ?> Tenant<?= $overdueTenantCount === 1 ? '' : 's' ?></div>
        <div class="stat-sub text-xs text-slate-500">Past due · immediate action needed</div>
      </div>
      <?php if ($overdueTenantCount > 0): ?><span class="badge badge-danger mt-3 d-inline-block">Action Required</span><?php endif; ?>
    </div>
    <div class="icon-wrapper icon-circle stat-icon stat-icon-red"><i class="bi bi-exclamation-triangle-fill"></i></div>
  </div>
  <div class="stat-card h-100">
    <div class="stat-card-body d-flex flex-column justify-content-between h-100">
      <div>
        <div class="stat-label">Urgent Maintenance</div>
        <div class="stat-value"><?= $openMaintenance ?> Open</div>
        <div class="stat-sub"><?= $urgentMaintenance ?> urgent · <?= $ongoingCount ?> in progress</div>
      </div>
      <?php if ($urgentMaintenance > 0): ?><span class="badge badge-warning mt-3 d-inline-block"><?= $urgentMaintenance ?> Urgent</span><?php endif; ?>
    </div>
    <div class="icon-wrapper icon-circle stat-icon stat-icon-amber"><i class="bi bi-tools"></i></div>
  </div>
  <div class="stat-card pending-reg-card h-100 pb-3">
    <div class="stat-card-body d-flex flex-column justify-content-between h-100">
      <div>
        <div class="stat-label">Pending Registrations</div>
        <div class="stat-value"><?= $pendingRegistrations ?> Pending</div>
        <div class="stat-sub">Awaiting admin approval</div>
      </div>
      <?php if ($pendingRegistrations > 0): ?><span class="badge pending-review-badge mt-2 d-inline-flex align-items-center justify-content-center">Needs Review</span><?php endif; ?>
    </div>
    <div class="icon-wrapper icon-circle stat-icon pending-reg-icon"><i class="bi bi-person-lines-fill"></i></div>
  </div>
</div>

<nav class="dashboard-tabs" aria-label="Dashboard views">
  <div class="dashboard-tab-list">
    <a id="pill-overview" class="dashboard-tab tab-pill <?= $activeTab === 'overview' ? 'active' : '' ?>" href="?tab=overview" data-dashboard-tab="overview"><i class="bi bi-grid-1x2-fill"></i> Overview</a>
    <a id="pill-tenants" class="dashboard-tab tab-pill <?= $activeTab === 'tenants' ? 'active' : '' ?>" href="?tab=tenants" data-dashboard-tab="tenants"><i class="bi bi-people-fill"></i> Tenants <span class="tab-count"><?= $pendingRegistrations ?></span></a>
    <a id="pill-payments" class="dashboard-tab tab-pill <?= $activeTab === 'payments' ? 'active' : '' ?>" href="?tab=payments" data-dashboard-tab="payments"><i class="bi bi-credit-card-fill"></i> Payments <span class="tab-count"><?= $pendingOverdueTenantCount ?></span></a>
    <a id="pill-maintenance" class="dashboard-tab tab-pill <?= $activeTab === 'maintenance' ? 'active' : '' ?>" href="?tab=maintenance" data-dashboard-tab="maintenance"><i class="bi bi-wrench-adjustable-circle-fill"></i> Maintenance <span class="tab-count"><?= $openMaintenance ?></span></a>
  </div>
</nav>

<div id="tab-overview" class="tab-content-panel dashboard-view dashboard-overview-view" style="<?= $activeTab === 'overview' ? '' : 'display:none;' ?>">
  <div class="row g-4">
    <div class="col-lg-8">
      <div class="panel">
        <div class="panel-header">
          <h2>Revenue Trend</h2>
          <span class="chart-legend"><span class="legend-pill"><span class="dot" style="background:#800000"></span>Billed</span><span class="legend-pill"><span class="dot" style="background:#2fa15c"></span>Collected</span></span>
        </div>
        <span class="text-muted small">Billed vs. Collected — last 7 months</span>
        <div class="revenue-chart-wrap">
          <canvas id="revenueTrendChart" aria-label="Revenue trend chart"></canvas>
        </div>
        <?php if (!$hasRevenueData): ?><p class="text-muted text-center mt-3">No payment records yet.</p><?php endif; ?>
      </div>
      <div class="panel mt-3">
        <div class="panel-header"><h2>Room Type Breakdown</h2></div>
        <?php if (!$roomTypeBreakdown): ?><p class="text-muted mb-0">No rooms have been added yet.</p><?php endif; ?>
        <?php foreach ($roomTypeBreakdown as $rt): $pct = $rt['total'] > 0 ? round(($rt['occupied'] / $rt['total']) * 100) : 0; ?>
          <div class="mix-bar-row">
            <div class="mix-bar-label"><span><?= clean($rt['room_type']) ?></span><span class="text-muted"><?= (int) $rt['occupied'] ?> / <?= (int) $rt['total'] ?> occupied</span></div>
            <div class="mix-bar-track"><div class="mix-bar-fill" style="width:<?= $pct ?>%;background:var(--maroon)"></div></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="panel">
        <div class="panel-header"><h2>Occupancy Overview</h2></div>
        <div class="donut-wrap"><canvas id="occupancyDonut" width="170" height="170"></canvas></div>
        <div class="donut-legend"><span><span class="dot" style="background:var(--maroon)"></span>Occupied</span><span><span class="dot" style="background:var(--border)"></span>Vacant</span></div>
        <div class="donut-stats">
          <div class="detail-row"><span class="text-muted">Total Rooms</span><strong><?= $totalRooms ?></strong></div>
          <div class="detail-row"><span class="text-muted">Occupied</span><strong><?= $occupiedRooms ?></strong></div>
          <div class="detail-row"><span class="text-muted">Vacant</span><strong><?= $vacantRooms ?></strong></div>
          <div class="detail-row"><span class="text-muted">Occupancy Rate</span><strong><?= $occupancyRate ?>%</strong></div>
        </div>
      </div>
      <div class="panel mt-3">
        <div class="panel-header"><h2>Recent Activity</h2><a href="#recentActivityModal" class="group inline-nav-link dashboard-card-footer-link" data-bs-toggle="modal" data-bs-target="#recentActivityModal" aria-controls="recentActivityModal">View all <i class="bi bi-arrow-up-right link-arrow-icon"></i></a></div>
        <?php if (!$recentActivity): ?><p class="text-muted mb-0">Activity will appear here as the system is used.</p><?php endif; ?>
        <div class="recent-activity-feed d-flex flex-column gap-2">
          <?php foreach ($recentActivity as $a): ?>
            <?php $rawTimestamp = $a['created_at'] ?? null; ?>
            <?php $meta = getSystemActivityIcon($a['activity_type'] ?? 'default'); ?>
            <div class="activity-row d-flex align-items-center gap-3 p-2 rounded-3 hover-bg-light">
              <div class="icon-wrapper icon-circle icon-avatar <?= $meta['bg_color'] ?>">
                <i class="bi <?= $meta['icon'] . ' ' . $meta['text_color'] ?> fs-6"></i>
              </div>
              <div class="flex-grow-1 min-w-0">
                <p class="activity-text mb-0 text-dark fw-medium xxs-text"><?= clean($a['description']) ?></p>
                <span class="text-muted xxs-text js-relative-time" data-relative-time="<?= clean(!empty($rawTimestamp) ? date('c', strtotime((string) $rawTimestamp)) : '') ?>"><?= clean(time_ago($rawTimestamp)) ?></span>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<section id="tab-tenants" class="tab-content-panel dashboard-view" style="<?= $activeTab === 'tenants' ? '' : 'display:none;' ?>">
  <div class="stat-grid stat-grid-4">
    <div class="stat-card"><div class="stat-card-body"><div class="stat-label">Active Tenants</div><div class="stat-value"><?= $activeTenantsCount ?></div><div class="stat-sub">of <?= $totalApprovedTenants ?> total</div></div><div class="stat-icon stat-icon-green"><i class="bi bi-people-fill"></i></div></div>
    <div class="stat-card stat-card-link" role="link" tabindex="0" aria-label="Review pending registrations" onclick="window.location='<?= BASE_URL ?>/admin/tenants.php?tab=registration&status=Pending';" onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); window.location='<?= BASE_URL ?>/admin/tenants.php?tab=registration&status=Pending'; }" style="cursor:pointer;">
      <div class="stat-card-body"><div class="stat-label">Pending Approval</div><div class="stat-value"><?= $pendingRegistrations ?></div><div class="stat-sub">Awaiting registration review</div></div>
      <div class="stat-icon stat-icon-amber"><i class="bi bi-hourglass-split"></i></div>
    </div>
    <div class="stat-card"><div class="stat-card-body"><div class="stat-label">Overdue Rent</div><div class="stat-value"><?= $overdueTenantCount ?></div><div class="stat-sub">Past due this billing period</div></div><div class="stat-icon stat-icon-red"><i class="bi bi-cash-stack"></i></div></div>
    <div class="stat-card"><div class="stat-card-body"><div class="stat-label">Expiring Contracts</div><div class="stat-value"><?= $expiringContracts ?></div><div class="stat-sub">Due within 30 days</div></div><div class="stat-icon stat-icon-amber"><i class="bi bi-file-earmark-text"></i></div></div>
  </div>

  <div class="row g-4 mt-1">
    <div class="col-lg-8">
      <div class="panel">
        <div class="panel-header">
          <h2>Pending Registrations <span class="text-muted small fw-normal">(<?= $pendingRegistrations ?> awaiting review)</span></h2>
          <a href="<?= BASE_URL ?>/admin/tenants.php?tab=registration&status=Pending" class="group inline-nav-link dashboard-card-footer-link fw-semibold">Review All <i class="bi bi-arrow-up-right link-arrow-icon"></i></a>
        </div>
        <?php if (!$pendingRegRows): ?><p class="text-muted mb-0">No pending applications.</p><?php endif; ?>
        <?php foreach ($pendingRegRows as $p): ?>
          <a href="<?= BASE_URL ?>/admin/tenants.php?tab=registration&status=Pending" class="pending-reg-card pending-reg-card-link" aria-label="Review pending applicant <?= clean($p['first_name'] . ' ' . $p['last_name']) ?>">
            <div><strong><?= clean($p['first_name'] . ' ' . $p['last_name']) ?></strong><div class="text-muted small">Applied <?= clean(date('M j, Y', strtotime($p['date_registered']))) ?></div></div>
            <span class="badge badge-warning">● Pending</span>
          </a>
        <?php endforeach; ?>
      </div>
      <div class="panel mt-3 panel-spaced-footer">
        <div class="panel-header"><h2>Recent Active Tenants</h2></div>
        <?php if (!$recentActiveTenants): ?><p class="text-muted mb-0 panel-empty-state">No active tenants yet.</p><?php endif; ?>
        <div class="panel-list">
          <?php foreach ($recentActiveTenants as $t): ?>
            <div class="tenant-row panel-list-item">
              <div class="cell-person">
                <div class="applicant-avatar"><i class="bi bi-person-fill" aria-hidden="true"></i></div>
                <div><?= clean($t['first_name'] . ' ' . $t['last_name']) ?><div class="sub"><?= $t['room_number'] ? 'Room ' . clean($t['room_number']) : 'Unassigned' ?> · <?= clean($t['tenant_type']) ?></div></div>
              </div>
              <span class="badge badge-<?= status_badge_class($t['last_payment_status']) ?>">● <?= clean($t['last_payment_status']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="panel-footer-row">
          <a href="<?= BASE_URL ?>/admin/manage-tenants.php" class="group footer-link dashboard-card-footer-link"><i class="bi bi-arrow-up-right link-arrow-icon"></i> Open Full Tenant Directory</a>
        </div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="panel">
        <div class="panel-header"><h2>Tenant Mix</h2></div>
        <div class="mix-bar-row">
          <div class="mix-bar-label"><span>Students</span><span class="text-muted"><?= $studentCount ?> / <?= $studentCount + $employeeCount ?></span></div>
          <div class="mix-bar-track"><div class="mix-bar-fill" style="width:<?= $studentCount + $employeeCount > 0 ? round($studentCount / ($studentCount + $employeeCount) * 100) : 0 ?>%;background:var(--maroon)"></div></div>
        </div>
        <div class="mix-bar-row">
          <div class="mix-bar-label"><span>Employees</span><span class="text-muted"><?= $employeeCount ?> / <?= $studentCount + $employeeCount ?></span></div>
          <div class="mix-bar-track"><div class="mix-bar-fill" style="width:<?= $studentCount + $employeeCount > 0 ? round($employeeCount / ($studentCount + $employeeCount) * 100) : 0 ?>%;background:#4f7fd6"></div></div>
        </div>
        <div class="mt-3">
          <?php foreach ($tenantStatusSummary as $label => $count): ?>
            <div class="detail-row"><span class="text-muted"><?= clean($label) ?></span><strong><?= $count ?></strong></div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<section id="tab-payments" class="tab-content-panel dashboard-view" style="<?= $activeTab === 'payments' ? '' : 'display:none;' ?>">
  <div class="stat-grid stat-grid-3">
    <div class="stat-card"><div class="stat-card-body"><div class="stat-label">Collected This Month</div><div class="stat-value text-success"><?= peso($collectedThisMonth['t']) ?></div><div class="stat-sub"><?= (int) $collectedThisMonth['c'] ?> transaction<?= (int) $collectedThisMonth['c'] === 1 ? '' : 's' ?> completed</div></div><div class="stat-icon stat-icon-green"><i class="bi bi-check-lg"></i></div></div>
    <div class="stat-card"><div class="stat-card-body"><div class="stat-label">Pending Payments</div><div class="stat-value"><?= peso($pending['total']) ?></div><div class="stat-sub"><?= (int) $pending['c'] ?> tenant<?= (int) $pending['c'] === 1 ? '' : 's' ?> due</div></div><div class="stat-icon stat-icon-amber"><i class="bi bi-clock-fill"></i></div></div>
    <div class="stat-card"><div class="stat-card-body"><div class="stat-label">Overdue Amount</div><div class="stat-value text-danger"><?= peso($overdue['total']) ?></div><div class="stat-sub"><?= (int) $overdue['c'] ?> account<?= (int) $overdue['c'] === 1 ? '' : 's' ?> past due</div></div><div class="stat-icon stat-icon-red"><i class="bi bi-exclamation-triangle-fill"></i></div></div>
  </div>

  <div class="row g-4 mt-1">
    <div class="col-lg-8">
      <div class="panel">
        <div class="panel-header"><h2>Collection Rate — <?= date('F Y') ?> (<?= $collectionRate ?>%)</h2></div>
        <div class="collection-rate-bar">
          <div style="width:<?= $collectedPct ?>%;background:#2fa15c" title="Collected <?= $collectedPct ?>%"></div>
          <div style="width:<?= $pendingPct ?>%;background:#e6a917" title="Pending <?= $pendingPct ?>%"></div>
          <div style="width:<?= $overduePct ?>%;background:#d3555a" title="Overdue <?= $overduePct ?>%"></div>
        </div>
        <div class="donut-legend mt-2"><span><span class="dot" style="background:#2fa15c"></span>Collected</span><span><span class="dot" style="background:#e6a917"></span>Pending</span><span><span class="dot" style="background:#d3555a"></span>Overdue</span></div>
      </div>
      <div class="panel mt-3 panel-spaced-footer">
        <div class="panel-header"><h2>Recent Payments</h2></div>
        <?php if (!$recentPayments): ?><p class="text-muted mb-0 panel-empty-state">No payments recorded yet.</p><?php endif; ?>
        <div class="panel-list">
          <?php foreach ($recentPayments as $p): ?>
            <div class="tenant-row panel-list-item">
              <div class="cell-person">
                <div class="applicant-avatar"><i class="bi bi-person-fill" aria-hidden="true"></i></div>
                <div><?= clean($p['first_name'] . ' ' . $p['last_name']) ?><div class="sub"><?= $p['room_number'] ? 'Room ' . clean($p['room_number']) : '—' ?> · <?= clean($p['payment_method'] ?: '—') ?> · <?= $p['payment_date'] ? clean(date('M j, Y', strtotime($p['payment_date']))) : '—' ?></div></div>
              </div>
              <strong class="text-success"><?= peso($p['payment_amount']) ?></strong>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="panel-footer-row">
          <a href="<?= BASE_URL ?>/admin/payments.php#payments" class="group footer-link dashboard-card-footer-link"><i class="bi bi-arrow-up-right link-arrow-icon"></i> Open Full Payment Ledger</a>
        </div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="panel">
        <div class="panel-header"><h2>Overdue Accounts</h2></div>
        <?php if (!$overdueAccounts): ?><p class="text-muted mb-0">No overdue accounts right now.</p><?php endif; ?>
        <?php foreach ($overdueAccounts as $o): ?>
          <div class="overdue-account-item">
            <div><strong><?= clean($o['first_name'] . ' ' . $o['last_name']) ?></strong><div class="text-muted small"><?= $o['room_number'] ? 'Room ' . clean($o['room_number']) : '—' ?></div></div>
            <div class="text-end"><div class="text-danger fw-bold"><?= peso($o['payment_amount']) ?></div><span class="badge badge-danger">● Overdue</span></div>
          </div>
        <?php endforeach; ?>
        <a href="<?= BASE_URL ?>/admin/notifications.php#notification-payments" class="group footer-link dashboard-card-footer-link"><i class="bi bi-arrow-up-right link-arrow-icon"></i> Send Payment Reminders</a>
      </div>
    </div>
  </div>
</section>

<section id="tab-maintenance" class="tab-content-panel dashboard-view" style="<?= $activeTab === 'maintenance' ? '' : 'display:none;' ?>">
  <div class="stat-grid stat-grid-4">
    <div class="stat-card"><div class="stat-card-body"><div class="stat-label">Open Requests</div><div class="stat-value text-danger"><?= $openCount ?></div><div class="stat-sub">Unassigned or pending</div></div><div class="stat-icon stat-icon-red"><i class="bi bi-inbox-fill"></i></div></div>
    <div class="stat-card"><div class="stat-card-body"><div class="stat-label">Urgent Items</div><div class="stat-value text-warning"><?= $urgentMaintenance ?></div><div class="stat-sub">Needs immediate action</div></div><div class="stat-icon stat-icon-amber"><i class="bi bi-exclamation-triangle-fill"></i></div></div>
    <div class="stat-card"><div class="stat-card-body"><div class="stat-label">In Progress</div><div class="stat-value"><?= $ongoingCount ?></div><div class="stat-sub">Currently being worked</div></div><div class="stat-icon stat-icon-blue"><i class="bi bi-arrow-repeat"></i></div></div>
    <div class="stat-card"><div class="stat-card-body"><div class="stat-label">Resolved</div><div class="stat-value text-success"><?= $resolvedThisPeriod ?></div><div class="stat-sub">Completed this period</div></div><div class="stat-icon stat-icon-green"><i class="bi bi-check-lg"></i></div></div>
  </div>

  <div class="row g-4 mt-1">
    <div class="col-lg-8">
      <div class="panel panel-spaced-footer">
        <div class="panel-header"><h2>Active Requests</h2><span class="text-muted small"><?= $openMaintenance ?> open or in-progress</span></div>
        <?php if (!$activeMaintenanceRequests): ?><p class="text-muted my-4 panel-empty-state">No open or in-progress requests.</p><?php endif; ?>
        <div class="panel-list">
          <?php foreach ($activeMaintenanceRequests as $m): ?>
            <div class="maintenance-request-card panel-list-item">
              <div class="maintenance-request-top">
                <?php $priorityBadge = ['Urgent' => 'danger', 'High' => 'warning', 'Medium' => 'info', 'Low' => 'secondary'][$m['priority_level']] ?? 'secondary'; ?>
                <span class="badge badge-secondary">MR-<?= str_pad((string) $m['maintenance_id'], 4, '0', STR_PAD_LEFT) ?></span>
                <span class="badge badge-<?= $priorityBadge ?>"><?= clean($m['priority_level']) ?></span>
                <span class="text-muted small"><?= clean(maintenance_category_bucket($m['issue_title'])) ?></span>
                <span class="badge badge-<?= $m['status'] === 'Pending' ? 'danger' : 'info' ?> ms-auto">● <?= $m['status'] === 'Pending' ? 'Open' : 'In Progress' ?></span>
              </div>
              <div class="fw-semibold"><?= clean($m['issue_title']) ?></div>
              <div class="text-muted small"><?= clean(mb_strimwidth($m['issue_description'], 0, 90, '…')) ?></div>
              <div class="text-muted small mt-1"><?= clean($m['first_name'] . ' ' . $m['last_name']) ?> · Room <?= clean($m['room_number']) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="panel-footer-row">
          <a href="<?= BASE_URL ?>/admin/maintenance.php" class="group footer-link dashboard-card-footer-link"><i class="bi bi-arrow-up-right link-arrow-icon"></i> Open Maintenance System</a>
        </div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="panel">
        <div class="panel-header"><h2>By Category</h2></div>
        <?php foreach ($categoryTotals as $cat => $count): $pct = round($count / $categoryTotalCount * 100); ?>
          <div class="mix-bar-row">
            <div class="mix-bar-label"><span><?= clean($cat) ?></span><span class="text-muted"><?= $count ?></span></div>
            <div class="mix-bar-track"><div class="mix-bar-fill" style="width:<?= $pct ?>%;background:var(--maroon)"></div></div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="panel mt-3">
        <div class="panel-header"><h2>Status Overview</h2></div>
        <div class="detail-row"><span class="text-muted">Open</span><strong><?= $openCount ?></strong></div>
        <div class="detail-row"><span class="text-muted">In Progress</span><strong><?= $ongoingCount ?></strong></div>
        <div class="detail-row"><span class="text-muted">Resolved</span><strong><?= $completedCount ?></strong></div>
      </div>
    </div>
  </div>
</section>

<div class="modal fade recent-activity-modal" id="recentActivityModal" tabindex="-1" aria-labelledby="recentActivityModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h2 class="modal-title fs-5" id="recentActivityModalLabel">Recent Activity</h2>
          <p class="recent-activity-modal-subtitle">System events from the audit trail and activity feed.</p>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-0">
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0 recent-activity-table">
            <thead class="table-light"><tr><th>Date &amp; Time</th><th>Activity</th><th>Description</th><th>Recorded By</th><th>Source</th></tr></thead>
            <tbody>
              <?php if (!$allRecentActivity): ?><tr><td colspan="5" class="text-center text-muted py-5">No activity has been recorded yet.</td></tr><?php endif; ?>
              <?php foreach ($allRecentActivity as $activity): ?>
                <tr>
                  <td class="text-nowrap"><?= clean(date('M j, Y g:i A', strtotime($activity['created_at']))) ?></td>
                  <td class="text-nowrap"><?= clean(str_replace('_', ' ', $activity['activity_type'])) ?></td>
                  <td><?= clean($activity['description']) ?></td>
                  <td class="text-nowrap"><?= clean($activity['performed_by']) ?></td>
                  <td><span class="badge badge-secondary"><?= clean($activity['source']) ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-action-outline" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<?php
$chartLabelsJson = json_encode($chartLabels);
$billedSeriesJson = json_encode($billedSeries);
$collectedSeriesJson = json_encode($collectedSeries);
$extraScripts = "
<script src=\"https://cdn.jsdelivr.net/npm/chart.js@4\"></script>
<script>
new Chart(document.getElementById('occupancyDonut'), {
  type: 'doughnut',
  data: {
    labels: ['Occupied', 'Vacant'],
    datasets: [{ data: [{$occupiedRooms}, {$vacantRooms}], backgroundColor: ['#800000', '#ebedf1'], borderWidth: 0 }]
  },
  options: { cutout: '72%', plugins: { legend: { display: false }, tooltip: { enabled: true } } }
});
" . ($hasRevenueData ? "
const revenueCanvas = document.getElementById('revenueTrendChart');
const revenueGradient = revenueCanvas.getContext('2d').createLinearGradient(0, 0, 0, revenueCanvas.clientHeight || 260);
revenueGradient.addColorStop(0, 'rgba(16, 185, 129, 0.12)');
revenueGradient.addColorStop(1, 'rgba(16, 185, 129, 0)');
if (window.myRevenueChart) {
  window.myRevenueChart.destroy();
}
window.myRevenueChart = new Chart(revenueCanvas, {
  type: 'line',
  data: {
    labels: {$chartLabelsJson}.map(function (label) { return label.split(' ')[0]; }),
    datasets: [
      { label: 'Billed', data: {$billedSeriesJson}, borderColor: '#800000', backgroundColor: 'transparent', borderWidth: 2.5, tension: 0.35, fill: false, pointRadius: 0, pointHoverRadius: 5, pointHoverBackgroundColor: '#800000' },
      { label: 'Collected', data: {$collectedSeriesJson}, borderColor: '#10b981', backgroundColor: revenueGradient, borderWidth: 2.5, tension: 0.35, fill: 'origin', pointRadius: 0, pointHoverRadius: 5, pointHoverBackgroundColor: '#10b981' }
    ]
  },
  plugins: [{
    id: 'revenueCrosshair',
    afterDraw: function (chart) {
      const activeElements = chart.tooltip?.getActiveElements() || [];
      if (!activeElements.length) return;

      const x = activeElements[0].element.x;
      const area = chart.chartArea;
      const context = chart.ctx;
      context.save();
      context.beginPath();
      context.moveTo(x, area.top);
      context.lineTo(x, area.bottom);
      context.lineWidth = 1;
      context.strokeStyle = 'rgba(128, 0, 0, 0.22)';
      context.setLineDash([4, 4]);
      context.stroke();
      context.restore();
    }
  }],
  options: {
    interaction: { mode: 'index', intersect: false },
    plugins: {
      legend: { display: false },
      tooltip: {
        enabled: true,
        backgroundColor: '#ffffff',
        titleColor: '#212529',
        bodyColor: '#800000',
        borderColor: '#e9ecef',
        borderWidth: 1,
        cornerRadius: 12,
        displayColors: false,
        padding: 12,
        caretPadding: 8,
        shadowColor: 'rgba(0, 0, 0, 0.1)',
        shadowBlur: 25,
        shadowOffsetY: 8,
        titleFont: { weight: '700' },
        bodyFont: { weight: '600' },
        callbacks: {
          title: function (items) {
            return items[0]?.label || '';
          },
          label: function (context) {
            return context.dataset.label + ': ' + new Intl.NumberFormat('en-PH', {
              style: 'currency',
              currency: 'PHP',
              maximumFractionDigits: 0
            }).format(context.parsed.y || 0);
          },
          labelTextColor: function (context) {
            return context.dataset.label === 'Collected' ? '#10b981' : '#800000';
          }
        }
      }
    },
    scales: {
      x: {
        grid: { display: false },
        border: { display: false },
        ticks: { color: '#8c98a4', font: { size: 11, weight: '600' } }
      },
      y: {
        beginAtZero: true,
        grid: { color: '#f1f5f9', borderDash: [4, 4] },
        border: { display: false },
        ticks: {
          color: '#8c98a4',
          font: { size: 11, weight: '600' },
          callback: function (value) {
            return '₱' + Math.round(Number(value) / 1000) + 'K';
          }
        }
      }
    }
  }
});
" : "") . "
</script>";
$extraScripts .= "
<script>
function switchDashboardTab(tabName) {
  document.querySelectorAll('.tab-content-panel').forEach(function (panel) {
    panel.style.display = 'none';
  });
  document.querySelectorAll('.dashboard-tab').forEach(function (tab) {
    tab.classList.remove('active');
  });
  const panel = document.getElementById('tab-' + tabName);
  const pill = document.getElementById('pill-' + tabName);
  if (panel) panel.style.display = 'block';
  if (pill) pill.classList.add('active');
  history.replaceState(null, '', '?tab=' + encodeURIComponent(tabName));
}
document.querySelectorAll('[data-dashboard-tab]').forEach(function (tab) {
  tab.addEventListener('click', function (event) {
    event.preventDefault();
    switchDashboardTab(tab.dataset.dashboardTab);
  });
});
</script>";
include __DIR__ . '/../includes/footer.php';
?>
