<?php

declare(strict_types=1);

define('DB_HOST',    trim((string)(getenv('DB_HOST')     ?: 'localhost')));
define('DB_PORT',    trim((string)(getenv('DB_PORT')     ?: '3306')));
define('DB_NAME',    trim((string)(getenv('DB_NAME')     ?: 'url_shortener')));
define('DB_USER',    trim((string)(getenv('DB_USER')     ?: 'root')));
define('DB_PASS',    trim((string)(getenv('DB_PASSWORD') ?: getenv('DB_PASS') ?: '')));
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
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,

        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

        PDO::ATTR_EMULATE_PREPARES   => false,

        PDO::MYSQL_ATTR_SSL_CA       => true,
        PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,

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
        error_log('[DB] Connection failed: ' . $e->getMessage());

        http_response_code(503);
        exit(json_encode([
            'success' => false,
            'message' => 'Service temporarily unavailable. Please try again later.',
        ]));
    }

    return $pdo;
}
