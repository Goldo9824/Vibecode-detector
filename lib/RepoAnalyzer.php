<?php
declare(strict_types=1);

require_once __DIR__ . '/Report.php';
require_once __DIR__ . '/Evidence.php';
require_once __DIR__ . '/GitHub.php';
require_once __DIR__ . '/GitAnalyzer.php';
require_once __DIR__ . '/CodeAnalyzer.php';
require_once __DIR__ . '/Text.php';

/**
 * Reads a public GitHub repository.
 *
 * This is the mode the rest of the tool keeps pointing at. The live-page modes
 * see what a site chose to serve; the history tab sees how the code arrived,
 * but only for somebody who already has the repository checked out. A public
 * repository is the one subject where all of it is visible at once — the
 * commits, the tree, and the source itself — without asking anyone to paste
 * anything.
 *
 * Three readings are folded into one report:
 *
 *   1. The history, through GitAnalyzer, from a log synthesised out of the
 *      commits API. Same checks, same weights, no pasting.
 *   2. The tree: which files exist. An assistant's own config file, a pile of
 *      session summaries, a committed .env, whether anything is tested.
 *   3. The source, through CodeAnalyzer, on a few of the largest files.
 *
 * What it cannot do is worth saying as loudly: it reads a *sample*. Three
 * files out of four hundred is a sample, the newest and oldest hundred commits
 * out of nine thousand is a sample, and a sample is why the report says how
 * much it managed to read.
 */
final class RepoAnalyzer
{
    /** Source files read in full and put through the code analyser. */
    const MAX_CODE_FILES = 3;

    /** Wall clock for the whole reading, in seconds. Shared hosting is not patient. */
    const TIME_BUDGET = 18.0;

    /** Below this a tree is a gist, not a codebase, and the tree signals stay quiet. */
    const SUBSTANTIAL_FILES = 25;

    /** @var GitHub */
    private $api;
    /** @var Report */
    private $r;
    /** @var float */
    private $started;
    /** @var array<int,array{path:string,size:int}> */
    private $paths = array();
    /** @var array<string,string> path => contents, for the files that were read */
    private $files = array();

    public function __construct(GitHub $api)
    {
        $this->api = $api;
    }

    /** @throws RepoError */
    public function analyze(): Report
    {
        $this->started = microtime(true);

        $meta = $this->api->repository();
        $branch = isset($meta['default_branch']) ? (string) $meta['default_branch'] : 'main';

        $this->r = new Report('repo', 'github.com/' . $this->api->fullName());
        $this->describe($meta);

        // 1. The history.
        $commits = $this->readHistory();

        // 2. The tree.
        $tree = $this->api->tree($branch);
        $this->paths = $tree['paths'];
        if ($tree['truncated']) {
            $this->r->note('The file list came back truncated — this repository has more files than GitHub will enumerate in one request, so the tree-level checks read only the part that arrived.');
        }
        $this->r->stat('files', count($this->paths));

        if ($this->paths) {
            $this->readKeyFiles($branch);
            $this->checkAgentConfig();
            $this->checkSessionDocs();
            $this->checkSecretsCommitted();
            $this->checkTesting();
            $this->checkFurniture();
            $this->checkManifest();
            $this->checkReadme();
        }

        // 3. The source.
        $this->readSource($branch);

        $this->closingNotes($commits);

        return $this->r;
    }

    // ------------------------------------------------------------- the repo

