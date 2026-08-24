<?php
declare(strict_types=1);

/**
 * Charts, drawn as SVG on the server.
 *
 * There is no charting library here for the same reason there is no framework:
 * the deployment target is an FTP upload to shared hosting, and a build step
 * would end that. Everything below writes SVG into the page as markup, which
 * costs nothing, works with JavaScript off, prints, and scales.
 *
 * Two conventions the rest of the project shares:
 *   - colour comes from the CSS custom properties in site.css, so a chart
 *     follows the light and dark themes without knowing they exist;
 *   - every chart carries a text alternative, because a picture of a number is
 *     not an accessible way to publish the number.
 */
final class Chart
{
    /**
     * Daily traffic: views as columns, distinct visitors as a line over them.
     *
     * Columns rather than two lines because at ninety points two lines cross
     * each other into porridge, and because views and visitors are different
     * kinds of quantity — one is how often, the other is how many.
     *
     * @param array<int,array{day:string,views:int,visitors:int}> $rows oldest first
     */
    public static function daily(array $rows, string $label = 'Views and visitors per day'): string
    {
        $n = count($rows);
        if ($n === 0) {
            return '';
        }

        $w = 720.0;
        $h = 180.0;
        $padL = 34.0;
        $padR = 8.0;
        $padT = 12.0;
        $padB = 22.0;
        $plotW = $w - $padL - $padR;
        $plotH = $h - $padT - $padB;

        $max = 0;
        foreach ($rows as $r) {
            $max = max($max, (int) $r['views'], (int) $r['visitors']);
        }
        // A flat zero row would divide by nothing and draw a full-height bar
        // for every empty day, which reads as a busy week of no traffic.
        $scale = $max > 0 ? $max : 1;

        $slot = $plotW / $n;
        $barW = max(1.0, min(18.0, $slot * 0.7));

        $bars = '';
        $line = array();
        foreach ($rows as $i => $r) {
            $views = (int) $r['views'];
            $visitors = (int) $r['visitors'];
            $cx = $padL + $slot * ($i + 0.5);

            if ($views > 0) {
                $bh = ($views / $scale) * $plotH;
                $bars .= sprintf(
                    '<rect x="%.2f" y="%.2f" width="%.2f" height="%.2f" rx="1" class="c-bar"><title>%s — %d views, %d visitors</title></rect>',
                    $cx - $barW / 2,
                    $padT + $plotH - $bh,
                    $barW,
                    $bh,
                    self::esc(self::dayLabel((string) $r['day'])),
                    $views,
                    $visitors
                );
            }
            $line[] = sprintf('%.2f,%.2f', $cx, $padT + $plotH - ($visitors / $scale) * $plotH);
        }

        $grid = self::gridlines($max, $padL, $padT, $plotH, $w - $padR);

        $first = self::dayLabel((string) $rows[0]['day']);
        $last  = self::dayLabel((string) $rows[$n - 1]['day']);
        $axis  = sprintf('<text x="%.2f" y="%.2f" class="c-axis">%s</text>', $padL, $h - 6, self::esc($first))
               . sprintf('<text x="%.2f" y="%.2f" class="c-axis" text-anchor="end">%s</text>', $w - $padR, $h - 6, self::esc($last));

        return sprintf(
            '<svg class="chart" viewBox="0 0 %d %d" preserveAspectRatio="none" role="img" aria-label="%s">'
            . '<title>%s</title>%s%s<polyline points="%s" class="c-line"/>%s</svg>',
            (int) $w, (int) $h,
            self::esc($label), self::esc($label),
            $grid, $bars, implode(' ', $line), $axis
        );
    }

    /**
     * One quantity per day, as columns.
     *
     * The same picture as daily() with the line taken off, for a series that
     * only has one number in it — a single website's analyses, where there is
     * no second quantity to draw and a lone line over a lone bar would just be
     * the same data twice.
     *
     * @param array<int,array{day:string,n:int}> $rows oldest first
     */
    public static function series(array $rows, string $label = 'Per day', string $noun = 'analyses'): string
    {
        $n = count($rows);
        if ($n === 0) {
            return '';
        }

        $w = 720.0;
        $h = 180.0;
        $padL = 34.0;
        $padR = 8.0;
        $padT = 12.0;
        $padB = 22.0;
        $plotW = $w - $padL - $padR;
        $plotH = $h - $padT - $padB;

        $max = 0;
        foreach ($rows as $r) {
            $max = max($max, (int) $r['n']);
        }
        // A flat zero row would divide by nothing and draw a full-height bar
        // for every empty day, which reads as a busy month of nothing.
        $scale = $max > 0 ? $max : 1;

        $slot = $plotW / $n;
        $barW = max(1.0, min(18.0, $slot * 0.7));

        $bars = '';
        foreach ($rows as $i => $r) {
            $value = (int) $r['n'];
            if ($value <= 0) {
                continue;
            }
            $cx = $padL + $slot * ($i + 0.5);
            $bh = ($value / $scale) * $plotH;
            $bars .= sprintf(
                '<rect x="%.2f" y="%.2f" width="%.2f" height="%.2f" rx="1" class="c-bar"><title>%s — %d %s</title></rect>',
                $cx - $barW / 2,
                $padT + $plotH - $bh,
                $barW,
                $bh,
                self::esc(self::rowLabel($r)),
                $value,
                self::esc($noun)
            );
        }

        $grid = self::gridlines($max, $padL, $padT, $plotH, $w - $padR);

        $axis = sprintf('<text x="%.2f" y="%.2f" class="c-axis">%s</text>',
                    $padL, $h - 6, self::esc(self::rowLabel($rows[0])))
              . sprintf('<text x="%.2f" y="%.2f" class="c-axis" text-anchor="end">%s</text>',
                    $w - $padR, $h - 6, self::esc(self::rowLabel($rows[$n - 1])));

        return sprintf(
            '<svg class="chart" viewBox="0 0 %d %d" preserveAspectRatio="none" role="img" aria-label="%s">'
            . '<title>%s</title>%s%s%s</svg>',
            (int) $w, (int) $h,
            self::esc($label), self::esc($label),
            $grid, $bars, $axis
        );
    }

