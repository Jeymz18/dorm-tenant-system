<?php
require_once __DIR__ . '/../config/app.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Please use the Log Out button to end your session.');
}
csrf_verify();
logout_user();
header('Location: ' . BASE_URL . '/auth/login.php');
exit;