    /** @param array<string,mixed> $meta */
    private function describe(array $meta): void
    {
        $bits = array();
        if (!empty($meta['language'])) {
            $bits[] = (string) $meta['language'];
        }
        if (!empty($meta['created_at'])) {
            $bits[] = 'created ' . gmdate('M Y', (int) strtotime((string) $meta['created_at']));
        }
        if (isset($meta['stargazers_count']) && (int) $meta['stargazers_count'] > 0) {
            $n = (int) $meta['stargazers_count'];
            $bits[] = $n . ' star' . ($n === 1 ? '' : 's');
        }
        $this->r->setSubtitle(implode(' · ', $bits));

        $this->r->stat('repo', array(
            'fullName'    => $this->api->fullName(),
            'url'         => $this->api->url(),
            'description' => isset($meta['description']) ? Report::excerpt((string) $meta['description'], 200) : '',
            'branch'      => isset($meta['default_branch']) ? (string) $meta['default_branch'] : '',
            'stars'       => isset($meta['stargazers_count']) ? (int) $meta['stargazers_count'] : 0,
            'forks'       => isset($meta['forks_count']) ? (int) $meta['forks_count'] : 0,
            'createdAt'   => isset($meta['created_at']) ? (string) $meta['created_at'] : '',
            'pushedAt'    => isset($meta['pushed_at']) ? (string) $meta['pushed_at'] : '',
            'language'    => isset($meta['language']) ? (string) $meta['language'] : '',
            'license'     => isset($meta['license']['spdx_id']) ? (string) $meta['license']['spdx_id'] : '',
            'isFork'      => !empty($meta['fork']),
            'archived'    => !empty($meta['archived']),
        ));

        if (!empty($meta['fork'])) {
            $this->r->note('This is a fork. Its history is somebody else\'s work up to the point it was forked, and nothing below distinguishes the two.');
        }
    }

    // ---------------------------------------------------------- the history

    /**
     * Commits, read from both ends of the history.
     *
     * The newest hundred and the oldest hundred, because those are the two
     * places where the interesting things happen: how the repository opened,
     * and what has been done to it lately. Everything between them costs a
     * request per hundred commits and adds cadence data to a picture that
     * already has the span of the whole thing in it.
     *
     * @return array{total:int,read:int}
     */
    private function readHistory(): array
    {
        $recent = $this->api->recentCommits();
        $commits = $recent['commits'];
        $pages = $recent['pages'];

        $oldest = array();
        $haveOpening = ($pages === 1);
        if ($pages > 1 && $this->timeLeft() > 6.0) {
            $oldest = $this->api->commitPage($pages);
            $haveOpening = (bool) $oldest;
        }

        // Newest page first, oldest page last; deduplicated by sha, because a
        // history of exactly one page is both pages.
        $seen = array();
        $all = array();
        foreach (array_merge($commits, $oldest) as $c) {
            if (!is_array($c) || empty($c['sha'])) {
                continue;
            }
            $sha = (string) $c['sha'];
            if (isset($seen[$sha])) {
                continue;
            }
            $seen[$sha] = true;
            $all[] = $c;
        }

        $total = $pages > 1
            ? ($pages - 1) * GitHub::COMMITS_PER_PAGE + count($oldest)
            : count($all);
        $read = count($all);

        $this->r->stat('commits', $total);
        $this->r->stat('commitsRead', $read);

        if ($read === 0) {
            $this->r->note('No commits could be read, so none of the history signals ran.');
            return array('total' => $total, 'read' => 0);
        }

        // The log is synthesised without line counts on purpose. GitHub does
        // not put them in a commit listing, and fetching them per commit would
        // cost a request each; a log where one commit has counts and ninety-nine
        // have zeroes would hand every check a false picture rather than no
        // picture, which is worse. The opening commit is measured separately
        // below, against the tree, where the comparison is honest.
        $log = array();
        foreach ($all as $c) {
            $commit = isset($c['commit']) && is_array($c['commit']) ? $c['commit'] : array();
            $date = isset($commit['author']['date']) ? (string) $commit['author']['date'] : '';
            $ts = $date !== '' ? (int) strtotime($date) : 0;
            $author = isset($commit['author']['name']) ? (string) $commit['author']['name'] : '';
            $subject = isset($commit['message']) ? (string) $commit['message'] : '';
            $subject = trim((string) strtok(str_replace(array("\r\n", "\r"), "\n", $subject), "\n"));

            if ($ts <= 0) {
                continue;
            }
            $log[] = sprintf('%s|%d|%s|%s', (string) $c['sha'], $ts, str_replace('|', ' ', $author), str_replace('|', ' ', $subject));
        }

        $sub = (new GitAnalyzer(implode("\n", $log)))->analyze();
        $this->adopt($sub);

        $this->r->stat('authors', $sub->statValue('authors'));
        $this->r->stat('spanDays', $sub->statValue('spanDays'));

        if ($haveOpening) {
            $this->checkOpeningCommit($all, $total);
        }

        return array('total' => $total, 'read' => $read);
    }

