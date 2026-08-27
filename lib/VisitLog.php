<?php
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

/**
 * Records one row per page view, and answers the admin panel's traffic
 * questions. Only ever active when data/db-config.php is configured — see
 * lib/Db.php — and switched off entirely by adding 'log_visits' => false to
 * that file.
 *
 * The difference between this and UsageLog is what the row is about.
 * UsageLog records an analysis: what was checked and how. This records a
 * visit: which page was opened and when. Both are on the same switch, because
 * the switch is "is there a database", and neither exists without one.
 *
 * What is recorded, on purpose, and what is not:
 *   - the path, a timestamp, the referring site's host, and a coarse device
 *     class. All properties of the request, none of the person making it.
 *   - a visitor token, which is what makes "412 views by 190 people" possible.
 *     It is an HMAC of the address and user agent under this installation's
 *     secret *and today's date*, truncated. It cannot be reversed into an
 *     address, and because the date is in the salt it cannot be used to
 *     recognise the same person tomorrow. Counting people who came today is
 *     the whole of what it can do.
 *   - never the address itself, never a cookie, never a session, never the
 *     query string — which is where the URL somebody asked about would be.
 *
 * Rows older than the retention window are deleted on admin page load, so the
 * table is a rolling window rather than a permanent record.
 */
final class VisitLog
{
    /** Kept for this long, then deleted. Overridable per installation. */
    const DEFAULT_RETENTION_DAYS = 90;

    /**
     * The window every report below shares: the last N calendar days in UTC,
     * today included.
     *
     * Calendar days rather than a rolling N×24 hours, because the chart is
     * drawn in days and has to be filled in day by day. With a rolling window
     * the oldest column would cover part of a day and read as a quiet one, and
     * the tables beside it would be counting a slightly different period from
     * the chart above them.
     */
    const WINDOW = 'created_at >= UTC_DATE() - INTERVAL ? DAY';

