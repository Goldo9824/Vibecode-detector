<?php
declare(strict_types=1);

require_once __DIR__ . '/Report.php';

/**
 * Reads a pasted source file and looks for the tells.
 *
 * Nothing in here is language-aware in the parsing sense; it is deliberately
 * regex and line-shape work, because the input is a paste from anywhere and
 * half of it will not parse. Guessing the language only decides which checks
 * are worth running.
 */
final class CodeAnalyzer
{
    /** Below this we refuse to pretend we know anything. */
    const THIN_LINES = 25;

    /** @var string */
    private $src;
    /** @var string[] */
    private $lines;
    /** @var string */
    private $lang;
    /** @var Report */
    private $r;

    public function __construct(string $source)
    {
        $this->src   = str_replace(array("\r\n", "\r"), "\n", $source);
        $this->lines = explode("\n", $this->src);
        $this->lang  = self::detectLanguage($this->src);
    }

    public function language(): string
    {
        return $this->lang;
    }

    public function analyze(?Report $report = null): Report
    {
        $n = count($this->lines);
        $this->r = $report !== null
            ? $report
            : new Report('code', sprintf('Pasted source (%s, %d lines)', self::languageLabel($this->lang), $n));

        if ($report === null) {
            $this->r->setSubtitle(self::languageLabel($this->lang) . ' · ' . $n . ' lines');
            $this->r->stat('language', $this->lang);
            $this->r->stat('lines', $n);
            $this->r->stat('bytes', strlen($this->src));
        }

        if ($n < self::THIN_LINES) {
            $this->r->stat('thin', true);
            $this->r->note('Short samples are exactly where automated detection performs worst. Anything under about 50 lines should be read as a hint, and several hundred lines gives a far more stable reading.');
        }

        $this->checkComments();
        $this->checkErrorHandling();
        $this->checkNaming();
        $this->checkTests();
        $this->checkStructure();
        $this->checkSecurity();
        $this->checkTypography();
        $this->checkHumanMarks();

        $this->r->note('Source alone cannot see the repository history, which is the single most reliable structural signal available: one enormous initial commit followed by a trail of "fix typo" commits is far harder to fake than anything in the file itself.');

        return $this->r;
    }

    // ---------------------------------------------------------------- comments

    private function checkComments(): void
    {
        $comments = $this->commentLines();
        if (!$comments) {
            return;
        }

        $emoji = array();
        foreach ($comments as $ln => $text) {
            if (Text::hasEmoji($text)) {
                $emoji[] = 'line ' . ($ln + 1) . ': ' . $text;
            }
        }
        if ($emoji) {
            $this->r->flag('cd.emoji_comments', $emoji);
        }

        // Section banners: # ==== Thing ====, // ---- Thing ----
        $banners = array();
        foreach ($comments as $ln => $text) {
            if (preg_match('~(={3,}|-{3,}|\*{3,}|_{3,})\s*[A-Za-z][^=\-*_]{2,40}\s*(={3,}|-{3,}|\*{3,}|_{3,})~', $text)) {
                $banners[] = 'line ' . ($ln + 1) . ': ' . $text;
            }
        }
        if (count($banners) >= 2) {
            $this->r->flag('cd.section_header_comments', $banners);
        }

        // What-not-why: the comment restates the line under it.
        $what = array();
        foreach ($comments as $ln => $text) {
            if ($this->isWhatComment($text, $ln)) {
                $what[] = 'line ' . ($ln + 1) . ': ' . $text;
            }
        }
        if (count($what) >= 3) {
            $this->r->flag('cd.what_comments', $what);
        }

        // An explanatory banner at the top of the file, restating its own name.
        $head = implode("\n", array_slice($this->lines, 0, 12));
        if (preg_match('~^\s*(?:/\*\*|"""|\'\'\'|//|#)~', ltrim($head))
            && preg_match('~\b(?:this (?:file|module|class|script)|handles|responsible for|provides|contains|implements)\b~i', $head)
            && strlen(trim($head)) > 60) {
            $this->r->flag('st.file_header_block', array(Report::excerpt(trim($head), 160)));
        }

        // Uniform density: chop the file into blocks and look at the spread.
        // Humans comment in bursts around the hard parts; generators spread it flat.
        $this->checkCommentUniformity($comments);

        // Every function documented, including the trivial ones.
        $this->checkDocblocks();
    }

