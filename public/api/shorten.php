<?php
/**
 * public/api/shorten.php
 *
 * POST  /api/shorten.php
 *
 * Accepts a JSON or form-encoded body with:
 *   - url          (string, required) — the long URL to shorten
 *   - expiry_date  (string, optional) — ISO-8601 date "YYYY-MM-DD" or datetime
 *
 * Returns JSON:
 *   Success → { success: true,  short_code, short_url, original_url, expires_at|null }
 *   Failure → { success: false, message, errors?: [...] }
 *
 * Auth:
 *   If $_SESSION['user_id'] is set the link is owned by that user;
 *   otherwise it is stored as a guest link (user_id = NULL).
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
session_start();

// Adjust path depth if you reorganise public/ relative to the project root.
$projectRoot = dirname(__DIR__, 2);   // URL Shortener with Analytics/
require_once $projectRoot . '/config/db.php';
require_once $projectRoot . '/src/Helpers/Shortener.php';

use App\Helpers\Shortener;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** Emit a JSON response and halt execution. */
function jsonResponse(int $httpCode, array $payload): never
{
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=UTF-8');
    // Prevent caching of API responses
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** Derive the site base URL from server globals (e.g. http://localhost/myapp). */
function getBaseUrl(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Strip /public/api from SCRIPT_NAME to get the project root web path.
    $base   = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/\\');
    return $scheme . '://' . $host . $base;
}

// ---------------------------------------------------------------------------
// Method guard — only POST is accepted
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(405, [
        'success' => false,
        'message' => 'Method not allowed. Use POST.',
    ]);
}

// ---------------------------------------------------------------------------
// Parse request body
// Supports both application/json and application/x-www-form-urlencoded
// ---------------------------------------------------------------------------
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$input       = [];

if (str_contains($contentType, 'application/json')) {
    $raw   = file_get_contents('php://input');
    $input = json_decode($raw, true);

    if (!is_array($input)) {
        jsonResponse(400, [
            'success' => false,
            'message' => 'Invalid JSON body.',
        ]);
    }
} else {
    // application/x-www-form-urlencoded  or  multipart/form-data
    $input = $_POST;
}

// ---------------------------------------------------------------------------
// Input extraction & sanitisation
// ---------------------------------------------------------------------------
$rawUrl     = trim((string) ($input['url']         ?? ''));
$rawExpiry  = trim((string) ($input['expiry_date'] ?? ''));

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------
$errors = [];

// 1. URL must be present
if ($rawUrl === '') {
    $errors[] = 'The "url" field is required.';
}

// 2. URL must be well-formed and use http(s)
if ($rawUrl !== '') {
    // FILTER_VALIDATE_URL accepts ftp://, file://, etc. — restrict to http(s).
    $filtered = filter_var($rawUrl, FILTER_VALIDATE_URL);
    if ($filtered === false) {
        $errors[] = 'The provided URL is not a valid URL.';
    } elseif (!preg_match('/^https?:\/\//i', $rawUrl)) {
        $errors[] = 'Only http:// and https:// URLs are accepted.';
    } else {
        $rawUrl = $filtered; // use the sanitised version
    }
}

// 3. Expiry date — optional, but if provided must be parsable & in the future
$expiryDatetime = null;
if ($rawExpiry !== '') {
    // Accept "YYYY-MM-DD" or "YYYY-MM-DD HH:MM:SS"
    $ts = strtotime($rawExpiry);
    if ($ts === false || $ts <= time()) {
        $errors[] = 'expiry_date must be a valid future date (YYYY-MM-DD or YYYY-MM-DD HH:MM:SS).';
    } else {
        $expiryDatetime = date('Y-m-d H:i:s', $ts);
    }
}

if (!empty($errors)) {
    jsonResponse(422, [
        'success' => false,
        'message' => 'Validation failed.',
        'errors'  => $errors,
    ]);
}

// ---------------------------------------------------------------------------
// Auth — pull user_id from session (NULL for guests)
// ---------------------------------------------------------------------------
$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

// ---------------------------------------------------------------------------
// Database operations
// ---------------------------------------------------------------------------
try {
    $pdo = getDbConnection();

    // Generate a collision-free short code
    $shortCode = Shortener::generateUniqueCode($pdo);

    $stmt = $pdo->prepare(
        'INSERT INTO `urls`
            (`user_id`, `original_url`, `short_code`, `expiry_date`, `is_active`)
         VALUES
            (:user_id, :original_url, :short_code, :expiry_date, 1)'
    );

    $stmt->execute([
        ':user_id'      => $userId,          // null → guest link
        ':original_url' => $rawUrl,
        ':short_code'   => $shortCode,
        ':expiry_date'  => $expiryDatetime,  // null → never expires
    ]);

    $insertedId = (int) $pdo->lastInsertId();

} catch (\RuntimeException $e) {
    // Shortener::generateUniqueCode() exhausted retries
    error_log('[shorten] Code generation failed: ' . $e->getMessage());
    jsonResponse(500, [
        'success' => false,
        'message' => 'Could not generate a short code. Please try again.',
    ]);
} catch (\PDOException $e) {
    error_log('[shorten] DB insert failed: ' . $e->getMessage());

    // Duplicate short_code race condition (virtually impossible but guarded)
    if ($e->getCode() === '23000') {
        jsonResponse(409, [
            'success' => false,
            'message' => 'A conflict occurred. Please try again.',
        ]);
    }

    jsonResponse(500, [
        'success' => false,
        'message' => 'Database error. Please try again later.',
    ]);
}

// ---------------------------------------------------------------------------
// Success response
// ---------------------------------------------------------------------------
$baseUrl  = getBaseUrl();
$shortUrl = $baseUrl . '/s/' . $shortCode;   // e.g. http://localhost/myapp/s/aB3xY9
                                              // Adjust /s/ to your redirect route

jsonResponse(201, [
    'success'      => true,
    'id'           => $insertedId,
    'short_code'   => $shortCode,
    'short_url'    => $shortUrl,
    'original_url' => $rawUrl,
    'expires_at'   => $expiryDatetime,        // null if no expiry set
    'owner'        => $userId !== null ? 'user' : 'guest',
]);
