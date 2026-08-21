<?php
declare(strict_types=1);

require_once __DIR__ . '/Report.php';

/**
 * Reads a pasted git log.
 *
 * This is the signal the rest of the tool keeps admitting it cannot see. A
 * repository's history is the hardest thing to fake retroactively: faking it
 * convincingly means inventing plausible timestamps, authors, mistakes and
 * reverts for every commit, and the shape of real work — evenings, weekends,
 * abandonment, second thoughts — is not something you produce by accident.
 *
 * The parser is deliberately tolerant. People paste whatever their terminal
 * gave them, so all three common shapes are accepted and the analysis degrades
 * to whatever the paste actually contains rather than refusing it.
 */
final class GitAnalyzer
{
    /** Below this there is no history to read, only a handful of commits. */
    const THIN_COMMITS = 4;

    /** @var string */
    private $raw;
    /** @var array<int,array<string,mixed>> commits, oldest first */
    private $commits = array();
    /** @var Report */
    private $r;

    public function __construct(string $log)
    {
        $this->raw = str_replace(array("\r\n", "\r"), "\n", $log);
        $this->commits = $this->parse($this->raw);
    }

    /** @return array<int,array<string,mixed>> */
    public function commits(): array
    {
        return $this->commits;
    }

    // ------------------------------------------------------------------ parse