    private function isWhatComment(string $text, int $lineNo): bool
    {
        $body = trim(preg_replace('~^\s*(//+|#+|/\*+|\*+|<!--|--)\s*~', '', $text));
        $body = trim(preg_replace('~(\*/|-->)\s*$~', '', $body));
        if ($body === '' || strlen($body) > 90) {
            return false;
        }
        // A why-comment nearly always carries something from outside the code.
        if (Text::looksLikeWhy($body)) {
            return false;
        }

        $imperative = '~^(set|get|gets|sets|increment|decrement|initiali[sz]e|initiali[sz]ing|loop through|iterate|check if|checks if|return|returns|create|creates|define|defines|import|imports|add|adds|remove|removes|update|updates|handle|handles|convert|converts|calculate|calculates|store|stores|fetch|fetches|declare|assign|call|render|display|print|log|start|stop|close|open|read|write|parse|validate|clear|reset|build|make|find|filter|map|sort|save|load|send|wait|apply|extract|format|generate|setup|configure|register|toggle|show|hide|enable|disable)\b~i';
        if (!preg_match($imperative, $body)) {
            return false;
        }

        // Confirm it is restating the neighbouring code rather than summarising
        // a real block: share a token with the next non-blank line.
        $next = '';
        for ($i = $lineNo + 1, $end = min($lineNo + 3, count($this->lines) - 1); $i <= $end; $i++) {
            $cand = trim($this->lines[$i]);
            if ($cand !== '' && !$this->isCommentLine($cand)) {
                $next = $cand;
                break;
            }
        }
        if ($next === '') {
            return false;
        }

        $words = preg_split('~[^a-z0-9]+~i', strtolower($body));
        $codeWords = preg_split('~[^a-z0-9]+~i', strtolower(Text::splitIdentifiers($next)));
        $codeWords = array_filter($codeWords, function ($w) { return strlen($w) > 2; });
        foreach ($words as $w) {
            if (strlen($w) > 3 && in_array($w, $codeWords, true)) {
                return true;
            }
        }
        return false;
    }

    private function checkCommentUniformity(array $comments): void
    {
        $total = count($this->lines);
        if ($total < 80 || count($comments) < 8) {
            return;
        }
        $blocks = 8;
        $size = (int) ceil($total / $blocks);
        $counts = array_fill(0, $blocks, 0);
        foreach (array_keys($comments) as $ln) {
            $b = min($blocks - 1, (int) floor($ln / $size));
            $counts[$b]++;
        }
        $mean = array_sum($counts) / $blocks;
        if ($mean < 0.8) {
            return;
        }
        $var = 0.0;
        foreach ($counts as $c) {
            $var += ($c - $mean) * ($c - $mean);
        }
        $cv = sqrt($var / $blocks) / $mean;

        // Every block commented, and the rate barely moves between them.
        $empty = count(array_filter($counts, function ($c) { return $c === 0; }));
        if ($cv < 0.45 && $empty === 0) {
            $this->r->flag('st.uniform_comment_density', array(
                sprintf('comment lines per eighth of the file: %s', implode(', ', $counts)),
                sprintf('spread (coefficient of variation) %.2f — human files usually exceed 0.6', $cv),
            ));
        }
    }

    private function checkDocblocks(): void
    {
        $funcs = 0;
        $documented = 0;
        $trivial = array();

        foreach ($this->lines as $i => $line) {
            if (!self::isDeclaration($line)) {
                continue;
            }
            $funcs++;

            // Look upward for a docblock terminator.
            for ($j = $i - 1; $j >= max(0, $i - 3); $j--) {
                $prev = trim($this->lines[$j]);
                if ($prev === '') continue;
                if ($prev === '*/' || preg_match('~^(///|#\'|"""|\'\'\')~', $prev) || preg_match('~^\*/~', $prev)) {
                    $documented++;
                    // Is the function body a one-liner? Then the docblock is ceremony.
                    $body = isset($this->lines[$i + 1]) ? trim($this->lines[$i + 1]) : '';
                    if (preg_match('~^(return|\$this->|self::)~', $body)) {
                        $trivial[] = 'line ' . ($i + 1) . ': ' . trim($line);
                    }
                }
                break;
            }
        }

        if ($funcs >= 4 && $documented >= $funcs && count($trivial) >= 1) {
            $this->r->flag('st.docblock_on_everything', array_merge(
                array(sprintf('%d of %d functions carry a docblock, including one-line accessors', $documented, $funcs)),
                $trivial
            ));
        }
    }

    // ----------------------------------------------------------- error handling

