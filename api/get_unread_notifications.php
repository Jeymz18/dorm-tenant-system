<?php
declare(strict_types=1);

ob_start();
error_reporting(0);
ini_set('display_errors', '0');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/app.php';

function notification_json(array $payload, int $status = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

function notification_count(PDO $database, string $query, array $parameters): int
{
    try {
        $statement = $database->prepare($query);
        $statement->execute($parameters);
        return (int) $statement->fetchColumn();
    } catch (Throwable $exception) {
        error_log('Notification count query failed: ' . $exception->getMessage());
        return 0;
    }
}

function normalize_notification_counts(array $counts): array
{
    return [
        'notifications' => (int) ($counts['notifications'] ?? 0),
        'payments' => (int) ($counts['payments'] ?? 0),
        'maintenance' => (int) ($counts['maintenance'] ?? 0),
        'tenants' => (int) ($counts['tenants'] ?? 0),
        'announcements' => (int) ($counts['announcements'] ?? 0),
    ];
}

try {
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $tenantId = (int) ($_SESSION['tenant_id'] ?? 0);
    $role = strtolower((string) ($_SESSION['role'] ?? ''));
    $emptyCounts = ['tenants' => 0, 'payments' => 0, 'maintenance' => 0, 'notifications' => 0, 'announcements' => 0];

    if (!$userId || !in_array($role, ['super_admin', 'admin', 'tenant'], true)) {
        notification_json(['status' => 'success', 'counts' => normalize_notification_counts($emptyCounts)]);
    }

    $database = get_db();

    $counts = $emptyCounts;

    if (in_array($role, ['super_admin', 'admin'], true)) {
        // Admin: Pending tenant approvals
        $counts['tenants'] = notification_count(
            $database,
            "SELECT COUNT(*) FROM tenants WHERE approval_status = 'Pending'",
            []
        );

        // Admin: Pending payments to review
        $counts['payments'] = notification_count(
            $database,
            "SELECT COUNT(*) FROM payments WHERE payment_status IN ('Pending', 'Overdue')",
            []
        );

        // Admin: Pending or active maintenance requests needing attention
        $counts['maintenance'] = notification_count(
            $database,
            "SELECT COUNT(*) FROM maintenance_requests WHERE is_resolved = 0",
            []
        );

        // Informational announcements use the notification dot.
        $counts['announcements'] = notification_count(
            $database,
                        "SELECT COUNT(*) FROM notifications n
                         LEFT JOIN notification_reads nr ON nr.notification_id = n.notification_id AND nr.user_id = ?
                         WHERE n.type = 'Announcement' AND nr.seen_at IS NULL",
            [$userId]
        );

        // Direct alerts require review and use the number badge.
        $counts['notifications'] = notification_count(
            $database,
                        "SELECT COUNT(*) FROM notifications n
                         LEFT JOIN notification_reads nr ON nr.notification_id = n.notification_id AND nr.user_id = ?
                         WHERE n.type <> 'Announcement' AND nr.seen_at IS NULL",
            [$userId]
        );

    } elseif ($role === 'tenant') {
        // Tenant Identity Resolution
        $tenant = null;
        $sessionTenantId = $tenantId ?: null;

        if ($sessionTenantId !== null && is_numeric($sessionTenantId)) {
            $stmt = $database->prepare('SELECT * FROM tenants WHERE tenant_id = ?');
            $stmt->execute([(int)$sessionTenantId]);
            $tenant = $stmt->fetch() ?: null;
        }

        if (!$tenant && $userId) {
            $stmt = $database->prepare('SELECT * FROM tenants WHERE user_id = ? OR tenant_id = ?');
            $stmt->execute([(int)$userId, (int)$userId]);
            $tenant = $stmt->fetch() ?: null;
        }

        if ($tenant) {
            $tenantId = (int) $tenant['tenant_id'];

            $roomNumber = '__none__';
            if (!empty($tenant['room_id'])) {
                try {
                    $roomStatement = $database->prepare('SELECT room_number FROM dorm_rooms WHERE room_id = ?');
                    $roomStatement->execute([$tenant['room_id']]);
                    $roomNumber = (string) ($roomStatement->fetchColumn() ?: '__none__');
                } catch (Throwable $e) {
                    // Fail silently
                }
            }

            $counts['maintenance'] = notification_count(
                $database,
                "SELECT COUNT(*) FROM maintenance_requests m
                 LEFT JOIN maintenance_reads mr ON mr.maintenance_id = m.maintenance_id AND mr.tenant_id = m.tenant_id
                 WHERE m.tenant_id = ? AND m.is_resolved = 0 AND m.updated_at IS NOT NULL
                   AND (mr.seen_updated_at IS NULL OR m.updated_at > mr.seen_updated_at)",
                [$tenantId]
            );

            // Pending or overdue bills need tenant attention.
            $counts['payments'] = notification_count(
                $database,
                "SELECT COUNT(*) FROM payments WHERE tenant_id = ? AND payment_status IN ('Pending', 'Overdue')",
                [$tenantId]
            );

            // Target notifications to this tenant or their room.
            $targetFilter = "(target_type = 'all' OR (target_type = 'tenant' AND target_value = ?) OR (target_type = 'room' AND FIND_IN_SET(?, REPLACE(target_value, ' ', ''))))";
            $counts['announcements'] = notification_count(
                $database,
                "SELECT COUNT(*) FROM notifications n
                 LEFT JOIN notification_reads nr ON nr.notification_id = n.notification_id AND nr.user_id = ?
                 WHERE n.type = 'Announcement' AND {$targetFilter} AND nr.seen_at IS NULL
                   AND n.date_sent >= (SELECT date_created FROM users WHERE user_id = ?)",
                [$userId, (string) $tenantId, $roomNumber, $userId]
            );
            $counts['notifications'] = notification_count(
                $database,
                "SELECT COUNT(*) FROM notifications n
                 LEFT JOIN notification_reads nr ON nr.notification_id = n.notification_id AND nr.user_id = ?
                 WHERE n.type <> 'Announcement' AND {$targetFilter} AND nr.seen_at IS NULL
                   AND n.date_sent >= (SELECT date_created FROM users WHERE user_id = ?)",
                [$userId, (string) $tenantId, $roomNumber, $userId]
            );
        }
    }

    notification_json(['status' => 'success', 'counts' => normalize_notification_counts($counts)]);
} catch (Throwable $exception) {
    error_log('Unread notification count error: ' . $exception->getMessage());
    notification_json(['status' => 'success', 'counts' => normalize_notification_counts($emptyCounts)]);
}