<?php
declare(strict_types=1);

require_once __DIR__ . '/Pager.php';
require_once __DIR__ . '/Num.php';

/**
 * The small presentation decisions the admin pages share.
 *
 * Timestamps come out of the database as UTC strings and mode names come out
 * as the four-letter codes the log stores. Neither is what a person reads, and
 * both were being spelled out inline on every page that showed them. They live
 * here so the panel says "Whole site" and "3 days ago" the same way everywhere,
 * and so the arithmetic in ago() can be tested without a database.
 */
final class AdminUi
{
    /** 2026-08-22 14:03:11 → 22 Aug 2026, 14:03 UTC. Null, or nonsense, → an em dash. */
    public static function when(?string $utc): string
    {
        $ts = self::stamp($utc);
        return $ts === null ? '—' : gmdate('j M Y, H:i', $ts) . ' UTC';
    }

    /** The same, to the day. */
    public static function day(?string $utc): string
    {
        $ts = self::stamp($utc);
        return $ts === null ? '—' : gmdate('j M Y', $ts);
    }

    /**
     * How long ago, in the coarsest unit that still says something.
     *
     * "22 Aug 2026, 14:03 UTC" answers when; this answers whether it is still
     * going on, which is the question a list sorted by recency is really being
     * asked. Both appear together — this as the label, the timestamp as its
     * tooltip.
     */
    public static function ago(?string $utc, ?int $now = null): string
    {
        $ts = self::stamp($utc);
        if ($ts === null) {
            return '—';
        }
        $now = $now !== null ? $now : time();
        $seconds = $now - $ts;

        // A clock a few seconds out of step with the database's is not the future.
        if ($seconds < 60) {
            return 'just now';
        }
        if ($seconds < 3600) {
            $n = (int) floor($seconds / 60);
            return $n . ($n === 1 ? ' minute ago' : ' minutes ago');
        }
        if ($seconds < 86400) {
            $n = (int) floor($seconds / 3600);
            return $n . ($n === 1 ? ' hour ago' : ' hours ago');
        }
        if ($seconds < 86400 * 30) {
            $n = (int) floor($seconds / 86400);
            return $n === 1 ? 'yesterday' : $n . ' days ago';
        }
        if ($seconds < 86400 * 365) {
            $n = (int) floor($seconds / (86400 * 30));
            return $n . ($n === 1 ? ' month ago' : ' months ago');
        }
        $n = (int) floor($seconds / (86400 * 365));
        return $n . ($n === 1 ? ' year ago' : ' years ago');
    }

    /**
     * A counter, shortened past a thousand, with the exact figure kept in the
     * title so nothing is actually hidden — "1.2k" to read, "1,247" to hover.
     *
     * Returns markup rather than a string, and is therefore the one thing here
     * that must not be passed through h() at the call site: it escapes what it
     * puts inside the tag itself.
     */
    public static function count(int $n): string
    {
        $compact = Num::compact($n);
        if (!Num::isShortened($n)) {
            return self::esc($compact);
        }
        return sprintf('<span class="approx" title="%s">%s</span>',
            self::esc(Num::exact($n)), self::esc($compact));
    }

    /** url → Live page, and anything unrecognised back out unchanged. */
    public static function modeLabel(string $mode): string
    {
        $labels = array(
            'url'  => 'Live page',
            'site' => 'Whole site',
            'repo' => 'GitHub repo',
            'code' => 'Pasted code',
            'git'  => 'Git history',
        );
        return isset($labels[$mode]) ? $labels[$mode] : $mode;
    }

    /** Where the request came from, in the words the rest of the panel uses. */
    public static function sourceLabel(string $source): string
    {
        $labels = array('ui' => 'This site', 'api' => 'API');
        return isset($labels[$source]) ? $labels[$source] : $source;
    }

    /**
     * The strip of page links: ‹ Prev, 1 2 … 7 8 9 … 40, Next ›.
     *
     * Links rather than a form, like the window switcher above it: every state
     * of the list is a URL that can be bookmarked, shared, and reloaded, and
     * the whole thing keeps working with JavaScript off — which on a panel
     * with no JavaScript at all is not a concession, it is the design.
     *
     * @param array<string,mixed> $params the rest of the query string — search, sort, window
     * @param array<string,mixed> $defaults values that are left out of the URL
     */
    public static function pagination(
        int $page,
        int $totalPages,
        array $params = array(),
        array $defaults = array(),
        string $path = ''
    ): string {
        if ($totalPages < 2) {
            return '';
        }
        $page = Pager::clamp($page, $totalPages);

        $link = function (int $n, string $label, string $class = '') use ($params, $defaults, $path): string {
            $query = Pager::query(array_merge($params, array('page' => $n > 1 ? $n : null)), $defaults);
            return sprintf('<a class="page%s" href="%s">%s</a>',
                $class !== '' ? ' ' . $class : '',
                self::esc($path . ($query === '' ? '' : $query)),
                self::esc($label));
        };

        $out = '<nav class="pager" aria-label="Pages">';
        $out .= $page > 1
            ? $link($page - 1, '‹ Prev', 'page-step')
            : '<span class="page page-step is-off">‹ Prev</span>';

        foreach (Pager::window($page, $totalPages) as $n) {
            if ($n === 0) {
                $out .= '<span class="page-gap">…</span>';
                continue;
            }
            $out .= $n === $page
                ? sprintf('<span class="page is-on" aria-current="page">%d</span>', $n)
                : $link($n, (string) $n);
        }

        $out .= $page < $totalPages
            ? $link($page + 1, 'Next ›', 'page-step')
            : '<span class="page page-step is-off">Next ›</span>';

        return $out . '</nav>';
    }

    /** @return int|null a unix timestamp, or null for null, empty, and unparseable */
    private static function stamp(?string $utc): ?int
    {
        if ($utc === null || trim($utc) === '') {
            return null;
        }
        $ts = strtotime($utc . ' UTC');
        return $ts === false ? null : $ts;
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