    private function checkErrorHandling(): void
    {
        $tries = preg_match_all('~\btry\s*[:{]~', $this->src);
        $funcs = max(1, preg_match_all('~\b(function|def|func)\s+\w+~', $this->src));

        if ($tries >= 3 && $tries >= $funcs * 0.7) {
            $this->r->flag('cd.blanket_try', array(
                sprintf('%d try blocks across roughly %d functions', $tries, $funcs),
            ));
        }

        $swallowed = array();

        // catch (e) { console.error(...) }  and nothing else
        if (preg_match_all('~catch\s*\([^)]*\)\s*\{\s*(?:console\.(?:error|log|warn)\([^)]*\);?\s*)?\}~', $this->src, $m)) {
            foreach ($m[0] as $hit) $swallowed[] = $hit;
        }
        // except Exception: pass   /   except: pass
        if (preg_match_all('~except[^\n:]*:\s*\n\s*pass~', $this->src, $m)) {
            foreach ($m[0] as $hit) $swallowed[] = $hit;
        }
        // catch (Throwable $e) { // ignore }
        if (preg_match_all('~catch\s*\([^)]*\)\s*\{\s*(?://[^\n]*|/\*.*?\*/)?\s*\}~s', $this->src, $m)) {
            foreach ($m[0] as $hit) $swallowed[] = $hit;
        }
        if (preg_match_all('~catch\s*\([^)]*\)\s*\{\s*return\s+(null|false|undefined|\[\]|\{\})\s*;?\s*\}~', $this->src, $m)) {
            foreach ($m[0] as $hit) $swallowed[] = $hit;
        }

        if (count($swallowed) >= 2) {
            $this->r->flag('cd.swallowed_errors', $swallowed);
        }

        // Debug logging density.
        $logs = preg_match_all('~\bconsole\.(log|debug|info)\s*\(~', $this->src);
        $logs += preg_match_all('~\b(var_dump|print_r|error_log)\s*\(~', $this->src);
        $per100 = count($this->lines) > 0 ? $logs / (count($this->lines) / 100) : 0;
        if ($logs >= 5 && $per100 >= 2.0) {
            $this->r->flag('cd.console_noise', array(
                sprintf('%d debug logging calls, about %.1f per 100 lines', $logs, $per100),
            ));
        }

        // Emoji in log and status output. Cleaned up even less often than
        // comments are, so this outlives most stylistic tells.
        $logEmoji = array();
        if (preg_match_all('~(?:console\.\w+|print|println|printf|echo|logger?\.\w+|log)\s*\(\s*[`\'"]([^`\'"]{0,120})~u', $this->src, $m)) {
            foreach ($m[1] as $str) {
                if (Text::hasEmoji($str)) {
                    $logEmoji[] = $str;
                }
            }
        }
        if ($logEmoji) {
            $this->r->flag('cd.emoji_logging', $logEmoji);
        }

        // Every access guarded, everywhere, whether or not it can be absent.
        $chains = preg_match_all('~\?\.~', $this->src) + preg_match_all('~\?\?~', $this->src);
        $accesses = max(1, preg_match_all('~[a-zA-Z_$)\]]\s*\.\s*[a-zA-Z_$]~', $this->src));
        if ($chains >= 8 && ($chains / $accesses) > 0.28) {
            $this->r->flag('cd.defensive_chaining', array(
                sprintf('%d optional-chain or nullish-fallback operators across %d property accesses', $chains, $accesses),
            ));
        }

        // An else for every if.
        $ifs = preg_match_all('~(?<![\w$])if\s*\(~', $this->src);
        $elses = preg_match_all('~(?<![\w$])else\b~', $this->src);
        if ($ifs >= 5 && $elses >= $ifs * 0.85) {
            $this->r->flag('cd.over_symmetric_branches', array(
                sprintf('%d if statements and %d else branches: nearly every path has a counterpart', $ifs, $elses),
            ));
        }

        // Formal, complete error messages.
        $formal = array();
        if (preg_match_all('~["\']((?:The|An?|Your|This|Please)\s+[^"\']{18,120}\.)["\']~', $this->src, $m)) {
            foreach ($m[1] as $msg) {
                if (preg_match('~\b(is|are|was|were|must|cannot|could not|does not|has not|should)\b~i', $msg)) {
                    $formal[] = $msg;
                }
            }
        }
        if (count($formal) >= 2) {
            $this->r->flag('cd.formal_errors', $formal);
        }
    }

    // ------------------------------------------------------------------ naming

