<?php
declare(strict_types=1);

/**
 * Page numbers for a list too long to put on one screen.
 *
 * Split out from the admin pages, and free of both HTML and SQL, because the
 * two things that go wrong with pagination are arithmetic: a last page that is
 * one short, and a strip of page numbers that runs off the side of the screen
 * once there are two hundred of them. Both are testable here without a
 * database and without rendering anything — see tests/run.php.
 */
final class Pager
{
    /** Rows per page. Forty fills a screen without asking anyone to scroll for a minute. */
    const PER_PAGE = 40;

    /** How many pages there are, and never fewer than one — an empty list is page 1 of 1. */
    public static function totalPages(int $rows, int $perPage = self::PER_PAGE): int
    {
        if ($perPage < 1) {
            $perPage = self::PER_PAGE;
        }
        return max(1, (int) ceil(max(0, $rows) / $perPage));
    }

    /** A page number from the query string, pulled back inside the list that exists. */
    public static function clamp(int $page, int $totalPages): int
    {
        $totalPages = max(1, $totalPages);
        if ($page < 1) {
            return 1;
        }
        return $page > $totalPages ? $totalPages : $page;
    }

    public static function offset(int $page, int $perPage = self::PER_PAGE): int
    {
        return max(0, ($page - 1) * max(1, $perPage));
    }

    /**
     * The strip of page numbers to draw: the first page, the last page, and a
     * few either side of where you are, with 0 standing in for a gap.
     *
     * A gap that hides exactly one number is worse than the number — it costs
     * the same width and takes away a place to click — so a run of one is
     * always spelled out.
     *
     * @return array<int,int> page numbers in order; 0 means an ellipsis
     */
    public static function window(int $page, int $totalPages, int $edge = 1, int $around = 2): array
    {
        $totalPages = max(0, $totalPages);
        if ($totalPages < 1) {
            return array();
        }
        $page = self::clamp($page, $totalPages);

        $keep = array();
        for ($i = 1; $i <= $edge; $i++) {
            if ($i <= $totalPages) {
                $keep[$i] = true;
            }
        }
        for ($i = $totalPages - $edge + 1; $i <= $totalPages; $i++) {
            if ($i >= 1) {
                $keep[$i] = true;
            }
        }
        for ($i = $page - $around; $i <= $page + $around; $i++) {
            if ($i >= 1 && $i <= $totalPages) {
                $keep[$i] = true;
            }
        }

        $numbers = array_keys($keep);
        sort($numbers);

        $out = array();
        $prev = 0;
        foreach ($numbers as $n) {
            if ($prev !== 0 && $n - $prev > 1) {
                $out[] = $n - $prev === 2 ? $prev + 1 : 0;
            }
            $out[] = $n;
            $prev = $n;
        }
        return $out;
    }

    /**
     * A query string carrying the state of the page, with the defaults left
     * out so the plain list has a plain URL.
     *
     * Returned raw: escape it with h() at the point it goes into markup, the
     * same as any other value.
     *
     * @param array<string,mixed> $params
     * @param array<string,mixed> $defaults keys equal to their default are dropped
     */
    public static function query(array $params, array $defaults = array()): string
    {
        $clean = array();
        foreach ($params as $key => $value) {
            if ($value === null || $value === '' || $value === false) {
                continue;
            }
            if (array_key_exists($key, $defaults) && $value === $defaults[$key]) {
                continue;
            }
            $clean[$key] = $value;
        }
        return $clean === array() ? '' : '?' . http_build_query($clean);
    }
}
