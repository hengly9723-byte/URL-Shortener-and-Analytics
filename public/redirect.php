<?php
/**
 * public/redirect.php
 *
 * Redirect handler for short links.
 *
 * Two routing modes (set $ROUTING_MODE below):
 *
 *  A) Apache mod_rewrite (recommended, see public/.htaccess)
 *     Requests to  /s/{code}  are internally rewritten to
 *     /redirect.php?code={code}
 *     → $_GET['code'] contains the raw short code.
 *
 *  B) Plain query-string fallback (no .htaccess needed)
 *     /redirect.php?code=aB3xY9
 *
 * Performance path:
 *  1. Validate short-code format (regex, no DB hit)          ← bail fast
 *  2. Single indexed SELECT on short_code                    ← one round-trip
 *  3. In-PHP expiry check                                    ← no extra query
 *  4. Send 302 + headers, flush output buffer to client      ← user is gone
 *  5. fastcgi_finish_request() if available                  ← truly async
 *  6. Insert click row into `clicks`                         ← invisible to user
 */

declare(strict_types=1);

$projectRoot = dirname(__DIR__);

require_once $projectRoot . '/config/db.php';
require_once $projectRoot . '/src/Helpers/Shortener.php';
require_once $projectRoot . '/src/Helpers/GeoIp.php';
require_once $projectRoot . '/src/Services/ClickLogger.php';

use App\Helpers\Shortener;
use App\Helpers\GeoIp;
use App\Services\ClickLogger;

// render an error view and exit
function renderError(int $httpCode, string $view, array $vars = []): never
{
    http_response_code($httpCode);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    // Expose only the variables the view needs (no globals leak).
    extract($vars, EXTR_SKIP);

    $viewPath = dirname(__DIR__) . '/views/errors/' . $view . '.php';
    if (file_exists($viewPath)) {
        require $viewPath;
    } else {
        echo '<h1>' . $httpCode . '</h1>';
    }
    exit;
}

// 1. Extract & validate short code — fail fast before any DB call
$shortCode = trim((string) ($_GET['code'] ?? ''));

if ($shortCode === '' || !Shortener::isValidCode($shortCode)) {
    renderError(404, 'link-not-found', ['shortCode' => $shortCode]);
}

// 2. Look up the URL — single query, uses the unique index on short_code
//    We intentionally do NOT filter on is_active or expiry here so we can
//    return the correct error page (not-found vs. expired) to the user.
try {
    $pdo = getDbConnection();

    $stmt = $pdo->prepare(
        'SELECT `id`, `original_url`, `is_active`, `expiry_date`
           FROM `urls`
          WHERE `short_code` = :code
          LIMIT 1'
    );
    $stmt->execute([':code' => $shortCode]);
    $url = $stmt->fetch(PDO::FETCH_ASSOC);

} catch (\PDOException $e) {
    error_log('[redirect] DB lookup failed for code=' . $shortCode . ': ' . $e->getMessage());
    http_response_code(503);
    exit('Service temporarily unavailable.');
}

// 3. Existence check
if ($url === false || $url === null) {
    renderError(404, 'link-not-found', ['shortCode' => $shortCode]);
}

// 4. Active check
if ((int) $url['is_active'] !== 1) {
    renderError(404, 'link-not-found', ['shortCode' => $shortCode]);
}
// 5. Expiry check (PHP-side — avoids a second DB round-trip)
if ($url['expiry_date'] !== null) {
    $expiryTs = strtotime($url['expiry_date']);
    if ($expiryTs !== false && $expiryTs < time()) {
        renderError(410, 'link-expired', ['expiryDate' => $url['expiry_date']]);
    }
}

// 6. Collect analytics data BEFORE sending headers
// (header() calls must come before any output, country lookup happens here)
$urlId    = (int) $url['id'];
$destUrl  = $url['original_url'];

// Collect request context — all pure PHP, no I/O yet
$ipAddress = ClickLogger::resolveIp();
$userAgent = ClickLogger::resolveUserAgent();
$referrer  = ClickLogger::resolveReferrer();

// Country lookup — has a 1-second timeout; runs before redirect so we have
// the data ready to write after flushing. For maximum speed in production,
// swap GeoIp::getCountryCode() for a local MaxMind GeoLite2 lookup (< 1 ms).
$country   = GeoIp::getCountryCode($ipAddress);

// 7. Send 302 redirect — client browser starts following immediately

// Validate the destination URL one more time to prevent open-redirect abuse
// if someone somehow injected a javascript: or data: URL into the DB.
if (!filter_var($destUrl, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $destUrl)) {
    error_log('[redirect] Blocked unsafe destination for code=' . $shortCode . ': ' . $destUrl);
    renderError(404, 'link-not-found', ['shortCode' => $shortCode]);
}

// Cache-control: prevent browsers/CDNs from caching the redirect response
// so that future changes to the link (deactivation, new destination) take effect.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Standard referrer policy — pass referrer to destination but strip sensitive parts
header('Referrer-Policy: no-referrer-when-downgrade');

// The 302 redirect itself
header('Location: ' . $destUrl, true, 302);

// Flush response to client — user's browser starts loading $destUrl now.
// Everything below is invisible to the visitor.

// Close the output buffer and push bytes to the client.
if (ob_get_level() > 0) {
    ob_end_flush();
}
flush();

// PHP-FPM: tell FPM the response is done so the browser disconnects.
// The worker process continues running to do the DB insert.
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

// 9. Log the click — runs AFTER the browser has already received the redirect.
// Total user-visible latency: DB lookup only (step 2).
// Country lookup + DB insert happen in the background.
$logger = new ClickLogger($pdo);
$logger->log($urlId, $ipAddress, $userAgent, $referrer, $country);