    /**
     * Handles three formats:
     *
     *   1. The documented one: `%H|%at|%an|%s` with optional --numstat blocks.
     *   2. Default `git log`: commit/Author/Date/indented subject.
     *   3. `git log --oneline`: hash and subject only, no timing.
     *
     * @return array<int,array<string,mixed>>
     */
    private function parse(string $log): array
    {
        $out = array();
        $current = null;

        $push = function () use (&$current, &$out) {
            if ($current !== null) {
                $out[] = $current;
                $current = null;
            }
        };

        $lines = explode("\n", $log);
        $pendingDefault = null;

        foreach ($lines as $line) {
            $trimmed = rtrim($line);

            // Format 1: hash|epoch|author|subject
            if (preg_match('~^\s*([0-9a-f]{6,40})\|(\d{6,})\|([^|]*)\|(.*)$~i', $trimmed, $m)) {
                $push();
                $current = self::blank($m[1], (int) $m[2], trim($m[3]), trim($m[4]));
                continue;
            }

            // --numstat rows: added \t removed \t path  (binary files use "-")
            if ($current !== null && preg_match('~^\s*(\d+|-)\t(\d+|-)\t(.+)$~', $trimmed, $m)) {
                $current['added']   += ($m[1] === '-') ? 0 : (int) $m[1];
                $current['removed'] += ($m[2] === '-') ? 0 : (int) $m[2];
                $current['files']++;
                continue;
            }

            // --shortstat rows: " 3 files changed, 120 insertions(+), 4 deletions(-)"
            if ($current !== null && preg_match('~(\d+) files? changed~', $trimmed, $m)) {
                $current['files'] = max($current['files'], (int) $m[1]);
                if (preg_match('~(\d+) insertions?\(\+\)~', $trimmed, $i)) {
                    $current['added'] += (int) $i[1];
                }
                if (preg_match('~(\d+) deletions?\(-\)~', $trimmed, $d)) {
                    $current['removed'] += (int) $d[1];
                }
                continue;
            }

            // Format 2: default git log
            if (preg_match('~^commit\s+([0-9a-f]{7,40})~i', $trimmed, $m)) {
                $push();
                $pendingDefault = self::blank($m[1], 0, '', '');
                $current = $pendingDefault;
                continue;
            }
            if ($current !== null && preg_match('~^Author:\s*(.+?)\s*<~', $trimmed, $m)) {
                $current['author'] = trim($m[1]);
                continue;
            }
            if ($current !== null && preg_match('~^Date:\s*(.+)$~', $trimmed, $m)) {
                $ts = strtotime(trim($m[1]));
                if ($ts !== false) {
                    $current['ts'] = $ts;
                }
                continue;
            }
            if ($current !== null && $current['subject'] === '' && preg_match('~^\s{4}(\S.*)$~', $trimmed, $m)) {
                $current['subject'] = trim($m[1]);
                continue;
            }

            // Format 3: oneline — hash then subject, no pipe, no "commit" prefix.
            if (preg_match('~^\s*([0-9a-f]{7,40})\s+(\S.*)$~i', $trimmed, $m)
                && !preg_match('~^(commit|Author|Date|Merge)\b~i', $trimmed)) {
                $push();
                $out[] = self::blank($m[1], 0, '', trim($m[2]));
                continue;
            }
        }
        $push();

        // Oldest first, so "the opening commit" means what it says. Entries
        // without timestamps keep their paste order, which for git log output
        // is newest-first, hence the reverse.
        $haveTs = false;
        foreach ($out as $c) {
            if ($c['ts'] > 0) {
                $haveTs = true;
                break;
            }
        }
        if ($haveTs) {
            usort($out, function ($a, $b) {
                if ($a['ts'] === $b['ts']) {
                    return strcmp($a['hash'], $b['hash']);
                }
                return $a['ts'] <=> $b['ts'];
            });
        } else {
            $out = array_reverse($out);
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private static function blank(string $hash, int $ts, string $author, string $subject): array
    {
        return array(
            'hash' => strtolower($hash), 'ts' => $ts, 'author' => $author,
            'subject' => $subject, 'added' => 0, 'removed' => 0, 'files' => 0,
        );
    }

    // ---------------------------------------------------------------- analyse

    public function analyze(): Report
    {
        $n = count($this->commits);

        $this->r = new Report('git', sprintf('Repository history (%d commit%s)', $n, $n === 1 ? '' : 's'));

        if ($n === 0) {
            $this->r->stat('thin', true);
            $this->r->stat('commits', 0);
            $this->r->note('No commits could be read from that. Check the paste — the command on the page produces the format this understands, and plain `git log` or `git log --oneline` also work.');
            return $this->r;
        }

        $withTs = $this->withTimestamps();
        $spanDays = $this->spanDays();
        $authors = $this->authors();
        $hasStats = $this->hasLineCounts();

        $this->r->setSubtitle($this->describe($n, $spanDays, count($authors)));
        $this->r->stat('commits', $n);
        $this->r->stat('authors', count($authors));
        $this->r->stat('spanDays', $spanDays);
        $this->r->stat('hasLineCounts', $hasStats);

        if ($n < self::THIN_COMMITS) {
            $this->r->stat('thin', true);
            $this->r->note('Four or five commits is not a history. Paste the whole log — this reads best over dozens of commits, where the cadence becomes visible.');
        }

        $this->checkBigBang($hasStats);
        $this->checkVelocity($withTs, $hasStats);
        $this->checkMicroFixTrail($hasStats);
        $this->checkMessages();
        $this->checkCadence($withTs, $spanDays);
        $this->checkCollaboration($authors);

        if ($hasStats && $n >= self::THIN_COMMITS) {
            $this->r->stat('trend', $this->buildTrend());
        }

        if (!$hasStats) {
            $this->r->note('The paste carried no line counts, so nothing about commit size could be checked. Adding --numstat to the command roughly doubles what this can see.');
        }
        if (!$withTs) {
            $this->r->note('The paste carried no timestamps, so cadence and velocity could not be checked — which is most of the value here. `git log --oneline` is the weakest input this accepts.');
        }

        $this->r->note('History is the strongest signal this tool has, but it reads the shape of the work, not its authorship. A developer who commits carefully while an agent writes the code produces a perfectly human-looking log.');

        return $this->r;
    }

    // ----------------------------------------------------------------- checks

    private function checkBigBang(bool $hasStats): void
    {
        if (!$hasStats || !$this->commits) {
            return;
        }
        $first = $this->commits[0];
        $total = 0;
        foreach ($this->commits as $c) {
            $total += $c['added'];
        }
        if ($total <= 0) {
            return;
        }

        $share = $first['added'] / $total;
        $looksInitial = (bool) preg_match('~^(initial|first)\b|^init\b|initial commit~i', $first['subject']);

        if ($first['added'] >= 400 && ($share >= 0.5 || $looksInitial)) {
            $this->r->flag('gh.big_bang', array(
                sprintf('the opening commit adds %s lines across %d file%s',
                    number_format($first['added']), $first['files'], $first['files'] === 1 ? '' : 's'),
                sprintf('that is %d%% of every line added in the whole history', (int) round($share * 100)),
                '"' . Report::excerpt($first['subject'], 70) . '"',
            ));
        }
    }

    private function checkVelocity(bool $withTs, bool $hasStats): void
    {
        if (!$withTs || !$hasStats || count($this->commits) < 3) {
            return;
        }

        $bursts = array();
        $n = count($this->commits);
        for ($i = 0; $i < $n; $i++) {
            $lines = 0;
            $commits = 0;
            for ($j = $i; $j < $n; $j++) {
                $gap = $this->commits[$j]['ts'] - $this->commits[$i]['ts'];
                if ($gap > 1200) { // 20 minutes
                    break;
                }
                $lines += $this->commits[$j]['added'];
                $commits++;
            }
            if ($lines >= 400 && $commits >= 2) {
                $mins = max(1, (int) round(($this->commits[min($j, $n - 1)]['ts'] - $this->commits[$i]['ts']) / 60));
                $bursts[] = sprintf('%s lines across %d commits in about %d minute%s',
                    number_format($lines), $commits, $mins, $mins === 1 ? '' : 's');
                $i = $j - 1; // do not report the same burst from every offset
            }
        }

        if ($bursts) {
            $this->r->flag('gh.velocity', array_slice($bursts, 0, 3));
        }
    }

    private function checkMicroFixTrail(bool $hasStats): void
    {
        $n = count($this->commits);
        if ($n < 4) {
            return;
        }

        $fixRe = '~^(fix|fixed|fixes|correct|update)\b.{0,40}\b(typo|import|imports|path|build|lint|error|missing|syntax|reference|name|case|indent|format)\b~i';
        $tinyRe = '~^(fix typo|typo|oops|fix|fix build|fix lint|small fix|minor fix|fix error|fix import)\.?$~i';

        for ($i = 0; $i < $n - 3; $i++) {
            $big = $this->commits[$i];
            if ($hasStats && $big['added'] < 200) {
                continue;
            }
            if (!$hasStats && !preg_match('~^(initial|add|implement|create|build)\b~i', $big['subject'])) {
                continue;
            }

            $run = array();
            for ($j = $i + 1; $j < $n; $j++) {
                $c = $this->commits[$j];
                $isTiny = $hasStats ? ($c['added'] <= 8) : true;
                if ($isTiny && (preg_match($fixRe, $c['subject']) || preg_match($tinyRe, $c['subject']))) {
                    $run[] = '"' . Report::excerpt($c['subject'], 60) . '"'
                           . ($hasStats ? sprintf(' (+%d)', $c['added']) : '');
                } else {
                    break;
                }
            }

            if (count($run) >= 3) {
                array_unshift($run, sprintf('after a commit of +%s lines:', number_format($big['added'])));
                $this->r->flag('gh.micro_fix_trail', $run);
                return;
            }
        }
    }

    private function checkMessages(): void
    {
        $subjects = array();
        foreach ($this->commits as $c) {
            if ($c['subject'] !== '') {
                $subjects[] = $c['subject'];
            }
        }
        if (!$subjects) {
            return;
        }
        $total = count($subjects);

        // Messages that restate a specification rather than a change.
        $prompt = array();
        foreach ($subjects as $s) {
            $words = str_word_count($s);
            if ($words >= 8 && preg_match('~^(add|implement|create|build|set ?up|integrate|develop)\b~i', $s)
                && preg_match('~\b(with|using|including|and|for)\b~i', $s)
                && preg_match('~\b(auth\w*|api|endpoint|component|dashboard|integration|validation|middleware|schema|jwt|oauth|crud|responsive|payment|database|routing|state management)\b~i', $s)) {
                $prompt[] = '"' . Report::excerpt($s, 80) . '"';
            }
        }
        if (count($prompt) >= 2) {
            $this->r->flag('gh.prompt_messages', $prompt);
        }

        // Interchangeable messages.
        $genericRe = '~^(update|updates|updated|changes|change|changed|fix|fixes|fixed|wip|misc|stuff|edit|edits|new|test|tests|commit|final|done|cleanup|clean up|refactor)\.?$~i';
        $generic = array();
        foreach ($subjects as $s) {
            if (preg_match($genericRe, trim($s))) {
                $generic[] = '"' . $s . '"';
            }
        }
        if (count($generic) >= 3 && count($generic) / $total >= 0.3) {
            $this->r->flag('gh.generic_messages', array_merge(
                array(sprintf('%d of %d subjects carry no information', count($generic), $total)),
                array_slice(array_unique($generic), 0, 3)
            ));
        }

        // Tracked work.
        $refs = array();
        foreach ($subjects as $s) {
            if (preg_match('~(#\d{1,6}\b|\b[A-Z]{2,8}-\d{1,6}\b)~', $s, $m)) {
                $refs[] = '"' . Report::excerpt($s, 70) . '"';
            }
        }
        if (count($refs) >= 2) {
            $this->r->flag('gh.issue_refs', array_slice($refs, 0, 4));
        }

        // Audible frustration.
        $mess = array();
        foreach ($subjects as $s) {
            if (preg_match('~\b(oops|argh|ugh|whoops|sigh|dammit|damn|finally|actually|for real|this time|please work|why (?:is|does|won\'?t)|forgot|stupid|revert the revert|no really|last try|hopefully)\b~i', $s)) {
                $mess[] = '"' . Report::excerpt($s, 70) . '"';
            }
        }
        if ($mess) {
            $this->r->flag('gh.human_mess', array_slice($mess, 0, 4));
        }
    }

    private function checkCadence(bool $withTs, float $spanDays): void
    {
        if (!$withTs || count($this->commits) < 5) {
            return;
        }

        $days = array();
        foreach ($this->commits as $c) {
            if ($c['ts'] > 0) {
                $days[gmdate('Y-m-d', $c['ts'])] = true;
            }
        }
        $distinctDays = count($days);

        if ($spanDays >= 21 && $distinctDays >= 8) {
            $this->r->flag('gh.steady_cadence', array(
                sprintf('%d commits over %d days, on %d separate days',
                    count($this->commits), (int) round($spanDays), $distinctDays),
            ));
        } elseif ($spanDays <= 0.5 && count($this->commits) >= 5) {
            $hours = max(1, (int) round($spanDays * 24));
            $this->r->flag('gh.single_session', array(
                sprintf('the whole history spans about %d hour%s', $hours, $hours === 1 ? '' : 's'),
                sprintf('%d commits, all on %s', count($this->commits), array_key_first($days)),
            ));
        }
    }

    /** @param array<string,int> $authors */
    private function checkCollaboration(array $authors): void
    {
        if (count($authors) >= 2) {
            $names = array_slice(array_keys($authors), 0, 4);
            $this->r->flag('gh.multiple_authors', array(
                sprintf('%d distinct authors: %s', count($authors), implode(', ', $names)),
            ));
        }

        $branchy = array();
        foreach ($this->commits as $c) {
            if (preg_match('~^(merge|revert)\b~i', $c['subject'])) {
                $branchy[] = '"' . Report::excerpt($c['subject'], 70) . '"';
            }
        }
        if ($branchy) {
            $this->r->flag('gh.merges_and_reverts', array_slice($branchy, 0, 4));
        }
    }

    /**
     * A compact shape of the whole history: churn per section, oldest first.
     *
     * The signals above each answer one question about the log; this answers
     * none of them and is shown as-is, so a reader can see a big-bang opening
     * or a burst mid-history for themselves rather than taking the signals'
     * word for it. Bucketed rather than one point per commit, because a log of
     * a thousand commits would otherwise hand the front end a payload larger
     * than the page it renders into.
     *
     * @return array<int,array{added:int,removed:int,commits:int}>
     */
    private function buildTrend(): array
    {
        $n = count($this->commits);
        $buckets = min(40, $n);
        if ($buckets < 1) {
            return array();
        }

        $points = array();
        for ($b = 0; $b < $buckets; $b++) {
            $lo = (int) floor($b * $n / $buckets);
            $hi = (int) floor(($b + 1) * $n / $buckets);
            if ($hi <= $lo) {
                $hi = $lo + 1;
            }
            $added = 0;
            $removed = 0;
            $commits = 0;
            for ($i = $lo; $i < $hi && $i < $n; $i++) {
                $added   += $this->commits[$i]['added'];
                $removed += $this->commits[$i]['removed'];
                $commits++;
            }
            $points[] = array('added' => $added, 'removed' => $removed, 'commits' => $commits);
        }
        return $points;
    }

    // ---------------------------------------------------------------- helpers

    private function withTimestamps(): bool
    {
        foreach ($this->commits as $c) {
            if ($c['ts'] > 0) {
                return true;
            }
        }
        return false;
    }

    private function hasLineCounts(): bool
    {
        foreach ($this->commits as $c) {
            if ($c['added'] > 0 || $c['removed'] > 0) {
                return true;
            }
        }
        return false;
    }

    private function spanDays(): float
    {
        $stamps = array();
        foreach ($this->commits as $c) {
            if ($c['ts'] > 0) {
                $stamps[] = $c['ts'];
            }
        }
        if (count($stamps) < 2) {
            return 0.0;
        }
        return (max($stamps) - min($stamps)) / 86400;
    }

    /** @return array<string,int> author => commit count */
    private function authors(): array
    {
        $out = array();
        foreach ($this->commits as $c) {
            $name = trim($c['author']);
            if ($name === '') {
                continue;
            }
            $key = self::fold($name);
            if (!isset($out[$name])) {
                // Fold case-variant spellings of the same person together.
                foreach (array_keys($out) as $seen) {
                    if (self::fold($seen) === $key) {
                        $out[$seen]++;
                        continue 2;
                    }
                }
                $out[$name] = 0;
            }
            $out[$name]++;
        }
        return $out;
    }

    /** Case-fold without assuming mbstring is installed. */
    private static function fold(string $s): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    }

    private function describe(int $n, float $spanDays, int $authors): string
    {
        $bits = array($n . ' commit' . ($n === 1 ? '' : 's'));
        if ($spanDays >= 1) {
            $bits[] = (int) round($spanDays) . ' days';
        } elseif ($spanDays > 0) {
            $bits[] = 'under a day';
        }
        if ($authors > 0) {
            $bits[] = $authors . ' author' . ($authors === 1 ? '' : 's');
        }
        return implode(' · ', $bits);
    }
}
