<?php
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

/**
 * What people say when a reading looks wrong to them.
 *
 * The front page has always ended with a link to the issue tracker, which is
 * the right place for a well-argued false positive and completely the wrong
 * place for "that's way too high" — the whole population of readers who
 * disagree and are not going to open a GitHub account. This records the second
 * kind, in two clicks, on the page where the disagreement happened.
 *
 * A report is only accepted with the certificate the reading was issued with.
 * That token is a signature over the mode, the address, the score and the
 * verdict (see vcd_cert_token() in lib/bootstrap.php), so what a report
 * disagrees with is a reading this site actually produced, at the score it
 * actually gave — not whatever a form said it was. It also means a report
 * carries the reading without the reader having to describe it, and the
 * certificate id makes a second thought about the same reading an edit rather
 * than a second vote.
 *
 * What is recorded: the reading being disputed, which way the reader thinks it
 * is wrong, what they say the truth is, and their note if they left one.
 * Nothing about who they are — no address, no cookie, no session, no email
 * field to fill in. There is nowhere for an answer to go, and the page says so
 * rather than implying one is coming.
 */
final class Feedback
{
    /** How long a note can be. Long enough for a reason, short enough to read. */
    const MAX_COMMENT = 500;

    /** Which way the reader says the number is wrong. */
    const DIRECTIONS = array('too_high', 'too_low', 'about_right');

    /** What the reader says the subject actually is. */
    const TRUTHS = array('human', 'ai', 'mixed', 'unsure');

    /** Anything else that arrives in the field becomes the safe default. */
    public static function normaliseDirection(string $value): string
    {
        return in_array($value, self::DIRECTIONS, true) ? $value : 'too_high';
    }

    public static function normaliseTruth(string $value): string
    {
        return in_array($value, self::TRUTHS, true) ? $value : 'unsure';
    }

    /**
     * A note, trimmed to something storable.
     *
     * Control characters go because a log line is not a place for them, and
     * newlines fold into spaces because a note is one line in a table. The
     * text is otherwise kept exactly as typed, and escaped where it is shown.
     *
     * Byte patterns rather than /u ones, deliberately: on input that is not
     * valid UTF-8 a /u pattern matches nothing and preg_replace returns null,
     * which would silently turn a note into an empty one. The encoding is
     * repaired first, the same way api/analyze.php repairs a paste, and only
     * then is the note cut to length.
     */
    public static function normaliseComment(string $value): ?string
    {
        $value = (string) preg_replace('~[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]~', '', $value);
        $value = trim((string) preg_replace('~\s+~', ' ', $value));
        if ($value === '') {
            return null;
        }

        if (!preg_match('//u', $value)) {
            $value = function_exists('mb_convert_encoding')
                ? (string) mb_convert_encoding($value, 'UTF-8', 'UTF-8')
                : (string) preg_replace('~[^\x09\x20-\x7E]~', '', $value);
            $value = trim($value);
            if ($value === '') {
                return null;
            }
        }

        return function_exists('mb_substr')
            ? mb_substr($value, 0, self::MAX_COMMENT, 'UTF-8')
            : substr($value, 0, self::MAX_COMMENT);
    }

    /** too_high → Score too high. */
    public static function directionLabel(string $direction): string
    {
        $labels = array(
            'too_high'    => 'Score too high',
            'too_low'     => 'Score too low',
            'about_right' => 'About right',
        );
        return isset($labels[$direction]) ? $labels[$direction] : $direction;
    }

    public static function truthLabel(string $truth): string
    {
        $labels = array(
            'human'  => 'Hand-written',
            'ai'     => 'AI-generated',
            'mixed'  => 'Both',
            'unsure' => 'Not sure',
        );
        return isset($labels[$truth]) ? $labels[$truth] : $truth;
    }

    /**
     * Which quarter of the scale a reading sat in.
     *
     * The same four bands the meter on the front page is painted in, so
     * "the disagreement is all in the 55–70 band" is a statement about
     * something the reader actually saw.
     */
    public static function band(int $score): string
    {
        if ($score >= 70) {
            return 'ai';
        }
        if ($score >= 55) {
            return 'mixed';
        }
        if ($score >= 42) {
            return 'unknown';
        }
        return 'human';
    }