    /**
     * Whether the repository arrived fully formed.
     *
     * Measured against the tree rather than against the rest of the history,
     * because the rest of the history has no line counts here. A first commit
     * that carries most of the files that exist today is the same finding by a
     * different route, and it is the more direct one: it is the difference
     * between a project that started and a project that was uploaded.
     *
     * Called only when the last page of the history was actually read, so
     * that the commit being measured really is the first one.
     *
     * @param array<int,array<string,mixed>> $commits newest first
     */
    private function checkOpeningCommit(array $commits, int $total): void
    {
        if ($this->timeLeft() < 4.0) {
            return;
        }
        $opening = end($commits);
        if (!is_array($opening) || empty($opening['sha'])) {
            return;
        }

        $detail = $this->api->commit((string) $opening['sha']);
        if ($detail === null || !isset($detail['stats']['additions'])) {
            return;
        }

        $added = (int) $detail['stats']['additions'];
        $files = isset($detail['files']) && is_array($detail['files']) ? count($detail['files']) : 0;
        $subject = isset($detail['commit']['message']) ? trim((string) strtok((string) $detail['commit']['message'], "\n")) : '';

        $this->r->stat('openingCommit', array('added' => $added, 'files' => $files, 'subject' => Report::excerpt($subject, 90)));

        $treeFiles = count($this->paths);
        $share = $treeFiles > 0 ? $files / $treeFiles : 0.0;

        // Both halves have to be true: a lot of code, and a lot of what is
        // there now. Four hundred lines in a repository that has since grown to
        // four hundred files is a normal first day's work.
        if ($added >= 400 && $files >= 8 && ($share >= 0.5 || $treeFiles === 0)) {
            $evidence = array(
                Excerpt::plain(sprintf('the opening commit adds %s lines across %d files', number_format($added), $files)),
                Excerpt::plain('"' . Report::excerpt($subject, 70) . '"'),
            );
            if ($treeFiles > 0) {
                $evidence[] = Excerpt::plain(sprintf(
                    'that is %d%% of the %d files in the repository today',
                    (int) round($share * 100), $treeFiles
                ));
            }
            $this->r->flag('gh.big_bang', $evidence);
        }
    }

    // ------------------------------------------------------------- the tree

    /** An assistant configured to work in this repository, and committed. */
    private function checkAgentConfig(): void
    {
        $patterns = array(
            '~^CLAUDE\.md$~i'                          => 'CLAUDE.md — instructions for Claude Code',
            '~^AGENTS?\.md$~i'                         => 'AGENTS.md — the cross-tool agent instruction file',
            '~^GEMINI\.md$~i'                          => 'GEMINI.md — instructions for Gemini CLI',
            '~^\.cursorrules$~i'                       => '.cursorrules — Cursor\'s project instructions',
            '~^\.cursor/~i'                            => '.cursor/ — Cursor\'s project rules',
            '~^\.windsurfrules$~i'                     => '.windsurfrules — Windsurf\'s project instructions',
            '~^\.clinerules~i'                         => '.clinerules — Cline\'s project instructions',
            '~^\.claude/~i'                            => '.claude/ — Claude Code\'s project directory',
            '~^\.aider\.~i'                            => '.aider — aider\'s configuration',
            '~^\.github/copilot-instructions\.md$~i'   => '.github/copilot-instructions.md — Copilot\'s project instructions',
            '~^\.continue/~i'                          => '.continue/ — Continue\'s project configuration',
        );

        $found = array();
        foreach ($this->paths as $entry) {
            foreach ($patterns as $re => $label) {
                if (preg_match($re, $entry['path'])) {
                    $found[$label] = $entry['path'];
                }
            }
        }
        if (!$found) {
            return;
        }

        $evidence = array();
        foreach (array_slice($found, 0, 4, true) as $label => $path) {
            $evidence[] = Excerpt::plain($label, 1, $path);
        }
        $this->r->flag('rp.agent_config', $evidence, count($found));
    }

