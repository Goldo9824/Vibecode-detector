<?php
declare(strict_types=1);

require_once __DIR__ . '/Pdf.php';

/**
 * The mark, and the palette shared by the site and the certificate.
 *
 * The logo is a signal trace inside a ring. The left half is jagged and
 * irregular; the right half snaps into a perfectly even wave. That is the
 * whole thesis of the tool in one drawing: humans vary in small ways and hold
 * a shape overall, generators do the reverse.
 *
 * It is defined here as geometry rather than as an image file so the same
 * mark can be stroked into a PDF and written out as SVG without either copy
 * drifting from the other.
 */
final class Brand
{
    const INK    = array(23, 20, 15);
    const PAPER  = array(251, 249, 244);
    /*
     * Two palettes, because there are two grounds.
     *
     * INK/PAPER/RED are the printed certificate's: dark ink on light stock,
     * which is what a document handed to a third party has to be. The *_WEB
     * pair is the site's, which is dark. Artwork that sits next to the site —
     * the favicon, the social card — has to use the second set or it renders
     * dark on dark and reads as a different site's mark.
     */
    const PAPER_WEB = array(11, 11, 12);
    const INK_WEB   = array(244, 244, 245);
    const RED_WEB   = array(255, 77, 46);
    const GREY_WEB  = array(117, 116, 125);
    const RULE_WEB  = array(38, 38, 43);
    const RED    = array(184, 64, 46);
    const GREEN  = array(63, 107, 74);
    const AMBER  = array(176, 125, 26);
    const GREY   = array(107, 100, 89);
    const RULE   = array(216, 210, 196);
    const FAINT  = array(240, 236, 227);

    /** Ring centre and radius in the 100x100 design box. */
    const CX = 50.0;
    const CY = 50.0;
    const R  = 41.0;

    /**
     * The irregular half: hand-picked, deliberately unrhythmic.
     *
     * @return array<int,array{0:float,1:float}>
     */
    public static function jaggedPath(): array
    {
        return array(
            array(14, 50), array(19, 40), array(23, 57), array(28, 33),
            array(32, 54), array(36, 44), array(41, 61), array(45, 47),
            array(48, 50),
        );
    }

    /**
     * The regular half: two clean periods, amplitude 14, from x=48 to x=86.
     *
     * @return array<int,array{0:float,1:float}>
     */
    public static function wavePath(int $steps = 48): array
    {
        $pts = array();
        for ($i = 0; $i <= $steps; $i++) {
            $t = $i / $steps;
            $pts[] = array(48.0 + $t * 38.0, 50.0 - 14.0 * sin($t * 4 * M_PI));
        }
        return $pts;
    }

    /**
     * Stroke the mark into a PDF inside a square box at ($x,$y).
     *
     * @param array{0:int,1:int,2:int}|null $ink
     * @param array{0:int,1:int,2:int}|null $accent
     */
    public static function drawMark(Pdf $pdf, float $x, float $y, float $size, ?array $ink = null, ?array $accent = null): void
    {
        $ink    = $ink    ?? self::INK;
        $accent = $accent ?? self::RED;
        $s = $size / 100.0;

        $map = function (array $p) use ($x, $y, $s): array {
            return array($x + $p[0] * $s, $y + $p[1] * $s);
        };

        $pdf->circle($x + self::CX * $s, $y + self::CY * $s, self::R * $s, null, $ink, 6.0 * $s);
        $pdf->polyline(array_map($map, self::jaggedPath()), $ink, 5.5 * $s);
        $pdf->polyline(array_map($map, self::wavePath()), $accent, 5.5 * $s);
    }

    /** The mark as standalone SVG markup. */
    public static function markSvg(int $px = 100, string $ink = 'currentColor', string $accent = '#b8402e', string $extraAttrs = ''): string
    {
        $jag = self::pathData(self::jaggedPath());
        $wave = self::pathData(self::wavePath(40));

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="' . $px . '" height="' . $px . '"'
            . ' fill="none" stroke-linecap="round" stroke-linejoin="round"' . ($extraAttrs !== '' ? ' ' . $extraAttrs : '') . '>'
            . '<circle cx="50" cy="50" r="41" stroke="' . $ink . '" stroke-width="6"/>'
            . '<path d="' . $jag . '" stroke="' . $ink . '" stroke-width="5.5"/>'
            . '<path d="' . $wave . '" stroke="' . $accent . '" stroke-width="5.5"/>'
            . '</svg>';
    }

    /** @param array<int,array{0:float,1:float}> $points */
    private static function pathData(array $points): string
    {
        $d = '';
        foreach ($points as $i => $p) {
            $d .= ($i === 0 ? 'M' : 'L') . self::num($p[0]) . ' ' . self::num($p[1]) . ' ';
        }
        return rtrim($d);
    }

    private static function num(float $n): string
    {
        return rtrim(rtrim(sprintf('%.2F', $n), '0'), '.');
    }

    /** @return array{0:int,1:int,2:int} */
    public static function verdictColor(string $code): array
    {
        switch ($code) {
            case 'builder_identified':
            case 'very_likely_ai':
            case 'likely_ai':
                return self::RED;
            case 'leaning_ai':
                return self::AMBER;
            case 'leaning_human':
            case 'likely_human':
                return self::GREEN;
            default:
                return self::GREY;
        }
    }
}
