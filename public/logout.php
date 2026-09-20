<?php
/**
 * public/logout.php
 *
 * Destroys user session and redirects to login page.
 * Supports both GET requests (e.g. standard link) and POST requests (e.g. form/Fetch).
 */

declare(strict_types=1);

session_start();

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/config/db.php';
require_once $projectRoot . '/src/Auth.php';

use App\Auth;

try {
    $pdo  = getDbConnection();
    $auth = new Auth($pdo);
    $auth->logout();
} catch (\Throwable $e) {
    // If DB fails, fall back to native PHP session destruction
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    session_destroy();
}

// Compute base URL
$scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base    = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
$baseUrl = $scheme . '://' . $host . $base;

// If request expects JSON (API call)
$accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
if (str_contains($accept, 'application/json') || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success'  => true,
        'message'  => 'Logged out successfully.',
        'redirect' => $baseUrl . '/login.php?logged_out=1',
    ]);
    exit;
}

// Standard browser redirect
header('Location: ' . $baseUrl . '/login.php?logged_out=1', true, 302);
exit;