    /**
     * Reports on the work, sitting in the repository next to the work.
     *
     * The names are the tell rather than the existence of documentation:
     * IMPLEMENTATION_SUMMARY, PHASE_2_COMPLETE, FIXES_APPLIED. These describe a
     * session rather than the software, they are written at the end rather than
     * for a reader, and nobody ever deletes them.
     */
    private function checkSessionDocs(): void
    {
        // The ones every repository is allowed to have.
        $standard = '~^(readme|licen[cs]e|copying|notice|changelog|history|contributing|code_of_conduct'
                  . '|security|support|authors|maintainers|governance|install|upgrading|roadmap|citation)~i';
        $session = '~(summary|complete[d]?|final|fixes|fixed|implementation|refactor|migration_plan|project_structure'
                 . '|changes_made|walkthrough|audit|status|progress|phase[_-]?\d|step[_-]?\d|part[_-]?\d'
                 . '|what[_-]?(was|i)[_-]?(done|did|changed)|next[_-]?steps|improvements|deliverable)~i';

        $found = array();
        foreach ($this->paths as $entry) {
            $path = $entry['path'];
            if (!preg_match('~\.mdx?$~i', $path)) {
                continue;
            }
            // Top level, or a docs directory: a summary buried in a package's
            // own folder is more likely to be documentation of that package.
            if (substr_count($path, '/') > 1 || (strpos($path, '/') !== false && !preg_match('~^docs?/~i', $path))) {
                continue;
            }
            $base = basename($path);
            if (preg_match($standard, $base)) {
                continue;
            }
            if (preg_match($session, $base)) {
                $found[] = $path;
            }
        }

        if (count($found) < 3) {
            return;
        }

        $evidence = array();
        foreach (array_slice($found, 0, 4) as $path) {
            $evidence[] = Excerpt::plain($path);
        }
        $evidence[] = Excerpt::plain(sprintf('%d files of this shape in the repository', count($found)));
        $this->r->flag('rp.session_docs', $evidence, count($found));
    }

    /** A secrets file that was committed along with everything else. */
    private function checkSecretsCommitted(): void
    {
        $found = array();
        foreach ($this->paths as $entry) {
            $path = $entry['path'];
            $base = basename($path);

            // .env.example is the opposite finding: somebody thought about it.
            if (preg_match('~^\.env(\.(local|production|development|prod|dev))?$~i', $base)) {
                $found[] = $path;
            } elseif (preg_match('~^(serviceaccountkey|service-account|credentials|client_secret[^.]*)\.json$~i', $base)) {
                $found[] = $path;
            } elseif (preg_match('~^(secrets?|credentials)\.(ya?ml|json|ini|toml)$~i', $base)) {
                $found[] = $path;
            } elseif (preg_match('~^(id_rsa|id_ed25519)$~i', $base) || preg_match('~\.(pem|p12|pfx|keystore)$~i', $base)) {
                $found[] = $path;
            }
        }
        if (!$found) {
            return;
        }

        $evidence = array();
        foreach (array_slice($found, 0, 4) as $path) {
            $evidence[] = Excerpt::plain($path . ' is committed');
        }
        $this->r->flag('se.committed_secrets', $evidence, count($found));
    }

    /** Whether anything here was ever checked by anything but a person looking at it. */
    private function checkTesting(): void
    {
        $source = 0;
        $tests = 0;
        foreach ($this->paths as $entry) {
            $path = $entry['path'];
            if (!self::isSource($path)) {
                continue;
            }
            $source++;
            if (self::isTest($path)) {
                $tests++;
            }
        }
        if ($source < self::SUBSTANTIAL_FILES) {
            return;
        }

        $this->r->stat('sourceFiles', $source);
        $this->r->stat('testFiles', $tests);

        if ($tests === 0) {
            $this->r->flag('rp.no_tests', array(
                Excerpt::plain(sprintf('%d source files, no test file anywhere in the tree', $source)),
            ));
            return;
        }
        if ($tests >= 5 && $tests / $source >= 0.1) {
            $this->r->flag('rp.tests_present', array(
                Excerpt::plain(sprintf('%d test files against %d source files', $tests, $source)),
            ));
        }
    }

