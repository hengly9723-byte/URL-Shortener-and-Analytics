<?php
declare(strict_types=1);

/**
 * config/db.php
 *
 * Database connection factory using PHP PDO (MySQL 8.x).
 * Returns a shared PDO instance configured for:
 *   - UTF-8 (utf8mb4) character set
 *   - Exceptions on error (never silent failures)
 *   - Named parameters (:param) as default fetch style
 *
 * Usage:
 *   require_once __DIR__ . '/../config/db.php';
 *   $pdo = getDbConnection();
 */

// ---------------------------------------------------------------------------
// Environment-aware credentials
// Prefer environment variables for production; fall back to local defaults.
// ---------------------------------------------------------------------------
define('DB_HOST',    getenv('DB_HOST')    ?: 'localhost');
define('DB_PORT',    getenv('DB_PORT')    ?: '3306');
define('DB_NAME',    getenv('DB_NAME')    ?: 'url_shortener');
define('DB_USER',    getenv('DB_USER')    ?: 'root');
define('DB_PASS',    getenv('DB_PASS')    ?: '');
define('DB_CHARSET', 'utf8mb4');

// ---------------------------------------------------------------------------
// Singleton holder — one connection per PHP process / request lifecycle.
// ---------------------------------------------------------------------------
function getDbConnection(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        DB_HOST,
        DB_PORT,
        DB_NAME,
        DB_CHARSET
    );

    $options = [
        // Throw PDOException on every error — never suppress failures silently.
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,

        // Return rows as associative arrays by default.
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

        // Disable emulated prepares so MySQL handles parameter binding natively.
        // This prevents certain SQL-injection edge cases and improves type safety.
        PDO::ATTR_EMULATE_PREPARES   => false,

        // Persistent connections are OFF by default; enable via env if you use
        // a connection pool / long-running FPM workers that benefit from it.
        PDO::ATTR_PERSISTENT         => filter_var(
            getenv('DB_PERSISTENT') ?: 'false',
            FILTER_VALIDATE_BOOLEAN
        ),
    ];

    if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
        $options[constant('PDO::MYSQL_ATTR_INIT_COMMAND')] = "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci";
    }

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        // Log the full message server-side; expose a generic message to clients.
        error_log('[DB] Connection failed: ' . $e->getMessage());

        // In production, swap this with a proper error page / JSON response.
        http_response_code(503);
        exit(json_encode([
            'success' => false,
            'message' => 'Service temporarily unavailable. Please try again later.',
        ]));
    }

    return $pdo;
}
