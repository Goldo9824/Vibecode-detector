<?php
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

/**
 * Records one row per request this site makes to the GitHub API, and answers
 * the one question that has no other answer: how many repositories can be read
 * in an hour before GitHub starts saying no.
 *
 * Why this exists separately from UsageLog. UsageLog records what somebody
 * asked for; this records what it cost. They come apart in both directions:
 * one repository scan is up to GitHub::MAX_REQUESTS API calls, and a scan that
 * failed because the allowance was spent is a row here and, usually, no row
 * there at all — the analysis never got far enough to be logged. A panel built
 * only on UsageLog therefore shows a quiet hour exactly when the tool was
 * being blocked hardest, which is precisely backwards.
 *
 * What is recorded:
 *   - the repository, as "owner/name", the same form UsageLog stores it in;
 *   - which endpoint was asked for, as a short word rather than the URL;
 *   - the HTTP status, and what it amounted to: ok, blocked, missing, error;
 *   - what GitHub said was left of the hourly allowance, and when it resets;
 *   - a timestamp.
 *
 * What is not: nothing about who asked. Same rule as everywhere else here —
 * this answers "how close to the ceiling is this server", not "who put it
 * there".
 *
 * Only API requests are recorded, because only API requests spend from the
 * allowance. Source files come off raw.githubusercontent.com, which is free
 * and therefore has nothing to report — see the note in lib/GitHub.php.
 */
final class GitHubLog
{
    /** Rows older than this are deleted when the panel loads. Overridable. */
    const DEFAULT_RETENTION_DAYS = 90;

    /** What an unauthenticated caller gets per hour, per address. GitHub's number. */
    const ANON_HOURLY = 60;

    /** What a token gets instead. */
    const TOKEN_HOURLY = 5000;

