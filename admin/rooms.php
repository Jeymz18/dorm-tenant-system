<?php
require_once __DIR__ . '/../config/app.php';
require_role('admin');

$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_room') {
        $roomId   = (int) ($_POST['room_id'] ?? 0);
        $number   = str_input($_POST, 'room_number');
        $type     = str_input($_POST, 'room_type');
        $capacity = (int) ($_POST['capacity'] ?? 1);
        $rate     = (float) ($_POST['monthly_rate'] ?? 0);
        $floor    = (int) ($_POST['floor_number'] ?? 1);
        $desc     = str_input($_POST, 'description');

        if ($number === '' || $type === '' || $rate <= 0 || $capacity <= 0) {
            flash('error', 'Please fill in room number, type, a valid capacity, and a valid monthly rate.');
        } else {
            try {
                if ($roomId > 0) {
                    $db->prepare('UPDATE dorm_rooms SET room_number=?, room_type=?, capacity=?, monthly_rate=?, floor_number=?, description=? WHERE room_id=?')
                       ->execute([$number, $type, $capacity, $rate, $floor, $desc, $roomId]);
                    flash('success', "Room $number updated.");
                } else {
                    $db->prepare('INSERT INTO dorm_rooms (room_number, room_type, capacity, monthly_rate, floor_number, description) VALUES (?,?,?,?,?,?)')
                       ->execute([$number, $type, $capacity, $rate, $floor, $desc]);
                    flash('success', "Room $number added.");
                }
            } catch (PDOException $e) {
                flash('error', 'That room number already exists.');
            }
        }
    }

    if ($action === 'assign_tenant') {
        $roomId   = (int) ($_POST['room_id'] ?? 0);
        $tenantId = (int) ($_POST['tenant_id'] ?? 0);

        $db->beginTransaction();
        $room = $db->prepare("SELECT r.*,
          (SELECT COUNT(*) FROM tenants t WHERE t.room_id = r.room_id AND t.status IN ('Active','Pending')) AS tenant_count,
          (SELECT COUNT(*) FROM room_reservations rr WHERE rr.room_id = r.room_id AND rr.status IN ('Pending','Confirmed')) AS reservation_count
          FROM dorm_rooms r WHERE r.room_id = ? FOR UPDATE");
        $room->execute([$roomId]);
        $room = $room->fetch();

        if (!$room || $room['status'] === 'Under Maintenance' || (int) $room['tenant_count'] + (int) $room['reservation_count'] >= (int) $room['capacity']) {
            $db->rollBack();
            flash('error', 'That room is no longer available.');
        } elseif ($tenantId <= 0) {
            $db->rollBack();
            flash('error', 'Please choose a tenant to assign.');
        } else {
            try {
                $db->prepare('UPDATE tenants SET room_id = ? WHERE tenant_id = ?')->execute([$roomId, $tenantId]);
                $newTenantCount = (int) $room['tenant_count'] + 1;
                $newAllocationCount = $newTenantCount + (int) $room['reservation_count'];
                $newStatus = $newTenantCount >= (int) $room['capacity'] ? 'Occupied' : ($newAllocationCount >= (int) $room['capacity'] ? 'Reserved' : 'Available');
                $db->prepare('UPDATE dorm_rooms SET status = ? WHERE room_id = ?')->execute([$newStatus, $roomId]);
                $db->commit();
                flash('success', 'Tenant assigned to Room ' . $room['room_number'] . '.');
            } catch (Exception $e) {
                $db->rollBack();
                flash('error', 'Could not assign tenant. Please try again.');
            }
        }
    }

    redirect('/admin/rooms.php');
}

$totalRooms  = (int) $db->query('SELECT COUNT(*) c FROM dorm_rooms')->fetch()['c'];
$available   = (int) $db->query("SELECT COUNT(*) c FROM dorm_rooms WHERE status='Available'")->fetch()['c'];
$occupied    = (int) $db->query("SELECT COUNT(*) c FROM dorm_rooms WHERE status='Occupied'")->fetch()['c'];
$occupancyRate = $totalRooms > 0 ? round(($occupied / $totalRooms) * 100) : 0;

