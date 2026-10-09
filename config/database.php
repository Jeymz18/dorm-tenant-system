<?php
if (!defined('BASE_URL')) { http_response_code(403); exit('Direct access not permitted.'); }
/**
 * config/database.php
 * PDO connection to MySQL. Defaults match a fresh XAMPP install
 * (root user, no password). Change these if your setup differs.
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'dorm_tenant_system');
define('DB_USER', 'root');
define('DB_PASS', '');

/**
 * Returns a single shared PDO instance (created once per request).
 */
function get_db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            $pdo->exec("SET time_zone = '+08:00'");
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            http_response_code(500);
            die('Database connection failed. Did you import database/schema.sql, '
              . 'and does config/database.php match your MySQL credentials? '
              . 'Check the PHP error log for the exact database error.');
        }
    }

    return $pdo;
}
