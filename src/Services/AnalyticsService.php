<?php
declare(strict_types=1);

/**
 * src/Services/AnalyticsService.php
 *
 * Query and aggregation service for link analytics:
 *  - URLs owned by a specific user
 *  - Aggregated click counts and timeline distribution
 *  - Referrer domain parsing
 *  - User-agent parsing for Device and Browser breakdown
 *  - Link updates, deactivation toggle, and deletion
 */

namespace App\Services;

use PDO;
use PDOException;

class AnalyticsService
{
    public function __construct(private PDO $pdo) {}

    /**
     * Get all shortened URLs belonging to a user, with their individual click counts.
     *
     * @param int $userId
     * @return array<int, array<string, mixed>>
     */
    public function getUserUrls(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.id,
                    u.original_url,
                    u.short_code,
                    u.created_at,
                    u.expiry_date,
                    u.is_active,
                    COUNT(c.id) AS total_clicks,
                    MAX(c.clicked_at) AS last_clicked_at
               FROM `urls` u
          LEFT JOIN `clicks` c ON c.url_id = u.id
              WHERE u.user_id = :user_id
           GROUP BY u.id
           ORDER BY u.created_at DESC'
        );
        $stmt->execute([':user_id' => $userId]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Cast fields properly
        return array_map(function ($row) {
            $row['id']           = (int) $row['id'];
            $row['is_active']    = (bool) $row['is_active'];
            $row['total_clicks'] = (int) $row['total_clicks'];
            $row['is_expired']   = !empty($row['expiry_date']) && strtotime((string) $row['expiry_date']) < time();
            return $row;
        }, $rows);
    }

    /**
     * Get aggregated metrics and charts data for a specific URL or all user's URLs.
     *
     * @param int $userId
     * @param int|null $urlId (null for all user's URLs combined)
     * @param int $days Number of days for timeline (default 30)
     * @return array<string, mixed>
     */
    public function getAnalytics(int $userId, ?int $urlId = null, int $days = 30): array
    {
        // 1. Ownership & URL info check
        $selectedUrl = null;
        if ($urlId !== null) {
            $stmt = $this->pdo->prepare(
                'SELECT `id`, `original_url`, `short_code`, `created_at`, `expiry_date`, `is_active`
                   FROM `urls`
                  WHERE `id` = :url_id AND `user_id` = :user_id
                  LIMIT 1'
            );
            $stmt->execute([':url_id' => $urlId, ':user_id' => $userId]);
            $selectedUrl = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$selectedUrl) {
                return ['ok' => false, 'error' => 'Link not found or access denied.'];
            }
            $selectedUrl['id']        = (int) $selectedUrl['id'];
            $selectedUrl['is_active'] = (bool) $selectedUrl['is_active'];
        }

        // 2. High-level metric summary for user
        $userSummary = $this->getUserSummaryMetrics($userId);

        // 3. Raw clicks scope
        $scopeSql = 'FROM `clicks` c INNER JOIN `urls` u ON c.url_id = u.id WHERE u.user_id = :user_id';
        $params   = [':user_id' => $userId];

        if ($urlId !== null) {
            $scopeSql .= ' AND u.id = :url_id';
            $params[':url_id'] = $urlId;
        }

        // Total clicks in this scope
        $stmt = $this->pdo->prepare("SELECT COUNT(c.id) {$scopeSql}");
        $stmt->execute($params);
        $totalClicksInScope = (int) $stmt->fetchColumn();

        // 4. Timeline (Clicks over time by date)
        $clicksByDate = $this->getClicksOverTime($scopeSql, $params, $days);

        // 5. Referrers breakdown
        $topReferrers = $this->getTopReferrers($scopeSql, $params, 10);

        // 6. Devices and Browsers breakdown
        $deviceBrowserStats = $this->getDeviceAndBrowserDistribution($scopeSql, $params);

        // 7. Countries breakdown
        $topCountries = $this->getTopCountries($scopeSql, $params, 10);

