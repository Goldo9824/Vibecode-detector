<?php
declare(strict_types=1);

require_once __DIR__ . '/Pdf.php';
require_once __DIR__ . '/Brand.php';
require_once __DIR__ . '/Catalog.php';

/**
 * Lays out the PDF certificate.
 *
 * It is built from the signed token rather than from anything the browser
 * posts, so a certificate always says what the analysis said. The signal list
 * is rebuilt from catalogue ids for the same reason: the payload carries
 * identifiers, never free text that could be swapped for something else.
 */
final class Certificate
{
    const MARGIN = 54.0;

    /** Height of the fixed caveat panel above the footer. */
    const CAVEAT_H = 84.0;

    /** Clearance the evidence list keeps above that panel. */
    const CAVEAT_GAP = 24.0;

    /** @var Pdf */
    private $pdf;
    /** @var array<string,mixed> */
    private $c;
    /** @var float */
    private $x0;
    /** @var float */
    private $x1;
    /** @var float */
    private $colW;

    /** @param array<string,mixed> $cert decoded, verified certificate payload */
    public function __construct(array $cert)
    {
        $this->c = $cert;
        $this->pdf = new Pdf();
        $this->x0 = self::MARGIN;
        $this->x1 = $this->pdf->width() - self::MARGIN;
        $this->colW = $this->x1 - $this->x0;
    }

    public function render(): string
    {
        $score   = (int) $this->c['s'];
        $verdict = $this->verdictLabel((string) $this->c['c']);
        $color   = Brand::verdictColor((string) $this->c['c']);

        $this->pdf->setTitle('Vibe Code Detector — Certificate of Analysis');
        $this->pdf->addPage();

        // Paper tint, so a printed certificate does not read as a bare memo.
        $this->pdf->rect(0, 0, $this->pdf->width(), $this->pdf->height(), Brand::PAPER);

        $y = $this->header();
        $y = $this->subject($y);
        $y = $this->verdictBlock($y, $score, $verdict, $color);
        $y = $this->meter($y, $score, $color);
        $y = $this->findings($y);
        $this->caveat();
        $this->footer();

        return $this->pdf->output();
    }

    // ------------------------------------------------------------------ blocks

    private function header(): float
    {
        $y = self::MARGIN;

        Brand::drawMark($this->pdf, $this->x0, $y - 4, 42);

        $this->pdf->text($this->x0 + 54, $y + 16, 'VIBE CODE DETECTOR', 'F2', 12.5, Brand::INK, 1.6);
        $this->pdf->text($this->x0 + 54, $y + 30, 'Authorship signal analysis', 'F1', 8.8, Brand::GREY);

        $this->pdf->textRight($this->x1, $y + 12, 'CERTIFICATE', 'F2', 9.5, Brand::RED, 2.2);
        $this->pdf->textRight($this->x1, $y + 24, 'OF ANALYSIS', 'F2', 9.5, Brand::RED, 2.2);
        $this->pdf->textRight($this->x1, $y + 37, $this->formatDate(), 'F4', 8, Brand::GREY);

        $y += 54;
        $this->pdf->line($this->x0, $y, $this->x1, $y, Brand::INK, 1.6);
        return $y + 26;
    }

    private function subject(float $y): float
    {
        $this->pdf->text($this->x0, $y, 'SUBJECT', 'F2', 8, Brand::GREY, 1.4);
        $y += 16;

        $isUrl = ($this->c['m'] === 'url');
        $target = (string) $this->c['t'];

        // A long URL is cut rather than wrapped: a broken line in a monospaced
        // face reads as two different addresses.
        $lines = $this->pdf->wrap($target, $this->colW - 8, 'F4', 11);
        $shown = array_slice($lines, 0, 2);
        if (count($lines) > 2) {
            $shown[1] = rtrim($shown[1]) . '…';
        }
        foreach ($shown as $line) {
            $this->pdf->text($this->x0, $y, $line, 'F4', 11, Brand::INK);
            $y += 15;
        }

        $kind = $isUrl ? 'Live page' : 'Pasted source';
        $extra = trim((string) ($this->c['n'] ?? ''));
        $this->pdf->text($this->x0, $y + 2, $kind . ($extra !== '' ? '  ·  ' . $extra : ''), 'F1', 9, Brand::GREY);

        return $y + 26;
    }

