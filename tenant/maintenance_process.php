<?php
require_once __DIR__ . '/../config/app.php';
require_role('tenant');

$db = get_db();
$tenant = current_tenant();

if (!$tenant || $tenant['approval_status'] !== 'Approved') {
    redirect('/tenant/dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/tenant/maintenance.php');
}

csrf_verify();

if (!$tenant['room_id']) {
    flash('error', "You don't have a room assigned yet, so there's nothing to file a request for.");
    redirect('/tenant/maintenance.php');
}

$title = str_input($_POST, 'issue_type');
$desc  = str_input($_POST, 'description');

if ($title === '' || $desc === '') {
    flash('error', 'Please choose an issue type and describe the problem.');
    redirect('/tenant/maintenance.php');
}

try {
    $photo = null;
    $photoField = !empty($_FILES['camera_photo']['name']) ? 'camera_photo' : 'maintenance_photo';
    if (!empty($_FILES[$photoField]['name']) && $_FILES[$photoField]['error'] !== UPLOAD_ERR_NO_FILE) {
        $photo = handle_upload($photoField, 'maintenance', ['jpg', 'jpeg', 'png']);
    }

    $db->prepare('INSERT INTO maintenance_requests (tenant_id, room_id, issue_title, issue_description, photo_file) VALUES (?,?,?,?,?)')
       ->execute([$tenant['tenant_id'], $tenant['room_id'], $title, $desc, $photo]);

    log_activity($db, 'maintenance_submitted', $title . ' request submitted', $tenant['tenant_id']);
    flash('success', 'Maintenance request submitted.');
} catch (RuntimeException $e) {
    flash('error', $e->getMessage());
}

redirect('/tenant/maintenance.php');
