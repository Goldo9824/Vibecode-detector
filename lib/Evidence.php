<?php
declare(strict_types=1);

require_once __DIR__ . '/Text.php';

/**
 * Evidence, with the code around it.
 *
 * A single matched line proves very little on its own. "line 42: // Fetch the
 * user data" is a fact about line 42; whether it is a tell depends on what
 * line 41 and line 43 look like, and until now the reader had to take the
 * detector's word for it. An excerpt therefore carries three things the bare
 * string did not:
 *
 *   - the lines above and below it, so the reader can judge the match in the
 *     place it was found rather than in isolation;
 *   - how many times the same pattern fired, because a habit repeated forty
 *     times across a file is a different claim from one that fired once;
 *   - which document it came from, because a page's evidence is gathered from
 *     the markup, the bundle and the source map at once.
 *
 * Occurrence counts are not decoration: Report::score reads them (see
 * Signal::repetitionFactor).
 */
final class Excerpt
{
    /** Lines kept either side of the match by default. */
    const RADIUS = 3;

    /** Hard cap on one context line, so a minified bundle cannot flood the payload. */
    const LINE_MAX = 160;

    /** @var string the matched text itself, trimmed and bounded */
    public $text;
    /** @var int|null 1-based line number in the document it came from */
    public $line;
    /** @var int how many times this pattern was found in the subject */
    public $count;
    /** @var string which document it came from: a file, an asset URL, '' when there is only one */
    public $source;
    /** @var array<int,array{n:int|null,code:string,match:bool}> */
    public $context;

    /**
     * @param array<int,array{n:int|null,code:string,match:bool}> $context
     */
    private function __construct(string $text, ?int $line, int $count, string $source, array $context)
    {
        $this->text    = $text;
        $this->line    = $line;
        $this->count   = max(1, $count);
        $this->source  = $source;
        $this->context = $context;
    }

    /**
     * Evidence with nowhere to point: a measurement, a ratio, a list of names.
     * Most of what a summary line says has no single line behind it, and
     * inventing one would be worse than admitting there isn't one.
     */
    public static function plain(string $text, int $count = 1, string $source = ''): self
    {
        return new self(Report::excerpt($text), null, $count, $source, array());
    }

    /**
     * Evidence anchored to a line, carrying the lines around it.
     *
     * @param string[] $lines  the whole document, split on newlines
     * @param int      $index  0-based index of the matching line
     */
    public static function atLine(array $lines, int $index, int $count = 1, string $source = '', int $radius = self::RADIUS): self
    {
        $total = count($lines);
        if ($total === 0 || $index < 0 || $index >= $total) {
            return self::plain('', $count, $source);
        }

        $from = max(0, $index - $radius);
        $to   = min($total - 1, $index + $radius);

        $context = array();
        for ($i = $from; $i <= $to; $i++) {
            $code = self::clip(rtrim((string) $lines[$i]));
            // Blank lines are kept: the gap above a block is part of its shape,
            // and closing it up would misreport where the match sits.
            $context[] = array('n' => $i + 1, 'code' => $code, 'match' => ($i === $index));
        }

        return new self(
            Report::excerpt(self::redact(trim((string) $lines[$index]))),
            $index + 1, $count, $source, $context
        );
    }

    /**
     * Evidence located by its own text: the caller has the matched substring
     * but not the line it sat on, which is the normal case for a regex run
     * over a whole document.
     *
     * Falls back to a contextless excerpt when the needle cannot be found —
     * the match may have come from a normalised copy of the document rather
     * than the document itself, and a wrong line number is worse than none.
     */
    public static function locate(string $document, string $needle, int $count = 1, string $source = '', int $radius = self::RADIUS): self
    {
        $needle = trim($needle);
        if ($needle === '' || $document === '') {
            return self::plain($needle, $count, $source);
        }

        $lines = self::split($document);
        $probe = self::probe($needle);

        foreach ($lines as $i => $line) {
            $at = ($probe === '') ? false : stripos($line, $probe);
            if ($at === false) {
                continue;
            }

            // A minified bundle is one line a hundred kilobytes long, and the
            // lines "above and below" it do not exist. Window the hit instead:
            // what surrounds it there is measured in characters.
            if (strlen($line) > self::LINE_MAX) {
                return self::window($line, (int) $at, $i + 1, $needle, $count, $source);
            }

            $found = self::atLine($lines, $i, $count, $source, $radius);
            // Keep what the caller matched as the headline: the reader wants
            // the hit, not whatever else shares its line.
            $found->text = Report::excerpt(self::redact($needle));
            return $found;
        }

        return self::plain($needle, $count, $source);
    }

