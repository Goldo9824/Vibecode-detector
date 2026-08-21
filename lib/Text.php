<?php
declare(strict_types=1);

/**
 * Small shared text predicates. Kept separate because both analyzers need
 * them and neither should own them.
 */
final class Text
{
    /**
     * Emoji ranges worth caring about: pictographs, dingbats, symbols and the
     * variation selector. Deliberately not exhaustive — the point is to catch
     * a rocket next to the word "performance", not to pass a Unicode audit.
     */
    public static function hasEmoji(string $s): bool
    {
        return (bool) preg_match(
            '~[\x{1F300}-\x{1FAFF}\x{1F000}-\x{1F0FF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}\x{2190}-\x{21FF}]~u',
            $s
        );
    }

    /** @return string[] */
    public static function findEmoji(string $s): array
    {
        preg_match_all(
            '~[\x{1F300}-\x{1FAFF}\x{1F000}-\x{1F0FF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}]~u',
            $s,
            $m
        );
        return array_values(array_unique($m[0]));
    }

    /**
     * Does this comment carry information from outside the code?
     *
     * This is the load-bearing predicate of the whole tool. A comment that
     * mentions a standard, a date, a person, a ticket, an outage or a tradeoff
     * is knowledge the code does not contain, and a generator has no way to
     * acquire it.
     */
    public static function looksLikeWhy(string $s): bool
    {
        $why = '~\b('
            . 'because|since|otherwise|so that|in order to|to avoid|to work around|workaround|'
            . 'due to|thanks to|turns out|it seems|apparently|historically|legacy|deprecated|'
            . 'required by|mandated|per (?:the )?(?:spec|rfc|standard|audit|policy)|'
            . 'RFC\s?\d+|NIST|GDPR|HIPAA|PCI|SOC ?2|ISO ?\d+|CVE-\d+|'
            . 'bug|regression|incident|outage|postmortem|hotfix|'
            . 'safari|ie ?\d+|firefox|chrome|android|ios|webkit|'
            . 'do not|don\'t|never|must not|careful|caution|warning|beware|'
            . 'tradeoff|trade-off|compromise|for now|temporary|until we|once we|'
            . 'faster|slower|performance|memory|race condition|deadlock|timeout|'
            . 'see |ref |cf |as discussed|per '
            . ')\b~i';

        if (preg_match($why, $s)) {
            return true;
        }
        // A year, a date, a version, a ticket: all outside context.
        if (preg_match('~\b(?:19|20)\d{2}\b|\bv?\d+\.\d+\.\d+\b|\b[A-Z]{2,8}-\d+\b|#\d{2,}~', $s)) {
            return true;
        }
        return false;
    }

    /** Split camelCase / snake_case / kebab-case into space-separated words. */
    public static function splitIdentifiers(string $s): string
    {
        $s = preg_replace('~([a-z0-9])([A-Z])~', '$1 $2', $s);
        $s = str_replace(array('_', '-'), ' ', (string) $s);
        return (string) $s;
    }

    public static function wordCount(string $identifier): int
    {
        $split = trim(self::splitIdentifiers($identifier));
        $parts = preg_split('~\s+~', $split);
        return $parts ? count(array_filter($parts)) : 0;
    }

    /** Population standard deviation. */
    public static function stddev(array $values): float
    {
        $n = count($values);
        if ($n < 2) return 0.0;
        $mean = array_sum($values) / $n;
        $sum = 0.0;
        foreach ($values as $v) {
            $sum += ($v - $mean) * ($v - $mean);
        }
        return sqrt($sum / $n);
    }

    /** Strip tags and collapse whitespace, for reading page copy. */
    public static function visibleText(string $html): string
    {
        $html = preg_replace('~<(script|style|noscript|svg)\b[^>]*>.*?</\1>~is', ' ', $html);
        $html = preg_replace('~<[^>]+>~', ' ', (string) $html);
        $html = html_entity_decode((string) $html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('~\s+~u', ' ', $html));
    }
}
