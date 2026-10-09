<?php
if (!defined('BASE_URL')) { http_response_code(403); exit('Direct access not permitted.'); }
/**
 * includes/report_data.php
 * Shared query logic for the 4 report types, used by both the
 * on-screen print view (report_print.php) and the PDF download
 * (report_export.php) so they never drift out of sync.
 */

function report_types(): array
{
    return ['occupancy' => 'Occupancy', 'payment' => 'Payment', 'tenant' => 'Tenant', 'maintenance' => 'Maintenance'];
}

/** Returns ['rows' => [...], 'columns' => ['Label' => 'field', ...]] for a report type. */
function fetch_report_data(PDO $db, string $type): array
{
    switch ($type) {
        case 'occupancy':
            $rows = $db->query("SELECT room_number, room_type, capacity, monthly_rate, floor_number, status FROM dorm_rooms ORDER BY floor_number, room_number")->fetchAll();
            $columns = ['Room' => 'room_number', 'Type' => 'room_type', 'Capacity' => 'capacity', 'Monthly Rate' => 'monthly_rate', 'Floor' => 'floor_number', 'Status' => 'status'];
            break;
        case 'payment':
            $rows = $db->query("
                SELECT u.first_name, u.last_name, r.room_number, p.payment_for_month, p.payment_amount, p.payment_status, p.payment_date
                FROM payments p JOIN tenants t ON t.tenant_id=p.tenant_id JOIN users u ON u.user_id=t.user_id LEFT JOIN dorm_rooms r ON r.room_id=t.room_id
                ORDER BY p.due_date DESC
            ")->fetchAll();
            $columns = ['First Name' => 'first_name', 'Last Name' => 'last_name', 'Room' => 'room_number', 'Month' => 'payment_for_month', 'Amount' => 'payment_amount', 'Status' => 'payment_status', 'Date' => 'payment_date'];
            break;
        case 'tenant':
            $rows = $db->query("
                SELECT u.first_name, u.last_name, u.email, u.phone, r.room_number, t.status, t.checkin_date
                FROM tenants t JOIN users u ON u.user_id=t.user_id LEFT JOIN dorm_rooms r ON r.room_id=t.room_id
                WHERE t.approval_status='Approved' ORDER BY u.first_name
            ")->fetchAll();
            $columns = ['First Name' => 'first_name', 'Last Name' => 'last_name', 'Email' => 'email', 'Phone' => 'phone', 'Room' => 'room_number', 'Status' => 'status', 'Check-in' => 'checkin_date'];
            break;
        case 'maintenance':
            $rows = $db->query("
                SELECT m.issue_title, u.first_name, u.last_name, m.priority_level, m.status, m.assigned_to, m.date_submitted, m.date_resolved
                FROM maintenance_requests m JOIN tenants t ON t.tenant_id=m.tenant_id JOIN users u ON u.user_id=t.user_id
                ORDER BY m.date_submitted DESC
            ")->fetchAll();
            $columns = ['Issue' => 'issue_title', 'Tenant' => 'first_name', 'Priority' => 'priority_level', 'Status' => 'status', 'Assigned To' => 'assigned_to', 'Submitted' => 'date_submitted', 'Resolved' => 'date_resolved'];
            break;
        default:
            $rows = [];
            $columns = [];
    }

    return ['rows' => $rows, 'columns' => $columns];
}

/** The <table> markup shared by the print view and the PDF export. */
function render_report_table(array $columns, array $rows): string
{
    ob_start();
    ?>
    <table>
        <thead><tr><?php foreach (array_keys($columns) as $label): ?><th><?= htmlspecialchars($label) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="<?= count($columns) ?>" style="text-align:center;color:#6c757d;">No records found.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <?php foreach ($columns as $field): ?>
                        <td><?= htmlspecialchars((string) ($row[$field] ?? '—')) ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
    return ob_get_clean();
}