    /** The things a repository acquires because other people turned up. */
    private function checkFurniture(): void
    {
        $wanted = array(
            'a changelog'             => '~^CHANGELOG(\.\w+)?$~i',
            'contribution guidelines' => '~^(\.github/)?CONTRIBUTING(\.\w+)?$~i',
            'a code of conduct'       => '~^(\.github/)?CODE_OF_CONDUCT(\.\w+)?$~i',
            'a security policy'       => '~^(\.github/)?SECURITY(\.\w+)?$~i',
            'issue templates'         => '~^\.github/ISSUE_TEMPLATE~i',
            'a pull request template' => '~^\.github/(PULL_REQUEST_TEMPLATE|pull_request_template)~i',
        );

        $found = array();
        foreach ($wanted as $label => $re) {
            foreach ($this->paths as $entry) {
                if (preg_match($re, $entry['path'])) {
                    $found[$label] = $entry['path'];
                    break;
                }
            }
        }
        if (count($found) < 3) {
            return;
        }

        $evidence = array();
        foreach ($found as $label => $path) {
            $evidence[] = Excerpt::plain($label . ': ' . $path);
        }
        $this->r->flag('rp.project_furniture', array_slice($evidence, 0, 4), count($found));
    }

    /**
     * What the manifest says, when there is one.
     *
     * The package file is the densest thing in a generated repository: it names
     * the builder that made it, the component kit it scaffolded from, and the
     * name nobody changed.
     */
    private function checkManifest(): void
    {
        $json = $this->fileContents('package.json');
        if ($json === null) {
            return;
        }
        $pkg = json_decode($json, true);
        if (!is_array($pkg)) {
            return;
        }

        $deps = array();
        foreach (array('dependencies', 'devDependencies') as $key) {
            if (isset($pkg[$key]) && is_array($pkg[$key])) {
                $deps = array_merge($deps, array_keys($pkg[$key]));
            }
        }
        $depSet = array_map('strtolower', $deps);
        $ctx = new SourceContext($json, 'package.json');

        // Builders that name themselves in the manifest. These are positive
        // identifications, which is why they are allowed to be this short.
        if (in_array('lovable-tagger', $depSet, true)) {
            $this->r->flag('fp.lovable', array($ctx->find('lovable-tagger')->withText('lovable-tagger is a dependency of this project')));
        }
        foreach ($depSet as $dep) {
            if (strpos($dep, '@base44/') === 0) {
                $this->r->flag('fp.base44', array($ctx->find($dep)->withText($dep . ' is a dependency of this project')));
                break;
            }
        }
        foreach ($this->paths as $entry) {
            if (preg_match('~^\.bolt/~', $entry['path'])) {
                $this->r->flag('fp.bolt', array(Excerpt::plain($entry['path'] . ' — bolt.new\'s own project directory')));
                break;
            }
        }

        // The scaffold nobody renamed.
        $name = isset($pkg['name']) ? strtolower(trim((string) $pkg['name'])) : '';
        $scaffolds = array('vite-project', 'my-app', 'my-v0-project', 'my-react-app', 'react-app', 'nextjs-dashboard',
                           'frontend', 'client', 'vite-react-typescript-starter', 'rest-express', 'shadcn-ui', 'workspace');
        if ($name !== '' && in_array($name, $scaffolds, true)) {
            $this->r->flag('st.untouched_scaffold', array(
                $ctx->find('"name"')->withText('the package is still called "' . $name . '"'),
            ));
        }

        // The whole component kit, arriving together.
        $kit = 0;
        $present = array();
        foreach (array('class-variance-authority', 'tailwind-merge', 'lucide-react', 'clsx', 'sonner', 'next-themes', 'vaul', 'cmdk') as $marker) {
            if (in_array($marker, $depSet, true)) {
                $kit++;
                $present[] = $marker;
            }
        }
        $radix = 0;
        foreach ($depSet as $dep) {
            if (strpos($dep, '@radix-ui/') === 0) {
                $radix++;
            }
        }
        if ($radix >= 6 && $kit >= 3) {
            $present[] = $radix . ' @radix-ui packages';
            $this->r->flag('st.generated_stack', array(
                $ctx->find('"dependencies"')->withText('the kit, whole: ' . implode(', ', array_slice($present, 0, 6))),
            ));
        }

        // Declared and never locked.
        $hasDeps = isset($pkg['dependencies']) && is_array($pkg['dependencies']) && count($pkg['dependencies']) >= 5;
        if ($hasDeps && !$this->hasPath('~^(package-lock\.json|yarn\.lock|pnpm-lock\.yaml|bun\.lockb?|npm-shrinkwrap\.json)$~i')) {
            $this->r->flag('rp.dependency_soup', array(
                Excerpt::plain(sprintf('%d dependencies declared, no lockfile committed', count($pkg['dependencies']))),
            ));
        }
    }

