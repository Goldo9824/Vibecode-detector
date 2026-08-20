<?php
declare(strict_types=1);

/**
 * A very small PDF 1.4 writer.
 *
 * Written by hand because the deployment target is shared hosting with no
 * Composer and no shell: a PDF library is not an option, and neither is
 * generating the certificate client-side if it is to carry a signature the
 * server issued.
 *
 * Scope is deliberately tiny — the four standard-14 faces used here need no
 * embedding, so a certificate is a few kilobytes of vector drawing and text.
 * Coordinates are given from the TOP-LEFT in points and flipped internally,
 * because reasoning about a page upside down is a needless tax.
 */
final class Pdf
{
    const A4_W = 595.28;
    const A4_H = 841.89;

    /** @var string[] finished page content streams */
    private $pages = array();
    /** @var string current content stream */
    private $buf = '';
    /** @var bool */
    private $started = false;
    /** @var float */
    private $w;
    /** @var float */
    private $h;
    /** @var string */
    private $title = 'Document';

    /** @var array<string,string> resource name => base font */
    private static $faces = array(
        'F1' => 'Helvetica',
        'F2' => 'Helvetica-Bold',
        'F3' => 'Helvetica-Oblique',
        'F4' => 'Courier',
        'F5' => 'Courier-Bold',
    );

    public function __construct(float $width = self::A4_W, float $height = self::A4_H)
    {
        $this->w = $width;
        $this->h = $height;
    }

    public function width(): float
    {
        return $this->w;
    }

    public function height(): float
    {
        return $this->h;
    }

    public function setTitle(string $t): void
    {
        $this->title = $t;
    }

    public function addPage(): void
    {
        if ($this->started) {
            $this->pages[] = $this->buf;
        }
        $this->buf = '';
        $this->started = true;
    }

    public function pageCount(): int
    {
        return count($this->pages) + ($this->started ? 1 : 0);
    }

    // ------------------------------------------------------------------ colour

    /** @param array{0:int,1:int,2:int} $rgb */
    public function fillColor(array $rgb): void
    {
        $this->raw(sprintf('%.3F %.3F %.3F rg', $rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255));
    }

    /** @param array{0:int,1:int,2:int} $rgb */
    public function strokeColor(array $rgb): void
    {
        $this->raw(sprintf('%.3F %.3F %.3F RG', $rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255));
    }

    // -------------------------------------------------------------------- text

    /**
     * Draw a single line of text with its baseline at $y.
     *
     * @param array{0:int,1:int,2:int}|null $color
     */
    public function text(float $x, float $y, string $s, string $font = 'F1', float $size = 10, ?array $color = null, float $charSpace = 0.0): void
    {
        if ($s === '') {
            return;
        }
        if ($color !== null) {
            $this->fillColor($color);
        }
        $enc = self::escape(self::toWinAnsi($s));
        $cs = $charSpace != 0.0 ? sprintf(' %.3F Tc', $charSpace) : '';
        $reset = $charSpace != 0.0 ? ' 0 Tc' : '';
        $this->raw(sprintf(
            'BT /%s %.2F Tf%s 1 0 0 1 %.2F %.2F Tm (%s) Tj%s ET',
            $font, $size, $cs, $x, $this->py($y), $enc, $reset
        ));
    }

    /** @param array{0:int,1:int,2:int}|null $color */
    public function textCenter(float $cx, float $y, string $s, string $font = 'F1', float $size = 10, ?array $color = null, float $charSpace = 0.0): void
    {
        $w = $this->textWidth($s, $font, $size, $charSpace);
        $this->text($cx - $w / 2, $y, $s, $font, $size, $color, $charSpace);
    }

    /** @param array{0:int,1:int,2:int}|null $color */
    public function textRight(float $rx, float $y, string $s, string $font = 'F1', float $size = 10, ?array $color = null): void
    {
        $this->text($rx - $this->textWidth($s, $font, $size), $y, $s, $font, $size, $color);
    }

