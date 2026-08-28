<?php
declare(strict_types=1);

require_once __DIR__ . '/Fetcher.php';
require_once __DIR__ . '/GitHubLog.php';

/** A repository that could not be read, with a sentence saying why. */
final class RepoError extends RuntimeException
{
}

/**
 * The small part of the GitHub API this tool needs.
 *
 * Only public, read-only endpoints, and only for a repository somebody has
 * pasted in deliberately. Every request goes through Fetcher, so the address
 * guard, the redirect vetting and the size ceilings are the same ones the
 * live-page mode is held to — this class chooses the URLs and the headers and
 * nothing else.
 *
 * The budget matters more here than anywhere else in the project. GitHub
 * allows an unauthenticated caller sixty requests an hour *per address*, and
 * on shared hosting that address is the whole site: eight requests per scan
 * means roughly seven scans an hour for every visitor put together. So the
 * request count is fixed and small, the raw file downloads are deliberately
 * routed at raw.githubusercontent.com (which does not spend from that
 * allowance), and an operator who wants more can drop a token in
 * data/github-config.php and have five thousand an hour instead.
 *
 * Not final, only so the repository tests can substitute a double. The
 * alternative is a test suite that cannot run without the network and sixty
 * requests an hour to spend on being run, which is a test suite nobody runs.
 * Overriding the endpoint methods exercises the whole of RepoAnalyzer against
 * fixtures without relaxing anything here.
 */
class GitHub
{
    const API  = 'https://api.github.com';
    const RAW  = 'https://raw.githubusercontent.com';

    /** Hard ceiling on API calls per scan, whatever the analysis asks for. */
    const MAX_REQUESTS = 8;

    const COMMITS_PER_PAGE = 100;

    /** Response ceilings. A tree or a commit page is JSON, and JSON does not survive truncation. */
    const MAX_JSON_BYTES = 2097152;   // 2 MB
    const MAX_FILE_BYTES = 262144;    // 256 KB of any one source file
    const TIMEOUT = 8;

    /** @var Fetcher */
    private $fetcher;
    /** @var string */
    private $owner;
    /** @var string */
    private $repo;
    /** @var int */
    private $spent = 0;
    /** @var array<string,string> */
    private $lastHeaders = array();

    public function __construct(string $owner, string $repo, ?Fetcher $fetcher = null)
    {
        $this->owner   = $owner;
        $this->repo    = $repo;
        $this->fetcher = $fetcher !== null ? $fetcher : new Fetcher();
    }

    /**
     * Pull owner and repository out of whatever someone pasted.
     *
     * People paste the address bar, the clone URL, a link to a file three
     * directories down, or just "owner/repo" because that is how repositories
     * are said out loud. All of those name the same repository and all of them
     * are accepted; anything else is refused with a sentence rather than a
     * silent guess at what was meant.
     *
     * @return array{0:string,1:string}
     * @throws RepoError
     */
    public static function parse(string $input): array
    {
        $s = trim($input);
        if ($s === '') {
            throw new RepoError('Give it a repository first — a GitHub URL, or just owner/name.');
        }
        if (strlen($s) > 400) {
            throw new RepoError('That is not a repository address.');
        }

        // git@github.com:owner/repo.git
        if (preg_match('~^git@github\.com:(.+)$~i', $s, $m)) {
            $s = $m[1];
        } else {
            $s = preg_replace('~^[a-z][a-z0-9+.-]*://~i', '', $s);
            $s = preg_replace('~^(?:www\.)?github\.com/~i', '', (string) $s);
        }

        $s = (string) $s;
        $s = preg_replace('~[?#].*$~', '', $s);
        $parts = array_values(array_filter(explode('/', trim((string) $s, '/')), 'strlen'));

        // Somebody else's forge, pasted in good faith. Worth its own sentence:
        // "that does not look like an owner and a repository" is true and
        // unhelpful when the real answer is that this only reads one host.
        if ($parts && preg_match('~^[a-z0-9-]+(\.[a-z0-9-]+)+$~i', $parts[0])) {
            throw new RepoError($parts[0] . ' is not GitHub. This reads public GitHub repositories only; for a repository anywhere else, a pasted git log gets you the history half of the same reading.');
        }

        if (count($parts) < 2) {
            throw new RepoError('That does not name a repository. Try github.com/owner/name, or just owner/name.');
        }

        $owner = $parts[0];
        $repo  = preg_replace('~\.git$~i', '', $parts[1]);

        // GitHub's own rules: owners are alphanumeric with hyphens, repository
        // names additionally allow dots and underscores. Checked rather than
        // trusted, because both go straight into a URL path.
        if (!preg_match('~^[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})$~', $owner)
            || !preg_match('~^[A-Za-z0-9._-]{1,100}$~', (string) $repo)
            || $repo === '.' || $repo === '..') {
            throw new RepoError('That does not look like a GitHub owner and repository name.');
        }

        return array($owner, (string) $repo);
    }

    public function fullName(): string
    {
        return $this->owner . '/' . $this->repo;
    }

    public function url(): string
    {
        return 'https://github.com/' . $this->owner . '/' . $this->repo;
    }

