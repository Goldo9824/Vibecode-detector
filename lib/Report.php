<?php
declare(strict_types=1);

require_once __DIR__ . '/Catalog.php';
require_once __DIR__ . '/Evidence.php';

/**
 * A finding, the excerpts behind it, and how often it fired.
 */
final class Signal
{
    /**
     * How far repetition can push a signal's weight.
     *
     * A tell found once is a coincidence candidate; the same tell found thirty
     * times is a habit, and habits are what this tool actually reads. So the
     * count earns weight — but on a log scale and with a ceiling, because the
     * difference between one occurrence and ten is real and the difference
     * between forty and eighty is the length of the file.
     *
     *   1 occurrence  x1.00     8 occurrences  x1.46
     *   3 occurrences x1.24    10 or more      x1.50
     */
    const REPETITION_MAX  = 0.5;
    const REPETITION_RATE = 0.22;

    /** @var string */
    public $id;
    /** @var string */
    public $label;
    /** @var string */
    public $category;
    /** @var string */
    public $direction;
    /** @var float */
    public $weight;
    /** @var string */
    public $detail;
    /** @var Excerpt[] */
    public $evidence;
    /** @var int total occurrences found, which can exceed the excerpts kept */
    public $occurrences;

    /**
     * @param Excerpt[] $evidence
     */
    public function __construct(string $id, array $meta, array $evidence, int $occurrences = 0)
    {
        $this->id        = $id;
        $this->label     = $meta['label'];
        $this->category  = $meta['category'];
        $this->direction = $meta['direction'];
        $this->weight    = (float) $meta['weight'];
        $this->detail    = $meta['detail'];
        $this->evidence  = $evidence;

        if ($occurrences <= 0) {
            $occurrences = 0;
            foreach ($evidence as $item) {
                $occurrences += $item->count;
            }
        }
        $this->occurrences = max(1, $occurrences);
    }

    public function strength(): string
    {
        return Catalog::strengthOf($this->weight);
    }

    /**
     * The multiplier repetition earns this signal.
     *
     * Fingerprints are exempt. A builder naming itself in the page is a positive
     * identification, and finding its badge three times identifies it exactly as
     * hard as finding it once.
     */
    public function repetitionFactor(): float
    {
        if ($this->category === Catalog::CAT_FINGERPRINT || $this->occurrences <= 1) {
            return 1.0;
        }
        return 1.0 + min(self::REPETITION_MAX, self::REPETITION_RATE * log((float) $this->occurrences));
    }

    /** Weight as scored: the catalogue weight, with repetition counted in. */
    public function effectiveWeight(): float
    {
        return $this->weight * $this->repetitionFactor();
    }

    /** The excerpt texts alone, which is what the evidence field used to be. */
    public function evidenceText(): array
    {
        $out = array();
        foreach ($this->evidence as $item) {
            $out[] = $item->text;
        }
        return $out;
    }

    public function toArray(): array
    {
        $items = array();
        foreach ($this->evidence as $item) {
            $items[] = $item->toArray();
        }

        return array(
            'id'            => $this->id,
            'label'         => $this->label,
            'category'      => $this->category,
            'categoryLabel' => Catalog::categories()[$this->category],
            'direction'     => $this->direction,
            'strength'      => $this->strength(),
            'weight'        => round($this->weight, 2),
            'detail'        => $this->detail,
            // Kept as a flat list of strings for anything that consumed it
            // before excerpts carried their surroundings.
            'evidence'      => $this->evidenceText(),
            'excerpts'      => $items,
            'occurrences'   => $this->occurrences,
            'repetition'    => round($this->repetitionFactor(), 2),
            'scoredWeight'  => round($this->effectiveWeight(), 2),
        );
    }
}

/**
 * Collects signals and turns them into a percentage.
 *
 * The number is deliberately not a probability of anything measurable. It is a
 * weighted reading of converging evidence, presented as a percentage because
 * that is what people asked for, and bounded away from 0 and 100 because no
 * amount of pattern-matching earns certainty about authorship.
 */
final class Report
{
    /** Base rate assumption before any evidence: assume not AI-generated. */
    const PRIOR_LOGIT = -1.0;