    /** @return array<string,string> band => what the meter calls it */
    public static function bandLabels(): array
    {
        return array(
            'human'   => 'Under 42 — hand-written',
            'unknown' => '42–54 — inconclusive',
            'mixed'   => '55–69 — possibly assisted',
            'ai'      => '70 and over — AI',
        );
    }

    /**
     * Record one report, replacing any earlier one about the same reading.
     *
     * @param array{mode:string,target:?string,score:int,verdict:string,cert_id:string} $reading
     * @return bool false when there is no database to record it in
     */
    public static function record(array $reading, string $direction, string $truth, ?string $comment): bool
    {
        $pdo = Db::connect();
        if ($pdo === null) {
            return false;
        }

        $target = $reading['target'];
        $host = null;
        if ($target !== null) {
            $parsed = parse_url($target, PHP_URL_HOST);
            $host = is_string($parsed) && $parsed !== '' ? substr($parsed, 0, 255) : null;
        }

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO result_feedback
                   (cert_id, mode, target, target_host, score, verdict, direction, truth, comment, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE
                   direction = VALUES(direction),
                   truth = VALUES(truth),
                   comment = VALUES(comment),
                   created_at = VALUES(created_at)'
            );
            $stmt->execute(array(
                substr($reading['cert_id'], 0, 12),
                substr($reading['mode'], 0, 16),
                $target !== null ? substr($target, 0, 2048) : null,
                $host,
                max(0, min(100, (int) $reading['score'])),
                substr($reading['verdict'], 0, 32),
                self::normaliseDirection($direction),
                self::normaliseTruth($truth),
                $comment,
            ));
            return true;
        } catch (Throwable $e) {
            // The table may not exist yet. A report that cannot be filed is not
            // a reason to fail the request in front of somebody who was trying
            // to help; the endpoint says it was not recorded.
            return false;
        }
    }

    // ------------------------------------------------------------- reporting

    /**
     * @return array{n:int,too_high:int,too_low:int,about_right:int,hosts:int,
     *               avg_high:?int,avg_low:?int,last:?string}
     */
    public static function summary(PDO $pdo, int $days = 30): array
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS n,
                    SUM(direction = 'too_high') AS too_high,
                    SUM(direction = 'too_low') AS too_low,
                    SUM(direction = 'about_right') AS about_right,
                    COUNT(DISTINCT target_host) AS hosts,
                    AVG(CASE WHEN direction = 'too_high' THEN score END) AS avg_high,
                    AVG(CASE WHEN direction = 'too_low' THEN score END) AS avg_low,
                    MAX(created_at) AS last
             FROM result_feedback
             WHERE created_at >= UTC_DATE() - INTERVAL ? DAY"
        );
        $stmt->execute(array(max(1, $days) - 1));
        $row = $stmt->fetch();

        return array(
            'n'           => $row ? (int) $row['n'] : 0,
            'too_high'    => $row ? (int) $row['too_high'] : 0,
            'too_low'     => $row ? (int) $row['too_low'] : 0,
            'about_right' => $row ? (int) $row['about_right'] : 0,
            'hosts'       => $row ? (int) $row['hosts'] : 0,
            'avg_high'    => ($row && $row['avg_high'] !== null) ? (int) round((float) $row['avg_high']) : null,
            'avg_low'     => ($row && $row['avg_low'] !== null) ? (int) round((float) $row['avg_low']) : null,
            'last'        => ($row && $row['last'] !== null) ? (string) $row['last'] : null,
        );
    }

    /** Reports in the last $days days, for the headline on the admin front page. */
    public static function countRecent(PDO $pdo, int $days = 30): int
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM result_feedback WHERE created_at >= UTC_DATE() - INTERVAL ? DAY');
        $stmt->execute(array(max(1, $days) - 1));
        return (int) $stmt->fetchColumn();
    }

    /**
     * Reports per day, split by which way they went, with the quiet days back.
     *
     * @return array<int,array{day:string,too_high:int,too_low:int,about_right:int,n:int}>
     */
    public static function daily(PDO $pdo, int $days = 30): array
    {
        $days = max(1, $days);
        $stmt = $pdo->prepare(
            "SELECT DATE(created_at) AS day,
                    SUM(direction = 'too_high') AS too_high,
                    SUM(direction = 'too_low') AS too_low,
                    SUM(direction = 'about_right') AS about_right,
                    COUNT(*) AS n
             FROM result_feedback
             WHERE created_at >= UTC_DATE() - INTERVAL ? DAY
             GROUP BY DATE(created_at)"
        );
        $stmt->execute(array($days - 1));

        $found = array();
        foreach ($stmt->fetchAll() as $row) {
            $found[(string) $row['day']] = array(
                'too_high'    => (int) $row['too_high'],
                'too_low'     => (int) $row['too_low'],
                'about_right' => (int) $row['about_right'],
                'n'           => (int) $row['n'],
            );
        }

        $empty = array('too_high' => 0, 'too_low' => 0, 'about_right' => 0, 'n' => 0);
        $out = array();
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = gmdate('Y-m-d', time() - $i * 86400);
            $out[] = array_merge(array('day' => $day), isset($found[$day]) ? $found[$day] : $empty);
        }
        return $out;
    }

    /**
     * Where on the scale the disagreement is, band by band.
     *
     * The most useful thing on the page: a tool whose reports cluster in one
     * band has a threshold in the wrong place, and one whose reports are spread
     * evenly is being argued with rather than being wrong.
     *
     * @return array<string,array{n:int,too_high:int,too_low:int,about_right:int}>
     */
    public static function byBand(PDO $pdo, int $days = 30): array
    {
        $stmt = $pdo->prepare(
            "SELECT CASE
                      WHEN score >= 70 THEN 'ai'
                      WHEN score >= 55 THEN 'mixed'
                      WHEN score >= 42 THEN 'unknown'
                      ELSE 'human'
                    END AS band,
                    COUNT(*) AS n,
                    SUM(direction = 'too_high') AS too_high,
                    SUM(direction = 'too_low') AS too_low,
                    SUM(direction = 'about_right') AS about_right
             FROM result_feedback
             WHERE created_at >= UTC_DATE() - INTERVAL ? DAY
             GROUP BY band"
        );
        $stmt->execute(array(max(1, $days) - 1));

        $out = array();
        foreach (array_keys(self::bandLabels()) as $band) {
            $out[$band] = array('n' => 0, 'too_high' => 0, 'too_low' => 0, 'about_right' => 0);
        }
        foreach ($stmt->fetchAll() as $row) {
            $band = (string) $row['band'];
            $out[$band] = array(
                'n'           => (int) $row['n'],
                'too_high'    => (int) $row['too_high'],
                'too_low'     => (int) $row['too_low'],
                'about_right' => (int) $row['about_right'],
            );
        }
        return $out;
    }

    /**
     * Reports per mode, biggest first.
     *
     * @return array<int,array{mode:string,n:int}>
     */
    public static function byMode(PDO $pdo, int $days = 30): array
    {
        $stmt = $pdo->prepare(
            'SELECT mode, COUNT(*) AS n
             FROM result_feedback
             WHERE created_at >= UTC_DATE() - INTERVAL ? DAY
             GROUP BY mode ORDER BY n DESC'
        );
        $stmt->execute(array(max(1, $days) - 1));

        $out = array();
        foreach ($stmt->fetchAll() as $row) {
            $out[] = array('mode' => (string) $row['mode'], 'n' => (int) $row['n']);
        }
        return $out;
    }

    /** @return array<int,array{label:string,n:int}> what people say the subject really was */
    public static function byTruth(PDO $pdo, int $days = 30): array
    {
        $stmt = $pdo->prepare(
            'SELECT truth, COUNT(*) AS n
             FROM result_feedback
             WHERE created_at >= UTC_DATE() - INTERVAL ? DAY
             GROUP BY truth ORDER BY n DESC'
        );
        $stmt->execute(array(max(1, $days) - 1));

        $out = array();
        foreach ($stmt->fetchAll() as $row) {
            $out[] = array('label' => self::truthLabel((string) $row['truth']), 'n' => (int) $row['n']);
        }
        return $out;
    }

    /**
     * The subjects people report most, which is where a fix would pay off most.
     *
     * @return array<int,array{target_host:string,n:int,too_high:int,too_low:int,avg:int}>
     */
    public static function topTargets(PDO $pdo, int $days = 30, int $limit = 15): array
    {
        $stmt = $pdo->prepare(
            "SELECT target_host, COUNT(*) AS n,
                    SUM(direction = 'too_high') AS too_high,
                    SUM(direction = 'too_low') AS too_low,
                    AVG(score) AS avg_score
             FROM result_feedback
             WHERE created_at >= UTC_DATE() - INTERVAL ? DAY AND target_host IS NOT NULL
             GROUP BY target_host
             ORDER BY n DESC
             LIMIT " . max(1, $limit)
        );
        $stmt->execute(array(max(1, $days) - 1));

        $out = array();
        foreach ($stmt->fetchAll() as $row) {
            $out[] = array(
                'target_host' => (string) $row['target_host'],
                'n'           => (int) $row['n'],
                'too_high'    => (int) $row['too_high'],
                'too_low'     => (int) $row['too_low'],
                'avg'         => (int) round((float) $row['avg_score']),
            );
        }
        return $out;
    }

    /**
     * The reports themselves, newest first. This is the page's real content:
     * everything above it is a way of deciding which of these to read.
     *
     * @return array<int,array{created_at:string,cert_id:string,mode:string,target:?string,
     *                         score:int,verdict:string,direction:string,truth:string,comment:?string}>
     */
    public static function recent(PDO $pdo, int $days = 30, int $limit = 50, string $direction = ''): array
    {
        $params = array(max(1, $days) - 1);
        $sql = 'SELECT created_at, cert_id, mode, target, score, verdict, direction, truth, comment
                FROM result_feedback
                WHERE created_at >= UTC_DATE() - INTERVAL ? DAY';
        if (in_array($direction, self::DIRECTIONS, true)) {
            $sql .= ' AND direction = ?';
            $params[] = $direction;
        }
        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit);

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $out = array();
        foreach ($stmt->fetchAll() as $row) {
            $out[] = array(
                'created_at' => (string) $row['created_at'],
                'cert_id'    => (string) $row['cert_id'],
                'mode'       => (string) $row['mode'],
                'target'     => $row['target'] !== null ? (string) $row['target'] : null,
                'score'      => (int) $row['score'],
                'verdict'    => (string) $row['verdict'],
                'direction'  => (string) $row['direction'],
                'truth'      => (string) $row['truth'],
                'comment'    => $row['comment'] !== null ? (string) $row['comment'] : null,
            );
        }
        return $out;
    }

    /**
     * How many reports there are for every hundred readings, per mode.
     *
     * A count of reports on its own says nothing: five complaints about a mode
     * used five thousand times is a mode that works. This is the figure worth
     * putting on a chart, and it needs both tables to compute — so it is done
     * here, in PHP, rather than as a join that would tie the two logs together.
     *
     * @param  array<int,array{mode:string,n:int}> $reports  from byMode()
     * @param  array<string,int>                   $analyses from UsageLog::totalsByMode()
     * @return array<int,array{mode:string,reports:int,analyses:int,per100:float}>
     */
    public static function rates(array $reports, array $analyses): array
    {
        $out = array();
        foreach ($reports as $row) {
            $mode = (string) $row['mode'];
            $n = isset($analyses[$mode]) ? (int) $analyses[$mode] : 0;
            $out[] = array(
                'mode'     => $mode,
                'reports'  => (int) $row['n'],
                'analyses' => $n,
                'per100'   => $n > 0 ? round(((int) $row['n']) / $n * 100, 1) : 0.0,
            );
        }
        return $out;
    }
}
