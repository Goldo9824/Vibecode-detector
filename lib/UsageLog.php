<?php
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/AdminUi.php';

/**
 * Records one row per analysis, and answers the admin panel's usage
 * questions. Only ever active when data/db-config.php is configured — see
 * lib/Db.php.
 *
 * What is recorded, on purpose, and what is not:
 *   - the mode (url, site, repo, code, git), the source (ui or api), the API
 *     key id if one was used, and a timestamp — always.
 *   - the target address, for url, site and repo mode only — never for code
 *     or git, because those carry no "website" to attribute usage to and the
 *     pasted content itself must never be written down anywhere. A repo
 *     target is stored as "github.com/owner/name" without a scheme, so it
 *     does not resolve to a host: a repository is not a website that was
 *     visited, and counting every scan against github.com would say it was.
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

        $out = array('url' => 0, 'site' => 0, 'repo' => 0, 'code' => 0, 'git' => 0);
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

    /**
     * Analyses per day across everything, split by mode, quiet days included.
     *
     * The panel's front page had a chart of visits and a table of totals, and
     * nothing that drew the totals: "412 analyses this month" is a number, and
     * whether that was a steady trickle or one afternoon is the actual
     * question. Split by mode because the modes cost wildly different things —
     * an afternoon of whole-site crawls is not an afternoon of pasted
     * snippets.
     *
     * @return array<int,array{day:string,url:int,site:int,repo:int,code:int,git:int,n:int}>
     */
    public static function daily(PDO $pdo, int $days = 30): array
    {
        $days = max(1, $days);
        $stmt = $pdo->prepare(
            'SELECT DATE(created_at) AS day, mode, COUNT(*) AS n
             FROM usage_log
             WHERE created_at >= UTC_DATE() - INTERVAL ? DAY
             GROUP BY DATE(created_at), mode'
        );
        $stmt->execute(array($days - 1));

        $empty = array('url' => 0, 'site' => 0, 'repo' => 0, 'code' => 0, 'git' => 0, 'n' => 0);
        $found = array();
        foreach ($stmt->fetchAll() as $row) {
            $day = (string) $row['day'];
            $mode = (string) $row['mode'];
            if (!isset($found[$day])) {
                $found[$day] = $empty;
            }
            if (isset($found[$day][$mode])) {
                $found[$day][$mode] += (int) $row['n'];
            }
            $found[$day]['n'] += (int) $row['n'];
        }

        $out = array();
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = gmdate('Y-m-d', time() - $i * 86400);
            $out[] = array_merge(array('day' => $day), isset($found[$day]) ? $found[$day] : $empty);
        }
        return $out;
    }

    /** @return array<int,int> analyses per hour of the day, 0–23, UTC */
    public static function byHour(PDO $pdo, int $days = 30, ?string $host = null): array
    {
        $params = array(max(1, $days) - 1);
        $sql = 'SELECT HOUR(created_at) AS h, COUNT(*) AS n
                FROM usage_log
                WHERE created_at >= UTC_DATE() - INTERVAL ? DAY';
        if ($host !== null) {
            $sql .= ' AND target_host = ?';
            $params[] = $host;
        }
        $sql .= ' GROUP BY HOUR(created_at)';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $out = array_fill(0, 24, 0);
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['h']] = (int) $row['n'];
        }
        return $out;
    }

    /** @return array<string,int> analyses per source (ui, api) across everything */
    public static function totalsBySource(PDO $pdo, int $days = 30): array
    {
        $stmt = $pdo->prepare(
            'SELECT source, COUNT(*) AS n FROM usage_log
             WHERE created_at >= UTC_DATE() - INTERVAL ? DAY
             GROUP BY source ORDER BY n DESC'
        );
        $stmt->execute(array(max(1, $days) - 1));

        $out = array();
        foreach ($stmt->fetchAll() as $row) {
            $out[(string) $row['source']] = (int) $row['n'];
        }
        return $out;
    }

    /**
     * Websites seen for the first time on each day of the window.
     *
     * Not "websites analysed that day": a host that has been checked every day
     * for a month is one line on this chart, on the day it arrived. It answers
     * whether the tool is reaching new things or being run against the same
     * dozen, which the total cannot.
     *
     * @return array<int,array{day:string,n:int}> oldest first
     */
    public static function newHostsDaily(PDO $pdo, int $days = 30): array
    {
        $days = max(1, $days);
        $stmt = $pdo->prepare(
            'SELECT DATE(first_seen) AS day, COUNT(*) AS n FROM (
                SELECT target_host, MIN(created_at) AS first_seen
                FROM usage_log
                WHERE target_host IS NOT NULL
                GROUP BY target_host
             ) f
             WHERE f.first_seen >= UTC_DATE() - INTERVAL ? DAY
             GROUP BY DATE(first_seen)'
        );
        $stmt->execute(array($days - 1));

        $found = array();
        foreach ($stmt->fetchAll() as $row) {
            $found[(string) $row['day']] = (int) $row['n'];
        }
        return self::fillDays($found, $days);
    }

    /**
     * Analyses per day for one API key, quiet days included.
     *
     * @return array<int,array{day:string,n:int}> oldest first
     */
    public static function keyDaily(PDO $pdo, int $apiKeyId, int $days = 30): array
    {
        $days = max(1, $days);
        $stmt = $pdo->prepare(
            'SELECT DATE(created_at) AS day, COUNT(*) AS n
             FROM usage_log
             WHERE api_key_id = ? AND created_at >= UTC_DATE() - INTERVAL ? DAY
             GROUP BY DATE(created_at)'
        );
        $stmt->execute(array($apiKeyId, $days - 1));

        $found = array();
        foreach ($stmt->fetchAll() as $row) {
            $found[(string) $row['day']] = (int) $row['n'];
        }
        return self::fillDays($found, $days);
    }

    /** @return array<string,int> analyses per mode for one API key, biggest first */
    public static function keyModes(PDO $pdo, int $apiKeyId, int $days = 30): array
    {
        $stmt = $pdo->prepare(
            'SELECT mode, COUNT(*) AS n
             FROM usage_log
             WHERE api_key_id = ? AND created_at >= UTC_DATE() - INTERVAL ? DAY
             GROUP BY mode ORDER BY n DESC'
        );
        $stmt->execute(array($apiKeyId, max(1, $days) - 1));

        $out = array();
        foreach ($stmt->fetchAll() as $row) {
            $out[(string) $row['mode']] = (int) $row['n'];
        }
        return $out;
    }

    /**
     * Turn a mode => count map into the slices Chart::share() draws, in the
     * order the modes are listed everywhere else rather than by size.
     *
     * A share bar is read against its own key, and a key whose rows move about
     * between two windows of the same page is a key that has to be re-read
     * every time. Ordering is therefore fixed here and the sizes fall where
     * they fall.
     *
     * @param  array<string,int> $modes
     * @return array<int,array{label:string,n:int}>
     */
    public static function modeSlices(array $modes): array
    {
        $order = array('url', 'site', 'repo', 'code', 'git');
        $out = array();
        foreach ($order as $mode) {
            if (!empty($modes[$mode])) {
                $out[] = array('label' => AdminUi::modeLabel($mode), 'n' => (int) $modes[$mode]);
            }
        }
        foreach ($modes as $mode => $n) {
            if (!in_array($mode, $order, true) && $n > 0) {
                $out[] = array('label' => AdminUi::modeLabel((string) $mode), 'n' => (int) $n);
            }
        }
        return $out;
    }

    // ------------------------------------------------- the whole list of sites

    /**
     * The orders the website list can be put in, as a whitelist: the key is
     * what appears in the URL, the value is what goes after ORDER BY.
     *
     * A map rather than a sanitised string because ORDER BY cannot be a bound
     * parameter, so the only safe version of "sort by whatever the query
     * string says" is one where the query string can only name a key here.
     *
     * @return array<string,array{label:string,sql:string}>
     */
    public static function sorts(): array
    {
        return array(
            // The default: the order they were searched in, most recent first.
            'recent' => array('label' => 'Last searched',  'sql' => 'last_seen DESC, n DESC'),
            'first'  => array('label' => 'First searched', 'sql' => 'first_seen ASC, target_host ASC'),
            'most'   => array('label' => 'Most analysed',  'sql' => 'n DESC, last_seen DESC'),
            'least'  => array('label' => 'Least analysed', 'sql' => 'n ASC, last_seen DESC'),
            'name'   => array('label' => 'A–Z',            'sql' => 'target_host ASC'),
        );
    }

    /** Anything the query string offers that is not an order above becomes the default one. */
    public static function normaliseSort(string $sort): string
    {
        $sorts = self::sorts();
        return isset($sorts[$sort]) ? $sort : 'recent';
    }

    /**
     * Make a search term safe to put either side of a LIKE wildcard.
     *
     * Without this, typing an underscore matches any character and typing a
     * percent sign matches the entire table — a search box that quietly
     * answers a different question from the one that was typed.
     */
    public static function escapeLike(string $term): string
    {
        return str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $term);
    }

    /** How many distinct websites match, which is what tells the pager how many pages there are. */
    public static function hostTotal(PDO $pdo, int $days = 0, string $search = ''): int
    {
        $params = array();
        $sql = 'SELECT COUNT(DISTINCT target_host) FROM usage_log WHERE target_host IS NOT NULL'
             . self::windowClause($days, $params)
             . self::searchClause($search, $params);

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * One page of the website list.
     *
     * $days of 0 or less means every row ever recorded, which is what the full
     * list defaults to: the point of the page is to see everything, and a
     * thirty-day window on a list called "all websites" hides most of it.
     *
     * @return array<int,array{target_host:string,n:int,first_seen:string,last_seen:string,modes:int}>
     */
    public static function hostPage(
        PDO $pdo,
        int $days = 0,
        string $search = '',
        string $sort = 'recent',
        int $limit = 40,
        int $offset = 0
    ): array {
        $sorts = self::sorts();
        $sort = self::normaliseSort($sort);

        $params = array();
        $sql = 'SELECT target_host,
                       COUNT(*) AS n,
                       COUNT(DISTINCT mode) AS modes,
                       MIN(created_at) AS first_seen,
                       MAX(created_at) AS last_seen
                FROM usage_log
                WHERE target_host IS NOT NULL'
             . self::windowClause($days, $params)
             . self::searchClause($search, $params)
             . ' GROUP BY target_host
                 ORDER BY ' . $sorts[$sort]['sql']
             . ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $out = array();
        foreach ($stmt->fetchAll() as $row) {
            $out[] = array(
                'target_host' => (string) $row['target_host'],
                'n'           => (int) $row['n'],
                'modes'       => (int) $row['modes'],
                'first_seen'  => (string) $row['first_seen'],
                'last_seen'   => (string) $row['last_seen'],
            );
        }
        return $out;
    }

    // ------------------------------------------------------------- one site

    /**
     * Everything the headline of a single website's page needs.
     *
     * @return array{n:int,modes:int,keys:int,pages:int,first_seen:?string,last_seen:?string}
     */
    public static function hostSummary(PDO $pdo, string $host, int $days = 0): array
    {
        $params = array($host);
        $sql = 'SELECT COUNT(*) AS n,
                       COUNT(DISTINCT mode) AS modes,
                       COUNT(DISTINCT api_key_id) AS `keys`,
                       COUNT(DISTINCT target) AS pages,
                       MIN(created_at) AS first_seen,
                       MAX(created_at) AS last_seen
                FROM usage_log
                WHERE target_host = ?' . self::windowClause($days, $params);

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return array(
            'n'          => $row ? (int) $row['n'] : 0,
            'modes'      => $row ? (int) $row['modes'] : 0,
            'keys'       => $row ? (int) $row['keys'] : 0,
            'pages'      => $row ? (int) $row['pages'] : 0,
            'first_seen' => ($row && $row['first_seen'] !== null) ? (string) $row['first_seen'] : null,
            'last_seen'  => ($row && $row['last_seen'] !== null) ? (string) $row['last_seen'] : null,
        );
    }

    /**
     * The single busiest day for one website, over the whole window.
     *
     * Its own query rather than the maximum of hostDaily(), because on "all
     * time" the chart stops at a year and the totals beside it do not — taking
     * the busiest day off the chart would quietly answer "the busiest day of
     * the last year" under a heading that says everything.
     *
     * @return array{day:string,n:int}|null
     */
    public static function hostBusiestDay(PDO $pdo, string $host, int $days = 0): ?array
    {
        $params = array($host);
        $sql = 'SELECT DATE(created_at) AS day, COUNT(*) AS n FROM usage_log WHERE target_host = ?'
             . self::windowClause($days, $params)
             . ' GROUP BY DATE(created_at) ORDER BY n DESC, day DESC LIMIT 1';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row ? array('day' => (string) $row['day'], 'n' => (int) $row['n']) : null;
    }

    /**
     * Analyses per day for one website, oldest first, with the quiet days put
     * back — the same reason as VisitLog::daily(): a chart drawn straight from
     * GROUP BY has no column where nothing happened, which draws a quiet
     * fortnight as a short one.
     *
     * @return array<int,array{day:string,n:int}>
     */
    public static function hostDaily(PDO $pdo, string $host, int $days = 30): array
    {
        $days = max(1, $days);
        $stmt = $pdo->prepare(
            'SELECT DATE(created_at) AS day, COUNT(*) AS n
             FROM usage_log
             WHERE target_host = ? AND created_at >= UTC_DATE() - INTERVAL ? DAY
             GROUP BY DATE(created_at)'
        );
        $stmt->execute(array($host, $days - 1));

        $found = array();
        foreach ($stmt->fetchAll() as $row) {
            $found[(string) $row['day']] = (int) $row['n'];
        }
        return self::fillDays($found, $days);
    }

    /**
     * Put the quiet days back into a daily count.
     *
     * @param  array<string,int> $found keyed by Y-m-d
     * @return array<int,array{day:string,n:int}> oldest first
     */
    public static function fillDays(array $found, int $days, ?int $now = null): array
    {
        $now = $now !== null ? $now : time();
        $out = array();
        for ($i = max(1, $days) - 1; $i >= 0; $i--) {
            $day = gmdate('Y-m-d', $now - $i * 86400);
            $out[] = array('day' => $day, 'n' => isset($found[$day]) ? (int) $found[$day] : 0);
        }
        return $out;
    }

    /**
     * Fold a daily series into buckets of $size days.
     *
     * A year of daily columns on a chart 720 units wide is a bar a pixel and a
     * half across: it draws as a gridline rather than as a measurement, and a
     * page of them reads as an empty table. Weeks fit. Days stay days for any
     * window short enough to draw them.
     *
     * Buckets are aligned to the end of the series, so the most recent one is
     * a whole week and it is the oldest that may be short — a partial column
     * at the right-hand end reads as a fall in traffic that did not happen.
     *
     * @param  array<int,array{day:string,n:int}> $rows oldest first
     * @return array<int,array{day:string,n:int,label:string}> oldest first
     */
    public static function bucket(array $rows, int $size): array
    {
        if ($size < 2 || count($rows) === 0) {
            return $rows;
        }

        $out = array();
        $count = count($rows);
        // Where the first bucket ends, so that the last one ends on the last day.
        $edge = $count % $size;
        $start = 0;
        while ($start < $count) {
            $take = ($start === 0 && $edge > 0) ? $edge : $size;
            $slice = array_slice($rows, $start, $take);

            $n = 0;
            foreach ($slice as $row) {
                $n += (int) $row['n'];
            }
            $from = (string) $slice[0]['day'];
            $to   = (string) $slice[count($slice) - 1]['day'];

            $out[] = array(
                'day'   => $from,
                'n'     => $n,
                'label' => $from === $to ? self::dayLabel($from) : self::dayLabel($from) . ' – ' . self::dayLabel($to),
            );
            $start += $take;
        }
        return $out;
    }

    /** 2026-08-22 → 22 Aug, and anything unparseable back out unchanged. */
    private static function dayLabel(string $day): string
    {
        $ts = strtotime($day . ' 00:00:00 UTC');
        return $ts === false ? $day : gmdate('j M', $ts);
    }

    /** @return array<string,int> analyses per mode for one website, biggest first */
    public static function hostModes(PDO $pdo, string $host, int $days = 0): array
    {
        $params = array($host);
        $sql = 'SELECT mode, COUNT(*) AS n FROM usage_log WHERE target_host = ?'
             . self::windowClause($days, $params)
             . ' GROUP BY mode ORDER BY n DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $out = array();
        foreach ($stmt->fetchAll() as $row) {
            $out[(string) $row['mode']] = (int) $row['n'];
        }
        return $out;
    }

    /** @return array<string,int> analyses per source (ui, api) for one website */
    public static function hostSources(PDO $pdo, string $host, int $days = 0): array
    {
        $params = array($host);
        $sql = 'SELECT source, COUNT(*) AS n FROM usage_log WHERE target_host = ?'
             . self::windowClause($days, $params)
             . ' GROUP BY source ORDER BY n DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $out = array();
        foreach ($stmt->fetchAll() as $row) {
            $out[(string) $row['source']] = (int) $row['n'];
        }
        return $out;
    }

    /**
     * Who asked about this website: named keys, plus whatever came in without
     * one, which is the anonymous UI and any key from data/api-keys.txt.
     *
     * @return array<int,array{id:?int,name:string,n:int}>
     */
    public static function hostCallers(PDO $pdo, string $host, int $days = 0, int $limit = 20): array
    {
        $params = array($host);
        $sql = 'SELECT u.api_key_id AS id, k.name AS name, COUNT(*) AS n
                FROM usage_log u
                LEFT JOIN api_keys k ON k.id = u.api_key_id
                WHERE u.target_host = ?'
             . self::windowClause($days, $params, 'u.created_at')
             . ' GROUP BY u.api_key_id, k.name ORDER BY n DESC LIMIT ' . max(1, $limit);

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $out = array();
        foreach ($stmt->fetchAll() as $row) {
            $out[] = array(
                'id'   => $row['id'] !== null ? (int) $row['id'] : null,
                'name' => $row['name'] !== null ? (string) $row['name'] : '',
                'n'    => (int) $row['n'],
            );
        }
        return $out;
    }

    /**
     * The most recent analyses of this website, newest first.
     *
     * @return array<int,array{created_at:string,mode:string,source:string,target:?string,key_name:?string}>
     */
    public static function hostRecent(PDO $pdo, string $host, int $days = 0, int $limit = 25): array
    {
        $params = array($host);
        $sql = 'SELECT u.created_at, u.mode, u.source, u.target, k.name AS key_name
                FROM usage_log u
                LEFT JOIN api_keys k ON k.id = u.api_key_id
                WHERE u.target_host = ?'
             . self::windowClause($days, $params, 'u.created_at')
             . ' ORDER BY u.created_at DESC, u.id DESC LIMIT ' . max(1, $limit);

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $out = array();
        foreach ($stmt->fetchAll() as $row) {
            $out[] = array(
                'created_at' => (string) $row['created_at'],
                'mode'       => (string) $row['mode'],
                'source'     => (string) $row['source'],
                'target'     => $row['target'] !== null ? (string) $row['target'] : null,
                'key_name'   => $row['key_name'] !== null ? (string) $row['key_name'] : null,
            );
        }
        return $out;
    }

    // ------------------------------------------------------------- fragments

    /**
     * The time window, as SQL to append and a parameter to bind.
     *
     * Zero or less means no window at all: the full list is about everything
     * that was ever searched, so "all time" has to be a real option rather
     * than a very large number of days.
     *
     * @param array<int,mixed> $params appended to in place
     */
    private static function windowClause(int $days, array &$params, string $column = 'created_at'): string
    {
        if ($days <= 0) {
            return '';
        }
        $params[] = $days;
        return ' AND ' . $column . ' >= UTC_TIMESTAMP() - INTERVAL ? DAY';
    }

    /** @param array<int,mixed> $params appended to in place */
    private static function searchClause(string $search, array &$params, string $column = 'target_host'): string
    {
        $search = trim($search);
        if ($search === '') {
            return '';
        }
        $params[] = '%' . self::escapeLike($search) . '%';
        return ' AND ' . $column . ' LIKE ?';
    }
}