    /**
     * Per-category ceilings on accumulated evidence, in log-odds.
     *
     * Signals within a category are not independent — a file that swallows its
     * exceptions usually also over-wraps them, and counting both at full weight
     * double-counts one underlying habit. Without ceilings, a subject that
     * trips eight weak style tells outscores one carrying a hard fingerprint,
     * which inverts the whole hierarchy of evidence.
     *
     * Fingerprints are deliberately absent: they are positive identifications,
     * not accumulated inference, and nothing should hold them back.
     */
    const CATEGORY_CAPS = array(
        // History is capped highest of the inferential categories: it is the
        // strongest evidence short of a fingerprint and the hardest to fake.
        Catalog::CAT_HISTORY    => 3.2,
        // What is in the tree, as opposed to how it got there. Capped below
        // history because a file's presence is one fact each, where a commit
        // pattern is a shape drawn from hundreds of them.
        Catalog::CAT_REPOSITORY => 2.4,
        // Site-wide evidence needs several pages to exist at all, so when it
        // does fire it is already corroborated across them.
        Catalog::CAT_SITEWIDE   => 2.2,
        Catalog::CAT_STRUCTURE  => 2.6,
        Catalog::CAT_CODE       => 2.8,
        Catalog::CAT_CONTENT    => 1.4,
        Catalog::CAT_SECURITY   => 1.6,
        Catalog::CAT_AESTHETIC  => self::AESTHETIC_CAP,
        Catalog::CAT_PROVENANCE => 3.0,
    );

    /** Aesthetics alone must never carry an accusation. */
    const AESTHETIC_CAP = 1.0;

    /**
     * Where inference stops.
     *
     * A fingerprint is a positive identification and can reach the top of the
     * scale. Everything else is a reading of converging habits, and a reading
     * — however many families of evidence agree — has not identified anybody.
     * The per-category ceilings already stop one category running away; this
     * stops several near their ceilings from adding up to the same number a
     * builder naming itself would produce, which would collapse the tier the
     * rest of the tool is built around.
     */
    const INFERENCE_CEIL = 92;

    /** Score ceiling/floor. Certainty is not on the menu. */
    const FLOOR = 3;
    const CEIL  = 97;

    /** @var Signal[] */
    private $signals = array();
    /** @var string[] */
    private $notes = array();
    /** @var array<string,mixed> */
    private $stats = array();
    /** @var string */
    private $mode;
    /** @var string */
    private $target;
    /** @var string */
    private $subtitle = '';

    public function __construct(string $mode, string $target)
    {
        $this->mode   = $mode;
        $this->target = $target;
    }

    /** Excerpts kept per signal. The rest are counted, not shown. */
    const EVIDENCE_SHOWN = 4;

    /**
     * Record a signal.
     *
     * Evidence is what the reader checks instead of trusting the number, so it
     * is kept as excerpts rather than sentences: each one carries the lines
     * around the match and how many times that pattern fired.
     *
     * Callers may pass plain strings — a measurement or a list of names has no
     * line to point at — and those become contextless excerpts. Everything the
     * caller passes is counted even though only the first few are shown, so a
     * habit that fired forty times scores as forty, not as the four displayed.
     *
     * @param array<int,string|Excerpt> $evidence
     * @param int $occurrences override when the caller counted hits the
     *                         evidence list does not enumerate
     */
    public function flag(string $id, array $evidence = array(), int $occurrences = 0): void
    {
        $meta = Catalog::get($id);
        if ($meta === null) {
            return; // an unknown id is a bug, not a finding
        }
        if (isset($this->signals[$id])) {
            return; // each signal fires at most once
        }

        $kept  = array();
        $seen  = array();
        $found = 0;

        foreach ($evidence as $item) {
            $ex = ($item instanceof Excerpt) ? $item : Excerpt::plain((string) $item);
            if ($ex->isEmpty()) {
                continue;
            }
            $found += $ex->count;

            $key = $ex->key();
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if (count($kept) < self::EVIDENCE_SHOWN) {
                $kept[] = $ex;
            }
        }

        $this->signals[$id] = new Signal($id, $meta, $kept, $occurrences > 0 ? $occurrences : $found);
    }

    public function note(string $text): void
    {
        if (!in_array($text, $this->notes, true)) {
            $this->notes[] = $text;
        }
    }

    public function stat(string $key, $value): void
    {
        $this->stats[$key] = $value;
    }