    /** A README made of the sections a README is supposed to have. */
    private function checkReadme(): void
    {
        $readme = null;
        $name = 'README.md';
        foreach (array('README.md', 'readme.md', 'README.MD', 'Readme.md') as $candidate) {
            $readme = $this->fileContents($candidate);
            if ($readme !== null) {
                $name = $candidate;
                break;
            }
        }
        if ($readme === null || strlen($readme) < 300) {
            return;
        }

        $ctx = new SourceContext($readme, $name);
        $evidence = array();
        $marks = 0;

        // Emoji in the section headings.
        $emojiHeads = 0;
        $firstHead = '';
        if (preg_match_all('~^#{1,4}\s+(.+)$~m', $readme, $m)) {
            foreach ($m[1] as $heading) {
                if (Text::hasEmoji($heading)) {
                    $emojiHeads++;
                    if ($firstHead === '') {
                        $firstHead = trim($heading);
                    }
                }
            }
        }
        if ($emojiHeads >= 2) {
            $marks++;
            $evidence[] = $ctx->find($firstHead, $emojiHeads)
                ->withText(sprintf('%d section headings carry an emoji, starting with "%s"', $emojiHeads, Report::excerpt($firstHead, 50)));
        }

        // The standard sections, in the standard order.
        $sections = 0;
        foreach (array('features', 'getting started', 'installation', 'usage', 'tech stack', 'contributing', 'license', 'roadmap') as $section) {
            if (preg_match('~^#{1,4}\s*[^\n]{0,8}' . preg_quote($section, '~') . '~mi', $readme)) {
                $sections++;
            }
        }
        if ($sections >= 5) {
            $marks++;
            $evidence[] = Excerpt::plain(sprintf('%d of the eight standard sections are present', $sections));
        }

        // A features list of adjectives in bold.
        $bold = preg_match_all('~^\s*[-*]\s+\*\*[^*\n]{2,48}\*\*\s*[-–—:]~m', $readme);
        if ($bold >= 4) {
            $marks++;
            $bullet = $ctx->match('~^\s*[-*]\s+\*\*[^*\n]{2,48}\*\*\s*[-–—:]~m',
                sprintf('%d bullet points of the form "**Something Fast** — description"', $bold));
            $evidence[] = $bullet !== null
                ? $bullet->withCount($bold)
                : Excerpt::plain(sprintf('%d bullet points of the form "**Something Fast** — description"', $bold), $bold);
        }

        // The sign-off.
        if (preg_match('~(?:made|built|crafted)\s+with\s+(?:❤|♥|love|:heart:)~iu', $readme, $m)) {
            $marks++;
            $evidence[] = $ctx->find($m[0])->withText('the sign-off: "' . Report::excerpt($m[0], 40) . '"');
        }

        if ($marks >= 2) {
            $this->r->flag('rp.readme_generated', $evidence, $marks);
        }

        // The scaffold's own README, never replaced.
        if (preg_match('~This template provides a minimal setup to get React working in Vite~i', $readme)
            || preg_match('~bootstrapped with \[Create React App\]~i', $readme)
            || preg_match('~This is a \[Next\.js\]\([^)]*\) project bootstrapped with~i', $readme)) {
            $scaffoldLine = $ctx->match('~This template provides a minimal setup|bootstrapped with~i',
                'the README is still the one the scaffold wrote');
            $this->r->flag('st.untouched_scaffold', $scaffoldLine !== null
                ? array($scaffoldLine)
                : array(Excerpt::plain('the README is still the one the scaffold wrote')));
        }
    }