    /** Characters kept either side of a hit inside a minified line. */
    const WINDOW = 160;

    /**
     * A hit inside a line too long to show whole, with the characters around it.
     */
    private static function window(string $line, int $at, int $lineNo, string $needle, int $count, string $source): self
    {
        $from = max(0, $at - self::WINDOW);
        $len  = strlen($needle) + self::WINDOW * 2;
        $slice = Text::safeCut(substr($line, $from), $len);

        $code = ($from > 0 ? '…' : '') . self::redact(trim($slice))
              . (($from + strlen($slice)) < strlen($line) ? '…' : '');

        return new self(
            Report::excerpt(self::redact($needle)),
            $lineNo,
            $count,
            $source,
            array(array('n' => $lineNo, 'code' => $code, 'match' => true))
        );
    }

    /**
     * Evidence at a byte offset, which is what a regex with OFFSET_CAPTURE gives.
     *
     * @param string $needle what to show as the headline; the text at the
     *                       offset is used when the caller has nothing better
     */
    public static function atOffset(string $document, int $offset, int $count = 1, string $source = '', int $radius = self::RADIUS, string $needle = ''): self
    {
        // Offsets are counted in the document the caller matched against, so the
        // line breaks are counted there too rather than in a normalised copy —
        // rewriting CRLF first would move every offset by a byte per line.
        $offset = max(0, min($offset, strlen($document)));
        $before = substr($document, 0, $offset);

        $index = preg_match_all('~\r\n|\r|\n~', $before);
        $lines = preg_split('~\r\n|\r|\n~', $document);
        if (!is_array($lines) || !isset($lines[$index])) {
            return self::plain($needle, $count, $source);
        }

        $lineStart = strrpos($before, "\n");
        if ($lineStart === false) {
            $lineStart = strrpos($before, "\r");
        }
        $col = ($lineStart === false) ? $offset : $offset - $lineStart - 1;

        if (strlen($lines[$index]) > self::LINE_MAX) {
            $probe = $needle !== '' ? $needle : trim(substr($lines[$index], $col, 60));
            return self::window($lines[$index], $col, $index + 1, $probe, $count, $source);
        }

        $found = self::atLine($lines, $index, $count, $source, $radius);
        if ($needle !== '') {
            $found->text = Report::excerpt(self::redact($needle));
        }
        return $found;
    }

    /** Split a document the way every reader here expects: on \n, after normalising. */
    public static function split(string $document): array
    {
        return explode("\n", str_replace(array("\r\n", "\r"), "\n", $document));
    }

    /**
     * The part of a needle worth searching for.
     *
     * A multi-line match cannot be found on any single line, and a very long
     * one may have been collapsed by whitespace normalisation before it got
     * here. Both search on their first line, bounded.
     */
    private static function probe(string $needle): string
    {
        $first = explode("\n", $needle);
        $probe = trim($first[0]);
        if (strlen($probe) > 60) {
            $probe = Text::safeCut($probe, 60);
        }
        return $probe;
    }