    private function checkNaming(): void
    {
        $idents = array();
        if (preg_match_all('~\b([a-z][a-zA-Z0-9_]{2,})\b~', $this->src, $m)) {
            $idents = array_unique($m[1]);
        }

        $verbose = array();
        $egregious = array();
        foreach ($idents as $id) {
            if (strlen($id) < 20) continue;
            $words = Text::wordCount($id);
            if ($words >= 4) {
                $verbose[] = $id;
                // One name this long is already the whole tell; a second is
                // confirmation, not a requirement.
                if (strlen($id) >= 28 && $words >= 5) {
                    $egregious[] = $id;
                }
            }
        }
        if (count($verbose) >= 2 || $egregious) {
            $this->r->flag('cd.verbose_names', $verbose);
        }

        $lazy = array();
        foreach ($idents as $id) {
            if (preg_match('~^(data|result|response|res|temp|tmp|item|value|arr|obj|list|output|input|handler|handle\w*|new\w*|final\w*|test|foo|bar)\d$~i', $id)
                || preg_match('~^\w+_(final|new|old|copy|v\d|2)$~i', $id)
                || preg_match('~^(newFunction|myFunction|doStuff|doSomething|processData|handleData)\d*$~i', $id)) {
                $lazy[] = $id;
            }
        }
        if (count($lazy) >= 2) {
            $this->r->flag('cd.lazy_names', $lazy);
        }

        // Ceremony classes with nothing to justify them.
        $helpers = array();
        if (preg_match_all('~\b(?:class|interface|abstract\s+class)\s+(\w*(?:Utils?|Manager|Handler|Helper|Service|Factory|Provider|Wrapper|Adapter)\w*)~', $this->src, $m)) {
            $helpers = array_unique($m[1]);
        }
        if (count($helpers) >= 3) {
            $this->r->flag('cd.helper_pileup', $helpers);
        }

        // Abbreviated, idiomatic names pull the other way.
        $abbrev = array();
        foreach ($idents as $id) {
            if (preg_match('~(?:^|[a-z])(Svc|Cfg|Mgr|Ctx|Idx|Buf|Repo|Auth|Btn|El|Ln|Msg|Qty|Amt)(?:[A-Z]|$)~', $id)
                || preg_match('~^(cfg|svc|mgr|ctx|idx|buf|acc|prev|curr|dst|src|tmp|qty|amt)[A-Z0-9_]~', $id)) {
                $abbrev[] = $id;
            }
        }
        if (count($abbrev) >= 3) {
            $this->r->flag('hu.abbrevs', array_slice($abbrev, 0, 4));
        }
    }

    // ------------------------------------------------------------------- tests

    private function checkTests(): void
    {
        $isTest = preg_match('~\b(describe|it|test|assert\w*|expect|@Test|def test_|TestCase)\b~', $this->src);
        if (!$isTest) {
            return;
        }

        $hollow = array();
        $patterns = array(
            '~expect\s*\(\s*(true|1)\s*\)\s*\.\s*to\w*\s*\(\s*(true|1)\s*\)~i',
            '~assert(?:Equals?|True|That)?\s*\(\s*(true|True|1)\s*(?:,\s*(true|True|1)\s*)?\)~',
            '~expect\s*\([^)]*\)\s*\.\s*(?:toBeDefined|not\.toBeNull|toBeTruthy)\s*\(\s*\)~',
            '~assert\s+\w+\s+is\s+not\s+None~',
        );
        foreach ($patterns as $re) {
            if (preg_match_all($re, $this->src, $m)) {
                foreach ($m[0] as $hit) $hollow[] = $hit;
            }
        }

        // Test bodies with no assertion at all.
        if (preg_match_all('~\b(?:it|test)\s*\(\s*[\'"][^\'"]+[\'"]\s*,\s*(?:async\s*)?\(\s*\)\s*=>\s*\{(.*?)\n\s*\}\s*\)~s', $this->src, $m)) {
            foreach ($m[1] as $i => $body) {
                if (!preg_match('~\b(expect|assert|should)\b~', $body)) {
                    $hollow[] = 'test case with no assertion in its body';
                }
            }
        }

        $negatives = preg_match_all('~\b(toThrow|assertRaises|rejects|expectError|toBeFalsy|assertFalse|pytest\.raises)\b~', $this->src);
        $assertions = preg_match_all('~\b(expect|assert\w*)\s*\(~', $this->src);

        if ($hollow) {
            $this->r->flag('cd.empty_tests', $hollow);
        } elseif ($assertions >= 6 && $negatives === 0) {
            $this->r->flag('cd.empty_tests', array(
                sprintf('%d assertions and not one negative or error path among them', $assertions),
            ));
        }
    }

