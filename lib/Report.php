<?php
declare(strict_types=1);

require_once __DIR__ . '/Catalog.php';

/**
 * A single piece of evidence found in the subject.
 */
final class Signal
{
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
    /** @var string[] */
    public $evidence;

    public function __construct(string $id, array $meta, array $evidence)
    {
        $this->id        = $id;
        $this->label     = $meta['label'];
        $this->category  = $meta['category'];
        $this->direction = $meta['direction'];
        $this->weight    = (float) $meta['weight'];
        $this->detail    = $meta['detail'];
        $this->evidence  = $evidence;
    }

    public function strength(): string
    {
        return Catalog::strengthOf($this->weight);
    }

    public function toArray(): array
    {
        return array(
            'id'            => $this->id,
            'label'         => $this->label,
            'category'      => $this->category,
            'categoryLabel' => Catalog::categories()[$this->category],
            'direction'     => $this->direction,
            'strength'      => $this->strength(),
            'weight'        => round($this->weight, 2),
            'detail'        => $this->detail,
            'evidence'      => array_values($this->evidence),
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

    /** Aesthetics alone must never carry an accusation. */
    const AESTHETIC_CAP = 1.0;

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

    /**
     * Record a signal. Evidence lines are short excerpts shown to the user so
     * they can check the reasoning rather than trust the number.
     *
     * @param string[] $evidence
     */
    public function flag(string $id, array $evidence = array()): void
    {
        $meta = Catalog::get($id);
        if ($meta === null) {
            return; // an unknown id is a bug, not a finding
        }
        if (isset($this->signals[$id])) {
            return; // each signal fires at most once
        }
        $clean = array();
        foreach ($evidence as $line) {
            $line = Report::excerpt((string) $line);
            if ($line !== '' && !in_array($line, $clean, true)) {
                $clean[] = $line;
            }
            if (count($clean) >= 4) {
                break;
            }
        }
        $this->signals[$id] = new Signal($id, $meta, $clean);
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
        $aesthetic = 0.0;

        foreach ($this->signals as $s) {
            if ($s->category === Catalog::CAT_AESTHETIC) {
                $aesthetic += $s->weight;
                continue;
            }
            $logit += ($s->direction === 'ai') ? $s->weight : -$s->weight;
        }

        $logit += min($aesthetic, self::AESTHETIC_CAP);

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
        usort($signals, function ($a, $b) {
            return $b['weight'] <=> $a['weight'];
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