    /**
     * Mask anything credential-shaped before it is shown.
     *
     * Context is raw source, and raw source is where keys live. The security
     * checks already redact the values they quote; without the same pass here,
     * a finding three lines above a hardcoded key would print the key as part
     * of its surroundings and undo that. Deliberately narrow: it masks token
     * shapes and the right-hand side of key-ish assignments, and leaves
     * ordinary code alone.
     */
    public static function redact(string $line): string
    {
        $patterns = array(
            // A JWT, which is the one shape that is unmistakable on sight.
            '~\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{6,}\.[A-Za-z0-9_-]{4,}~',
            // Vendor-prefixed keys that announce themselves.
            '~\b(?:sk|pk|rk)[-_](?:live|test|proj|ant|or)?[-_]?[A-Za-z0-9]{16,}~i',
            '~\bAKIA[0-9A-Z]{16}\b~',
            '~\bgh[pousr]_[A-Za-z0-9]{20,}~',
            '~\bAIza[0-9A-Za-z_\-]{20,}~',
            '~\bxox[baprs]-[A-Za-z0-9\-]{10,}~',
            // Placeholder credentials, masked for the same reason as live ones:
            // the security checks quote them redacted, and context printed raw
            // three lines away would put them back on the page.
            '~[\'"](?:your|my)[-_](?:secret|api|private|access)[-_]?(?:key|token|secret)?[\'"]~i',
            '~[\'"](?:change[-_]?me|replace[-_]?me|SECRET_KEY_HERE|YOUR_API_KEY|placeholder)[\'"]~i',
            '~<YOUR_[A-Z_]+>~',
        );
        foreach ($patterns as $re) {
            $line = (string) preg_replace($re, '[redacted]', $line);
        }
        // The value side of anything named like a credential.
        $line = (string) preg_replace(
            '~((?:api[_-]?key|apikey|secret|secret[_-]?key|password|passwd|pwd|token|auth[_-]?token|access[_-]?token|bearer|client[_-]?secret)["\']?\s*[:=]\s*)(["\'])([^"\'\n]{6,})(["\'])~i',
            '$1$2[redacted]$4',
            $line
        );
        return $line;
    }

    private static function clip(string $line): string
    {
        // Tabs are expanded because the renderer shows the context in a
        // pre-formatted block and a tab there is whatever the reader's browser
        // decides it is.
        $line = self::redact(str_replace("\t", '    ', $line));
        if (strlen($line) <= self::LINE_MAX) {
            return $line;
        }
        return Text::safeCut($line, self::LINE_MAX - 1) . '…';
    }

    /** Relabel the headline while keeping where it was found. */
    public function withText(string $text): self
    {
        $this->text = Report::excerpt(self::redact($text));
        return $this;
    }

    public function withCount(int $count): self
    {
        $this->count = max(1, $count);
        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->text === '' && !$this->context;
    }

    /** Identity for de-duplication inside one signal. */
    public function key(): string
    {
        return $this->source . '|' . ($this->line === null ? '-' : (string) $this->line) . '|' . $this->text;
    }

    public function toArray(): array
    {
        return array(
            'text'    => $this->text,
            'line'    => $this->line,
            'count'   => $this->count,
            'source'  => $this->source,
            'context' => $this->context,
        );
    }
}

/**
 * A document you can ask for evidence.
 *
 * Wraps a source file, a page's markup or a bundle once, so every check that
 * finds something in it gets line numbers and surrounding code without each
 * one re-splitting the document.
 */
final class SourceContext
{
    /** @var string */
    private $document;
    /** @var string[]|null */
    private $lines = null;
    /** @var string */
    private $label;
    /** @var int */
    private $radius;

    public function __construct(string $document, string $label = '', int $radius = Excerpt::RADIUS)
    {
        $this->document = $document;
        $this->label    = $label;
        $this->radius   = $radius;
    }

    /** @return string[] */
    public function lines(): array
    {
        if ($this->lines === null) {
            $this->lines = Excerpt::split($this->document);
        }
        return $this->lines;
    }

    public function label(): string
    {
        return $this->label;
    }

    /** Evidence at a known 0-based line index. */
    public function line(int $index, int $count = 1): Excerpt
    {
        return Excerpt::atLine($this->lines(), $index, $count, $this->label, $this->radius);
    }

    /** Evidence at a known byte offset, which is what preg_match's OFFSET_CAPTURE gives. */
    public function offset(int $offset, int $count = 1, string $needle = ''): Excerpt
    {
        return Excerpt::atOffset($this->document, $offset, $count, $this->label, $this->radius, $needle);
    }

    /**
     * Where a pattern first fires, with its surroundings and how often it fires.
     * Returns null when it does not fire at all, so callers can chain documents.
     */
    public function match(string $pattern, string $text = ''): ?Excerpt
    {
        if (!preg_match($pattern, $this->document, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $count = $this->occurrences($pattern);
        return $this->offset((int) $m[0][1], max(1, $count), $text !== '' ? $text : (string) $m[0][0]);
    }

    /** Evidence found by its own text. */
    public function find(string $needle, int $count = 1): Excerpt
    {
        return Excerpt::locate($this->document, $needle, $count, $this->label, $this->radius);
    }

    /** How many times a pattern fires in this document. */
    public function occurrences(string $pattern): int
    {
        $n = preg_match_all($pattern, $this->document);
        return $n === false ? 0 : $n;
    }
}