    // --------------------------------------------------------------- structure

    private function checkStructure(): void
    {
        // Imports grouped and alphabetised with no organic drift.
        $imports = array();
        foreach ($this->lines as $i => $line) {
            if (preg_match('~^\s*(?:import\s+|from\s+\S+\s+import\s+|use\s+[A-Z])~', $line)) {
                $imports[$i] = trim($line);
            }
        }
        if (count($imports) >= 6) {
            $keys = array_keys($imports);
            $contiguous = ($keys[count($keys) - 1] - $keys[0]) <= count($keys) + 3;
            $vals = array_values($imports);
            if ($contiguous && $this->sortedRuns($vals) >= 0.85) {
                $this->r->flag('st.import_block_sorted', array(
                    sprintf('%d imports in one contiguous block, ordered', count($vals)),
                    $vals[0],
                    $vals[count($vals) - 1],
                ));
            }
        }

        // The same job done several ways: local perfection, global incoherence.
        $families = array(
            'HTTP' => array('fetch(' => 'fetch', 'axios' => 'axios', 'XMLHttpRequest' => 'XMLHttpRequest',
                            'http.request' => 'http.request', 'got(' => 'got', 'superagent' => 'superagent',
                            'requests.' => 'requests', 'urllib' => 'urllib', 'httpx' => 'httpx',
                            'curl_init' => 'curl'),
            'dates' => array('moment(' => 'moment', 'dayjs' => 'dayjs', 'date-fns' => 'date-fns',
                             'luxon' => 'luxon', 'new Date(' => 'native Date', 'DateTime::' => 'DateTime',
                             'strtotime' => 'strtotime', 'datetime.' => 'datetime'),
            'validation' => array('zod' => 'zod', 'joi' => 'joi', 'yup' => 'yup', 'ajv' => 'ajv',
                                  'class-validator' => 'class-validator', 'pydantic' => 'pydantic',
                                  'filter_var(' => 'filter_var'),
            'hashing' => array('bcrypt' => 'bcrypt', 'argon2' => 'argon2', 'scrypt' => 'scrypt',
                               'md5(' => 'md5', 'sha1(' => 'sha1', 'password_hash(' => 'password_hash'),
            'ids' => array('uuid' => 'uuid', 'nanoid' => 'nanoid', 'randomUUID' => 'randomUUID',
                           'cuid' => 'cuid', 'ObjectId' => 'ObjectId'),
        );

        $mixed = array();
        $duplicated = 0;
        foreach ($families as $name => $members) {
            $found = array();
            foreach ($members as $needle => $label) {
                if (strpos($this->src, $needle) !== false) $found[] = $label;
            }
            if (count($found) >= 2) {
                $duplicated++;
                $mixed[] = $name . ' handled ' . count($found) . ' ways: ' . implode(', ', $found);
            }
            if (count($found) >= 3) {
                $duplicated += 2; // one family done three ways is the finding on its own
            }
        }
        if ($duplicated >= 2) {
            $this->r->flag('st.multiple_solutions', $mixed);
        }

        // TODO stubs shipped.
        $todo = array();
        foreach ($this->lines as $i => $line) {
            if (preg_match('~(TODO|FIXME)\s*:?\s*(implement|add|handle|complete|finish|replace this|actual)~i', $line)
                || preg_match('~^\s*(?://|#)\s*(Implementation goes here|Your code here|Add your .* here)~i', $line)
                || preg_match('~\braise NotImplementedError\b~', $line)) {
                $todo[] = 'line ' . ($i + 1) . ': ' . trim($line);
            }
        }
        if ($todo) {
            $this->r->flag('cd.todo_placeholders', $todo);
        }

        // Entry-point guard on something nothing runs.
        if ($this->lang === 'python' && preg_match('~if\s+__name__\s*==\s*[\'"]__main__[\'"]\s*:~', $this->src)) {
            $tail = substr($this->src, (int) strpos($this->src, '__main__'));
            if (preg_match('~__main__[\'"]\s*:\s*\n\s*(pass|print\(|main\(\)\s*$)~', $tail)
                || preg_match('~\bclass\s+\w+~', $this->src)) {
                $this->r->flag('cd.main_guard', array('entry-point guard present on what reads as a library module'));
            }
        }

        // Dead code: defined and never referenced again.
        $this->checkDeadCode();
    }

