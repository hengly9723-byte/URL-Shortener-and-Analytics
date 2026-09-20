<?php
/**
 * src/Services/ClickLogger.php
 *
 * Inserts a single analytics row into the `clicks` table.
 *
 * Designed to be called AFTER the HTTP redirect headers have been sent
 * (and optionally after fastcgi_finish_request() so the user never waits
 * for the DB write).
 */

declare(strict_types=1);

namespace App\Services;

use PDO;
use PDOException;

class ClickLogger
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Log one click/redirect event.
     *
     * @param int         $urlId      FK → urls.id
     * @param string|null $ipAddress  Resolved client IP (IPv4 or IPv6)
     * @param string|null $userAgent  Raw User-Agent header value
     * @param string|null $referrer   Raw Referer header value
     * @param string|null $country    ISO-3166-1 alpha-2 code, or null
     */
    public function log(
        int     $urlId,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $referrer,
        ?string $country
    ): void {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO `clicks`
                    (`url_id`, `clicked_at`, `ip_address`, `user_agent`, `referrer`, `country`)
                 VALUES
                    (:url_id, NOW(), :ip_address, :user_agent, :referrer, :country)'
            );

            $stmt->execute([
                ':url_id'     => $urlId,
                ':ip_address' => self::truncate($ipAddress, 45),   // VARCHAR(45) — max IPv6
                ':user_agent' => self::truncate($userAgent, 65535), // TEXT — safe upper bound
                ':referrer'   => self::truncate($referrer,  65535),
                ':country'    => self::truncate($country,   2),     // ISO alpha-2
            ]);
        } catch (PDOException $e) {
            // Never let a logging failure crash the redirect.
            // The redirect already went out; just record the error server-side.
            error_log('[ClickLogger] Insert failed for url_id=' . $urlId . ': ' . $e->getMessage());
        }
    }

    // -----------------------------------------------------------------------
    // Static request helpers — used by the redirect handler to collect data
    // -----------------------------------------------------------------------

    /**
     * Resolve the real client IP, respecting common reverse-proxy headers.
     * Returns null when nothing sensible can be found.
     *
     * ⚠️  Only trust X-Forwarded-For / X-Real-IP if your server sits
     *     behind a trusted proxy (Nginx, Cloudflare, AWS ALB, etc.).
     *     In a shared-hosting / direct-Apache setup, use REMOTE_ADDR only.
     */
    public static function resolveIp(): ?string
    {
        // Cloudflare sets CF-Connecting-IP with the real visitor IP.
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            $value = $_SERVER[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }

            // X-Forwarded-For can be a comma-separated chain; first is the client.
            $ip = trim(explode(',', $value)[0]);

            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return null;
    }

    /** Return the raw User-Agent string, or null. */
    public static function resolveUserAgent(): ?string
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        return ($ua !== null && $ua !== '') ? $ua : null;
    }

    /** Return the raw Referer header, or null. */
    public static function resolveReferrer(): ?string
    {
        $ref = $_SERVER['HTTP_REFERER'] ?? null;
        return ($ref !== null && $ref !== '') ? $ref : null;
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /** Safely truncate a nullable string to $maxLen characters. */
    private static function truncate(?string $value, int $maxLen): ?string
    {
        if ($value === null) {
            return null;
        }
        return mb_substr($value, 0, $maxLen);
    }
}