    /**
     * Record one API request. Never throws: a reading must not fail because
     * the thing watching it did.
     *
     * @param array<string,string> $headers the response headers, lower-cased
     */
    public static function record(string $repo, string $endpoint, int $status, array $headers = array()): void
    {
        $pdo = Db::connect();
        if ($pdo === null) {
            return;
        }

        $remaining = isset($headers['x-ratelimit-remaining']) ? (int) $headers['x-ratelimit-remaining'] : null;
        $allowance = isset($headers['x-ratelimit-limit']) ? (int) $headers['x-ratelimit-limit'] : null;
        $reset = isset($headers['x-ratelimit-reset']) ? (int) $headers['x-ratelimit-reset'] : 0;

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO github_log (repo, endpoint, status, outcome, remaining, allowance, reset_at, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
            );
            $stmt->execute(array(
                substr($repo, 0, 255),
                substr($endpoint, 0, 32),
                max(0, min(599, $status)),
                self::outcome($status, $remaining),
                $remaining,
                $allowance,
                $reset > 0 ? gmdate('Y-m-d H:i:s', $reset) : null,
            ));
        } catch (Throwable $e) {
            // The table may not exist yet (log in to admin/ once to create it),
            // or the connection may have dropped mid-scan.
        }
    }

    /**
     * What a status code amounted to.
     *
     * 403 and 429 are both how GitHub refuses, and it only says which of the
     * two reasons applies in the remaining header: nothing left means the
     * allowance is spent, anything else means the repository itself is
     * refused. 404 is its own thing because a private or deleted repository is
     * a normal answer to a normal question and should not read as an outage.
     * A status of 0 is a request that never landed at all.
     */
    public static function outcome(int $status, ?int $remaining = null): string
    {
        if ($status >= 200 && $status < 300) {
            return 'ok';
        }
        if ($status === 403 || $status === 429) {
            // A 403 with allowance left is a repository that is blocked, not a
            // server that is. Counted as blocked either way — the scan stopped
            // — but the panel can tell them apart by the remaining column.
            return 'blocked';
        }
        if ($status === 404 || $status === 451) {
            return 'missing';
        }
        return 'error';
    }

    /** Delete rows past the retention window. Returns how many went. */
    public static function prune(PDO $pdo): int
    {
        $days = (int) Db::option('github_retention_days', self::DEFAULT_RETENTION_DAYS);
        if ($days <= 0) {
            return 0;
        }
        $stmt = $pdo->prepare('DELETE FROM github_log WHERE created_at < UTC_TIMESTAMP() - INTERVAL ? DAY');
        $stmt->execute(array($days));
        return $stmt->rowCount();
    }

    // ------------------------------------------------------------- headline

    /**
     * The figures at the top of the page.
     *
     * @return array{requests:int,blocked:int,repos:int,ok:int,missing:int,errors:int,lowest:?int,last_block:?string}
     */
    public static function summary(PDO $pdo, int $days = 30): array
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS requests,
                    COUNT(DISTINCT repo) AS repos,
                    SUM(outcome = 'ok') AS n_ok,
                    SUM(outcome = 'blocked') AS n_blocked,
                    SUM(outcome = 'missing') AS n_missing,
                    SUM(outcome = 'error') AS n_error,
                    MIN(remaining) AS lowest,
                    MAX(CASE WHEN outcome = 'blocked' THEN created_at END) AS last_block
             FROM github_log
             WHERE created_at >= UTC_DATE() - INTERVAL ? DAY"
        );
        $stmt->execute(array(max(1, $days) - 1));
        $row = $stmt->fetch();

        return array(
            'requests'   => $row ? (int) $row['requests'] : 0,
            'repos'      => $row ? (int) $row['repos'] : 0,
            'ok'         => $row ? (int) $row['n_ok'] : 0,
            'blocked'    => $row ? (int) $row['n_blocked'] : 0,
            'missing'    => $row ? (int) $row['n_missing'] : 0,
            'errors'     => $row ? (int) $row['n_error'] : 0,
            'lowest'     => ($row && $row['lowest'] !== null) ? (int) $row['lowest'] : null,
            'last_block' => ($row && $row['last_block'] !== null) ? (string) $row['last_block'] : null,
        );
    }

    /**
     * Requests and repositories per day, with the quiet days put back — the
     * same reason as UsageLog::hostDaily().
     *
     * @return array<int,array{day:string,ok:int,missing:int,blocked:int,errors:int,repos:int,n:int}>
     */
    public static function daily(PDO $pdo, int $days = 30): array
    {
        $days = max(1, $days);
        $stmt = $pdo->prepare(
            "SELECT DATE(created_at) AS day,
                    SUM(outcome = 'ok') AS n_ok,
                    SUM(outcome = 'missing') AS n_missing,
                    SUM(outcome = 'blocked') AS n_blocked,
                    SUM(outcome = 'error') AS n_error,
                    COUNT(DISTINCT repo) AS repos,
                    COUNT(*) AS n
             FROM github_log
             WHERE created_at >= UTC_DATE() - INTERVAL ? DAY
             GROUP BY DATE(created_at)"
        );
        $stmt->execute(array($days - 1));

        $found = array();
        foreach ($stmt->fetchAll() as $row) {
            $found[(string) $row['day']] = array(
                'ok'      => (int) $row['n_ok'],
                'missing' => (int) $row['n_missing'],
                'blocked' => (int) $row['n_blocked'],
                'errors'  => (int) $row['n_error'],
                'repos'   => (int) $row['repos'],
                'n'       => (int) $row['n'],
            );
        }

        $empty = array('ok' => 0, 'missing' => 0, 'blocked' => 0, 'errors' => 0, 'repos' => 0, 'n' => 0);
        $out = array();
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = gmdate('Y-m-d', time() - $i * 86400);
            $out[] = array_merge(array('day' => $day), isset($found[$day]) ? $found[$day] : $empty);
        }
        return $out;
    }

    /**
     * One row per clock hour that saw any traffic at all, oldest first.
     *
     * The hour is the unit GitHub's allowance is measured in, so it is the
     * unit this page is built on: everything about "how many before it stops"
     * is a statement about one of these rows.
     *
     * @return array<int,array{hour:string,requests:int,repos:int,blocked:int}>
     */
    public static function hourly(PDO $pdo, int $days = 7, int $limit = 336): array
    {
        $stmt = $pdo->prepare(
            "SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H') AS hour,
                    COUNT(*) AS requests,
                    COUNT(DISTINCT repo) AS repos,
                    SUM(outcome = 'blocked') AS blocked
             FROM github_log
             WHERE created_at >= UTC_DATE() - INTERVAL ? DAY
             GROUP BY hour
             ORDER BY hour DESC
             LIMIT " . max(1, $limit)
        );
        $stmt->execute(array(max(1, $days) - 1));

        $out = array();
        foreach ($stmt->fetchAll() as $row) {
            $out[] = array(
                'hour'     => (string) $row['hour'],
                'requests' => (int) $row['requests'],
                'repos'    => (int) $row['repos'],
                'blocked'  => (int) $row['blocked'],
            );
        }
        return array_reverse($out);
    }

    /**
     * For every hour that ended in a block: how far it had got first.
     *
     * This is the measurement the whole page exists for. Counting the whole
     * hour would count the requests made *after* the refusal too — which are
     * the cheap ones, since a blocked request is answered instantly — and
     * would put the ceiling higher than it is. So each hour is cut at its
     * first refusal and only what came before it is counted.
     *
     * @return array<int,array{hour:string,requests:int,repos:int}> oldest first
     */
    public static function runsBeforeBlock(PDO $pdo, int $days = 30): array
    {
        $stmt = $pdo->prepare(
            "SELECT b.hour AS hour,
                    COUNT(g.id) AS requests,
                    COUNT(DISTINCT g.repo) AS repos
             FROM (
                SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H') AS hour, MIN(created_at) AS first_block
                FROM github_log
                WHERE outcome = 'blocked' AND created_at >= UTC_DATE() - INTERVAL ? DAY
                GROUP BY hour
             ) b
             LEFT JOIN github_log g
                    ON DATE_FORMAT(g.created_at, '%Y-%m-%d %H') = b.hour
                   AND g.created_at < b.first_block
             GROUP BY b.hour
             ORDER BY b.hour"
        );
        $stmt->execute(array(max(1, $days) - 1));

        $out = array();
        foreach ($stmt->fetchAll() as $row) {
            $out[] = array(
                'hour'     => (string) $row['hour'],
                'requests' => (int) $row['requests'],
                'repos'    => (int) $row['repos'],
            );
        }
        return $out;
    }

    /**
     * Where the ceiling actually is, from the two things that bound it: the
     * emptiest hour that still got blocked, and the fullest that did not.
     *
     * Deliberately not an average of the blocked hours on its own. An average
     * of "how far it got" is dragged down by every hour that opened on an
     * allowance somebody else had already half spent, and reported alone it
     * reads as the ceiling being lower than it is. The pair is the honest
     * answer: it stopped as early as X and as late as Y, and it managed Z in
     * an hour without being stopped at all.
     *
     * Pure, so the arithmetic can be checked without a database.
     *
     * @param  array<int,array{hour:string,requests:int,repos:int}> $runs   from runsBeforeBlock()
     * @param  array<int,array{hour:string,requests:int,repos:int,blocked:int}> $hours from hourly()
     * @return array{blocks:int,typical:?int,earliest:?int,latest:?int,clean:?int,cleanHours:int}
     */
    public static function ceiling(array $runs, array $hours): array
    {
        $repos = array();
        foreach ($runs as $run) {
            $repos[] = (int) $run['repos'];
        }
        sort($repos);

        $clean = null;
        $cleanHours = 0;
        foreach ($hours as $hour) {
            if ((int) $hour['blocked'] > 0) {
                continue;
            }
            $cleanHours++;
            $clean = $clean === null ? (int) $hour['repos'] : max($clean, (int) $hour['repos']);
        }

        return array(
            'blocks'     => count($repos),
            'typical'    => self::median($repos),
            'earliest'   => $repos ? $repos[0] : null,
            'latest'     => $repos ? $repos[count($repos) - 1] : null,
            'clean'      => $clean,
            'cleanHours' => $cleanHours,
        );
    }

    /**
     * The middle value of a sorted list, or the lower of the two middles.
     *
     * The lower one rather than the mean of the pair, because this is a count
     * of repositories and "seven and a half" is not a number of repositories.
     *
     * @param array<int,int> $sorted
     */
    public static function median(array $sorted): ?int
    {
        $n = count($sorted);
        if ($n === 0) {
            return null;
        }
        return (int) $sorted[(int) floor(($n - 1) / 2)];
    }

    /** @return array<int,int> requests per hour of the day, 0–23, UTC */
    public static function byHour(PDO $pdo, int $days = 30): array
    {
        $stmt = $pdo->prepare(
            'SELECT HOUR(created_at) AS h, COUNT(*) AS n
             FROM github_log
             WHERE created_at >= UTC_DATE() - INTERVAL ? DAY
             GROUP BY HOUR(created_at)'
        );
        $stmt->execute(array(max(1, $days) - 1));

        $out = array_fill(0, 24, 0);
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['h']] = (int) $row['n'];
        }
        return $out;
    }

    /**
     * Which endpoints the allowance goes on.
     *
     * @return array<int,array{label:string,n:int}> biggest first
     */
    public static function byEndpoint(PDO $pdo, int $days = 30): array
    {
        $stmt = $pdo->prepare(
            'SELECT endpoint, COUNT(*) AS n
             FROM github_log
             WHERE created_at >= UTC_DATE() - INTERVAL ? DAY
             GROUP BY endpoint ORDER BY n DESC'
        );
        $stmt->execute(array(max(1, $days) - 1));

        $out = array();
        foreach ($stmt->fetchAll() as $row) {
            $out[] = array('label' => (string) $row['endpoint'], 'n' => (int) $row['n']);
        }
        return $out;
    }

    /**
     * The repositories this site has looked at, busiest first.
     *
     * @return array<int,array{repo:string,requests:int,blocked:int,missing:int,last_seen:string}>
     */
    public static function topRepos(PDO $pdo, int $days = 30, int $limit = 25): array
    {
        $stmt = $pdo->prepare(
            "SELECT repo,
                    COUNT(*) AS requests,
                    SUM(outcome = 'blocked') AS blocked,
                    SUM(outcome = 'missing') AS missing,
                    MAX(created_at) AS last_seen
             FROM github_log
             WHERE created_at >= UTC_DATE() - INTERVAL ? DAY AND repo <> ''
             GROUP BY repo
             ORDER BY requests DESC, last_seen DESC
             LIMIT " . max(1, $limit)
        );
        $stmt->execute(array(max(1, $days) - 1));

        $out = array();
        foreach ($stmt->fetchAll() as $row) {
            $out[] = array(
                'repo'      => (string) $row['repo'],
                'requests'  => (int) $row['requests'],
                'blocked'   => (int) $row['blocked'],
                'missing'   => (int) $row['missing'],
                'last_seen' => (string) $row['last_seen'],
            );
        }
        return $out;
    }

    /** How many distinct repositories have ever been read. */
    public static function repoTotal(PDO $pdo): int
    {
        $stmt = $pdo->query("SELECT COUNT(DISTINCT repo) FROM github_log WHERE repo <> ''");
        return $stmt === false ? 0 : (int) $stmt->fetchColumn();
    }

    /**
     * The most recent refusals, newest first.
     *
     * @return array<int,array{created_at:string,repo:string,endpoint:string,status:int,remaining:?int,reset_at:?string}>
     */
    public static function recentBlocks(PDO $pdo, int $days = 30, int $limit = 20): array
    {
        $stmt = $pdo->prepare(
            "SELECT created_at, repo, endpoint, status, remaining, reset_at
             FROM github_log
             WHERE outcome = 'blocked' AND created_at >= UTC_DATE() - INTERVAL ? DAY
             ORDER BY created_at DESC, id DESC
             LIMIT " . max(1, $limit)
        );
        $stmt->execute(array(max(1, $days) - 1));

        $out = array();
        foreach ($stmt->fetchAll() as $row) {
            $out[] = array(
                'created_at' => (string) $row['created_at'],
                'repo'       => (string) $row['repo'],
                'endpoint'   => (string) $row['endpoint'],
                'status'     => (int) $row['status'],
                'remaining'  => $row['remaining'] !== null ? (int) $row['remaining'] : null,
                'reset_at'   => $row['reset_at'] !== null ? (string) $row['reset_at'] : null,
            );
        }
        return $out;
    }

    /**
     * The most recent requests of any kind, newest first — the log itself.
     *
     * @return array<int,array{created_at:string,repo:string,endpoint:string,status:int,outcome:string,remaining:?int}>
     */
    public static function recent(PDO $pdo, int $days = 30, int $limit = 40): array
    {
        $stmt = $pdo->prepare(
            'SELECT created_at, repo, endpoint, status, outcome, remaining
             FROM github_log
             WHERE created_at >= UTC_DATE() - INTERVAL ? DAY
             ORDER BY created_at DESC, id DESC
             LIMIT ' . max(1, $limit)
        );
        $stmt->execute(array(max(1, $days) - 1));

        $out = array();
        foreach ($stmt->fetchAll() as $row) {
            $out[] = array(
                'created_at' => (string) $row['created_at'],
                'repo'       => (string) $row['repo'],
                'endpoint'   => (string) $row['endpoint'],
                'status'     => (int) $row['status'],
                'outcome'    => (string) $row['outcome'],
                'remaining'  => $row['remaining'] !== null ? (int) $row['remaining'] : null,
            );
        }
        return $out;
    }

    /**
     * The hourly chart's rows, as series() wants them: one column per hour,
     * the hours that were refused flagged so they are drawn in the accent.
     *
     * The gaps are not filled in. An hour with no requests in it is an hour
     * nobody asked for a repository, and drawing it as a zero column would
     * turn a quiet night into forty empty bars between two real ones.
     *
     * @param  array<int,array{hour:string,requests:int,repos:int,blocked:int}> $hours
     * @return array<int,array{day:string,n:int,label:string,flag:bool}>
     */
    public static function hourColumns(array $hours, string $field = 'repos'): array
    {
        $out = array();
        foreach ($hours as $hour) {
            $stamp = strtotime((string) $hour['hour'] . ':00:00 UTC');
            $out[] = array(
                'day'   => substr((string) $hour['hour'], 0, 10),
                'n'     => (int) $hour[$field],
                'label' => $stamp === false
                    ? (string) $hour['hour']
                    : gmdate('j M, H:00', $stamp) . ' UTC',
                'flag'  => ((int) $hour['blocked']) > 0,
            );
        }
        return $out;
    }
}
