<?php
/**
 * cron/check_expirations.php
 *
 * Run this on a schedule to auto-generate contract-expiry and
 * overdue-payment notifications (+ email alerts). This is the
 * "Workflow Automation" piece — Notification Management (in the
 * admin panel) covers manual sends; this script covers the automatic
 * ones so nothing has to be checked by hand every day.
 *
 * Windows Task Scheduler setup:
 *   Program/script : C:\xampp\php\php.exe
 *   Arguments      : "C:\xampp\htdocs\dorm-tenant-system\cron\check_expirations.php"
 *   Trigger        : Daily, e.g. 7:00 AM
 *
 * macOS/Linux cron:
 *   0 7 * * * /Applications/XAMPP/xamppfiles/bin/php /path/to/dorm-tenant-system/cron/check_expirations.php
 *
 * You can also just double-click / run this manually while testing.
 */

require_once __DIR__ . '/../config/app.php';

$db = get_db();
// Do not assume the seeded admin has user_id=1: imported databases and
// existing installations may have different auto-increment values.
$senderStmt = $db->query("SELECT user_id FROM users WHERE is_active = 1 AND role IN ('super_admin','admin') ORDER BY (role = 'super_admin') DESC, user_id ASC LIMIT 1");
$systemUserId = $senderStmt->fetchColumn();
if ($systemUserId === false) {
    fwrite(STDERR, 'Expiration check aborted: no active admin account is available to own system notifications.' . PHP_EOL);
    exit(1);
}
$systemUserId = (int) $systemUserId;
$sentCount = 0;

function create_notification_once(PDO $db, int $senderId, string $type, string $subject, string $message, int $tenantId, string $sourceKey): bool
{
    $statement = $db->prepare("INSERT IGNORE INTO notifications (sender_id, type, subject, message, target_type, target_value, source_key)
        VALUES (?, ?, ?, ?, 'tenant', ?, ?)");
    $statement->execute([$senderId, $type, $subject, $message, $tenantId, $sourceKey]);
    return $statement->rowCount() === 1;
}

// ---- 0) Roll contract statuses forward --------------------------------
// Leases that ran past their end date become 'Expired', ones inside the
// 30-day window become 'Expiring Soon'. The app does this on page load
// too, but running it here means it happens even on a quiet day when
// nobody signs in.
refresh_contract_statuses($db);

// ---- 1) Contracts expiring within the next 7 days ---------------------
$stmt = $db->query("
    SELECT c.contract_id, c.contract_end, u.first_name, u.email, t.tenant_id
    FROM contracts c
    JOIN tenants t ON t.tenant_id = c.tenant_id
    JOIN users u ON u.user_id = t.user_id
    WHERE c.contract_status IN ('Active','Expiring Soon')
      AND c.contract_end BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
");
foreach ($stmt->fetchAll() as $row) {
    $subject = 'Your dorm contract is expiring soon';
    $message = "Hi {$row['first_name']}, your contract ends on "
             . date('F j, Y', strtotime($row['contract_end']))
             . '. Please visit the office to renew.';

    if (create_notification_once($db, $systemUserId, 'Contract Expiry Alert', $subject, $message, (int) $row['tenant_id'], 'contract-expiring-' . $row['contract_id'] . '-' . $row['contract_end'])) {
        send_email_alert($row['email'], $row['first_name'], $subject, email_template($subject, $message));
        $sentCount++;
    }
}

// ---- 1b) Contracts that expired yesterday -----------------------------
// One clear "it's up, here's what happens next" alert on the day after
// the term ends. Bounded to a single day so this can't re-send every
// morning for a lease nobody has gotten around to closing out.
$expiredStmt = $db->query("
    SELECT c.contract_id, c.contract_end, u.first_name, u.email, t.tenant_id
    FROM contracts c
    JOIN tenants t ON t.tenant_id = c.tenant_id
    JOIN users u ON u.user_id = t.user_id
    WHERE c.contract_status = 'Expired'
      AND c.contract_end = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
");
foreach ($expiredStmt->fetchAll() as $row) {
    $subject = 'Your dorm contract has expired';
    $message = "Hi {$row['first_name']}, your contract ended on "
             . date('F j, Y', strtotime($row['contract_end']))
             . '. You can request a renewal from My Room & Contract in your portal, or visit the office to arrange moving out.';

    if (create_notification_once($db, $systemUserId, 'Contract Expiry Alert', $subject, $message, (int) $row['tenant_id'], 'contract-expired-' . $row['contract_id'] . '-' . $row['contract_end'])) {
        send_email_alert($row['email'], $row['first_name'], $subject, email_template($subject, $message));
        $sentCount++;
    }
}

// ---- 2) Payments that are now overdue ----------------------------------
$stmt2 = $db->query("
    SELECT p.payment_id, p.due_date, u.first_name, u.email, t.tenant_id
    FROM payments p
    JOIN tenants t ON t.tenant_id = p.tenant_id
    JOIN users u ON u.user_id = t.user_id
    WHERE p.payment_status = 'Pending' AND p.due_date < CURDATE()
");
foreach ($stmt2->fetchAll() as $row) {
    $updated = $db->prepare("UPDATE payments SET payment_status = 'Overdue', reminder_sent = TRUE WHERE payment_id = ? AND payment_status = 'Pending'");
    $updated->execute([$row['payment_id']]);
    if ($updated->rowCount() !== 1) {
        continue;
    }
    log_action('payment_overdue', 'Payment #' . $row['payment_id'] . ' changed to Overdue by expiration check');

    $subject = 'Payment overdue — action needed';
    $message = "Hi {$row['first_name']}, your payment due on "
             . date('F j, Y', strtotime($row['due_date']))
             . ' is now overdue. Please settle it as soon as you can.';

    if (create_notification_once($db, $systemUserId, 'Payment Reminder', $subject, $message, (int) $row['tenant_id'], 'payment-overdue-' . $row['payment_id'])) {
        send_email_alert($row['email'], $row['first_name'], $subject, email_template($subject, $message));
        $sentCount++;
    }
}

echo '[' . date('Y-m-d H:i:s') . "] Expiration check complete — {$sentCount} alert(s) generated." . PHP_EOL;