        return [
            'ok'            => true,
            'selected_url'  => $selectedUrl,
            'summary'       => [
                'total_links'    => $userSummary['total_links'],
                'active_links'   => $userSummary['active_links'],
                'total_clicks'   => $totalClicksInScope,
                'overall_clicks' => $userSummary['overall_clicks'],
            ],
            'timeline'      => $clicksByDate,
            'referrers'     => $topReferrers,
            'devices'       => $deviceBrowserStats['devices'],
            'browsers'      => $deviceBrowserStats['browsers'],
            'countries'     => $topCountries,
        ];
    }

    /**
     * Calculate user-level summary stats.
     */
    private function getUserSummaryMetrics(int $userId): array
    {
        $stmt1 = $this->pdo->prepare(
            'SELECT COUNT(u.id) AS total_links,
                    COALESCE(SUM(CASE WHEN u.is_active = 1 AND (u.expiry_date IS NULL OR u.expiry_date > NOW()) THEN 1 ELSE 0 END), 0) AS active_links
               FROM `urls` u
              WHERE u.user_id = :user_id'
        );
        $stmt1->execute([':user_id' => $userId]);
        $res1 = $stmt1->fetch(PDO::FETCH_ASSOC);

        $stmt2 = $this->pdo->prepare(
            'SELECT COUNT(c.id)
               FROM `clicks` c
         INNER JOIN `urls` u ON c.url_id = u.id
              WHERE u.user_id = :user_id'
        );
        $stmt2->execute([':user_id' => $userId]);
        $overallClicks = (int) $stmt2->fetchColumn();

        return [
            'total_links'    => (int) ($res1['total_links'] ?? 0),
            'active_links'   => (int) ($res1['active_links'] ?? 0),
            'overall_clicks' => $overallClicks,
        ];
    }

    /**
     * Clicks grouped by day, padded with zeros for days with no activity.
     */
    private function getClicksOverTime(string $scopeSql, array $params, int $days): array
    {
        $sinceDate = date('Y-m-d 00:00:00', strtotime("-{$days} days"));
        $timelineParams = array_merge($params, [':since' => $sinceDate]);

        $stmt = $this->pdo->prepare(
            "SELECT DATE(c.clicked_at) AS click_date, COUNT(c.id) AS count
             {$scopeSql} AND c.clicked_at >= :since
             GROUP BY DATE(c.clicked_at)
             ORDER BY click_date ASC"
        );
        $stmt->execute($timelineParams);
        $results = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // Fill in missing days so the chart is continuous
        $labels = [];
        $data   = [];

        for ($i = $days; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $labels[] = date('M d', strtotime($d));
            $data[]   = isset($results[$d]) ? (int) $results[$d] : 0;
        }

        return [
            'labels' => $labels,
            'data'   => $data,
        ];
    }

    /**
     * Aggregated top referrers by domain.
     */
    private function getTopReferrers(string $scopeSql, array $params, int $limit = 10): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT c.referrer
             {$scopeSql}
             ORDER BY c.id DESC
             LIMIT 1000"
        );
        $stmt->execute($params);
        $rawReferrers = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $counts = [];
        foreach ($rawReferrers as $ref) {
            $ref = trim((string) $ref);
            if ($ref === '') {
                $domain = 'Direct / None';
            } else {
                $parsed = parse_url($ref, PHP_URL_HOST);
                $domain = $parsed ?: $ref;
                $domain = preg_replace('/^www\./i', '', $domain);
            }
            $counts[$domain] = ($counts[$domain] ?? 0) + 1;
        }

        arsort($counts);
        $counts = array_slice($counts, 0, $limit, true);

        $items = [];
        foreach ($counts as $domain => $count) {
            $items[] = [
                'referrer' => $domain,
                'count'    => $count,
            ];
        }

        return $items;
    }

    /**
     * User-Agent parsing for Device and Browser breakdown.
     */
    private function getDeviceAndBrowserDistribution(string $scopeSql, array $params): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT c.user_agent
             {$scopeSql}
             ORDER BY c.id DESC
             LIMIT 2000"
        );
        $stmt->execute($params);
        $userAgents = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $devices = [
            'Desktop' => 0,
            'Mobile'  => 0,
            'Tablet'  => 0,
            'Other'   => 0,
        ];

        $browsers = [
            'Chrome'  => 0,
            'Safari'  => 0,
            'Firefox' => 0,
            'Edge'    => 0,
            'Opera'   => 0,
            'Other'   => 0,
        ];

        foreach ($userAgents as $ua) {
            $uaStr = (string) $ua;
            $device  = $this->parseDevice($uaStr);
            $browser = $this->parseBrowser($uaStr);

            $devices[$device]   = ($devices[$device] ?? 0) + 1;
            $browsers[$browser] = ($browsers[$browser] ?? 0) + 1;
        }

        // Clean out zero entries if empty, but keep structure clean
        return [
            'devices'  => $devices,
            'browsers' => $browsers,
        ];
    }

    /**
     * Country breakdown from logged IP geolocation data.
     */
    private function getTopCountries(string $scopeSql, array $params, int $limit = 10): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(NULLIF(c.country, ''), 'Unknown') AS country, COUNT(c.id) AS count
             {$scopeSql}
             GROUP BY country
             ORDER BY count DESC
             LIMIT {$limit}"
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Simple fast User-Agent device parser.
     */
    private function parseDevice(string $ua): string
    {
        if ($ua === '') return 'Other';

        $lower = strtolower($ua);

        if (preg_match('/ipad|tablet|playbook|silk|kindle|(android(?!.*mobile))/i', $lower)) {
            return 'Tablet';
        }

        if (preg_match('/mobile|iphone|ipod|android|blackberry|opera mini|iemobile|wpdesktop/i', $lower)) {
            return 'Mobile';
        }

        if (preg_match('/windows|macintosh|mac os x|linux/i', $lower)) {
            return 'Desktop';
        }

        return 'Other';
    }

    /**
     * Simple fast User-Agent browser parser.
     */
    private function parseBrowser(string $ua): string
    {
        if ($ua === '') return 'Other';

        $lower = strtolower($ua);

        if (str_contains($lower, 'edg/') || str_contains($lower, 'edge/')) {
            return 'Edge';
        }
        if (str_contains($lower, 'opr/') || str_contains($lower, 'opera')) {
            return 'Opera';
        }
        if (str_contains($lower, 'chrome') || str_contains($lower, 'crios')) {
            return 'Chrome';
        }
        if (str_contains($lower, 'firefox') || str_contains($lower, 'fxios')) {
            return 'Firefox';
        }
        if (str_contains($lower, 'safari') && !str_contains($lower, 'chrome')) {
            return 'Safari';
        }

        return 'Other';
    }

    /**
     * Delete a URL owned by a specific user (clicks cascade via foreign key).
     */
    public function deleteUrl(int $userId, int $urlId): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM `urls` WHERE `id` = :url_id AND `user_id` = :user_id'
        );
        $stmt->execute([':url_id' => $urlId, ':user_id' => $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Toggle active state for a URL owned by a specific user.
     */
    public function toggleUrlStatus(int $userId, int $urlId): ?bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT `is_active` FROM `urls` WHERE `id` = :url_id AND `user_id` = :user_id LIMIT 1'
        );
        $stmt->execute([':url_id' => $urlId, ':user_id' => $userId]);
        $current = $stmt->fetchColumn();

        if ($current === false) {
            return null;
        }

        $newState = ((int) $current === 1) ? 0 : 1;

        $updateStmt = $this->pdo->prepare(
            'UPDATE `urls` SET `is_active` = :state WHERE `id` = :url_id AND `user_id` = :user_id'
        );
        $updateStmt->execute([
            ':state'   => $newState,
            ':url_id'  => $urlId,
            ':user_id' => $userId,
        ]);

        return (bool) $newState;
    }

    /**
     * Update target destination or expiry for a user's URL.
     */
    public function updateUrl(int $userId, int $urlId, string $originalUrl, ?string $expiryDate): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE `urls`
                SET `original_url` = :url,
                    `expiry_date`  = :expiry
              WHERE `id` = :url_id AND `user_id` = :user_id'
        );
        $stmt->execute([
            ':url'     => $originalUrl,
            ':expiry'  => $expiryDate,
            ':url_id'  => $urlId,
            ':user_id' => $userId,
        ]);

        return $stmt->rowCount() > 0;
    }
}
