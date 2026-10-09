<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/app.php';

header('Content-Type: application/json');

try {
    $action = $_POST['action'] ?? '';
    $email = trim((string) ($_POST['email'] ?? ''));
    $csrf = trim((string) ($_POST['csrf_token'] ?? ''));

    if ($action !== 'resend_otp') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid resend request.']);
        exit;
    }

    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Your session expired. Please reload the page and try again.']);
        exit;
    }

    if ($email === '') {
        $email = trim((string) ($_SESSION['reset_email'] ?? $_SESSION['email'] ?? ''));
    }

    $sessionResetEmail = strtolower(trim((string) ($_SESSION['reset_email'] ?? '')));
    if ($email === '' || $sessionResetEmail === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Session expired. Please restart the password reset process.']);
        exit;
    }
    if (!hash_equals($sessionResetEmail, strtolower(trim($email)))) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'The email does not match this password-reset session. Please restart the process.']);
        exit;
    }
    $email = $sessionResetEmail;

    $stmt = get_db()->prepare('SELECT * FROM users WHERE LOWER(TRIM(email)) = ? AND is_active = TRUE');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'We could not find an account for that email address.']);
        exit;
    }

    $now = time();
    $createdAt = $user['reset_otp_created_at'] ?? null;
    $secondsSinceSent = $createdAt ? $now - strtotime((string) $createdAt) : PHP_INT_MAX;
    if ($secondsSinceSent < 60) {
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => 'Please wait a moment before requesting a new code.']);
        exit;
    }

    $otp = (string) random_int(100000, 999999);
    $_SESSION['reset_email'] = $email;
    $_SESSION['email'] = $email;
    $_SESSION['otp_last_sent_at'] = $now;
    $expires = date('Y-m-d H:i:s', $now + 10 * 60);

    $update = get_db()->prepare('UPDATE users SET reset_otp_code = ?, reset_otp_attempts = 0, reset_otp_created_at = ?, reset_otp_expires_at = ? WHERE user_id = ?');
    $updated = $update->execute([password_hash($otp, PASSWORD_DEFAULT), date('Y-m-d H:i:s', $now), $expires, $user['user_id']]);

    if (!$updated) {
        error_log('[OTP resend] Database update failed.');
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'The OTP could not be saved in the database. Please try again.']);
        exit;
    }

    $body = "Hi {$user['first_name']}, use this verification code to reset your password:\n\n{$otp}\n\nThis code expires in 10 minutes. If you didn't request this, you can safely ignore this email.";
    $sent = send_email_alert($user['email'], $user['first_name'], 'Your password reset code', email_template('Reset your password', $body));

    if (!$sent) {
        get_db()->prepare('UPDATE users SET reset_otp_code = NULL, reset_otp_attempts = 0, reset_otp_created_at = NULL, reset_otp_expires_at = NULL WHERE user_id = ?')->execute([$user['user_id']]);
        error_log('[OTP resend] Email delivery failed for user ID ' . (int) $user['user_id'] . '.');
        http_response_code(502);
        echo json_encode(['success' => false, 'message' => 'We could not send a new code right now because the mailer is unavailable. Please try again in a moment.']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => 'A new verification code has been sent to ' . $email . '.'
    ]);
    exit;
} catch (Throwable $e) {
    error_log('Resend OTP error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'We could not send a new code right now. Please try again.'
    ]);
    exit;
}