    /** A stat already recorded, or null. Checks that build on earlier ones read it. */
    public function statValue(string $key)
    {
        return isset($this->stats[$key]) ? $this->stats[$key] : null;
    }

    public function setSubtitle(string $s): void
    {
        $this->subtitle = $s;
    }

    public function has(string $id): bool
    {
        return isset($this->signals[$id]);
    }

    /** @return Signal[] */
    public function signals(): array
    {
        return array_values($this->signals);
    }

    public function countAi(bool $excludeAesthetic = false): int
    {
        $n = 0;
        foreach ($this->signals as $s) {
            if ($s->direction !== 'ai') continue;
            if ($excludeAesthetic && $s->category === Catalog::CAT_AESTHETIC) continue;
            $n++;
        }
        return $n;
    }

    public function countHuman(): int
    {
        $n = 0;
        foreach ($this->signals as $s) {
            if ($s->direction === 'human') $n++;
        }
        return $n;
    }

    /** Every hit behind every signal, which is what the header line counts. */
    public function countOccurrences(): int
    {
        $n = 0;
        foreach ($this->signals as $s) {
            $n += $s->occurrences;
        }
        return $n;
    }

    public function hasFingerprint(): bool
    {
        foreach ($this->signals as $s) {
            if ($s->category === Catalog::CAT_FINGERPRINT) return true;
        }
        return false;
    }

    public function score(): int
    {
        $logit = self::PRIOR_LOGIT;

        // Accumulate per category and direction, then apply that category's
        // ceiling, so related signals reinforce each other without compounding.
        // Keying on direction as well means a human-pointing signal added to a
        // non-provenance category later still nets off correctly.
        $groups = array();
        foreach ($this->signals as $s) {
            $key = $s->category . '|' . $s->direction;
            if (!isset($groups[$key])) {
                $groups[$key] = array('category' => $s->category, 'direction' => $s->direction, 'total' => 0.0);
            }
            // Repetition counts here, not at the catalogue weight: how often a
            // tell fired is a property of this subject, not of the tell.
            $groups[$key]['total'] += $s->effectiveWeight();
        }

        foreach ($groups as $group) {
            $total = $group['total'];
            if (isset(self::CATEGORY_CAPS[$group['category']])) {
                $total = min($total, self::CATEGORY_CAPS[$group['category']]);
            }
            $logit += ($group['direction'] === 'ai') ? $total : -$total;
        }

        $score = (int) round(100.0 / (1.0 + exp(-$logit)));

        // Aesthetics-only readings get held below the line on purpose. A purple
        // gradient and an icon set are a reason to look closer, never a finding.
        if ($this->countAi(true) === 0 && !$this->hasFingerprint() && $score > 55) {
            $score = 55;
        }

        // Thin subjects cannot support a strong reading in either direction.
        if (!empty($this->stats['thin']) && !$this->hasFingerprint()) {
            $score = (int) round(50 + ($score - 50) * 0.55);
        }

        // Converging inference is not identification.
        if (!$this->hasFingerprint() && $score > self::INFERENCE_CEIL) {
            $score = self::INFERENCE_CEIL;
        }

        return max(self::FLOOR, min(self::CEIL, $score));
    }

    /** @return array<string,string> */
    public function verdict(): array
    {
        $score = $this->score();
        $converging = $this->countAi(true);

        if ($this->hasFingerprint()) {
            return array(
                'code'    => 'builder_identified',
                'label'   => 'Built by an AI site builder',
                'summary' => 'The page names its own generator. This is a positive identification, not a statistical guess.',
            );
        }
        if ($score >= 85 && $converging >= 4) {
            return array(
                'code'    => 'very_likely_ai',
                'label'   => 'Very likely AI-generated',
                'summary' => 'Several independent families of evidence agree. That convergence, not any single tell, is what carries the reading.',
            );
        }
        if ($score >= 72) {
            return array(
                'code'    => 'likely_ai',
                'label'   => 'Likely AI-generated',
                'summary' => 'The evidence leans clearly one way, but it is thinner than a confident call needs. Read the signals below before repeating this anywhere.',
            );
        }
        if ($score >= 58) {
            return array(
                'code'    => 'leaning_ai',
                'label'   => 'Possibly AI-assisted',
                'summary' => 'Some generated-looking patterns, not enough of them to distinguish a generator from a developer with good tooling.',
            );
        }
        if ($score >= 42) {
            return array(
                'code'    => 'inconclusive',
                'label'   => 'Inconclusive',
                'summary' => 'Evidence points both ways or there is too little of it. This is a real answer, and the correct one more often than people would like.',
            );
        }
        if ($score >= 25) {
            return array(
                'code'    => 'leaning_human',
                'label'   => 'Probably hand-written',
                'summary' => 'The subject carries marks of incremental human work, though reviewed and edited AI code looks like this too.',
            );
        }
        return array(
            'code'    => 'likely_human',
            'label'   => 'Likely hand-written',
            'summary' => 'Context, inconsistency and history that a generator does not produce, and that would take deliberate effort to fake.',
        );
    }