    // ----------------------------------------------------------- the source

    /**
     * Files worth reading, and the reading of them.
     *
     * Ranked rather than taken in tree order: a repository's first path
     * alphabetically is almost always configuration, and configuration has
     * nothing in it to read. What is wanted is application source — the larger
     * the better, because every code signal in the catalogue is a habit, and a
     * habit needs room to repeat.
     */
    private function readSource(string $branch): void
    {
        $candidates = array();
        foreach ($this->paths as $entry) {
            $rank = self::rank($entry['path'], $entry['size']);
            if ($rank <= 0) {
                continue;
            }
            $candidates[] = array('path' => $entry['path'], 'rank' => $rank, 'size' => $entry['size']);
        }
        if (!$candidates) {
            return;
        }

        usort($candidates, function ($a, $b) {
            if ($a['rank'] === $b['rank']) {
                return strcmp($a['path'], $b['path']); // stable on 7.4
            }
            return $b['rank'] <=> $a['rank'];
        });

        $read = array();
        foreach ($candidates as $candidate) {
            if (count($read) >= self::MAX_CODE_FILES || $this->timeLeft() < 3.0) {
                break;
            }
            $source = $this->fileContents($candidate['path'], $branch);
            if ($source === null || strlen($source) < 400) {
                continue;
            }

            $sub = new CodeAnalyzer($source, $candidate['path']);
            $sub->analyze($this->r);

            $read[] = array(
                'path'     => $candidate['path'],
                'lines'    => substr_count($source, "\n") + 1,
                'bytes'    => strlen($source),
                'language' => $sub->language(),
            );
        }

        if ($read) {
            $this->r->stat('filesRead', $read);
        }
    }

    /**
     * How much a path is worth reading, or 0 for not at all.
     *
     * Everything generated, vendored, minified or declared is worth nothing:
     * a lockfile has no habits in it, a minified bundle has had its habits
     * removed, and a vendor directory holds somebody else's.
     */
    public static function rank(string $path, int $size): int
    {
        if ($size < 400 || $size > 200000) {
            return 0;
        }
        if (preg_match('~(^|/)(node_modules|vendor|dist|build|out|\.next|\.nuxt|coverage|migrations|__pycache__|third_party|generated)/~i', $path)) {
            return 0;
        }
        if (preg_match('~\.(min|bundle|chunk)\.\w+$~i', $path) || preg_match('~\.d\.ts$~i', $path)
            || preg_match('~(^|/)[\w.-]*lock[\w.-]*\.\w+$~i', $path)) {
            return 0;
        }
        if (!self::isSource($path)) {
            return 0;
        }

        // Bigger is better, but only up to a point: past a few hundred lines
        // there is already more than enough to read, and the biggest file in a
        // repository is usually a data table.
        $rank = (int) min(60, $size / 700);

        if (preg_match('~^(src|app|lib|server|api|components|pages|routes)/~i', $path)) $rank += 30;
        if (self::isTest($path))                                                        $rank -= 25;
        if (preg_match('~(^|/)(config|setup|constants|types|schema|index)\.\w+$~i', $path)) $rank -= 10;
        if (preg_match('~\.(css|scss|less)$~i', $path))                                  $rank -= 5;
        if (substr_count($path, '/') > 4)                                                $rank -= 10;

        return max(0, $rank);
    }

