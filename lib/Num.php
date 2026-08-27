<?php
declare(strict_types=1);

/**
 * Numbers as a reader wants to see them.
 *
 * A counter is read, not audited: "1.2k page views" answers the question the
 * number is there to answer, and "1,247" spends four more characters saying
 * something nobody needed. Past a thousand this shortens; below it, it does
 * not touch the number at all.
 *
 * Two rules that matter more than they look:
 *
 *   - It rounds *down*. 1,999 is "1.9k", never "2k". A stats panel that
 *     rounds up tells you something happened two thousand times when it
 *     happened one thousand nine hundred and ninety-nine, and a number that
 *     overstates is worse than a number that is long.
 *   - The decimal disappears once the leading part reaches double figures:
 *     "1.2k", but "12k" rather than "12.3k". The point of shortening is a
 *     figure you take in at a glance, and four characters is the budget.
 *
 * The exact number is never thrown away — every place this is used in the
 * admin panel keeps it in the element's title, so it is one hover away.
 */
final class Num
{
    /** Below this, a number is left exactly as it is. */
    const THRESHOLD = 1000;

    /** @var array<int,array{0:int,1:string}> biggest first */
    private static $units = array(
        array(1000000000000, 'T'),
        array(1000000000, 'B'),
        array(1000000, 'M'),
        array(1000, 'k'),
    );

    /**
     * Small numbers spelled out, the way they are written in a sentence.
     *
     * Prose that counts something the code also counts goes stale silently:
     * a page that says "fourteen signs" above a list of sixteen is wrong in
     * the one way a reader will notice and never report. Anything above
     * twenty comes back as digits, because that is how it would be written
     * anyway.
     */
    public static function word(int $n): string
    {
        $words = array(
            0 => 'no', 1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five',
            6 => 'six', 7 => 'seven', 8 => 'eight', 9 => 'nine', 10 => 'ten',
            11 => 'eleven', 12 => 'twelve', 13 => 'thirteen', 14 => 'fourteen',
            15 => 'fifteen', 16 => 'sixteen', 17 => 'seventeen', 18 => 'eighteen',
            19 => 'nineteen', 20 => 'twenty',
        );
        return isset($words[$n]) ? $words[$n] : self::exact($n);
    }

    /** 999 → "999", 1000 → "1k", 1100 → "1.1k", 12345 → "12k", 1250000 → "1.2M" */
    public static function compact(int $n): string
    {
        $abs = $n < 0 ? -$n : $n;
        if ($abs < self::THRESHOLD) {
            return self::exact($n);
        }
        $sign = $n < 0 ? '-' : '';

        foreach (self::$units as $unit) {
            list($divisor, $suffix) = $unit;
            if ($abs < $divisor) {
                continue;
            }
            // Tenths, by dividing by a tenth of the unit rather than
            // multiplying first — the multiplication would overflow a 64-bit
            // int somewhere above a hundred quadrillion, and this cannot.
            $tenths = intdiv($abs, intdiv($divisor, 10));
            $whole = intdiv($tenths, 10);
            $frac = $tenths % 10;

            return $whole < 10 && $frac > 0
                ? $sign . $whole . '.' . $frac . $suffix
                : $sign . $whole . $suffix;
        }

        return self::exact($n);
    }

    /** The whole number, grouped: 1247 → "1,247". */
    public static function exact(int $n): string
    {
        return number_format($n);
    }

    /** True when compact() would actually shorten this number. */
    public static function isShortened(int $n): bool
    {
        return self::compact($n) !== self::exact($n);
    }
}