    private function checkDeadCode(): void
    {
        $dead = array();

        // Imported and never called: the residue of a prompt that changed its
        // mind about which library to use.
        if (preg_match_all('~^\s*import\s+(?:(\w+)|\{([^}]+)\})\s+from~m', $this->src, $im, PREG_SET_ORDER)) {
            foreach ($im as $hit) {
                $names = $hit[1] !== '' ? array($hit[1]) : explode(',', $hit[2] ?? '');
                foreach ($names as $name) {
                    $name = trim(preg_replace('~\s+as\s+.*$~', '', trim($name)) ?? '');
                    if ($name === '' || strlen($name) < 2) continue;
                    if (preg_match_all('~\b' . preg_quote($name, '~') . '\b~', $this->src) <= 1) {
                        $dead[] = $name . ' is imported and never used';
                    }
                }
            }
        }

        if (!preg_match_all('~\b(?:function|def|const|class)\s+([A-Za-z_]\w{3,})~', $this->src, $m)) {
            if (count($dead) >= 2) {
                $this->r->flag('st.dead_code', $dead);
            }
            return;
        }
        foreach (array_unique($m[1]) as $name) {
            if (preg_match('~^(main|index|app|default|constructor|render|__init__|setUp)$~i', $name)) {
                continue;
            }
            $uses = preg_match_all('~\b' . preg_quote($name, '~') . '\b~', $this->src);
            if ($uses === 1 && !preg_match('~\b(export|module\.exports|public)\b[^\n]*\b' . preg_quote($name, '~') . '\b~', $this->src)) {
                $dead[] = $name . '() is defined and never referenced';
            }
        }
        if (count($dead) >= 3) {
            $this->r->flag('st.dead_code', $dead);
        }
    }

    // ---------------------------------------------------------------- security

    private function checkSecurity(): void
    {
        $secrets = array();
        $patterns = array(
            '~[\'"](?:your|my)[-_](?:secret|api|private|access)[-_]?(?:key|token|secret)?[\'"]~i',
            '~[\'"](?:change[-_]?me|replace[-_]?me|SECRET_KEY_HERE|YOUR_API_KEY|xxx+|placeholder)[\'"]~i',
            '~\b(?:secret|api_?key|password|token|passwd)\s*[:=]\s*[\'"][A-Za-z0-9_\-]{6,}[\'"]~i',
            '~<YOUR_[A-Z_]+>~',
        );
        foreach ($patterns as $re) {
            if (preg_match_all($re, $this->src, $m)) {
                foreach ($m[0] as $hit) {
                    // Do not echo anything that looks like a live credential back
                    // at the user; the shape is the finding, not the value.
                    $secrets[] = preg_replace('~[\'"][A-Za-z0-9_\-]{6,}[\'"]~', '"[redacted]"', $hit);
                }
            }
        }
        if ($secrets) {
            $this->r->flag('se.placeholder_secret', $secrets);
        }

        $insecure = array();
        if (preg_match('~Access-Control-Allow-Origin[\'"]?\s*[,:=]\s*[\'"]\*~i', $this->src)) {
            $insecure[] = 'CORS opened to every origin';
        }
        if (preg_match('~(verify\s*=\s*False|CURLOPT_SSL_VERIFYPEER\s*,\s*(?:false|0)|rejectUnauthorized\s*:\s*false|NODE_TLS_REJECT_UNAUTHORIZED)~i', $this->src)) {
            $insecure[] = 'TLS certificate verification disabled';
        }
        if (preg_match('~(?:SELECT|INSERT|UPDATE|DELETE)[^\n;]{0,80}(?:\.\s*\$|\'\s*\.\s*\$|\+\s*\w+\s*\+|\$\{|%s\'\s*%|f["\'])~i', $this->src)) {
            $insecure[] = 'query built by string concatenation rather than binding';
        }
        if (preg_match('~\b(?:echo|print|innerHTML\s*=)\s*[^;\n]*\$_(?:GET|POST|REQUEST)~', $this->src)) {
            $insecure[] = 'request input rendered without escaping';
        }
        if ($insecure) {
            $this->r->flag('se.insecure_defaults', $insecure);
        }

        $auth = array();
        if (preg_match('~\b(?:jwt|sign)\s*\(~i', $this->src)
            && !preg_match('~\b(expiresIn|exp\b|setExpiration|ttl|expires_at)~i', $this->src)) {
            $auth[] = 'token signing with no expiry set anywhere in the file';
        }
        // Routes that read an id from the request and query it without an owner check.
        if (preg_match('~(?:params|query|body|\$_GET|\$_POST)\s*\[?[\'"]?(?:id|user_?id|order_?id)~i', $this->src)
            && !preg_match('~\b(?:user_?id\s*[=:]{1,2}\s*(?:\$?(?:current|auth|session|req)\w*)|owner|belongs_?to|->user_?id|can\(|authorize|abort\(403|403\b)~i', $this->src)
            && preg_match('~\b(?:findById|findOne|SELECT|->find\(|get\()~i', $this->src)) {
            $auth[] = 'record looked up by an id taken straight from the request, with no ownership check nearby';
        }
        if ($auth) {
            $this->r->flag('se.weak_auth', $auth);
        }
    }