    /**
     * Record one page view. Never throws, never blocks the page.
     *
     * @param string $path the route being viewed, e.g. '/', '/method', '/signs'
     */
    public static function record(string $path): void
    {
        if (!Db::option('log_visits', true)) {
            return;
        }
        $pdo = Db::connect();
        if ($pdo === null) {
            return;
        }

        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO visit_log (path, visitor, referer_host, device, created_at)
                 VALUES (?, ?, ?, ?, UTC_TIMESTAMP())'
            );
            $stmt->execute(array(
                self::normalisePath($path),
                self::visitorToken($ua),
                self::refererHost(),
                self::deviceOf($ua),
            ));
        } catch (Throwable $e) {
            // The table may not exist yet (log in to admin/ once to create it)
            // or the connection may have dropped. Either way, counting a visit
            // must never be the reason somebody cannot read the page.
        }
    }

    /**
     * A token that identifies this visitor today and nobody tomorrow.
     *
     * The date in the salt is the point of it. Without one, a hash of an
     * address is a stable pseudonym — the same visitor recognisable across
     * months, which is the thing this project says it does not do. With one,
     * the token answers "how many different people came today" and stops being
     * able to answer anything at all at midnight.
     */
    public static function visitorToken(string $userAgent, ?string $ip = null, ?string $day = null): string
    {
        $ip = $ip !== null ? $ip : (function_exists('vcd_client_ip') ? vcd_client_ip() : '');
        $day = $day !== null ? $day : gmdate('Y-m-d');
        $secret = function_exists('vcd_secret') ? vcd_secret() : 'no-secret';

        return substr(hash_hmac('sha256', $ip . "\n" . $userAgent, $secret . "\n" . $day), 0, 16);
    }

    /**
     * The path, without the query string.
     *
     * The query is dropped rather than trimmed: on this site it is where the
     * address somebody asked about ends up, and a certificate payload besides.
     * Neither belongs in a table about which pages get opened.
     */
    public static function normalisePath(string $path): string
    {
        $path = (string) strtok($path, '?#');
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . ltrim($path, '/');
        }
        $path = (string) preg_replace('~/index\.php$~i', '/', $path);
        if (strlen($path) > 255) {
            $path = substr($path, 0, 255);
        }
        return $path;
    }

    /**
     * Where the visit came from, as a host and nothing more.
     *
     * A full referrer carries the search someone typed and the page they were
     * reading. The host answers the only question worth asking of it — which
     * sites send people here — and carries none of that.
     */
    public static function refererHost(?string $referer = null): ?string
    {
        $referer = $referer !== null
            ? $referer
            : (isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '');
        if ($referer === '') {
            return null;
        }
        $host = parse_url($referer, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }
        $host = strtolower($host);
        // Arriving from another page of this site is navigation, not a referral.
        $self = strtolower((string) parse_url(vcd_site_url(), PHP_URL_HOST));
        if ($self !== '' && ($host === $self || $host === 'www.' . $self)) {
            return null;
        }
        return substr($host, 0, 255);
    }

    /**
     * A coarse class for the client, so bots can be told from people.
     *
     * Crawlers are most of the traffic to a small site, and a chart that mixes
     * them in is a chart about crawlers. This is a user-agent string, so it is
     * a claim rather than a fact — but the honest crawlers say so, and the
     * ones that lie are indistinguishable from people by any method available
     * here.
     */
    public static function deviceOf(string $ua): string
    {
        if ($ua === '') {
            return 'other';
        }
        if (preg_match('~bot|crawler|spider|crawl|slurp|bingpreview|facebookexternalhit|whatsapp|telegram|preview|monitor|uptime|curl|wget|python-requests|httpx|axios|go-http|java/|okhttp|scrapy|headless|lighthouse|pagespeed|gptbot|claudebot|ccbot|perplexity|applebot|semrush|ahrefs|mj12|dotbot|petalbot|yandex|baidu~i', $ua)) {
            return 'bot';
        }
        if (preg_match('~ipad|tablet|playbook|silk|kindle~i', $ua)) {
            return 'tablet';
        }
        if (preg_match('~mobi|android|iphone|ipod|windows phone~i', $ua)) {
            return 'mobile';
        }
        if (preg_match('~mozilla|chrome|safari|firefox|edge|opera~i', $ua)) {
            return 'desktop';
        }
        return 'other';
    }

    // ------------------------------------------------------------- reporting

    /**
     * Views and distinct visitors per day, oldest first, with the quiet days
     * filled in.
     *
     * A chart drawn straight from GROUP BY has no bars where nothing happened,
     * which draws a week of silence as a short week rather than a quiet one.
     * The zeroes are put back here so the shape is honest.
     *
     * @return array<int,array{day:string,views:int,visitors:int}>
     */
    public static function daily(PDO $pdo, int $days = 30, bool $includeBots = false): array
    {
        $sql = 'SELECT DATE(created_at) AS day, COUNT(*) AS views, COUNT(DISTINCT visitor) AS visitors
                FROM visit_log
                WHERE ' . self::WINDOW;
        if (!$includeBots) {
            $sql .= " AND device <> 'bot'";
        }
        $sql .= ' GROUP BY DATE(created_at)';

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array($days - 1));

        $found = array();
        foreach ($stmt->fetchAll() as $row) {
            $found[(string) $row['day']] = array(
                'views'    => (int) $row['views'],
                'visitors' => (int) $row['visitors'],
            );
        }
        return self::fillDays($found, $days);
    }

    /**
     * Put the quiet days back.
     *
     * Separated from the query so the arithmetic can be tested without a
     * database, because this is where an off-by-one would live and a chart
     * that silently drops a day is a chart nobody can check.
     *
     * @param  array<string,array{views:int,visitors:int}> $found keyed by Y-m-d
     * @return array<int,array{day:string,views:int,visitors:int}> oldest first
     */
    public static function fillDays(array $found, int $days, ?int $now = null): array
    {
        $now = $now !== null ? $now : time();
        $out = array();
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = gmdate('Y-m-d', $now - $i * 86400);
            $out[] = array(
                'day'      => $day,
                'views'    => isset($found[$day]) ? (int) $found[$day]['views'] : 0,
                'visitors' => isset($found[$day]) ? (int) $found[$day]['visitors'] : 0,
            );
        }
        return $out;
    }

    /**
     * Totals for the window: views, distinct visitors, bot hits.
     *
     * Visitors are counted per day and summed rather than counted across the
     * whole window, because the token changes daily on purpose — somebody who
     * came on Monday and Thursday is two, and there is no way to know
     * otherwise, so the label says "visits" and not "people".
     *
     * @return array{views:int,visitors:int,bots:int,busiest:?array{day:string,views:int}}
     */
    public static function summary(PDO $pdo, int $days = 30): array
    {
        $daily = self::daily($pdo, $days, false);

        $views = 0;
        $visitors = 0;
        $busiest = null;
        foreach ($daily as $row) {
            $views += $row['views'];
            $visitors += $row['visitors'];
            if ($busiest === null || $row['views'] > $busiest['views']) {
                $busiest = array('day' => $row['day'], 'views' => $row['views']);
            }
        }

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM visit_log WHERE " . self::WINDOW . " AND device = 'bot'"
        );
        $stmt->execute(array($days - 1));

        return array(
            'views'    => $views,
            'visitors' => $visitors,
            'bots'     => (int) $stmt->fetchColumn(),
            'busiest'  => ($busiest !== null && $busiest['views'] > 0) ? $busiest : null,
        );
    }

    /**
     * @return array<int,array{path:string,views:int,visitors:int}>
     */
    public static function topPaths(PDO $pdo, int $days = 30, int $limit = 20, bool $includeBots = false): array
    {
        $sql = 'SELECT path, COUNT(*) AS views, COUNT(DISTINCT visitor) AS visitors
                FROM visit_log
                WHERE ' . self::WINDOW;
        if (!$includeBots) {
            $sql .= " AND device <> 'bot'";
        }
        $sql .= ' GROUP BY path ORDER BY views DESC LIMIT ' . (int) $limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array($days - 1));

        $out = array();
        foreach ($stmt->fetchAll() as $row) {
            $out[] = array(
                'path'     => (string) $row['path'],
                'views'    => (int) $row['views'],
                'visitors' => (int) $row['visitors'],
            );
        }
        return $out;
    }

    /**
     * @return array<int,array{referer_host:string,views:int}>
     */
    public static function topReferrers(PDO $pdo, int $days = 30, int $limit = 15): array
    {
        $stmt = $pdo->prepare(
            "SELECT referer_host, COUNT(*) AS views
             FROM visit_log
             WHERE " . self::WINDOW . "
               AND referer_host IS NOT NULL
               AND device <> 'bot'
             GROUP BY referer_host ORDER BY views DESC LIMIT " . (int) $limit
        );
        $stmt->execute(array($days - 1));

        $out = array();
        foreach ($stmt->fetchAll() as $row) {
            $out[] = array(
                'referer_host' => (string) $row['referer_host'],
                'views'        => (int) $row['views'],
            );
        }
        return $out;
    }

    /** @return array<string,int> views per device class, biggest first */
    public static function byDevice(PDO $pdo, int $days = 30): array
    {
        $stmt = $pdo->prepare(
            'SELECT device, COUNT(*) AS n FROM visit_log
             WHERE ' . self::WINDOW . '
             GROUP BY device ORDER BY n DESC'
        );
        $stmt->execute(array($days - 1));

        $out = array();
        foreach ($stmt->fetchAll() as $row) {
            $out[(string) $row['device']] = (int) $row['n'];
        }
        return $out;
    }

    /**
     * Views per hour of the day across the window, 0–23.
     *
     * Answers the one scheduling question a small site actually has: when are
     * people here, and when can something be deployed without anyone noticing.
     *
     * @return array<int,int>
     */
    public static function byHour(PDO $pdo, int $days = 30): array
    {
        $stmt = $pdo->prepare(
            "SELECT HOUR(created_at) AS h, COUNT(*) AS n FROM visit_log
             WHERE " . self::WINDOW . " AND device <> 'bot'
             GROUP BY HOUR(created_at)"
        );
        $stmt->execute(array($days - 1));

        $out = array_fill(0, 24, 0);
        foreach ($stmt->fetchAll() as $row) {
            $h = (int) $row['h'];
            if ($h >= 0 && $h <= 23) {
                $out[$h] = (int) $row['n'];
            }
        }
        return $out;
    }

    /**
     * Delete anything past the retention window.
     *
     * Called from the admin page rather than from the logging path: pruning on
     * every page view would put a DELETE in front of every visitor, and the
     * window does not need to be accurate to the minute.
     *
     * @return int rows removed
     */
    public static function prune(PDO $pdo, ?int $days = null): int
    {
        $days = $days !== null ? $days : (int) Db::option('visit_retention_days', self::DEFAULT_RETENTION_DAYS);
        if ($days < 1) {
            $days = self::DEFAULT_RETENTION_DAYS;
        }
        $stmt = $pdo->prepare('DELETE FROM visit_log WHERE created_at < UTC_TIMESTAMP() - INTERVAL ? DAY');
        $stmt->execute(array($days));
        return (int) $stmt->rowCount();
    }
}
