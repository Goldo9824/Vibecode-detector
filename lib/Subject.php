<?php
declare(strict_types=1);

/**
 * What did somebody just paste?
 *
 * The four modes each want a different kind of input, and asking a visitor to
 * classify their own paste before the tool will look at it is asking them to
 * do the one piece of work the tool is better at. This decides for them.
 *
 * Two rules shape every heuristic below:
 *
 *   1. Order by how distinctive the evidence is, not by how common the mode
 *      is. A git log announces itself — "commit" followed by forty hex
 *      characters is not a thing that appears in anything else — so it is
 *      tested first even though it is the rarest paste. Source code is last
 *      because it is the residue: it is what an input is when it is not any
 *      of the shapes that can be recognised positively.
 *
 *   2. Never guess a mode that costs somebody else. Repository mode spends
 *      from a GitHub allowance the whole installation shares and live-page
 *      mode fetches a stranger's server, so an ambiguous input falls back to
 *      reading the text itself, which costs nothing and nobody. Guessing
 *      "code" when it was a URL wastes one click; guessing "url" when it was
 *      a line of code sends a request to whatever that line happened to
 *      resemble.
 *
 * The classification is reported back in the result, so a reading always says
 * which mode it chose and the visitor can override it by picking the tab.
 */
final class Subject
{
    const URL  = 'url';
    const REPO = 'repo';
    const CODE = 'code';
    const GIT  = 'git';

    /**
     * The mode that best fits this input.
     *
     * Always returns one of the four constants; CODE is the fallback, because
     * every input is at minimum a piece of text that can be read as text.
     */
    public static function classify(string $input): string
    {
        $trimmed = trim(str_replace(array("\r\n", "\r"), "\n", $input));
        if ($trimmed === '') {
            return self::CODE;
        }

        if (self::looksLikeGitLog($trimmed)) {
            return self::GIT;
        }

        // The single-line shapes. A paste with a newline in it is a document,
        // not an address, even when its first line happens to be one.
        if (strpos($trimmed, "\n") === false && strlen($trimmed) <= 400) {
            if (self::looksLikeRepo($trimmed)) {
                return self::REPO;
            }
            if (self::looksLikeUrl($trimmed)) {
                return self::URL;
            }
        }

        return self::CODE;
    }

    /**
     * How the choice should be described back to the person who made it.
     *
     * Written as a sentence about the input rather than a mode name, because
     * "read as a repository" is checkable by the reader and "REPO" is not.
     */
    public static function describe(string $mode): string
    {
        switch ($mode) {
            case self::URL:  return 'That looked like an address, so it was fetched and read as a live page.';
            case self::REPO: return 'That looked like a GitHub repository, so its history, tree and a few files were read.';
            case self::GIT:  return 'That looked like the output of git log, so it was read as a repository history.';
            default:         return 'That did not look like an address, a repository or a git log, so it was read as source.';
        }
    }

    // ------------------------------------------------------------- the shapes

    /**
     * A git log announces itself in one of three formats, and all three carry
     * a marker nothing else does: a commit hash in a fixed position.
     */
    private static function looksLikeGitLog(string $s): bool
    {
        // The documented format: hash|epoch|author|subject.
        if (preg_match('~^\s*[0-9a-f]{6,40}\|\d{6,}\|~mi', $s)) {
            return true;
        }
        // Default `git log`: a commit line, and an Author or Date under it.
        if (preg_match('~^commit\s+[0-9a-f]{7,40}\s*$~mi', $s)
            && preg_match('~^(Author|Date):\s~mi', $s)) {
            return true;
        }
        // `git log --oneline`: several lines of "hash subject" and nothing else.
        $lines = array_values(array_filter(array_map('trim', explode("\n", $s)), 'strlen'));
        if (count($lines) >= 3) {
            $oneline = 0;
            foreach ($lines as $line) {
                if (preg_match('~^[0-9a-f]{7,40}\s+\S~i', $line)) {
                    $oneline++;
                }
            }
            if ($oneline >= 3 && $oneline / count($lines) >= 0.8) {
                return true;
            }
        }
        return false;
    }