    /**
     * Views by hour of day, 0–23, in UTC.
     *
     * @param array<int,int> $counts indexed by hour
     */
    public static function hours(array $counts, string $label = 'Views by hour of day, UTC'): string
    {
        $max = 0;
        foreach ($counts as $n) {
            $max = max($max, (int) $n);
        }
        if ($max === 0) {
            return '';
        }

        $w = 720.0;
        $h = 110.0;
        $padT = 8.0;
        $padB = 20.0;
        $plotH = $h - $padT - $padB;
        $slot = $w / 24;
        $barW = $slot * 0.62;

        $bars = '';
        for ($hour = 0; $hour < 24; $hour++) {
            $n = isset($counts[$hour]) ? (int) $counts[$hour] : 0;
            if ($n === 0) {
                continue;
            }
            $bh = ($n / $max) * $plotH;
            $bars .= sprintf(
                '<rect x="%.2f" y="%.2f" width="%.2f" height="%.2f" rx="1" class="c-bar"><title>%02d:00 UTC — %d views</title></rect>',
                $slot * $hour + ($slot - $barW) / 2, $padT + $plotH - $bh, $barW, $bh, $hour, $n
            );
        }

        $ticks = '';
        foreach (array(0, 6, 12, 18) as $hour) {
            $ticks .= sprintf('<text x="%.2f" y="%.2f" class="c-axis" text-anchor="middle">%02d:00</text>',
                $slot * $hour + $slot / 2, $h - 6, $hour);
        }

        return sprintf(
            '<svg class="chart chart-hours" viewBox="0 0 %d %d" preserveAspectRatio="none" role="img" aria-label="%s">'
            . '<title>%s</title>%s%s</svg>',
            (int) $w, (int) $h, self::esc($label), self::esc($label), $bars, $ticks
        );
    }

    /**
     * The width, as a percentage string, of an in-table bar.
     *
     * Table rows get a bar behind the label rather than their own chart: it is
     * the same comparison a bar chart would draw, in a shape that stays
     * readable at twenty rows and stays copyable as text.
     */
    public static function barWidth(int $value, int $max): string
    {
        if ($max <= 0 || $value <= 0) {
            return '0%';
        }
        return sprintf('%.1f%%', min(100.0, ($value / $max) * 100.0));
    }

    /**
     * Gridlines at nothing, half and all, with the numbers that go with them.
     *
     * A label is skipped when it would repeat the one below it. On a chart
     * whose busiest day saw one analysis, the halfway line rounds to the same
     * number as the top one, and an axis reading "1, 1, 0" says the scale is
     * broken rather than that the traffic is small.
     */
    private static function gridlines(int $max, float $padL, float $padT, float $plotH, float $right): string
    {
        $out = '';
        $drawn = null;
        foreach (array(0.0, 0.5, 1.0) as $frac) {
            $y = $padT + $plotH - $frac * $plotH;
            $out .= sprintf('<line x1="%.2f" y1="%.2f" x2="%.2f" y2="%.2f" class="c-grid"/>', $padL, $y, $right, $y);

            $value = (int) round($max * $frac);
            if ($drawn !== null && $value === $drawn) {
                continue;
            }
            $out .= sprintf('<text x="%.2f" y="%.2f" class="c-axis" text-anchor="end">%d</text>',
                $padL - 6, $y + 3.5, $value);
            $drawn = $value;
        }
        return $out;
    }

    /** A row's own label if it has one — a bucket spans days and says so — otherwise its date. */
    private static function rowLabel(array $row): string
    {
        if (isset($row['label']) && $row['label'] !== '') {
            return (string) $row['label'];
        }
        return self::dayLabel((string) $row['day']);
    }

    /** 2026-08-22 → 22 Aug, and anything unparseable back out unchanged. */
    private static function dayLabel(string $day): string
    {
        $ts = strtotime($day . ' 00:00:00 UTC');
        return $ts === false ? $day : gmdate('j M', $ts);
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
