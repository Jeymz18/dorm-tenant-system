<?php
if (!defined('BASE_URL')) { http_response_code(403); exit('Direct access not permitted.'); }
/**
 * includes/auth.php
 * Session handling + role guards. This is the ONE place that decides
 * who is logged in and what role they have — every protected page
 * calls require_role() at the very top before doing anything else.
 */

function login_user(array $user): void
{
    // Prevents session fixation: issue a brand new session ID on privilege change.
    session_regenerate_id(true);

    $_SESSION['user_id']    = (int) $user['user_id'];
    $_SESSION['role']       = $user['role'];
    $_SESSION['first_name'] = $user['first_name'];
    $_SESSION['last_name']  = $user['last_name'];
    $_SESSION['email']      = $user['email'];
    $_SESSION['pwd_changed_at'] = $user['password_changed_at'];
    unset($_SESSION['tenant_id']);

    if ($user['role'] === 'tenant') {
        $tenantStatement = get_db()->prepare('SELECT tenant_id FROM tenants WHERE user_id = ?');
        $tenantStatement->execute([$user['user_id']]);
        $tenantId = $tenantStatement->fetchColumn();
        if ($tenantId !== false) {
            $_SESSION['tenant_id'] = (int) $tenantId;
        }
    }

    get_db()->prepare('UPDATE users SET last_login = NOW() WHERE user_id = ?')
             ->execute([$user['user_id']]);
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function is_logged_in(): bool
{
    return isset($_SESSION['user_id']);
}

function current_role(): ?string
{
    return $_SESSION['role'] ?? null;
}

function is_admin_role(?string $role = null): bool
{
    return in_array($role ?? current_role(), ['super_admin', 'admin'], true);
}

function is_super_admin(): bool
{
    return current_role() === 'super_admin';
}

function role_home_path(?string $role = null): string
{
    return is_admin_role($role) ? '/admin/dashboard.php' : '/tenant/dashboard.php';
}

function current_user_id(): ?int
{
    return $_SESSION['user_id'] ?? null;
}

/**
 * A logged-in tenant's row in the `tenants` table (their tenancy
 * profile, separate from their `users` login row). Cached per request.
 */
function current_tenant(): ?array
{
    static $tenant = false; // false = "not looked up yet", null = "looked up, none found"

    if ($tenant === false) {
        if (current_role() !== 'tenant') {
            $tenant = null;
        } else {
            $stmt = get_db()->prepare('SELECT * FROM tenants WHERE user_id = ?');
            $stmt->execute([current_user_id()]);
            $tenant = $stmt->fetch() ?: null;
        }
    }

    return $tenant;
}

/**
 * Redirect to login if nobody is signed in. Also re-checks the
 * account is still active on every request — not just at login —
 * so deactivating someone takes effect immediately instead of
 * waiting for them to eventually log out on their own. Also logs
 * out this session if the password was changed elsewhere (e.g. a
 * forgot-password reset from another device) since that session's
 * pwd_changed_at snapshot will no longer match the database.
 */
function require_login(): void
{
    if (!is_logged_in()) {
        redirect('/auth/login.php');
    }

    static $checked = false;
    if (!$checked) {
        $checked = true;
        $stmt = get_db()->prepare('SELECT is_active, password_changed_at, role FROM users WHERE user_id = ?');
        $stmt->execute([current_user_id()]);
        $row = $stmt->fetch();
        if (!$row || !$row['is_active']) {
            logout_user();
            redirect('/auth/login.php?deactivated=1');
        }
        if (!in_array($row['role'], ['super_admin', 'admin', 'tenant'], true)
            || $row['role'] !== current_role()) {
            logout_user();
            redirect('/auth/login.php');
        }
        if ($row['password_changed_at'] !== ($_SESSION['pwd_changed_at'] ?? null)) {
            logout_user();
            redirect('/auth/login.php?pwreset=1');
        }
    }
}

/**
 * Redirect to login if not signed in, OR to the user's own dashboard
 * if they're signed in as the WRONG role. This is the real enforcement
 * behind "admin pages never render for a tenant and vice versa" — the
 * sidebar hides the links, but this is what stops someone from just
 * typing the URL directly.
 */
function require_role(string $role): void
{
    require_login();
    $hasRole = $role === 'admin' ? is_admin_role() : current_role() === $role;
    if (!$hasRole) {
        redirect(role_home_path());
    }
}