    /**
     * A GitHub repository, as a link to one or as the owner/name people say
     * out loud.
     *
     * Deliberately narrow. "src/main.js" is owner/name shaped and is a file
     * path; so is "and/or" in a sentence fragment. The owner half therefore
     * has to satisfy GitHub's own rule — alphanumerics and hyphens, no dots,
     * no underscores — which throws out most paths and all prose.
     */
    private static function looksLikeRepo(string $s): bool
    {
        $s = rtrim($s, '/');

        // Any github.com address that names an owner and a repository. A link
        // to a file inside one still names the repository it lives in.
        if (preg_match('~^(?:https?://)?(?:www\.)?github\.com/([^/\s]+)/([^/\s?#]+)~i', $s, $m)) {
            return self::validOwner($m[1]) && self::validRepo($m[2]);
        }
        if (preg_match('~^git@github\.com:([^/\s]+)/([^/\s]+?)(?:\.git)?$~i', $s, $m)) {
            return self::validOwner($m[1]) && self::validRepo($m[2]);
        }

        // Bare owner/name. Refused when either half carries a dot, because
        // that is how "example.com/pricing" and "src/app.js" get in.
        if (preg_match('~^([A-Za-z0-9][A-Za-z0-9-]{0,38})/([A-Za-z0-9._-]{1,100})$~', $s, $m)) {
            if (strpos($m[2], '.') !== false) {
                return false;
            }
            return self::validOwner($m[1]) && self::validRepo($m[2]);
        }

        return false;
    }

    /**
     * An address, either written out or in the shorthand everybody types.
     *
     * The bare-domain branch is the one that has to be careful: a public
     * suffix is what separates "example.com" from "Math.max" and from
     * "config.json". Requiring a two-to-twenty-four letter final label, and
     * refusing anything with a character an address cannot contain, gets
     * there without shipping a suffix list this project has no way to update.
     */
    private static function looksLikeUrl(string $s): bool
    {
        if (preg_match('~^https?://\S+$~i', $s)) {
            return (bool) filter_var($s, FILTER_VALIDATE_URL);
        }

        // Anything that is plainly not an address: brackets, quotes, operators.
        if (preg_match('~[\s<>{}()\[\]"\'`;=,\\\\]~', $s)) {
            return false;
        }

        // host[/path], where the host ends in a letters-only public suffix.
        if (!preg_match('~^([a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*\.([a-z]{2,24}))(?::\d{1,5})?(?:/.*)?$~i', $s, $m)) {
            return false;
        }

        // A file extension is not a domain. This is what keeps app.js, main.py
        // and README.md out, all of which are otherwise host-shaped.
        $suffix = strtolower($m[2]);
        $notDomains = array(
            'js', 'ts', 'jsx', 'tsx', 'mjs', 'cjs', 'py', 'rb', 'php', 'go', 'rs', 'java',
            'kt', 'swift', 'cs', 'c', 'h', 'cpp', 'hpp', 'css', 'scss', 'less', 'html',
            'htm', 'json', 'yml', 'yaml', 'toml', 'ini', 'lock', 'md', 'txt', 'sql',
            'sh', 'bat', 'exe', 'zip', 'png', 'jpg', 'gif', 'svg', 'pdf', 'log', 'env',
        );
        if (in_array($suffix, $notDomains, true) && strpos($s, '/') === false) {
            return false;
        }

        return true;
    }

    private static function validOwner(string $owner): bool
    {
        return (bool) preg_match('~^[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})$~', $owner);
    }

    private static function validRepo(string $repo): bool
    {
        $repo = preg_replace('~\.git$~i', '', $repo);
        return $repo !== '.' && $repo !== '..'
            && (bool) preg_match('~^[A-Za-z0-9._-]{1,100}$~', (string) $repo);
    }
}
