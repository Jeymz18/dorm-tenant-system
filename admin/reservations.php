<?php
require_once __DIR__ . '/../config/app.php';
require_role('admin');

$db = get_db();
$selfPath = '/admin/reservations.php';

function refresh_reservation_room_status(PDO $db, int $roomId): void
{
    $roomStmt = $db->prepare('SELECT capacity, status FROM dorm_rooms WHERE room_id = ? FOR UPDATE');
    $roomStmt->execute([$roomId]);
    $room = $roomStmt->fetch();
    if (!$room || $room['status'] === 'Under Maintenance') {
        return;
    }

    $tenantStmt = $db->prepare("SELECT COUNT(*) FROM tenants WHERE room_id = ? AND status IN ('Active','Pending')");
    $tenantStmt->execute([$roomId]);
    $tenantCount = (int) $tenantStmt->fetchColumn();

    $reservationStmt = $db->prepare("SELECT COUNT(*) FROM room_reservations WHERE room_id = ? AND status IN ('Pending','Confirmed')");
    $reservationStmt->execute([$roomId]);
    $reservationCount = (int) $reservationStmt->fetchColumn();

    if ($tenantCount + $reservationCount >= (int) $room['capacity']) {
        $newStatus = $tenantCount >= (int) $room['capacity'] ? 'Occupied' : 'Reserved';
    } else {
        $newStatus = 'Available';
    }
    $db->prepare('UPDATE dorm_rooms SET status = ? WHERE room_id = ?')->execute([$newStatus, $roomId]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = str_input($_POST, 'action');

    if ($action === 'create_reservation') {
        $name = str_input($_POST, 'applicant_name');
        $email = str_input($_POST, 'email');
        $phone = str_input($_POST, 'phone');
        $phoneClean = ph_mobile_normalize($phone);
        $roomId = (int) ($_POST['room_id'] ?? 0);
        $reservationDate = str_input($_POST, 'reservation_date');
        $nameParts = preg_split('/\s+/', $name, 2);
        $date = DateTime::createFromFormat('!Y-m-d', $reservationDate);
        $dateValid = $date && $date->format('Y-m-d') === $reservationDate && $reservationDate >= date('Y-m-d');

        if (count($nameParts) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL) || $phoneClean === null || $roomId <= 0 || !$dateValid) {
            flash('error', 'Enter the applicant’s full name, valid email and phone, room, and a reservation date today or later.');
        } else {
            $db->beginTransaction();
            try {
                $roomStmt = $db->prepare("SELECT room_id, capacity, status FROM dorm_rooms WHERE room_id = ? FOR UPDATE");
                $roomStmt->execute([$roomId]);
                $room = $roomStmt->fetch();
                $tenantStmt = $db->prepare("SELECT COUNT(*) FROM tenants WHERE room_id = ? AND status IN ('Active','Pending')");
                $tenantStmt->execute([$roomId]);
                $tenantCount = (int) $tenantStmt->fetchColumn();
                $reservationStmt = $db->prepare("SELECT COUNT(*) FROM room_reservations WHERE room_id = ? AND status IN ('Pending','Confirmed')");
                $reservationStmt->execute([$roomId]);
                $reservationCount = (int) $reservationStmt->fetchColumn();

                if (!$room || $room['status'] === 'Under Maintenance' || $tenantCount + $reservationCount >= (int) $room['capacity']) {
                    throw new RuntimeException('That room no longer has reservation capacity.');
                }

                $insert = $db->prepare('INSERT INTO room_reservations (applicant_name, email, phone, room_id, reservation_date) VALUES (?, ?, ?, ?, ?)');
                $insert->execute([$name, $email, $phoneClean, $roomId, $reservationDate]);
                refresh_reservation_room_status($db, $roomId);
                $db->commit();
                flash('success', 'Reservation created for ' . $name . '.');
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                flash('error', $e instanceof RuntimeException ? $e->getMessage() : 'Could not create the reservation. Please try again.');
            }
        }
        redirect($selfPath);
    }

    if (in_array($action, ['confirm_reservation', 'cancel_reservation'], true)) {
        $reservationId = (int) ($_POST['reservation_id'] ?? 0);
        $db->beginTransaction();
        try {
            $reservationStmt = $db->prepare('SELECT id, room_id, status FROM room_reservations WHERE id = ? FOR UPDATE');
            $reservationStmt->execute([$reservationId]);
            $reservation = $reservationStmt->fetch();
            if (!$reservation || !in_array($reservation['status'], ['Pending', 'Confirmed'], true)) {
                throw new RuntimeException('This reservation can no longer be updated.');
            }

            $roomStmt = $db->prepare('SELECT capacity, status FROM dorm_rooms WHERE room_id = ? FOR UPDATE');
            $roomStmt->execute([(int) $reservation['room_id']]);
            $room = $roomStmt->fetch();
            if (!$room) {
                throw new RuntimeException('The reserved room could not be found.');
            }
            if ($action === 'confirm_reservation') {
                $tenantStmt = $db->prepare("SELECT COUNT(*) FROM tenants WHERE room_id = ? AND status IN ('Active','Pending')");
                $tenantStmt->execute([(int) $reservation['room_id']]);
                $tenantCount = (int) $tenantStmt->fetchColumn();
                $otherReservations = $db->prepare("SELECT COUNT(*) FROM room_reservations WHERE room_id = ? AND status IN ('Pending','Confirmed') AND id <> ?");
                $otherReservations->execute([(int) $reservation['room_id'], $reservationId]);
                if ($room['status'] === 'Under Maintenance' || $tenantCount + (int) $otherReservations->fetchColumn() >= (int) $room['capacity']) {
                    throw new RuntimeException('The room is no longer available to confirm this reservation.');
                }
                $newStatus = 'Confirmed';
            } else {
                $newStatus = 'Cancelled';
            }

            $db->prepare('UPDATE room_reservations SET status = ? WHERE id = ?')->execute([$newStatus, $reservationId]);
            refresh_reservation_room_status($db, (int) $reservation['room_id']);
            $db->commit();
            flash('success', 'Reservation ' . strtolower($newStatus) . '.');
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            flash('error', $e instanceof RuntimeException ? $e->getMessage() : 'Could not update the reservation. Please try again.');
        }
        redirect($selfPath);
    }

    if ($action === 'convert_reservation') {
        $reservationId = (int) ($_POST['reservation_id'] ?? 0);
        $emailForNotice = null;
        $nameForNotice = null;
        $db->beginTransaction();
        try {
            $reservationStmt = $db->prepare("SELECT * FROM room_reservations WHERE id = ? AND status = 'Confirmed' FOR UPDATE");
            $reservationStmt->execute([$reservationId]);
            $reservation = $reservationStmt->fetch();
            if (!$reservation) {
                throw new RuntimeException('Only confirmed reservations can be converted.');
            }

            $roomStmt = $db->prepare('SELECT room_id, capacity, status FROM dorm_rooms WHERE room_id = ? FOR UPDATE');
            $roomStmt->execute([(int) $reservation['room_id']]);
            $room = $roomStmt->fetch();
            $tenantStmt = $db->prepare("SELECT COUNT(*) FROM tenants WHERE room_id = ? AND status IN ('Active','Pending')");
            $tenantStmt->execute([(int) $reservation['room_id']]);
            $tenantCount = (int) $tenantStmt->fetchColumn();
            $otherReservations = $db->prepare("SELECT COUNT(*) FROM room_reservations WHERE room_id = ? AND status IN ('Pending','Confirmed') AND id <> ?");
            $otherReservations->execute([(int) $reservation['room_id'], $reservationId]);
            if (!$room || $room['status'] === 'Under Maintenance' || $tenantCount + (int) $otherReservations->fetchColumn() >= (int) ($room['capacity'] ?? 0)) {
                throw new RuntimeException('The room no longer has capacity for this applicant.');
            }

            $nameParts = preg_split('/\s+/', trim($reservation['applicant_name']), 2);
            if (count($nameParts) < 2) {
                throw new RuntimeException('The applicant needs a first and last name before conversion.');
            }
            $userStmt = $db->prepare('SELECT user_id, role FROM users WHERE email = ? FOR UPDATE');
            $userStmt->execute([$reservation['email']]);
            $user = $userStmt->fetch();
            if ($user && $user['role'] !== 'tenant') {
                throw new RuntimeException('That email belongs to a non-tenant account.');
            }
            if ($user) {
                $existingTenant = $db->prepare('SELECT tenant_id FROM tenants WHERE user_id = ? FOR UPDATE');
                $existingTenant->execute([(int) $user['user_id']]);
                if ($existingTenant->fetch()) {
                    throw new RuntimeException('This applicant already has a tenant registration.');
                }
                $userId = (int) $user['user_id'];
                $db->prepare('UPDATE users SET first_name = ?, last_name = ?, phone = ? WHERE user_id = ?')
                    ->execute([$nameParts[0], $nameParts[1], $reservation['phone'], $userId]);
            } else {
                $temporarySecret = bin2hex(random_bytes(32));
                $db->prepare('INSERT INTO users (first_name, last_name, email, password_hash, phone, role) VALUES (?, ?, ?, ?, ?, \'tenant\')')
                    ->execute([$nameParts[0], $nameParts[1], $reservation['email'], password_hash($temporarySecret, PASSWORD_DEFAULT), $reservation['phone']]);
                $userId = (int) $db->lastInsertId();
            }

            $db->prepare("INSERT INTO tenants (user_id, room_id, status, approval_status, contact_number) VALUES (?, ?, 'Pending', 'Pending', ?)")
                ->execute([$userId, (int) $reservation['room_id'], $reservation['phone']]);
            $tenantId = (int) $db->lastInsertId();
            $db->prepare("UPDATE room_reservations SET status = 'Converted' WHERE id = ?")->execute([$reservationId]);
            refresh_reservation_room_status($db, (int) $reservation['room_id']);
            $db->commit();

            log_activity($db, 'tenant_registered', trim($reservation['applicant_name']) . ' was registered from a room reservation', $tenantId);
            $emailForNotice = $reservation['email'];
            $nameForNotice = $nameParts[0];
            flash('success', 'Reservation converted to a Pending tenant registration. The applicant can set their password using Forgot Password.');
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            flash('error', $e instanceof RuntimeException ? $e->getMessage() : 'Could not convert the reservation. Please try again.');
        }
        if ($emailForNotice !== null) {
            $resetUrl = absolute_url('/auth/forgot_password.php');
            $body = "Hi {$nameForNotice}, your room reservation has been converted into a tenant registration. An administrator will review your application. Set your account password using <a href=\"{$resetUrl}\">Forgot Password</a> on the sign-in page.";
            send_email_alert($emailForNotice, $nameForNotice, 'Tenant registration created', email_template('Tenant registration created', $body));
        }
        redirect($selfPath);
    }

    redirect($selfPath);
}

