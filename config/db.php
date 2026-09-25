<?php

declare(strict_types=1);

define('DB_HOST',    getenv('DB_HOST')     ?: 'localhost');
define('DB_PORT',    getenv('DB_PORT')     ?: '3306');
define('DB_NAME',    getenv('DB_NAME')     ?: 'url_shortener');
define('DB_USER',    getenv('DB_USER')     ?: 'root');
define('DB_PASS',    getenv('DB_PASSWORD') ?: getenv('DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');

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
        PDO::ATTR_EMULATE_PREPARES   => false,

        // Enable SSL for cloud databases like Aiven
        PDO::MYSQL_ATTR_SSL_CA       => true,
        PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,

        // Persistent connections setting
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

        http_response_code(503);
        exit(json_encode([
            'success' => false,
            'message' => 'Service temporarily unavailable. Please try again later.',
        ]));
    }

    return $pdo;
}