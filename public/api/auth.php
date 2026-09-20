<?php
declare(strict_types=1);

/**
 * public/api/auth.php
 *
 * POST /api/auth.php
 *
 * Accepts JSON or form-encoded body with an `action` field:
 *
 *   action = "register"
 *     Body: { username, email, password, _csrf }
 *     → 201 { success: true,  message, username, redirect }
 *     → 422 { success: false, errors: [...] }
 *
 *   action = "login"
 *     Body: { identifier, password, _csrf }
 *     → 200 { success: true,  message, username, redirect }
 *     → 401 { success: false, error }
 *
 *   action = "logout"
 *     Body: { _csrf }
 *     → 200 { success: true, redirect }
 *
 * Only POST method is accepted. CSRF tokens are validated on every action.
 */

session_start();

$projectRoot = dirname(__DIR__, 2);   // URL/ root
require_once $projectRoot . '/config/db.php';
require_once $projectRoot . '/src/Auth.php';

use App\Auth;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function jsonResponse(int $code, array $payload): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function getBaseUrl(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base   = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/\\');
    return $scheme . '://' . $host . $base;
}

// ---------------------------------------------------------------------------
// Method guard
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(405, ['success' => false, 'message' => 'Method not allowed.']);
}

// ---------------------------------------------------------------------------
// Parse body (JSON or form-encoded)
// ---------------------------------------------------------------------------
$contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
$input       = [];

if (str_contains($contentType, 'application/json')) {
    $raw   = file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (!is_array($input)) {
        jsonResponse(400, ['success' => false, 'message' => 'Invalid JSON body.']);
    }
} else {
    $input = $_POST;
}

// ---------------------------------------------------------------------------
// CSRF validation (all actions require a valid token)
// ---------------------------------------------------------------------------
$csrfToken = trim((string) ($input['_csrf'] ?? ''));
if (!Auth::validateCsrf($csrfToken)) {
    jsonResponse(403, ['success' => false, 'message' => 'Invalid or expired form token. Please refresh and try again.']);
}

// ---------------------------------------------------------------------------
// Route to action
// ---------------------------------------------------------------------------
$action  = trim((string) ($input['action'] ?? ''));
$baseUrl = getBaseUrl();

try {
    $pdo  = getDbConnection();
    $auth = new Auth($pdo);

    switch ($action) {

        // ── REGISTER ─────────────────────────────────────────────────────────
        case 'register':
            $username = trim((string) ($input['username'] ?? ''));
            $email    = trim((string) ($input['email']    ?? ''));
            $password = (string) ($input['password']      ?? '');

            $result = $auth->register($username, $email, $password);

            if (!$result['ok']) {
                jsonResponse(422, [
                    'success' => false,
                    'errors'  => $result['errors'],
                ]);
            }

            // Auto-login after registration
            $auth->login($email, $password);

            jsonResponse(201, [
                'success'  => true,
                'message'  => 'Account created successfully. Welcome to SnapLink!',
                'username' => Auth::currentUsername(),
                'redirect' => $baseUrl . '/dashboard.php',
            ]);

        // ── LOGIN ─────────────────────────────────────────────────────────────
        case 'login':
            $identifier = trim((string) ($input['identifier'] ?? ''));
            $password   = (string) ($input['password']       ?? '');

            $result = $auth->login($identifier, $password);

            if (!$result['ok']) {
                jsonResponse(401, [
                    'success' => false,
                    'error'   => $result['error'],
                ]);
            }

            // Support ?redirect= for post-login destination
            $redirectTo = $input['redirect'] ?? ($baseUrl . '/dashboard.php');
            // Safety: only allow same-origin redirects
            if (!str_starts_with($redirectTo, $baseUrl)) {
                $redirectTo = $baseUrl . '/dashboard.php';
            }

            jsonResponse(200, [
                'success'  => true,
                'message'  => 'Welcome back, ' . Auth::currentUsername() . '!',
                'username' => Auth::currentUsername(),
                'redirect' => $redirectTo,
            ]);

        // ── LOGOUT ────────────────────────────────────────────────────────────
        case 'logout':
            $auth->logout();
            jsonResponse(200, [
                'success'  => true,
                'redirect' => $baseUrl . '/',
            ]);

        // ── UNKNOWN ACTION ────────────────────────────────────────────────────
        default:
            jsonResponse(400, [
                'success' => false,
                'message' => 'Unknown action "' . htmlspecialchars($action, ENT_QUOTES) . '".',
            ]);
    }

} catch (\PDOException $e) {
    error_log('[api/auth] DB error: ' . $e->getMessage());
    jsonResponse(503, [
        'success' => false,
        'message' => 'Service temporarily unavailable. Please try again later.',
    ]);
} catch (\Throwable $e) {
    error_log('[api/auth] Unexpected error: ' . $e->getMessage());
    jsonResponse(500, [
        'success' => false,
        'message' => 'An unexpected error occurred.',
    ]);
}