    public function requestsSpent(): int
    {
        return $this->spent;
    }

    /**
     * What GitHub says is left of this address's hourly allowance, or null.
     *
     * Worth surfacing because it is the one failure mode of this mode that is
     * nobody's fault and cannot be worked around by trying again immediately.
     */
    public function rateLimitRemaining(): ?int
    {
        return isset($this->lastHeaders['x-ratelimit-remaining'])
            ? (int) $this->lastHeaders['x-ratelimit-remaining']
            : null;
    }

    // ------------------------------------------------------------- endpoints

    /**
     * The repository itself: description, dates, size, default branch.
     *
     * @return array<string,mixed>
     * @throws RepoError
     */
    public function repository(): array
    {
        $res = $this->api('/repos/' . $this->owner . '/' . $this->repo);

        if ($res['status'] === 404) {
            throw new RepoError('No public repository at ' . $this->fullName() . '. Private repositories cannot be read: this only ever sees what anyone can see.');
        }
        $this->assertOk($res);

        $data = $this->decode($res['body']);
        if ($data === null || !isset($data['full_name'])) {
            throw new RepoError('GitHub answered with something that was not a repository.');
        }
        return $data;
    }

    /**
     * The most recent commits, newest first, and how many there are in total.
     *
     * The total is read from the Link header rather than counted, because
     * counting means downloading every page of a history that can run to tens
     * of thousands of commits. GitHub publishes the last page number, and the
     * last page is also where the opening commit lives — which is the single
     * most informative commit in any repository.
     *
     * @return array{commits:array<int,array<string,mixed>>,pages:int}
     * @throws RepoError
     */
    public function recentCommits(): array
    {
        $res = $this->api(sprintf(
            '/repos/%s/%s/commits?per_page=%d',
            $this->owner, $this->repo, self::COMMITS_PER_PAGE
        ), self::MAX_JSON_BYTES);

        if ($res['status'] === 409) {
            throw new RepoError('That repository is empty — there is no history to read yet.');
        }
        $this->assertOk($res);

        $commits = $this->decode($res['body']);
        if (!is_array($commits)) {
            $commits = array();
        }

        return array(
            'commits' => $commits,
            'pages'   => $this->lastPage(isset($res['headers']['link']) ? (string) $res['headers']['link'] : ''),
        );
    }

    /**
     * One page of commits, for reaching the oldest ones.
     *
     * @return array<int,array<string,mixed>>
     */
    public function commitPage(int $page): array
    {
        $res = $this->api(sprintf(
            '/repos/%s/%s/commits?per_page=%d&page=%d',
            $this->owner, $this->repo, self::COMMITS_PER_PAGE, max(1, $page)
        ), self::MAX_JSON_BYTES);

        if ($res['status'] !== 200) {
            return array();
        }
        $commits = $this->decode($res['body']);
        return is_array($commits) ? $commits : array();
    }

    /**
     * One commit in full, which is the only way to learn its line counts.
     *
     * Spent on the opening commit and nothing else: whether a repository
     * arrived fully formed is worth a request, and the size of every commit
     * after it is not worth a hundred.
     *
     * @return array<string,mixed>|null
     */
    public function commit(string $sha): ?array
    {
        if (!preg_match('~^[0-9a-f]{7,40}$~i', $sha)) {
            return null;
        }
        $res = $this->api('/repos/' . $this->owner . '/' . $this->repo . '/commits/' . $sha, self::MAX_JSON_BYTES);
        if ($res['status'] !== 200) {
            return null;
        }
        $data = $this->decode($res['body']);
        return is_array($data) ? $data : null;
    }

    /**
     * Every path in the repository at one commit.
     *
     * @return array{paths:array<int,array{path:string,size:int}>,truncated:bool}
     */
    public function tree(string $ref): array
    {
        $res = $this->api(sprintf(
            '/repos/%s/%s/git/trees/%s?recursive=1',
            $this->owner, $this->repo, rawurlencode($ref)
        ), self::MAX_JSON_BYTES, 10);

        if ($res['status'] !== 200) {
            return array('paths' => array(), 'truncated' => false);
        }

        $data = $this->decode($res['body']);
        if (!is_array($data) || !isset($data['tree']) || !is_array($data['tree'])) {
            // Our own size ceiling can cut a very large tree mid-JSON. That is
            // a repository too big to enumerate, not an error worth stopping
            // the whole reading for.
            return array('paths' => array(), 'truncated' => true);
        }

        $paths = array();
        foreach ($data['tree'] as $node) {
            if (!is_array($node) || ($node['type'] ?? '') !== 'blob' || !isset($node['path'])) {
                continue;
            }
            $paths[] = array(
                'path' => (string) $node['path'],
                'size' => isset($node['size']) ? (int) $node['size'] : 0,
            );
        }

        return array('paths' => $paths, 'truncated' => !empty($data['truncated']));
    }