    private function verdictBlock(float $y, int $score, string $verdict, array $color): float
    {
        $boxH = 96.0;
        $this->pdf->rect($this->x0, $y, $this->colW, $boxH, array(255, 255, 255), Brand::RULE, 0.8);
        // Accent edge, on the left, which is admittedly one of the tells. It is
        // 4pt of irony and it stays.
        $this->pdf->rect($this->x0, $y, 4, $boxH, $color);

        $numX = $this->x0 + 26;
        $this->pdf->text($numX, $y + 68, (string) $score, 'F2', 58, $color);
        $numW = $this->pdf->textWidth((string) $score, 'F2', 58);
        $this->pdf->text($numX + $numW + 4, $y + 68, '%', 'F2', 24, $color);

        $tx = $numX + $numW + 44;
        $tw = $this->x1 - $tx - 24;

        $this->pdf->text($tx, $y + 34, 'VERDICT', 'F2', 8, Brand::GREY, 1.4);
        $vLines = $this->pdf->wrap($verdict, $tw, 'F2', 15);
        $vy = $y + 54;
        foreach (array_slice($vLines, 0, 2) as $line) {
            $this->pdf->text($tx, $vy, $line, 'F2', 15, Brand::INK);
            $vy += 18;
        }

        $conf = $this->confidenceLabel((string) ($this->c['f'] ?? 'low'));
        $this->pdf->text($tx, min($vy + 4, $y + $boxH - 14), 'Confidence: ' . $conf, 'F1', 9.5, Brand::GREY);

        return $y + $boxH + 24;
    }

    private function meter(float $y, int $score, array $color): float
    {
        $h = 9.0;
        $this->pdf->rect($this->x0, $y, $this->colW, $h, Brand::FAINT);

        $filled = $this->colW * ($score / 100);
        $this->pdf->rect($this->x0, $y, $filled, $h, $color);

        // Quartile ticks, so the number has somewhere to sit.
        for ($i = 1; $i < 4; $i++) {
            $tx = $this->x0 + $this->colW * ($i / 4);
            $this->pdf->line($tx, $y, $tx, $y + $h, Brand::PAPER, 1.0);
        }

        $y += $h + 12;
        $this->pdf->text($this->x0, $y, 'hand-written', 'F1', 7.5, Brand::GREY);
        $this->pdf->textCenter($this->x0 + $this->colW / 2, $y, 'inconclusive', 'F1', 7.5, Brand::GREY);
        $this->pdf->textRight($this->x1, $y, 'AI-generated', 'F1', 7.5, Brand::GREY);

        return $y + 26;
    }

    private function findings(float $y): float
    {
        $ids = isset($this->c['g']) && is_array($this->c['g']) ? $this->c['g'] : array();

        $this->pdf->text($this->x0, $y, 'EVIDENCE ON THE RECORD', 'F2', 8, Brand::GREY, 1.4);
        $y += 8;
        $this->pdf->line($this->x0, $y, $this->x1, $y, Brand::RULE, 0.8);
        $y += 18;

        if (!$ids) {
            $this->pdf->text($this->x0, $y, 'No signals of any kind were found in either direction.', 'F3', 10, Brand::GREY);
            return $y + 22;
        }

        $cats = Catalog::categories();
        $shown = 0;

        foreach ($ids as $id) {
            $meta = Catalog::get((string) $id);
            if ($meta === null) {
                continue;
            }
            if ($y > $this->caveatTop() - self::CAVEAT_GAP) {
                $this->pdf->text($this->x0, $y, sprintf('… and %d further signal(s), listed in the full report online.', count($ids) - $shown), 'F3', 9, Brand::GREY);
                $y += 18;
                break;
            }

            $isHuman = ($meta['direction'] === 'human');
            $dot = $isHuman ? Brand::GREEN : ($meta['category'] === Catalog::CAT_FINGERPRINT ? Brand::RED : Brand::INK);

            $this->pdf->circle($this->x0 + 3, $y - 3, 2.4, $dot);

            $label = (string) $meta['label'];
            $lines = $this->pdf->wrap($label, $this->colW - 150, 'F1', 10);
            $this->pdf->text($this->x0 + 14, $y, $lines[0] . (count($lines) > 1 ? ' …' : ''), 'F1', 10, Brand::INK);

            $tag = strtoupper($cats[$meta['category']]);
            $this->pdf->textRight($this->x1, $y, $tag, 'F1', 7.5, Brand::GREY);

            $strength = Catalog::strengthOf((float) $meta['weight']);
            $this->pdf->textRight($this->x1 - 118, $y, $strength, 'F3', 8, $isHuman ? Brand::GREEN : Brand::RED);

            $y += 17;
            $shown++;
        }

        return $y + 8;
    }

