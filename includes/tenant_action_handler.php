<?php
if (!defined('BASE_URL')) { http_response_code(403); exit('Direct access not permitted.'); }
/**
 * includes/tenant_action_handler.php
 *
 * Shared POST handler for the tenant lifecycle: approve, reject,
 * check-in, check-out, evict. The prototype spreads these actions
 * across three separate screens (Tenant Registration/Approval, Track
 * Status, Monitor Check-In/Check-out), but the underlying logic —
 * especially the transactional room-freeing on checkout/evict — has
 * to be identical everywhere it's triggered from. Rather than copy
 * the same five if-blocks into three files (and risk them drifting
 * out of sync), each of those three pages just requires this file at
 * the top and sets two variables first: $db and $selfPath (where to
 * redirect back to once the action is done).
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action   = $_POST['action'] ?? '';

    /**
     * "Clear" on Registration/Approval, Track Status, and Check-in/
     * Check-out only hides rows from that one page — it records the
     * (page, tenant) pair in dismissed_records so that page's query can
     * filter it out. Nothing in tenants/contracts/payments is touched,
     * so reports and analytics keep seeing every record.
     */
    if ($action === 'clear_view') {
        $page   = $_POST['page'] ?? '';
        $filter = $_POST['filter'] ?? 'all';

        // column = which tenants column the filter value matches; extra = an
        // always-applied condition scoping this page's own base query;
        // limit/order mirror the page's own query so "clear" only ever
        // touches what that page could actually show.
        $pageConfig = [
            'approval'   => ['statuses' => ['Approved', 'Rejected'], 'column' => 'approval_status', 'extra' => null, 'limit' => 5, 'order' => 't.date_registered DESC'],
            'status'     => ['statuses' => ['Active', 'Pending', 'Checked Out', 'Evicted'], 'column' => 'status', 'extra' => "t.approval_status = 'Approved'", 'limit' => null, 'order' => null],
            'checkinout' => ['statuses' => ['Active', 'Checked Out'], 'column' => 'status', 'extra' => "t.status IN ('Active','Checked Out')", 'limit' => null, 'order' => null],
        ];

        $cfg = $pageConfig[$page] ?? null;
        $statuses = $cfg ? ($filter === 'all' ? $cfg['statuses'] : array_intersect([$filter], $cfg['statuses'])) : [];

        if ($cfg && $statuses) {
            $placeholders = implode(',', array_fill(0, count($statuses), '?'));
            $sql = "SELECT t.tenant_id FROM tenants t
                    WHERE t.tenant_id NOT IN (SELECT tenant_id FROM dismissed_records WHERE page = ?)
                      AND t.{$cfg['column']} IN ($placeholders)"
                 . ($cfg['extra'] ? " AND {$cfg['extra']}" : '')
                 . ($cfg['order'] ? " ORDER BY {$cfg['order']}" : '')
                 . ($cfg['limit'] ? " LIMIT {$cfg['limit']}" : '');

            $stmt = $db->prepare($sql);
            $stmt->execute(array_merge([$page], array_values($statuses)));
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $insert = $db->prepare('INSERT IGNORE INTO dismissed_records (page, tenant_id) VALUES (?, ?)');
            foreach ($ids as $id) {
                $insert->execute([$page, $id]);
            }
            flash('success', count($ids) . ' record(s) cleared from view. They remain in the database for reports.');
        } else {
            flash('error', 'Invalid clear request.');
        }

        redirect($selfPath);
    }

    $tenantId = (int) ($_POST['tenant_id'] ?? 0);

    $tenantStmt = $db->prepare("SELECT t.*, u.first_name, u.last_name, u.email FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE t.tenant_id = ?");
    $tenantStmt->execute([$tenantId]);
    $tenant = $tenantStmt->fetch();

    if (!$tenant) {
        flash('error', 'Tenant not found.');
        redirect($selfPath);
    }

    if ($action === 'approve') {
        $db->prepare("UPDATE tenants SET approval_status = 'Approved', rejection_reason = NULL WHERE tenant_id = ?")->execute([$tenantId]);
        log_activity($db, 'tenant_approved', $tenant['first_name'] . ' ' . $tenant['last_name'] . '\'s application was approved', $tenantId);
        flash('success', $tenant['first_name'] . ' has been approved. Assign them a room in Property Management next.');
        send_email_alert($tenant['email'], $tenant['first_name'], 'Your application has been approved',
            email_template('You\'re approved!', "Hi {$tenant['first_name']}, your tenant application has been approved. We'll notify you again once a room is assigned."));
    }

    if ($action === 'reject') {
        $reason = str_input($_POST, 'reason');
        $db->prepare("UPDATE tenants SET approval_status = 'Rejected', rejection_reason = ? WHERE tenant_id = ?")
           ->execute([$reason ?: null, $tenantId]);
        log_activity($db, 'tenant_rejected', $tenant['first_name'] . ' ' . $tenant['last_name'] . '\'s application was rejected', $tenantId);
        flash('success', 'Application rejected.');
        $body = "Hi {$tenant['first_name']}, your tenant application was not approved."
              . ($reason !== '' ? " Reason given: {$reason}" : '')
              . ' If you have questions or believe this was a mistake, please contact the dorm office.';
        send_email_alert($tenant['email'], $tenant['first_name'], 'Update on your tenant application', email_template('Application update', $body));
    }

    if ($action === 'reconsider') {
        $db->prepare("UPDATE tenants SET approval_status = 'Pending', rejection_reason = NULL WHERE tenant_id = ?")->execute([$tenantId]);
        flash('success', $tenant['first_name'] . '\'s application has been moved back to Pending for another look.');
    }

    if ($action === 'checkin') {
        if ($tenant['approval_status'] !== 'Approved' || $tenant['status'] !== 'Pending') {
            flash('error', 'Only approved tenants who have not yet checked in can be checked in.');
        } elseif (!$tenant['room_id']) {
            flash('error', 'Assign a room to this tenant before checking them in.');
        } else {
            $db->prepare("UPDATE tenants SET status = 'Active', checkin_date = CURDATE(), key_returned = FALSE WHERE tenant_id = ?")->execute([$tenantId]);
            flash('success', $tenant['first_name'] . ' checked in.');
        }
    }

    if (in_array($action, ['checkout', 'evict'], true) && !in_array($tenant['status'], ['Active', 'Pending'], true)) {
        flash('error', $tenant['first_name'] . ' has already left, so this action does not apply.');
        redirect($selfPath);
    }

    if ($action === 'checkout') {
        $keysReturned = isset($_POST['keys_returned']) ? 1 : 0;
        $db->beginTransaction();
        try {
            $db->prepare("UPDATE tenants SET status = 'Checked Out', checkout_date = CURDATE(), key_returned = ? WHERE tenant_id = ?")->execute([$keysReturned, $tenantId]);
            if ($tenant['room_id']) {
                recalc_room_status($db, (int) $tenant['room_id']);
            }
            // Close out their lease: 'Expired' if the term had run its course, 'Terminated' if they left early.
            $db->prepare("
                UPDATE contracts SET contract_status = IF(contract_end <= CURDATE(), 'Expired', 'Terminated')
                WHERE tenant_id = ? AND contract_status IN ('Active','Expiring Soon')
            ")->execute([$tenantId]);
            $db->commit();
            log_activity($db, 'tenant_checked_out', $tenant['first_name'] . ' ' . $tenant['last_name'] . ' checked out', $tenantId);
            flash('success', $tenant['first_name'] . ' checked out. Their room is now available again.');
        } catch (Exception $e) {
            $db->rollBack();
            flash('error', 'Check-out failed. Please try again.');
        }
    }

    if ($action === 'evict') {
        $db->beginTransaction();
        try {
            $db->prepare("UPDATE tenants SET status = 'Evicted' WHERE tenant_id = ?")->execute([$tenantId]);
            if ($tenant['room_id']) {
                recalc_room_status($db, (int) $tenant['room_id']);
            }
            // Early/forced end of tenancy — the lease no longer runs its natural course.
            $db->prepare("UPDATE contracts SET contract_status = 'Terminated' WHERE tenant_id = ? AND contract_status IN ('Active','Expiring Soon')")->execute([$tenantId]);
            $db->commit();
            log_activity($db, 'tenant_evicted', $tenant['first_name'] . ' ' . $tenant['last_name'] . ' had their lease terminated', $tenantId);
            flash('success', 'Lease terminated for ' . $tenant['first_name'] . '. Their room is now available again.');
        } catch (Exception $e) {
            $db->rollBack();
            flash('error', 'Update failed. Please try again.');
        }
    }

    if ($action === 'mark_key_returned') {
        $db->prepare("UPDATE tenants SET key_returned = TRUE WHERE tenant_id = ?")->execute([$tenantId]);
        flash('success', 'Key return recorded for ' . $tenant['first_name'] . '.');
    }

    redirect($selfPath);
}
