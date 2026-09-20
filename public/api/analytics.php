<?php
declare(strict_types=1);

/**
 * public/api/analytics.php
 *
 * Analytics & Link Management API for authenticated users.
 *
 * GET /api/analytics.php
 *   - ?action=stats[&url_id=123][&days=30]
 *     Returns aggregated summary, timeline, referrers, devices, and browsers.
 *   - ?action=urls
 *     Returns list of all URLs owned by current user.
 *
 * POST /api/analytics.php
 *   Body (JSON or POST):
 *   - action="toggle", url_id
 *     Toggles link active state (1 -> 0 or 0 -> 1).
 *   - action="update", url_id, original_url, [expiry_date]
 *     Updates destination URL or expiry.
 *   - action="delete", url_id
 *     Deletes the link and cascades clicks.
 */

session_start();

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/config/db.php';
require_once $projectRoot . '/src/Auth.php';
require_once $projectRoot . '/src/Services/AnalyticsService.php';

use App\Auth;
use App\Services\AnalyticsService;

function jsonResponse(int $code, array $payload): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------------------------
// 1. Auth Guard
// ---------------------------------------------------------------------------
if (!Auth::isLoggedIn()) {
    jsonResponse(401, [
        'success' => false,
        'message' => 'Unauthorized. Please sign in to access analytics.',
    ]);
}

$userId = (int) Auth::currentUserId();

try {
    $pdo     = getDbConnection();
    $service = new AnalyticsService($pdo);

    $method = $_SERVER['REQUEST_METHOD'];

    // -----------------------------------------------------------------------
    // GET Requests: Fetch stats or URL lists
    // -----------------------------------------------------------------------
    if ($method === 'GET') {
        $action = trim((string) ($_GET['action'] ?? 'stats'));

        if ($action === 'urls') {
            $urls = $service->getUserUrls($userId);
            jsonResponse(200, [
                'success' => true,
                'urls'    => $urls,
            ]);
        }

        if ($action === 'stats') {
            $urlId = isset($_GET['url_id']) && is_numeric($_GET['url_id']) ? (int) $_GET['url_id'] : null;
            $days  = isset($_GET['days']) && is_numeric($_GET['days']) ? max(1, min(365, (int) $_GET['days'])) : 30;

            $data = $service->getAnalytics($userId, $urlId, $days);

            if (!$data['ok']) {
                jsonResponse(404, [
                    'success' => false,
                    'message' => $data['error'] ?? 'Link not found.',
                ]);
            }

            jsonResponse(200, array_merge(['success' => true], $data));
        }

        jsonResponse(400, [
            'success' => false,
            'message' => 'Unknown GET action.',
        ]);
    }

    // -----------------------------------------------------------------------
    // POST Requests: Mutations (toggle, update, delete)
    // -----------------------------------------------------------------------
    if ($method === 'POST') {
        $contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
        $input = [];

        if (str_contains($contentType, 'application/json')) {
            $raw   = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: [];
        } else {
            $input = $_POST;
        }

        // Validate CSRF if present in header or payload
        $csrfToken = (string) ($input['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($csrfToken !== '' && !Auth::validateCsrf($csrfToken)) {
            jsonResponse(403, [
                'success' => false,
                'message' => 'CSRF verification failed.',
            ]);
        }

        $action = trim((string) ($input['action'] ?? ''));
        $urlId  = isset($input['url_id']) && is_numeric($input['url_id']) ? (int) $input['url_id'] : 0;

        if ($urlId <= 0) {
            jsonResponse(422, [
                'success' => false,
                'message' => 'A valid "url_id" is required.',
            ]);
        }

        // Action: Toggle Active Status
        if ($action === 'toggle') {
            $newState = $service->toggleUrlStatus($userId, $urlId);
            if ($newState === null) {
                jsonResponse(404, [
                    'success' => false,
                    'message' => 'Link not found or permission denied.',
                ]);
            }

            jsonResponse(200, [
                'success'   => true,
                'is_active' => $newState,
                'message'   => $newState ? 'Link activated.' : 'Link paused.',
            ]);
        }

        // Action: Delete URL
        if ($action === 'delete') {
            $deleted = $service->deleteUrl($userId, $urlId);
            if (!$deleted) {
                jsonResponse(404, [
                    'success' => false,
                    'message' => 'Link not found or permission denied.',
                ]);
            }

            jsonResponse(200, [
                'success' => true,
                'message' => 'Link deleted successfully.',
            ]);
        }

        // Action: Update URL
        if ($action === 'update') {
            $rawUrl = trim((string) ($input['original_url'] ?? ''));
            $expiry = trim((string) ($input['expiry_date'] ?? ''));

            if ($rawUrl === '' || !filter_var($rawUrl, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $rawUrl)) {
                jsonResponse(422, [
                    'success' => false,
                    'message' => 'Please enter a valid destination URL beginning with http:// or https://.',
                ]);
            }

            $expiryDate = null;
            if ($expiry !== '') {
                $ts = strtotime($expiry);
                if ($ts === false || $ts <= time()) {
                    jsonResponse(422, [
                        'success' => false,
                        'message' => 'Expiry date must be in the future.',
                    ]);
                }
                $expiryDate = date('Y-m-d H:i:s', $ts);
            }

            $updated = $service->updateUrl($userId, $urlId, $rawUrl, $expiryDate);

            jsonResponse(200, [
                'success'      => true,
                'message'      => 'Link updated successfully.',
                'original_url' => $rawUrl,
                'expiry_date'  => $expiryDate,
            ]);
        }

        jsonResponse(400, [
            'success' => false,
            'message' => 'Invalid or unspecified POST action.',
        ]);
    }

    jsonResponse(405, ['success' => false, 'message' => 'Method not allowed.']);

} catch (\PDOException $e) {
    error_log('[api/analytics] DB error: ' . $e->getMessage());
    jsonResponse(503, [
        'success' => false,
        'message' => 'Database error. Please try again later.',
    ]);
} catch (\Throwable $e) {
    error_log('[api/analytics] Unexpected: ' . $e->getMessage());
    jsonResponse(500, [
        'success' => false,
        'message' => 'Internal server error.',
    ]);
}