    // -------------------------------------------------------------- typography

    private function checkTypography(): void
    {
        $hits = array();
        foreach ($this->lines as $i => $line) {
            if (preg_match('~[\x{2018}\x{2019}\x{201C}\x{201D}\x{2014}]~u', $line)) {
                $hits[] = 'line ' . ($i + 1) . ': ' . trim($line);
            }
        }
        if (count($hits) >= 2) {
            $this->r->flag('cd.typography', $hits);
        }
    }

    // ------------------------------------------------------------- human marks

    private function checkHumanMarks(): void
    {
        $comments = $this->commentLines();

        $why = array();
        $tickets = array();
        $informal = array();

        foreach ($comments as $ln => $text) {
            $body = trim($text);
            if (Text::looksLikeWhy($body)) {
                $why[] = 'line ' . ($ln + 1) . ': ' . $body;
            }
            if (preg_match('~(?:#\d{2,}|\b[A-Z]{2,8}-\d+\b|(?:github|gitlab|jira|linear|atlassian)\.[a-z]{2,}/|\bcf\.?\s|see\s+(?:issue|ticket|bug|PR)\b)~i', $body)) {
                $tickets[] = 'line ' . ($ln + 1) . ': ' . $body;
            }
            if (preg_match('~\b(HACK|XXX|WTF|UGH|ARGH|sigh|do ?n[\'o]?t touch|no idea why|god knows|for some reason|somehow|apparently|used to be|blame|sorry|yikes|gross|nasty|horrible|stupid|dumb|cursed|jank\w*|footgun|magic\b|voodoo)\b~i', $body)) {
                $informal[] = 'line ' . ($ln + 1) . ': ' . $body;
            }
        }

        if ($why)      $this->r->flag('hu.why_comments', $why);
        if ($tickets)  $this->r->flag('hu.ticket_refs', $tickets);
        if ($informal) $this->r->flag('hu.informal', $informal);

        // Commented-out code.
        $dead = array();
        foreach ($comments as $ln => $text) {
            $body = trim(preg_replace('~^\s*(//+|#+|/\*+|\*+)\s*~', '', $text));
            if ($body === '') continue;
            if (preg_match('~[;{}]\s*$~', $body) && preg_match('~[=(]~', $body)) {
                $dead[] = 'line ' . ($ln + 1) . ': ' . $body;
            } elseif (preg_match('~^(if|for|while|return|const|let|var|def|print|echo|\$\w+\s*=)\b~', $body)) {
                $dead[] = 'line ' . ($ln + 1) . ': ' . $body;
            } elseif (preg_match('~^[a-z_$][\w.$]*\([^()]*\)\s*;?(?:\s|$)~i', $body)) {
                // A bare call sitting at the head of a comment: switched-off code.
                $dead[] = 'line ' . ($ln + 1) . ': ' . $body;
            }
        }
        if (count($dead) >= 2) {
            $this->r->flag('hu.commented_code', $dead);
        }

        // Formatting drift.
        $tabs = 0; $spaces = 0;
        foreach ($this->lines as $line) {
            if (preg_match('~^\t~', $line)) $tabs++;
            elseif (preg_match('~^ ~', $line)) $spaces++;
        }
        if ($tabs > 2 && $spaces > 2 && count($this->lines) > 30) {
            $this->r->flag('hu.inconsistent_format', array(
                sprintf('%d lines indented with tabs, %d with spaces, in one file', $tabs, $spaces),
            ));
        }

        if (preg_match('~\$\(document\)\.ready|jQuery\(|\$\.ajax\(|\.live\(|var\s+\w+\s*=\s*function~', $this->src)) {
            $this->r->flag('hu.legacy_stack', array('jQuery-era idioms rather than the current default stack'));
        }
    }

    // ----------------------------------------------------------------- helpers

