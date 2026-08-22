<?php
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

/**
 * Records one row per analysis, and answers the admin panel's usage
 * questions. Only ever active when data/db-config.php is configured — see
 * lib/Db.php.
 *
 * What is recorded, on purpose, and what is not:
 *   - the mode (url, site, code, git), the source (ui or api), the API key
 *     id if one was used, and a timestamp — always.
 *   - the target address, for url and site mode only — never for code or
 *     git, because those carry no "website" to attribute usage to and the
 *     pasted content itself must never be written down anywhere.
 *   - nothing about who is asking: no IP address, no cookie, no session.
 *     This answers "how much is this tool used, and against what", not
 *     "who is using it".
 */
final class UsageLog
{
    public static function record(string $source, ?int $apiKeyId, string $mode, ?string $target): void
    {
        $pdo = Db::connect();
        if ($pdo === null) {
            return;
        }

        $host = null;
        if ($target !== null) {
            $parsed = parse_url($target, PHP_URL_HOST);
            $host = is_string($parsed) && $parsed !== '' ? substr($parsed, 0, 255) : null;
        }

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO usage_log (source, api_key_id, mode, target, target_host, created_at)
                 VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())'
            );
            $stmt->execute(array(
                $source,
                $apiKeyId,
                $mode,
                $target !== null ? substr($target, 0, 2048) : null,
                $host,
            ));
        } catch (Throwable $e) {
            // The table may not exist yet (log in to admin/ once to create
            // it) or the connection may have dropped mid-request. Either
            // way, logging must never be the reason an analysis fails.
        }
    }

    /** Total requests in the last $days days. */
    public static function totalCount(PDO $pdo, int $days = 30): int
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM usage_log WHERE created_at >= UTC_TIMESTAMP() - INTERVAL ? DAY');
        $stmt->execute(array($days));
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,int> request count per mode in the last $days days */
    public static function totalsByMode(PDO $pdo, int $days = 30): array
    {
        $stmt = $pdo->prepare(
            'SELECT mode, COUNT(*) AS n FROM usage_log
             WHERE created_at >= UTC_TIMESTAMP() - INTERVAL ? DAY
             GROUP BY mode'
        );
        $stmt->execute(array($days));

        $out = array('url' => 0, 'site' => 0, 'code' => 0, 'git' => 0);
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['mode']] = (int) $row['n'];
        }
        return $out;
    }

    /**
     * The most-analysed hosts in the last $days days, optionally restricted
     * to one API key.
     *
     * @return array<int,array{target_host:string,n:int}>
     */
    public static function topHosts(PDO $pdo, int $days = 30, ?int $apiKeyId = null, int $limit = 20): array
    {
        $sql = 'SELECT target_host, COUNT(*) AS n FROM usage_log
                WHERE created_at >= UTC_TIMESTAMP() - INTERVAL ? DAY
                  AND target_host IS NOT NULL';
        $params = array($days);
        if ($apiKeyId !== null) {
            $sql .= ' AND api_key_id = ?';
            $params[] = $apiKeyId;
        }
        $sql .= ' GROUP BY target_host ORDER BY n DESC LIMIT ' . (int) $limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function countForKey(PDO $pdo, int $apiKeyId, int $days = 30): int
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM usage_log WHERE api_key_id = ? AND created_at >= UTC_TIMESTAMP() - INTERVAL ? DAY'
        );
        $stmt->execute(array($apiKeyId, $days));
        return (int) $stmt->fetchColumn();
    }
}