    /**
     * One file's contents, from the raw host.
     *
     * Deliberately not the contents API: raw.githubusercontent.com serves the
     * file directly, without base64, and — the reason it is worth a separate
     * host — without spending from the hourly API allowance. Reading four
     * files therefore costs nothing that another visitor's scan needs.
     */
    public function file(string $ref, string $path): ?string
    {
        if ($path === '' || strpos($path, '..') !== false) {
            return null;
        }
        $encoded = implode('/', array_map('rawurlencode', explode('/', $path)));
        $url = self::RAW . '/' . $this->owner . '/' . $this->repo . '/' . rawurlencode($ref) . '/' . $encoded;

        try {
            $res = $this->fetcher->fetchApi($url, array('Accept' => 'text/plain,*/*;q=0.8'), self::MAX_FILE_BYTES, self::TIMEOUT);
        } catch (FetchError $e) {
            return null;
        }
        if ($res['status'] !== 200 || $res['body'] === '') {
            return null;
        }
        return str_replace("\0", '', $res['body']);
    }

    // ------------------------------------------------------------- plumbing

    /**
     * @return array{url:string,body:string,status:int,contentType:string,headers:array<string,string>,assets:array<string,string>}
     * @throws RepoError
     */
    private function api(string $path, int $maxBytes = self::MAX_FILE_BYTES, int $timeout = self::TIMEOUT): array
    {
        if ($this->spent >= self::MAX_REQUESTS) {
            throw new RepoError('This reading ran out of its request budget before it finished.');
        }
        $this->spent++;

        try {
            $res = $this->fetcher->fetchApi(self::API . $path, self::headers(), $maxBytes, $timeout);
        } catch (FetchError $e) {
            // A request that never landed is still a request that was made,
            // and an hour of them is worth being able to see. Status 0 says it
            // got no answer at all rather than a refusing one.
            GitHubLog::record($this->fullName(), self::endpointName($path), 0);
            throw new RepoError('Could not reach GitHub: ' . $e->getMessage());
        }

        $this->lastHeaders = isset($res['headers']) ? (array) $res['headers'] : array();

        // What this cost, and what GitHub said was left. Recorded only when the
        // operator has configured a database; a no-op otherwise, like every
        // other logging call in this project. See lib/GitHubLog.php.
        GitHubLog::record($this->fullName(), self::endpointName($path), (int) $res['status'], $this->lastHeaders);

        return $res;
    }

    /**
     * Which endpoint a path was, as one word.
     *
     * The log stores this rather than the URL: the owner and repository are
     * already in their own column, and a path keeps a query string, which is
     * where the page number and the per-page count live — detail that makes
     * every row look distinct without saying anything a reader wanted.
     */
    public static function endpointName(string $path): string
    {
        $path = (string) preg_replace('~[?#].*$~', '', $path);

        if (preg_match('~/git/trees/~', $path)) {
            return 'tree';
        }
        if (preg_match('~/commits/[^/]+$~', $path)) {
            return 'commit';
        }
        if (preg_match('~/commits$~', $path)) {
            return 'commits';
        }
        if (preg_match('~^/repos/[^/]+/[^/]+$~', $path)) {
            return 'repository';
        }
        return 'other';
    }

    /** @throws RepoError */
    private function assertOk(array $res): void
    {
        $status = (int) $res['status'];
        if ($status === 200) {
            return;
        }
        if ($status === 403 || $status === 429) {
            $remaining = $this->rateLimitRemaining();
            if ($remaining !== null && $remaining <= 0) {
                throw new RepoError('GitHub is rate-limiting this server. Its hourly allowance is shared by everyone using the tool, so it resets within the hour — or paste a git log into the history tab instead, which needs nothing from GitHub.');
            }
            throw new RepoError('GitHub refused the request (HTTP 403). The repository may be blocked, or the allowance for this server is spent.');
        }
        if ($status === 451) {
            throw new RepoError('That repository is unavailable for legal reasons.');
        }
        throw new RepoError(sprintf('GitHub answered with HTTP %d. Nothing to read.', $status));
    }

    /**
     * @return array<string,string>
     */
    private static function headers(): array
    {
        $headers = array(
            'Accept'               => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
        );
        $token = self::token();
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        return $headers;
    }

    /**
     * An optional read-only token, from data/github-config.php or the
     * environment. Absent on a plain checkout, which is the intended state:
     * everything works without one, sixty requests an hour at a time.
     */
    public static function token(): string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $file = VCD_DATA . '/github-config.php';
        if (is_readable($file)) {
            $config = require $file;
            if (is_array($config) && !empty($config['token'])) {
                return $cached = trim((string) $config['token']);
            }
        }

        $env = getenv('VCD_GITHUB_TOKEN');
        return $cached = ($env === false ? '' : trim((string) $env));
    }

    /** @return array<string,mixed>|null */
    private function decode(string $json)
    {
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    /**
     * The last page number GitHub advertises in a Link header.
     *
     * Returns 1 when there is no header at all, which is what GitHub does for
     * a history short enough to fit in one page.
     */
    public static function lastPage(string $link): int
    {
        if ($link !== '' && preg_match('~[?&]page=(\d+)[^>]*>;\s*rel="last"~', $link, $m)) {
            return max(1, (int) $m[1]);
        }
        return 1;
    }
}