    /** @return array<string,string> */
    public function confidence(): array
    {
        if ($this->hasFingerprint()) {
            return array('level' => 'high', 'label' => 'High',
                'reason' => 'A hard platform fingerprint identifies the tool outright.');
        }
        if (!empty($this->stats['thin'])) {
            return array('level' => 'insufficient', 'label' => 'Insufficient',
                'reason' => 'There was not enough material to read. Longer input gives a far better answer; short samples are where detectors fail worst.');
        }

        $converging = $this->countAi(true);
        $human = $this->countHuman();

        if ($converging >= 4 || $human >= 4) {
            return array('level' => 'moderate', 'label' => 'Moderate',
                'reason' => 'Four or more independent signals agree. This is about as far as pattern-reading can take you without the repository history.');
        }
        if ($converging >= 2 || $human >= 2) {
            return array('level' => 'low', 'label' => 'Low',
                'reason' => 'Two or three signals is a hunch, not a diagnosis. Treat this as a prompt to look at the code yourself.');
        }
        return array('level' => 'insufficient', 'label' => 'Insufficient',
            'reason' => 'Almost nothing to go on either way.');
    }

    public function toArray(): array
    {
        $signals = array();
        foreach ($this->signals as $s) {
            $signals[] = $s->toArray();
        }
        // Decisive first, then by weight, so the reasoning reads top-down.
        //
        // The id is a tiebreaker rather than decoration: most weights are shared
        // by several signals, and sorts are only stable from PHP 8.0 onwards. On
        // 7.4 an unbroken tie orders arbitrarily, which would make the evidence
        // list — and the signal ids baked into a certificate — differ between
        // hosts running the same code on the same input.
        usort($signals, function ($a, $b) {
            // Ranked on the weight that was scored, so a tell that fired forty
            // times reads above one that fired once at the same catalogue weight.
            if ($a['scoredWeight'] === $b['scoredWeight']) {
                if ($a['weight'] === $b['weight']) {
                    return strcmp($a['id'], $b['id']);
                }
                return $b['weight'] <=> $a['weight'];
            }
            return $b['scoredWeight'] <=> $a['scoredWeight'];
        });

        return array(
            'ok'         => true,
            'mode'       => $this->mode,
            'target'     => $this->target,
            'subtitle'   => $this->subtitle,
            'score'      => $this->score(),
            'verdict'    => $this->verdict(),
            'confidence' => $this->confidence(),
            'signals'    => $signals,
            'counts'     => array(
                'ai'          => $this->countAi(),
                'converging'  => $this->countAi(true),
                'human'       => $this->countHuman(),
                'fingerprint' => $this->hasFingerprint() ? 1 : 0,
                'occurrences' => $this->countOccurrences(),
            ),
            'stats'      => $this->stats,
            'notes'      => $this->notes,
            'analyzedAt' => gmdate('c'),
            'version'    => VCD_VERSION,
        );
    }

    /** Trim an evidence excerpt down to something displayable and safe. */
    public static function excerpt(string $s, int $max = 140): string
    {
        $s = preg_replace('/\s+/u', ' ', $s);
        $s = trim((string) $s);
        if ($s === '') {
            return '';
        }
        if (function_exists('mb_strlen') && mb_strlen($s, 'UTF-8') > $max) {
            return mb_substr($s, 0, $max - 1, 'UTF-8') . '…';
        }
        if (strlen($s) > $max) {
            return substr($s, 0, $max - 1) . '…';
        }
        return $s;
    }
}