    /**
     * Is this line declaring a function?
     *
     * Has to cover the shorthand class-method form as well as the keyword
     * forms, because a generated JavaScript class is nothing but shorthand
     * methods and missing them means missing the docblock pattern entirely.
     */
    private static function isDeclaration(string $line): bool
    {
        if (preg_match('~\b(?:function|def|func|fn)\s+\w+\s*\(~', $line)) {
            return true;
        }
        if (preg_match('~\b(?:const|let|var)\s+\w+\s*=\s*(?:async\s*)?(?:function\b|\([^)]*\)\s*=>)~', $line)) {
            return true;
        }
        // Shorthand method: `  async getThing(a, b) {`, but not `if (x) {`.
        if (preg_match('~^\s+(?:static\s+)?(?:async\s+)?(?:get\s+|set\s+)?([A-Za-z_$]\w*)\s*\([^()]*\)\s*\{\s*$~', $line, $m)) {
            return !in_array(strtolower($m[1]), array(
                'if', 'for', 'while', 'switch', 'catch', 'return', 'do', 'else', 'function', 'with',
            ), true);
        }
        return false;
    }

    /** @return array<int,string> line index => raw comment line */
    private function commentLines(): array
    {
        $out = array();
        $inBlock = false;
        foreach ($this->lines as $i => $line) {
            $t = trim($line);
            if ($t === '') continue;

            if ($inBlock) {
                $out[$i] = $t;
                if (strpos($t, '*/') !== false) $inBlock = false;
                continue;
            }
            if (preg_match('~^/\*~', $t)) {
                $out[$i] = $t;
                if (strpos($t, '*/') === false) $inBlock = true;
                continue;
            }
            if ($this->isCommentLine($t)) {
                $out[$i] = $t;
                continue;
            }
            // Trailing comment on a code line.
            if (preg_match('~\S\s+(//[^\n]{3,}|#[^\n]{3,})$~', $line, $m)) {
                $out[$i] = trim($m[1]);
            }
        }
        return $out;
    }

    private function isCommentLine(string $t): bool
    {
        return (bool) preg_match('~^(//|#(?!!\[)|\*|<!--|--\s)~', $t);
    }

    /** Fraction of adjacent pairs that are in order. */
    private function sortedRuns(array $vals): float
    {
        $n = count($vals);
        if ($n < 2) return 0.0;
        $ok = 0;
        for ($i = 1; $i < $n; $i++) {
            if (strcasecmp($vals[$i - 1], $vals[$i]) <= 0) $ok++;
        }
        return $ok / ($n - 1);
    }

    public static function detectLanguage(string $src): string
    {
        $tests = array(
            'php'        => '~<\?php|\$this->|->\w+\(|namespace\s+\w+;|\becho\b~',
            'python'     => '~^\s*(def|class)\s+\w+.*:\s*$|^\s*import\s+\w+$|elif\b|self\.~m',
            'typescript' => '~:\s*(string|number|boolean|void|any|unknown)\b|\binterface\s+\w+\s*\{|\btype\s+\w+\s*=|<\w+>\(~',
            'jsx'        => '~<[A-Z]\w*[\s/>]|className=|useState\(|useEffect\(~',
            'javascript' => '~\b(const|let|function|=>|require\(|module\.exports)\b~',
            'html'       => '~<!DOCTYPE html|<html|<div|<section|<body~i',
            'css'        => '~[.#]?[\w-]+\s*\{[^}]*:[^}]*;[^}]*\}~',
            'go'         => '~\bfunc\s+\w+\(|package\s+main~',
            'java'       => '~\b(public|private)\s+(static\s+)?(void|class|int|String)\b~',
            'sql'        => '~\b(SELECT|INSERT INTO|CREATE TABLE)\b~i',
        );
        $best = 'unknown';
        $bestScore = 0;
        foreach ($tests as $lang => $re) {
            $n = preg_match_all($re, $src);
            if ($n > $bestScore) {
                $bestScore = $n;
                $best = $lang;
            }
        }
        return $bestScore > 0 ? $best : 'unknown';
    }

    public static function languageLabel(string $lang): string
    {
        $map = array(
            'php' => 'PHP', 'python' => 'Python', 'typescript' => 'TypeScript',
            'jsx' => 'React / JSX', 'javascript' => 'JavaScript', 'html' => 'HTML',
            'css' => 'CSS', 'go' => 'Go', 'java' => 'Java', 'sql' => 'SQL',
            'unknown' => 'Unrecognised language',
        );
        return isset($map[$lang]) ? $map[$lang] : ucfirst($lang);
    }
}