$rooms = $db->query("
    SELECT r.*,
      (SELECT COUNT(*) FROM tenants t2 WHERE t2.room_id = r.room_id AND t2.status = 'Active') AS occupancy_count,
      (SELECT COUNT(*) FROM tenants t3 WHERE t3.room_id = r.room_id AND t3.status IN ('Active','Pending')) +
      (SELECT COUNT(*) FROM room_reservations rr WHERE rr.room_id = r.room_id AND rr.status IN ('Pending','Confirmed')) AS allocation_count,
       u.first_name, u.last_name
    FROM dorm_rooms r
    LEFT JOIN tenants t ON t.room_id = r.room_id AND t.status = 'Active'
    LEFT JOIN users u ON u.user_id = t.user_id
    ORDER BY r.floor_number, r.room_number
")->fetchAll();

// Approved tenants without a room yet — these are the only ones assignable
$unassignedTenants = $db->query("
    SELECT t.tenant_id, u.first_name, u.last_name
    FROM tenants t JOIN users u ON u.user_id = t.user_id
    WHERE t.room_id IS NULL AND t.approval_status = 'Approved'
    ORDER BY u.first_name
")->fetchAll();

// Coming from the "Assign Room" shortcut on Tenant Approval? Pre-select
// them in the modal once the admin picks a room, instead of making them
// find the name again in the dropdown.
$preselectTenant = null;
foreach ($unassignedTenants as $t) {
    if ((int) $t['tenant_id'] === (int) ($_GET['tenant'] ?? 0)) {
        $preselectTenant = $t;
        break;
    }
}

$activeTab = str_input($_GET, 'tab') ?: 'rooms';
if (!in_array($activeTab, ['rooms', 'assign', 'availability'], true)) {
    $activeTab = 'rooms';
}

$pageTitle = 'Property Management';
include __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/module_tabs.php';
require_once __DIR__ . '/../includes/page_header.php';
render_page_header(
    'bi-door-open-fill',
    'Property Management',
    'Manage dorm rooms, assign tenants, and monitor availability.',
    '<button type="button" class="btn btn-top-action" data-bs-toggle="modal" data-bs-target="#roomModal"><i class="bi bi-plus-lg"></i> Add Room</button>'
);
render_module_tabs([
  ['key' => 'rooms', 'label' => 'Room Inventory', 'href' => '/admin/rooms.php?tab=rooms#rooms'],
  ['key' => 'assign', 'label' => 'Assign Tenants', 'href' => '/admin/rooms.php?tab=assign#rooms'],
  ['key' => 'availability', 'label' => 'Monitor Availability', 'href' => '/admin/rooms.php?tab=availability#rooms'],
], $activeTab); ?>

<div class="stat-grid stat-grid-4">
  <div class="stat-card"><div class="stat-card-body"><div class="stat-label">Total Rooms</div><div class="stat-value"><?= $totalRooms ?></div></div><div class="stat-icon stat-icon-outline"><i class="bi bi-building"></i></div></div>
  <div class="stat-card"><div class="stat-card-body"><div class="stat-label">Available</div><div class="stat-value text-success"><?= $available ?></div></div><div class="stat-icon stat-icon-outline"><i class="bi bi-door-open"></i></div></div>
  <div class="stat-card"><div class="stat-card-body"><div class="stat-label">Occupied</div><div class="stat-value"><?= $occupied ?></div></div><div class="stat-icon stat-icon-outline"><i class="bi bi-door-open"></i></div></div>
  <div class="stat-card"><div class="stat-card-body"><div class="stat-label">Occupancy</div><div class="stat-value"><?= $occupancyRate ?>%</div></div><div class="stat-icon stat-icon-outline"><i class="bi bi-building"></i></div></div>
</div>

<?php if ($preselectTenant && $activeTab === 'assign'): ?>
  <div class="callout callout-info mt-2">
    <i class="bi bi-info-circle-fill"></i>
    <div>Assigning a room for <strong><?= clean($preselectTenant['first_name'] . ' ' . $preselectTenant['last_name']) ?></strong> — click <strong>Assign</strong> on any available room below.</div>
  </div>
<?php endif; ?>

<?php
  $byFloor = [];
  foreach ($rooms as $r) { $byFloor[(int) $r['floor_number']][] = $r; }
  krsort($byFloor);

  function render_room_card(array $r, string $mode): void {
    $isAssignMode = $mode === 'assign';
    ?>
    <div class="room-card">
      <div class="room-card-top">
        <strong>Room <?= clean($r['room_number']) ?></strong>
        <span class="door-icon"><i class="bi bi-door-open"></i></span>
      </div>
      <div class="text-muted small"><?= clean($r['room_type']) ?></div>
      <div class="room-card-rate"><?= peso($r['monthly_rate']) ?><span class="text-muted fw-normal">/mo</span></div>
      <div class="text-muted small mt-1">Capacity: <?= (int) $r['capacity'] ?></div>
      <?php if ($r['first_name'] && !$isAssignMode): ?>
        <div class="small mt-2"><i class="bi bi-person-fill"></i> <?= clean($r['first_name'] . ' ' . $r['last_name']) ?></div>
      <?php elseif ($isAssignMode): ?>
        <div class="small mt-2"><i class="bi bi-people-fill"></i> <?= (int) $r['occupancy_count'] ?> / <?= (int) $r['capacity'] ?> occupied</div>
      <?php else: ?>
        <div class="small mt-2"><span class="badge badge-<?= status_badge_class($r['status']) ?>"><?= clean($r['status']) ?></span></div>
      <?php endif; ?>
      <div class="room-card-actions">
        <?php if ($isAssignMode): ?>
        <button type="button" class="btn btn-sm btn-action-outline w-100" data-bs-toggle="modal" data-bs-target="#assignModal"
          data-room-id="<?= $r['room_id'] ?>" data-room-number="<?= clean($r['room_number']) ?>">Assign Tenant</button>
        <?php else: ?>
        <button type="button" class="btn btn-sm btn-action-outline edit-room-btn" data-bs-toggle="modal" data-bs-target="#roomModal"
          data-id="<?= $r['room_id'] ?>" data-number="<?= clean($r['room_number']) ?>" data-type="<?= clean($r['room_type']) ?>"
          data-capacity="<?= (int) $r['capacity'] ?>" data-rate="<?= clean((string) $r['monthly_rate']) ?>" data-floor="<?= (int) $r['floor_number'] ?>"
          data-description="<?= clean($r['description'] ?? '') ?>">Edit</button>
        <?php endif; ?>
      </div>
    </div>
    <?php
  }
?>

<?php if ($activeTab === 'rooms'): ?>
  <div class="panel mt-2" id="rooms">
    <div class="panel-header"><h2>Room Grid</h2></div>
          <?php foreach ($byFloor as $floorNum => $floorRooms): ?>
      <div class="floor-group">
        <div class="floor-label">Floor <?= $floorNum ?></div>
        <div class="room-grid">
          <?php foreach ($floorRooms as $r): render_room_card($r, 'inventory'); endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$rooms): ?><p class="text-muted text-center py-4">No rooms yet — add your first one above.</p><?php endif; ?>
  </div>

<?php elseif ($activeTab === 'assign'): ?>
  <div class="row g-4 mt-0" id="rooms">
    <div class="col-lg-8">
      <div class="panel">
        <?php $availableRooms = array_filter($rooms, fn($r) => $r['status'] !== 'Under Maintenance' && (int) $r['allocation_count'] < (int) $r['capacity']); ?>
        <div class="panel-header"><h2>Available Rooms</h2><span class="text-muted small"><?= count($availableRooms) ?> ready to assign</span></div>
        <?php if (!$availableRooms): ?><p class="text-muted text-center py-4">No available rooms right now.</p><?php endif; ?>
        <div class="room-grid">
          <?php foreach ($availableRooms as $r): render_room_card($r, 'assign'); endforeach; ?>
        </div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="panel">
        <div class="panel-header"><h2>Tenants Waiting</h2></div>
        <?php if (!$unassignedTenants): ?><p class="text-muted mb-0">No approved tenants are waiting for a room right now.</p><?php endif; ?>
        <?php foreach ($unassignedTenants as $t): ?>
          <div class="detail-row"><span><i class="bi bi-person-fill"></i> <?= clean($t['first_name'] . ' ' . $t['last_name']) ?></span></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

<?php else: /* availability */ ?>
  <div class="row g-4 mt-0" id="rooms">
    <div class="col-lg-6">
      <div class="panel">
        <div class="panel-header"><h2>Availability by Floor</h2></div>
        <?php foreach ($byFloor as $floorNum => $floorRooms):
          $floorAvailable = count(array_filter($floorRooms, fn($r) => $r['status'] === 'Available'));
          $floorTotal = count($floorRooms);
          $pct = $floorTotal > 0 ? round($floorAvailable / $floorTotal * 100) : 0;
        ?>
          <div class="mix-bar-row">
            <div class="mix-bar-label"><span>Floor <?= $floorNum ?></span><span class="text-muted"><?= $floorAvailable ?> / <?= $floorTotal ?> available</span></div>
            <div class="mix-bar-track"><div class="mix-bar-fill" style="width:<?= $pct ?>%;background:#2fa15c"></div></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="panel">
        <div class="panel-header"><h2>Availability by Room Type</h2></div>
        <?php
          $byType = [];
          foreach ($rooms as $r) {
            $byType[$r['room_type']]['total'] = ($byType[$r['room_type']]['total'] ?? 0) + 1;
            $byType[$r['room_type']]['available'] = ($byType[$r['room_type']]['available'] ?? 0) + ($r['status'] === 'Available' ? 1 : 0);
          }
        ?>
        <?php foreach ($byType as $type => $counts): $pct = $counts['total'] > 0 ? round($counts['available'] / $counts['total'] * 100) : 0; ?>
          <div class="mix-bar-row">
            <div class="mix-bar-label"><span><?= clean($type) ?></span><span class="text-muted"><?= $counts['available'] ?> / <?= $counts['total'] ?> available</span></div>
            <div class="mix-bar-track"><div class="mix-bar-fill" style="width:<?= $pct ?>%;background:var(--maroon)"></div></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
<?php endif; ?>

<!-- Add/Edit Room Modal -->
<div class="modal fade" id="roomModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_room">
        <input type="hidden" name="room_id" id="room_id">
        <div class="modal-header"><h5 class="modal-title" id="roomModalTitle">Add Room</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Room Number</label><input class="form-control" name="room_number" id="room_number" required></div>
            <div class="col-md-6"><label class="form-label">Room Type</label><input class="form-control" name="room_type" id="room_type" placeholder="Studio / 1BR / 2BR" required></div>
          </div>
          <div class="row g-3 mt-0">
            <div class="col-md-4"><label class="form-label">Capacity</label><input type="number" min="1" class="form-control" name="capacity" id="capacity" value="1" required></div>
            <div class="col-md-4"><label class="form-label">Monthly Rate (₱)</label><input type="number" min="0" step="0.01" class="form-control" name="monthly_rate" id="monthly_rate" required></div>
            <div class="col-md-4"><label class="form-label">Floor</label><input type="number" min="1" class="form-control" name="floor_number" id="floor_number" value="1" required></div>
          </div>
          <div class="mb-1 mt-3"><label class="form-label">Description</label><textarea class="form-control" name="description" id="description" rows="2"></textarea></div>
        </div>
        <div class="modal-footer"><button class="btn btn-light" data-bs-dismiss="modal" type="button">Cancel</button><button class="btn btn-sm btn-action-primary">Save Room</button></div>
      </form>
    </div>
  </div>
</div>

<!-- Assign Tenant Modal -->
<div class="modal fade" id="assignModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="assign_tenant">
        <input type="hidden" name="room_id" id="assign_room_id">
        <div class="modal-header"><h5 class="modal-title">Assign Tenant to Room <span id="assign_room_number"></span></h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <?php if (!$unassignedTenants): ?>
            <p class="text-muted">No approved tenants are waiting for a room right now. Approve applicants first in <a href="<?= BASE_URL ?>/admin/tenants.php">Tenant Management</a>.</p>
          <?php else: ?>
            <label class="form-label">Tenant</label>
            <select class="form-select" name="tenant_id" id="assign_tenant_id" required>
              <option value="">Choose a tenant…</option>
              <?php foreach ($unassignedTenants as $t): ?>
                <option value="<?= $t['tenant_id'] ?>"><?= clean($t['first_name'] . ' ' . $t['last_name']) ?></option>
              <?php endforeach; ?>
            </select>
          <?php endif; ?>
        </div>
        <div class="modal-footer">
          <button class="btn btn-light" data-bs-dismiss="modal" type="button">Cancel</button>
          <?php if ($unassignedTenants): ?><button class="btn btn-sm btn-action-primary">Assign Tenant</button><?php endif; ?>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraScripts = "<script>
document.getElementById('roomModal').addEventListener('show.bs.modal', function (e) {
  const btn = e.relatedTarget;
  const isEdit = btn && btn.classList.contains('edit-room-btn');
  document.getElementById('roomModalTitle').textContent = isEdit ? ('Edit Room ' + btn.dataset.number) : 'Add Room';
  document.getElementById('room_id').value = isEdit ? btn.dataset.id : '';
  document.getElementById('room_number').value = isEdit ? btn.dataset.number : '';
  document.getElementById('room_type').value = isEdit ? btn.dataset.type : '';
  document.getElementById('capacity').value = isEdit ? btn.dataset.capacity : 1;
  document.getElementById('monthly_rate').value = isEdit ? btn.dataset.rate : '';
  document.getElementById('floor_number').value = isEdit ? btn.dataset.floor : 1;
  document.getElementById('description').value = isEdit ? btn.dataset.description : '';
});
document.getElementById('assignModal').addEventListener('show.bs.modal', function (e) {
  const btn = e.relatedTarget;
  document.getElementById('assign_room_id').value = btn.dataset.roomId;
  document.getElementById('assign_room_number').textContent = btn.dataset.roomNumber;
  const preselect = " . (int) ($preselectTenant['tenant_id'] ?? 0) . ";
  const tenantSelect = document.getElementById('assign_tenant_id');
  if (preselect && tenantSelect.querySelector('option[value=\"' + preselect + '\"]')) {
    tenantSelect.value = preselect;
  }
});
</script>";
include __DIR__ . '/../includes/footer.php';
?>