$search = str_input($_GET, 'q');
$statusFilter = str_input($_GET, 'status', 'all');
$allowedFilters = ['all', 'Pending', 'Confirmed', 'Cancelled', 'Converted'];
if (!in_array($statusFilter, $allowedFilters, true)) $statusFilter = 'all';
$where = [];
$params = [];
if ($statusFilter !== 'all') {
    $where[] = 'rr.status = ?';
    $params[] = $statusFilter;
}
if ($search !== '') {
    $where[] = '(rr.applicant_name LIKE ? OR rr.email LIKE ? OR rr.phone LIKE ? OR r.room_number LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$statusCounts = ['Pending' => 0, 'Confirmed' => 0, 'Cancelled' => 0, 'Converted' => 0];
$countWhere = '';
$countParams = [];
if ($search !== '') {
    $countWhere = 'WHERE (rr.applicant_name LIKE ? OR rr.email LIKE ? OR rr.phone LIKE ? OR r.room_number LIKE ?)';
    $countParams = array_fill(0, 4, '%' . $search . '%');
}
$countStmt = $db->prepare("SELECT rr.status, COUNT(*) AS c
    FROM room_reservations rr JOIN dorm_rooms r ON r.room_id = rr.room_id
    $countWhere GROUP BY rr.status");
$countStmt->execute($countParams);
foreach ($countStmt->fetchAll() as $countRow) {
    $statusCounts[$countRow['status']] = (int) $countRow['c'];
}
$totalCount = array_sum($statusCounts);
$statusPills = [
    'all' => 'All',
    'Pending' => 'Pending',
    'Confirmed' => 'Confirmed',
    'Cancelled' => 'Cancelled',
    'Converted' => 'Converted',
];

$result = paginate(
    $db,
    "SELECT rr.id, rr.applicant_name, rr.email, rr.phone, rr.reservation_date, rr.status, rr.created_at, r.room_number
     FROM room_reservations rr JOIN dorm_rooms r ON r.room_id = rr.room_id
     $whereSql ORDER BY FIELD(rr.status,'Pending','Confirmed','Cancelled','Converted'), rr.reservation_date ASC, rr.created_at DESC",
    "SELECT COUNT(*) c FROM room_reservations rr JOIN dorm_rooms r ON r.room_id = rr.room_id $whereSql",
    $params,
    10
);
$reservations = $result['rows'];
$availableRooms = $db->query("SELECT r.room_id, r.room_number, r.room_type, r.capacity, r.status,
        (SELECT COUNT(*) FROM tenants t WHERE t.room_id = r.room_id AND t.status IN ('Active','Pending')) +
        (SELECT COUNT(*) FROM room_reservations rr WHERE rr.room_id = r.room_id AND rr.status IN ('Pending','Confirmed')) AS allocated_count
    FROM dorm_rooms r
    WHERE r.status <> 'Under Maintenance'
    ORDER BY r.floor_number, r.room_number")->fetchAll();
$availableRooms = array_filter($availableRooms, static fn($room) => (int) $room['allocated_count'] < (int) $room['capacity']);

$pageTitle = 'Room Reservations';
include __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/module_tabs.php';
require_once __DIR__ . '/../includes/page_header.php';
render_page_header('bi-calendar-check', 'Room Reservations', 'Review room requests and convert confirmed applicants into tenant registrations.');
render_module_tabs([
  ['key' => 'registration', 'label' => 'Registration & Approval', 'href' => '/admin/tenants.php'],
  ['key' => 'status', 'label' => 'Track Status', 'href' => '/admin/tenant-status.php'],
  ['key' => 'checkin', 'label' => 'Check-in / Check-out', 'href' => '/admin/checkinout.php'],
  ['key' => 'reservations', 'label' => 'Reservations', 'href' => '/admin/reservations.php'],
], 'reservations');
?>

<div class="panel">
  <div class="panel-header"><h2>New Reservation</h2></div>
  <?php if (!$availableRooms): ?>
    <p class="text-muted mb-0">No rooms currently have reservation capacity.</p>
  <?php else: ?>
    <form method="post" class="row g-3 align-items-end">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create_reservation">
      <div class="col-lg-3"><label class="form-label" for="applicant_name">Applicant name</label><input class="form-control" id="applicant_name" name="applicant_name" required maxlength="120"></div>
      <div class="col-lg-3"><label class="form-label" for="reservation_email">Email</label><input type="email" class="form-control" id="reservation_email" name="email" required maxlength="255"></div>
      <div class="col-lg-2"><label class="form-label" for="reservation_phone">Phone</label><input type="tel" class="form-control" id="reservation_phone" name="phone" required maxlength="20" pattern="[0-9+() .-]{7,20}"></div>
      <div class="col-lg-2"><label class="form-label" for="room_id">Room</label><select class="form-select" id="room_id" name="room_id" required><option value="">Choose room</option><?php foreach ($availableRooms as $room): ?><option value="<?= (int) $room['room_id'] ?>">Room <?= clean($room['room_number']) ?> (<?= clean($room['room_type']) ?>, <?= (int) $room['capacity'] - (int) $room['allocated_count'] ?> available)</option><?php endforeach; ?></select></div>
      <div class="col-lg-2"><label class="form-label" for="reservation_date">Reservation date</label><input type="date" class="form-control" id="reservation_date" name="reservation_date" min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" required></div>
      <div class="col-12"><button class="btn btn-action-primary"><i class="bi bi-plus-lg"></i> Create Reservation</button></div>
    </form>
  <?php endif; ?>
</div>

<div class="panel mt-2">
    <div class="panel-header"><h2>Reservations</h2></div>
    <div class="filter-toolbar">
        <form class="search-box" method="get">
            <i class="bi bi-search"></i>
            <?php if ($statusFilter !== 'all'): ?><input type="hidden" name="status" value="<?= clean($statusFilter) ?>"><?php endif; ?>
            <input type="search" name="q" value="<?= clean($search) ?>" placeholder="Search applicant, room…" class="form-control form-control-sm">
        </form>
        <div class="filter-pills">
            <?php foreach ($statusPills as $key => $label):
                    $count = $key === 'all' ? $totalCount : $statusCounts[$key];
                    $qs = http_build_query(array_filter(['status' => $key === 'all' ? null : $key, 'q' => $search ?: null]));
            ?>
                <a href="?<?= $qs ?>" class="filter-pill <?= $statusFilter === $key ? 'active' : '' ?>"><?= clean($label) ?> <span class="pill-count"><?= $count ?></span></a>
            <?php endforeach; ?>
        </div>
        <span class="filter-result-count"><?= $result['total'] ?> result<?= $result['total'] === 1 ? '' : 's' ?></span>
    </div>

  <?php if (!$reservations): ?>
    <div class="empty-state-card text-center p-5"><i class="bi bi-calendar2-x fs-1 text-muted"></i><h5 class="mt-3 fw-bold text-dark">No reservations found</h5><p class="text-muted small mb-0">Try a different search or status filter.</p></div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table app-table align-middle">
        <thead><tr><th>Applicant</th><th>Contact</th><th>Room</th><th>Reservation date</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($reservations as $reservation): ?>
          <tr>
            <td><?= clean($reservation['applicant_name']) ?><div class="sub"><?= clean($reservation['email']) ?></div></td>
            <td><?= clean($reservation['phone']) ?></td>
            <td>Room <?= clean($reservation['room_number']) ?></td>
            <td><?= clean(date('M j, Y', strtotime($reservation['reservation_date']))) ?></td>
            <td><span class="badge badge-<?= status_badge_class($reservation['status']) ?>"><?= clean($reservation['status']) ?></span></td>
            <td class="text-end"><div class="d-flex justify-content-end gap-2 flex-wrap">
              <?php if ($reservation['status'] === 'Pending'): ?>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="confirm_reservation"><input type="hidden" name="reservation_id" value="<?= (int) $reservation['id'] ?>"><button class="btn btn-sm btn-action-approve"><i class="bi bi-check-lg"></i> Confirm</button></form>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="cancel_reservation"><input type="hidden" name="reservation_id" value="<?= (int) $reservation['id'] ?>"><button class="btn btn-sm btn-action-reject"><i class="bi bi-x-lg"></i> Cancel</button></form>
              <?php elseif ($reservation['status'] === 'Confirmed'): ?>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="convert_reservation"><input type="hidden" name="reservation_id" value="<?= (int) $reservation['id'] ?>"><button class="btn btn-sm btn-action-primary"><i class="bi bi-person-check"></i> Convert to Tenant</button></form>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="cancel_reservation"><input type="hidden" name="reservation_id" value="<?= (int) $reservation['id'] ?>"><button class="btn btn-sm btn-action-reject"><i class="bi bi-x-lg"></i> Cancel</button></form>
              <?php else: ?><span class="text-muted small">No actions</span><?php endif; ?>
            </div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= pagination_links($result['page'], $result['totalPages']) ?>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