    // --------------------------------------------------------------- helpers

    private static function isSource(string $path): bool
    {
        return (bool) preg_match(
            '~\.(js|jsx|ts|tsx|mjs|cjs|vue|svelte|py|rb|php|go|rs|java|kt|kts|swift|cs|c|h|cpp|hpp|m|scala|ex|exs|dart|lua|pl|sh|sql|css|scss|less|astro)$~i',
            $path
        );
    }

    private static function isTest(string $path): bool
    {
        return (bool) preg_match('~(^|/)(tests?|__tests__|spec|specs|e2e|cypress)/~i', $path)
            || (bool) preg_match('~\.(test|spec)\.\w+$~i', $path)
            || (bool) preg_match('~(^|/)test_[^/]+\.py$~i', $path)
            || (bool) preg_match('~[^/]+_test\.(go|py|rb)$~i', $path);
    }

    private function hasPath(string $pattern): bool
    {
        foreach ($this->paths as $entry) {
            if (preg_match($pattern, $entry['path'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * One file's contents, fetched once and remembered.
     *
     * Returns null for a path the tree does not carry, without spending a
     * request on finding that out.
     */
    private function fileContents(string $path, string $branch = ''): ?string
    {
        if (array_key_exists($path, $this->files)) {
            return $this->files[$path];
        }
        if ($branch === '') {
            $repo = $this->r->statValue('repo');
            $branch = is_array($repo) && !empty($repo['branch']) ? (string) $repo['branch'] : 'main';
        }
        if (!$this->hasPath('~^' . preg_quote($path, '~') . '$~')) {
            return $this->files[$path] = null;
        }
        if ($this->timeLeft() < 2.5) {
            return $this->files[$path] = null;
        }
        return $this->files[$path] = $this->api->file($branch, $path);
    }

    /** The two files every tree-level check wants, fetched before anything else needs them. */
    private function readKeyFiles(string $branch): void
    {
        foreach (array('package.json', 'README.md') as $path) {
            $this->fileContents($path, $branch);
        }
    }

    /** Fold another reading's signals into this one, keeping where they came from. */
    private function adopt(Report $sub): void
    {
        foreach ($sub->signals() as $signal) {
            $this->r->flag($signal->id, $signal->evidence, $signal->occurrences);
        }
    }

    private function timeLeft(): float
    {
        return self::TIME_BUDGET - (microtime(true) - $this->started);
    }

    /** @param array{total:int,read:int} $commits */
    private function closingNotes(array $commits): void
    {
        $files = count($this->paths);
        $sourceRead = $this->r->statValue('filesRead');
        $sourceCount = is_array($sourceRead) ? count($sourceRead) : 0;

        if ($commits['total'] > $commits['read']) {
            $this->r->note(sprintf(
                'The repository has about %s commits and this read %d of them — the newest and the oldest. The span and the shape of the ends are accurate; anything in the middle was not looked at.',
                number_format($commits['total']), $commits['read']
            ));
        }
        if ($files > 0 && $sourceCount > 0) {
            $this->r->note(sprintf(
                'Of %s files in the repository, %d %s read in full. Code-style signals therefore describe those files, not the codebase — a habit absent from the handful that were read is not a habit absent from the project.',
                number_format($files), $sourceCount, $sourceCount === 1 ? 'was' : 'were'
            ));
        } elseif ($files > 0) {
            $this->r->note('No source file here was worth reading in full — everything was too small, generated, or vendored — so this reading is the history and the tree only.');
        }

        $this->r->note('Commit metadata is not authorship. A developer who reviews and commits carefully while an agent writes the code produces a repository that looks entirely human, because in every respect git records, it is.');

        if (GitHub::token() === '') {
            $remaining = $this->api->rateLimitRemaining();
            if ($remaining !== null && $remaining < 15) {
                $this->r->note(sprintf(
                    'GitHub has %d unauthenticated requests left for this server this hour, shared across everyone using the tool. If this stops working, that is why.',
                    $remaining
                ));
            }
        }
    }
}