    public function textWidth(string $s, string $font = 'F1', float $size = 10, float $charSpace = 0.0): float
    {
        $s = self::toWinAnsi($s);
        $widths = self::widths($font);
        $total = 0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = ord($s[$i]);
            $total += isset($widths[$c]) ? $widths[$c] : 556;
        }
        return ($total / 1000) * $size + $charSpace * max(0, $len - 1);
    }

    /**
     * Greedy word wrap.
     *
     * @return string[]
     */
    public function wrap(string $s, float $maxWidth, string $font = 'F1', float $size = 10): array
    {
        $s = trim(preg_replace('~\s+~u', ' ', $s) ?? '');
        if ($s === '') {
            return array();
        }
        $out = array();
        $line = '';
        foreach (explode(' ', $s) as $word) {
            $try = $line === '' ? $word : $line . ' ' . $word;
            if ($this->textWidth($try, $font, $size) <= $maxWidth) {
                $line = $try;
                continue;
            }
            if ($line !== '') {
                $out[] = $line;
            }
            // A single word wider than the column has to be cut.
            while ($this->textWidth($word, $font, $size) > $maxWidth && strlen($word) > 1) {
                $cut = strlen($word);
                while ($cut > 1 && $this->textWidth(substr($word, 0, $cut), $font, $size) > $maxWidth) {
                    $cut--;
                }
                $out[] = substr($word, 0, $cut);
                $word = substr($word, $cut);
            }
            $line = $word;
        }
        if ($line !== '') {
            $out[] = $line;
        }
        return $out;
    }

    /**
     * Draw wrapped text and return the y just past the last baseline.
     *
     * @param array{0:int,1:int,2:int}|null $color
     */
    public function paragraph(float $x, float $y, float $maxWidth, string $s, string $font = 'F1', float $size = 10, ?float $leading = null, ?array $color = null): float
    {
        $leading = $leading ?? $size * 1.42;
        foreach ($this->wrap($s, $maxWidth, $font, $size) as $line) {
            $this->text($x, $y, $line, $font, $size, $color);
            $y += $leading;
        }
        return $y;
    }

    // ---------------------------------------------------------------- graphics

    /**
     * @param array{0:int,1:int,2:int}|null $fill
     * @param array{0:int,1:int,2:int}|null $stroke
     */
    public function rect(float $x, float $y, float $w, float $h, ?array $fill = null, ?array $stroke = null, float $lineWidth = 1.0): void
    {
        if ($fill === null && $stroke === null) {
            return;
        }
        if ($fill !== null)   $this->fillColor($fill);
        if ($stroke !== null) { $this->strokeColor($stroke); $this->raw(sprintf('%.2F w', $lineWidth)); }

        $op = ($fill !== null && $stroke !== null) ? 'B' : ($fill !== null ? 'f' : 'S');
        $this->raw(sprintf('%.2F %.2F %.2F %.2F re %s', $x, $this->py($y + $h), $w, $h, $op));
    }

    /** @param array{0:int,1:int,2:int} $color */
    public function line(float $x1, float $y1, float $x2, float $y2, array $color, float $width = 1.0): void
    {
        $this->strokeColor($color);
        $this->raw(sprintf('%.2F w %.2F %.2F m %.2F %.2F l S', $width, $x1, $this->py($y1), $x2, $this->py($y2)));
    }

    /**
     * @param array<int,array{0:float,1:float}> $points
     * @param array{0:int,1:int,2:int}|null     $stroke
     * @param array{0:int,1:int,2:int}|null     $fill
     */
    public function polyline(array $points, ?array $stroke = null, float $width = 1.0, bool $close = false, ?array $fill = null, string $cap = 'round'): void
    {
        if (count($points) < 2) {
            return;
        }
        if ($fill !== null)   $this->fillColor($fill);
        if ($stroke !== null) $this->strokeColor($stroke);

        $capCode = $cap === 'round' ? 1 : ($cap === 'square' ? 2 : 0);
        $this->raw(sprintf('%.2F w %d J %d j', $width, $capCode, $capCode === 1 ? 1 : 0));

        $d = sprintf('%.2F %.2F m', $points[0][0], $this->py($points[0][1]));
        for ($i = 1, $n = count($points); $i < $n; $i++) {
            $d .= sprintf(' %.2F %.2F l', $points[$i][0], $this->py($points[$i][1]));
        }
        if ($close) {
            $d .= ' h';
        }
        $op = ($fill !== null && $stroke !== null) ? 'B' : ($fill !== null ? 'f' : 'S');
        $this->raw($d . ' ' . $op);
    }

    /**
     * @param array{0:int,1:int,2:int}|null $fill
     * @param array{0:int,1:int,2:int}|null $stroke
     */
    public function circle(float $cx, float $cy, float $r, ?array $fill = null, ?array $stroke = null, float $width = 1.0): void
    {
        $k = 0.5522847498 * $r;
        $y = $this->py($cy);

        if ($fill !== null)   $this->fillColor($fill);
        if ($stroke !== null) { $this->strokeColor($stroke); $this->raw(sprintf('%.2F w', $width)); }

        $d  = sprintf('%.2F %.2F m', $cx + $r, $y);
        $d .= sprintf(' %.2F %.2F %.2F %.2F %.2F %.2F c', $cx + $r, $y + $k, $cx + $k, $y + $r, $cx, $y + $r);
        $d .= sprintf(' %.2F %.2F %.2F %.2F %.2F %.2F c', $cx - $k, $y + $r, $cx - $r, $y + $k, $cx - $r, $y);
        $d .= sprintf(' %.2F %.2F %.2F %.2F %.2F %.2F c', $cx - $r, $y - $k, $cx - $k, $y - $r, $cx, $y - $r);
        $d .= sprintf(' %.2F %.2F %.2F %.2F %.2F %.2F c', $cx + $k, $y - $r, $cx + $r, $y - $k, $cx + $r, $y);

        $op = ($fill !== null && $stroke !== null) ? 'B' : ($fill !== null ? 'f' : 'S');
        $this->raw($d . ' ' . $op);
    }

    /** Rounded rectangle. @param array{0:int,1:int,2:int}|null $fill */
    public function roundRect(float $x, float $y, float $w, float $h, float $r, ?array $fill = null, ?array $stroke = null, float $lineWidth = 1.0): void
    {
        $r = min($r, $w / 2, $h / 2);
        $k = 0.5522847498 * $r;
        $b = $this->py($y + $h);
        $t = $this->py($y);

        if ($fill !== null)   $this->fillColor($fill);
        if ($stroke !== null) { $this->strokeColor($stroke); $this->raw(sprintf('%.2F w', $lineWidth)); }

        $d  = sprintf('%.2F %.2F m', $x + $r, $b);
        $d .= sprintf(' %.2F %.2F l', $x + $w - $r, $b);
        $d .= sprintf(' %.2F %.2F %.2F %.2F %.2F %.2F c', $x + $w - $r + $k, $b, $x + $w, $b + $r - $k, $x + $w, $b + $r);
        $d .= sprintf(' %.2F %.2F l', $x + $w, $t - $r);
        $d .= sprintf(' %.2F %.2F %.2F %.2F %.2F %.2F c', $x + $w, $t - $r + $k, $x + $w - $r + $k, $t, $x + $w - $r, $t);
        $d .= sprintf(' %.2F %.2F l', $x + $r, $t);
        $d .= sprintf(' %.2F %.2F %.2F %.2F %.2F %.2F c', $x + $r - $k, $t, $x, $t - $r + $k, $x, $t - $r);
        $d .= sprintf(' %.2F %.2F l', $x, $b + $r);
        $d .= sprintf(' %.2F %.2F %.2F %.2F %.2F %.2F c', $x, $b + $r - $k, $x + $r - $k, $b, $x + $r, $b);
        $d .= ' h';

        $op = ($fill !== null && $stroke !== null) ? 'B' : ($fill !== null ? 'f' : 'S');
        $this->raw($d . ' ' . $op);
    }

    public function save(): void
    {
        $this->raw('q');
    }

    public function restore(): void
    {
        $this->raw('Q');
    }

    /** Clip everything that follows to a rectangle, until restore(). */
    public function clipRect(float $x, float $y, float $w, float $h): void
    {
        $this->raw(sprintf('%.2F %.2F %.2F %.2F re W n', $x, $this->py($y + $h), $w, $h));
    }

    public function raw(string $ops): void
    {
        if (!$this->started) {
            $this->addPage();
        }
        $this->buf .= $ops . "\n";
    }

    // ------------------------------------------------------------------ output

    public function output(): string
    {
        if ($this->started) {
            $this->pages[] = $this->buf;
            $this->started = false;
            $this->buf = '';
        }
        if (!$this->pages) {
            $this->pages[] = '';
        }

        $objects = array();
        $add = function (string $body) use (&$objects): int {
            $objects[] = $body;
            return count($objects);
        };

        $catalogId = $add('');  // 1, filled in below
        $pagesId   = $add('');  // 2

        $fontRes = '';
        foreach (self::$faces as $key => $base) {
            $id = $add('<< /Type /Font /Subtype /Type1 /BaseFont /' . $base . ' /Encoding /WinAnsiEncoding >>');
            $fontRes .= '/' . $key . ' ' . $id . ' 0 R ';
        }

        $kids = array();
        foreach ($this->pages as $content) {
            $stream = $content;
            $filter = '';
            if (function_exists('gzcompress')) {
                $packed = @gzcompress($stream, 6);
                if ($packed !== false && strlen($packed) < strlen($stream)) {
                    $stream = $packed;
                    $filter = '/Filter /FlateDecode ';
                }
            }
            $contentId = $add('<< ' . $filter . '/Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream");
            $pageId = $add(sprintf(
                '<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << %s>> /ProcSet [/PDF /Text] >> /Contents %d 0 R >>',
                $pagesId, $this->w, $this->h, $fontRes, $contentId
            ));
            $kids[] = $pageId . ' 0 R';
        }

        $objects[$pagesId - 1] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        $objects[$catalogId - 1] = '<< /Type /Catalog /Pages ' . $pagesId . ' 0 R >>';

        $now = '(D:' . gmdate('YmdHis') . 'Z)';
        $infoId = $add(
            '<< /Title (' . self::escape(self::toWinAnsi($this->title)) . ')'
            . ' /Producer (Vibe Code Detector)'
            . ' /Creator (Vibe Code Detector)'
            . ' /CreationDate ' . $now . ' /ModDate ' . $now . ' >>'
        );

        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = array();
        foreach ($objects as $i => $body) {
            $offsets[$i + 1] = strlen($out);
            $out .= ($i + 1) . " 0 obj\n" . $body . "\nendobj\n";
        }

        $xrefPos = strlen($out);
        $count = count($objects) + 1;
        $out .= "xref\n0 " . $count . "\n";
        $out .= "0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        $docId = strtoupper(md5($this->title . $xrefPos . gmdate('c')));
        $out .= "trailer\n<< /Size " . $count . " /Root " . $catalogId . " 0 R /Info " . $infoId . " 0 R"
              . " /ID [<" . $docId . "> <" . $docId . ">] >>\n";
        $out .= "startxref\n" . $xrefPos . "\n%%EOF\n";

        return $out;
    }

    // ----------------------------------------------------------------- private

    private function py(float $y): float
    {
        return $this->h - $y;
    }

    private static function escape(string $s): string
    {
        return str_replace(array('\\', '(', ')', "\r"), array('\\\\', '\\(', '\\)', ''), $s);
    }

    /**
     * The standard-14 faces are encoded WinAnsi, so UTF-8 has to come down to
     * CP1252. Anything outside it (emoji, CJK) is transliterated or dropped —
     * a certificate is not the place to discover a font-embedding problem.
     */
    private static function toWinAnsi(string $s): string
    {
        if ($s === '') {
            return '';
        }
        if (function_exists('iconv')) {
            $out = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s);
            if ($out !== false) {
                return $out;
            }
        }
        if (function_exists('mb_convert_encoding')) {
            return (string) mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
        }
        return preg_replace('~[^\x20-\x7E]~', '', $s) ?? '';
    }

    /** @return array<int,int> character code => width per 1000 em */
    private static function widths(string $font): array
    {
        static $cache = array();
        if (isset($cache[$font])) {
            return $cache[$font];
        }

        if ($font === 'F4' || $font === 'F5') {
            $w = array_fill(0, 256, 600); // Courier is monospaced
            return $cache[$font] = $w;
        }

        // Adobe metrics for Helvetica / Helvetica-Bold, codes 32..126.
        $regular = array(
            278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
            556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
            1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
            667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
            333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
            556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584,
        );
        $bold = array(
            278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278,
            556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611,
            975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778,
            667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556,
            333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611,
            611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584,
        );

        $table = ($font === 'F2') ? $bold : $regular;
        $w = array_fill(0, 256, 556);
        foreach ($table as $i => $width) {
            $w[32 + $i] = $width;
        }
        // Accented Latin-1 letters are close enough to their unaccented widths.
        for ($c = 192; $c <= 255; $c++) {
            $w[$c] = $w[($c >= 224) ? 97 : 65];
        }
        // CP1252 punctuation that actually turns up in this document.
        $w[0xA0] = $w[32];   // nbsp
        $w[0x91] = $w[0x92] = $w[39];   // curly single quotes
        $w[0x93] = $w[0x94] = $w[34];   // curly double quotes
        $w[0x97] = 1000;     // em dash
        $w[0x96] = 556;      // en dash
        $w[0x85] = 1000;     // ellipsis
        $w[0xB7] = 278;      // middot, used as a separator throughout
        $w[0xA9] = $w[0xAE] = 737;      // (c) and (r)
        $w[0xB0] = 400;      // degree
        $w[0xAB] = $w[0xBB] = 556;      // guillemets
        $w[0x80] = $w[0xA3] = $w[0xA5] = 556; // currency

        return $cache[$font] = $w;
    }
}
