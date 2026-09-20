<?php
/**
 * src/Helpers/GeoIp.php
 *
 * Lightweight country-code resolver.
 *
 * Strategy (in priority order):
 *  1. Cloudflare header  CF-IPCountry           — zero-latency, most reliable in prod
 *  2. ip-api.com free JSON API                  — works on localhost / dev
 *  3. Returns null                              — safe fallback, never crashes
 *
 * For production with high traffic, replace the ip-api.com call with a local
 * MaxMind GeoLite2 database (via composer require geoip2/geoip2) to avoid
 * external HTTP latency on every redirect.
 */

declare(strict_types=1);

namespace App\Helpers;

class GeoIp
{
    // ip-api.com free tier: 45 req/min per IP, no API key required.
    private const API_URL        = 'https://ip-api.com/json/%s?fields=countryCode';
    private const REQUEST_TIMEOUT = 1; // seconds — keeps redirect latency minimal

    /**
     * Attempt to resolve a 2-letter ISO-3166-1 country code for the given IP.
     *
     * @param  string|null $ip  IPv4 or IPv6 address
     * @return string|null      e.g. "US", "DE", "TH" — or null on failure/private IP
     */
    public static function getCountryCode(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        // Skip private / loopback ranges — geo APIs return nothing useful for these.
        if (!self::isPublicIp($ip)) {
            return null;
        }

        // ── 1. Cloudflare CDN header (no HTTP call needed) ──────────────────
        $cfCountry = $_SERVER['HTTP_CF_IPCOUNTRY'] ?? null;
        if ($cfCountry !== null && strlen($cfCountry) === 2 && $cfCountry !== 'XX') {
            return strtoupper($cfCountry);
        }

        // ── 2. ip-api.com free JSON endpoint ────────────────────────────────
        return self::lookupViaApi($ip);
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /** Call ip-api.com with a strict timeout; parse the countryCode field. */
    private static function lookupViaApi(string $ip): ?string
    {
        $url = sprintf(self::API_URL, urlencode($ip));

        $context = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'timeout'         => self::REQUEST_TIMEOUT,
                'ignore_errors'   => true,
                'follow_location' => false,
                // Identify ourselves politely
                'header'          => "User-Agent: URLShortener/1.0\r\n",
            ],
        ]);

        try {
            $raw = @file_get_contents($url, false, $context);

            if ($raw === false || $raw === '') {
                return null;
            }

            $data = json_decode($raw, true);

            if (!is_array($data) || ($data['status'] ?? '') !== 'success') {
                return null;
            }

            $code = $data['countryCode'] ?? null;
            return (is_string($code) && strlen($code) === 2) ? strtoupper($code) : null;

        } catch (\Throwable) {
            // Any network / parse error → silent fallback
            return null;
        }
    }

    /**
     * Returns true only when $ip is a globally routable address.
     * Private, loopback, link-local, and reserved ranges return false.
     */
    private static function isPublicIp(string $ip): bool
    {
        return (bool) filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }
}