    /** Top edge of the caveat panel, which the evidence list must stay clear of. */
    private function caveatTop(): float
    {
        return $this->pdf->height() - self::MARGIN - 40 - self::CAVEAT_H;
    }

    private function caveat(): void
    {
        $y = $this->caveatTop();

        $this->pdf->rect($this->x0, $y, $this->colW, self::CAVEAT_H, array(255, 255, 255), Brand::RULE, 0.8);

        $tx = $this->x0 + 14;
        $tw = $this->colW - 28;
        $ty = $y + 20;

        $this->pdf->text($tx, $ty, 'WHAT THIS CERTIFICATE IS NOT', 'F2', 8, Brand::RED, 1.4);
        $ty += 15;

        $body = 'It is not proof of authorship. Peer-reviewed benchmarks put automated detection of '
              . 'AI-generated source near chance, and every stylistic tell weakens as models improve and as '
              . 'human developers adopt the same tools. Absence of signals proves nothing: agentic editors '
              . 'leave no fingerprint at all. Do not use this document to accuse anyone of anything.';

        // The caveat is about the limits of the method, not about this tool's
        // own provenance. That belongs on the site, where there is room to
        // explain it, rather than on a document someone is handing to a third
        // party as a finding.
        $this->pdf->paragraph($tx, $ty, $tw, $body, 'F1', 8.4, 11.2, Brand::GREY);
    }

    private function footer(): void
    {
        // Three rows now, so the block starts higher to keep the last line
        // inside the bottom margin.
        $y = $this->pdf->height() - self::MARGIN - 26;
        $this->pdf->line($this->x0, $y - 14, $this->x1, $y - 14, Brand::RULE, 0.8);

        $id = (string) ($this->c['id'] ?? '');
        $this->pdf->text($this->x0, $y, 'Certificate ' . $id, 'F4', 8, Brand::INK);
        $this->pdf->text($this->x0, $y + 11, 'Verify at ' . vcd_site_url() . '/verify', 'F1', 7.5, Brand::GREY);

        $this->pdf->textRight($this->x1, $y, preg_replace('~^https?://~', '', vcd_site_url()), 'F1', 8, Brand::INK);
        $this->pdf->textRight($this->x1, $y + 11, 'Open source · github.com/goldo9824/vibecode-detector', 'F1', 7.5, Brand::GREY);

        $this->pdf->textCenter($this->x0 + $this->colW / 2, $y + 24, 'A Landfall studio product', 'F3', 8, Brand::GREY);
    }

    // ----------------------------------------------------------------- helpers

    private function formatDate(): string
    {
        $ts = strtotime((string) ($this->c['d'] ?? 'now'));
        return gmdate('j F Y, H:i', $ts ?: time()) . ' UTC';
    }

    private function verdictLabel(string $code): string
    {
        $map = array(
            'builder_identified' => 'Built by an AI site builder',
            'very_likely_ai'     => 'Very likely AI-generated',
            'likely_ai'          => 'Likely AI-generated',
            'leaning_ai'         => 'Possibly AI-assisted',
            'inconclusive'       => 'Inconclusive',
            'leaning_human'      => 'Probably hand-written',
            'likely_human'       => 'Likely hand-written',
        );
        return isset($map[$code]) ? $map[$code] : 'Inconclusive';
    }

    private function confidenceLabel(string $level): string
    {
        $map = array(
            'high' => 'High', 'moderate' => 'Moderate',
            'low' => 'Low', 'insufficient' => 'Insufficient',
        );
        return isset($map[$level]) ? $map[$level] : 'Low';
    }
}
