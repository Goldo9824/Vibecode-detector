<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/AdminAuth.php';
require_once dirname(__DIR__) . '/lib/GitHub.php';
require_once dirname(__DIR__) . '/lib/GitHubLog.php';
require_once dirname(__DIR__) . '/lib/Chart.php';
require_once dirname(__DIR__) . '/lib/AdminUi.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');

AdminAuth::requireLogin();

/**
 * What repository mode costs, and where the ceiling is.
 *
 * GitHub gives an unauthenticated caller sixty API requests an hour per
 * address, and on shared hosting that address is this whole site. One
 * repository read spends up to GitHub::MAX_REQUESTS of them, so the practical
 * question — how many repositories can this site read in an hour before people
 * start seeing "GitHub is rate-limiting this server" — has an answer, and until
 * now nothing recorded enough to work it out. This page is that answer.
 */

$ranges = array(1, 7, 30, 90);
$days = isset($_GET['days']) ? (int) $_GET['days'] : 7;
if (!in_array($days, $ranges, true)) {
    $days = 7;
}

$pdo = Db::connect();
$error = '';
$summary = array('requests' => 0, 'repos' => 0, 'ok' => 0, 'blocked' => 0,
                 'missing' => 0, 'errors' => 0, 'lowest' => null, 'last_block' => null);
$daily = array();
$hourly = array();
$runs = array();
$ceiling = array('blocks' => 0, 'typical' => null, 'earliest' => null,
                 'latest' => null, 'clean' => null, 'cleanHours' => 0);
$byHour = array();
$endpoints = array();
$repos = array();
$blocks = array();
$recent = array();
$reposEver = 0;
$pruned = 0;

if ($pdo === null) {
    $error = 'No database configured, so nothing is being recorded. See docs/ADMIN.md.';
} else {
    try {
        Db::ensureSchema($pdo);
        $pruned = GitHubLog::prune($pdo);

        $summary   = GitHubLog::summary($pdo, $days);
        $daily     = GitHubLog::daily($pdo, $days);
        // A day of hours is 24 columns and a quarter is 2,160, which is not a
        // chart. The hour-by-hour view is the last week at most; everything
        // longer is read off the daily one above it.
        $hourly    = GitHubLog::hourly($pdo, min($days, 7));
        $runs      = GitHubLog::runsBeforeBlock($pdo, $days);
        $ceiling   = GitHubLog::ceiling($runs, $hourly);
        $byHour    = GitHubLog::byHour($pdo, $days);
        $endpoints = GitHubLog::byEndpoint($pdo, $days);
        $repos     = GitHubLog::topRepos($pdo, $days, 25);
        $blocks    = GitHubLog::recentBlocks($pdo, $days, 15);
        $recent    = GitHubLog::recent($pdo, $days, 30);
        $reposEver = GitHubLog::repoTotal($pdo);
    } catch (Throwable $e) {
        $error = 'Connected to the database, but a query failed: ' . $e->getMessage();
    }
}

// What this installation is actually allowed, which changes what every figure
// on the page means: sixty an hour is roughly seven repository reads, five
// thousand is more than this site will ever want.
$hasToken = GitHub::token() !== '';
$allowance = $hasToken ? GitHubLog::TOKEN_HOURLY : GitHubLog::ANON_HOURLY;
$scansPerHour = (int) floor($allowance / GitHub::MAX_REQUESTS);

$repoMax = 0;
foreach ($repos as $row) {
    $repoMax = max($repoMax, $row['requests']);
}
$endpointMax = 0;
foreach ($endpoints as $row) {
    $endpointMax = max($endpointMax, $row['n']);
}

$outcomeSlices = array();
foreach (array('ok' => 'Answered', 'missing' => 'No such repo', 'blocked' => 'Refused', 'errors' => 'Failed') as $key => $label) {
    if ($summary[$key] > 0) {
        $outcomeSlices[] = array('label' => $label, 'n' => (int) $summary[$key]);
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>GitHub — Vibe Code Detector</title>
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
<link rel="icon" href="../assets/img/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="../assets/css/site.css?v=<?= h(VCD_VERSION) ?>">
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= h(VCD_VERSION) ?>">
</head>
<body>
<main class="shell admin-shell wide">
  <div class="admin-head">
    <h1>GitHub</h1>
    <a href="index.php">&larr; Back to admin</a>
  </div>

  <?= AdminUi::nav('github') ?>

  <?php if ($error !== ''): ?>
    <p class="error-box"><?= h($error) ?></p>
  <?php endif; ?>

  <?php if ($pdo !== null): ?>

    <div class="range-bar">
      <span class="range-label">Window</span>
      <?php foreach ($ranges as $r): ?>
        <a class="range<?= $r === $days ? ' is-on' : '' ?>" href="?days=<?= (int) $r ?>">
          <?= $r === 1 ? 'Today' : (int) $r . ' days' ?>
        </a>
      <?php endforeach; ?>
    </div>

    <section class="admin-section">
      <h2>The allowance</h2>
      <div class="stat-row">
        <div class="stat">
          <span class="n"><?= AdminUi::count($allowance) ?></span>
          <span class="l">Requests per hour</span>
        </div>
        <div class="stat">
          <span class="n"><?= AdminUi::count($scansPerHour) ?></span>
          <span class="l">Repo reads that buys</span>
        </div>
        <div class="stat">
          <span class="n"><?= (int) GitHub::MAX_REQUESTS ?></span>
          <span class="l">Requests per read, max</span>
        </div>
        <div class="stat<?= $summary['lowest'] !== null && $summary['lowest'] <= 5 ? ' is-warn' : '' ?>">
          <span class="n"><?= $summary['lowest'] !== null ? AdminUi::count((int) $summary['lowest']) : '—' ?></span>
          <span class="l">Lowest it has been</span>
        </div>
      </div>

      <p class="hint">
        <?php if ($hasToken): ?>
          A read-only token is configured, so this server has GitHub's authenticated
          allowance of <?= AdminUi::count(GitHubLog::TOKEN_HOURLY) ?> requests an hour.
          Being blocked at that rate would mean something other than ordinary use.
        <?php else: ?>
          No token is configured, so this server has GitHub's anonymous allowance of
          <?= AdminUi::count(GitHubLog::ANON_HOURLY) ?> requests an hour &mdash; per
          <em>address</em>, which on shared hosting is every visitor put together.
          Dropping a read-only token into <code>data/github-config.php</code> raises it
          to <?= AdminUi::count(GitHubLog::TOKEN_HOURLY) ?>.
        <?php endif; ?>
        Source files do not spend from it: those come off <code>raw.githubusercontent.com</code>,
        which is free and therefore does not appear anywhere on this page.
      </p>
    </section>

    <section class="admin-section">
      <h2>How far it gets in an hour</h2>

      <div class="stat-row">
        <div class="stat<?= $ceiling['blocks'] > 0 ? ' is-warn' : ' is-good' ?>">
          <span class="n"><?= AdminUi::count((int) $ceiling['blocks']) ?></span>
          <span class="l">Hours that ended blocked</span>
        </div>
        <div class="stat">
          <span class="n"><?= $ceiling['typical'] !== null ? AdminUi::count((int) $ceiling['typical']) : '—' ?></span>
          <span class="l">Repos before a block, typical</span>
        </div>
        <div class="stat">
          <span class="n"><?= $ceiling['earliest'] !== null ? AdminUi::count((int) $ceiling['earliest']) : '—' ?></span>
          <span class="l">Earliest it was stopped</span>
        </div>
        <div class="stat">
          <span class="n"><?= $ceiling['clean'] !== null ? AdminUi::count((int) $ceiling['clean']) : '—' ?></span>
          <span class="l">Most in an hour, unblocked</span>
        </div>
      </div>

      <?php if ($ceiling['blocks'] > 0): ?>
        <p class="hint">
          Read as a pair. In the <?= AdminUi::count((int) $ceiling['blocks']) ?>
          hour<?= $ceiling['blocks'] === 1 ? '' : 's' ?> that ended in a refusal, this
          site had got through
          <?= $ceiling['earliest'] !== null ? AdminUi::count((int) $ceiling['earliest']) : '0' ?>
          to <?= $ceiling['latest'] !== null ? AdminUi::count((int) $ceiling['latest']) : '0' ?>
          repositories first &mdash; typically <?= AdminUi::count((int) $ceiling['typical']) ?>.
          The spread is not noise: an hour that opens on an allowance the previous
          one had already half spent runs out sooner, and the ceiling is properly a
          statement about the clock hour rather than about this site's own counting.
          <?php if ($ceiling['clean'] !== null): ?>
            The busiest hour that was <em>not</em> stopped reached
            <?= AdminUi::count((int) $ceiling['clean']) ?>.
          <?php endif; ?>
        </p>
      <?php else: ?>
        <p class="hint">
          Nothing has been refused in this window.
          <?php if ($ceiling['clean'] !== null && $ceiling['cleanHours'] > 0): ?>
            The busiest hour reached <?= AdminUi::count((int) $ceiling['clean']) ?>
            repositor<?= $ceiling['clean'] === 1 ? 'y' : 'ies' ?> across
            <?= AdminUi::count((int) $ceiling['cleanHours']) ?> active
            hour<?= $ceiling['cleanHours'] === 1 ? '' : 's' ?>, which is the most that
            can be said about the ceiling until something actually hits it.
          <?php else: ?>
            No repository has been read in this window either, so there is nothing
            to say about the ceiling yet.
          <?php endif; ?>
        </p>
      <?php endif; ?>

      <?php $hourChart = Chart::series(GitHubLog::hourColumns($hourly), 'Repositories read per hour', 'repositories'); ?>
      <?php if ($hourChart !== ''): ?>
        <h3 class="admin-sub">Hour by hour</h3>
        <figure class="chart-figure">
          <?= $hourChart ?>
          <figcaption>
            <span class="key key-bar"></span> repositories read in that hour
            <span class="key key-hit"></span> the hour was refused at some point
            &middot; quiet hours are left out rather than drawn as zero
          </figcaption>
        </figure>
      <?php endif; ?>

      <?php if (!empty($runs)): ?>
        <h3 class="admin-sub">Every hour that ran out</h3>
        <div class="table-scroll">
          <table class="admin-table">
            <thead><tr><th>Hour, UTC</th><th class="num">Repos first</th><th class="num">Requests first</th></tr></thead>
            <tbody>
              <?php foreach (array_reverse($runs) as $run): ?>
                <tr>
                  <td class="when"><?= h(AdminUi::when($run['hour'] . ':00:00')) ?></td>
                  <td class="num"><?= AdminUi::count($run['repos']) ?></td>
                  <td class="num"><?= AdminUi::count($run['requests']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p class="hint">Counted up to the first refusal in each hour and no further:
           everything after it is a request that was answered instantly with "no", and
           counting those would put the ceiling higher than it is.</p>
      <?php endif; ?>
    </section>

    <section class="admin-section">
      <h2>Requests, last <?= (int) $days ?> day<?= $days === 1 ? '' : 's' ?></h2>
      <div class="stat-row">
        <div class="stat"><span class="n"><?= AdminUi::count((int) $summary['requests']) ?></span><span class="l">API requests</span></div>
        <div class="stat"><span class="n"><?= AdminUi::count((int) $summary['repos']) ?></span><span class="l">Repositories</span></div>
        <div class="stat<?= $summary['blocked'] > 0 ? ' is-warn' : '' ?>"><span class="n"><?= AdminUi::count((int) $summary['blocked']) ?></span><span class="l">Refused</span></div>
        <div class="stat"><span class="n"><?= AdminUi::count((int) $summary['missing']) ?></span><span class="l">No such repo</span></div>
        <div class="stat">
          <span class="n is-date"><?= h($summary['last_block'] !== null ? AdminUi::ago($summary['last_block']) : 'never') ?></span>
          <span class="l">Last refusal</span>
        </div>
      </div>

      <?php
        $dailyChart = Chart::stack(
            $daily,
            array('ok', 'missing', 'blocked', 'errors'),
            array('ok' => 'answered', 'missing' => 'no such repo', 'blocked' => 'refused', 'errors' => 'failed'),
            'GitHub API requests per day'
        );
      ?>
      <?php if ($dailyChart !== '' && $summary['requests'] > 0): ?>
        <figure class="chart-figure">
          <?= $dailyChart ?>
          <figcaption>
            <?= Chart::key(0, 'answered') ?>
            <?= Chart::key(1, 'no such repo') ?>
            <?= Chart::key(2, 'refused') ?>
            <?= Chart::key(3, 'failed') ?>
          </figcaption>
        </figure>
      <?php else: ?>
        <p class="hint">No repository has been read in this window. Rows appear here from
           the moment somebody runs the repository tab &mdash; nothing else in the site
           talks to GitHub.</p>
      <?php endif; ?>

      <?php $shareChart = Chart::share($outcomeSlices, 'requests'); ?>
      <?php if ($shareChart !== ''): ?>
        <h3 class="admin-sub">What came back</h3>
        <figure class="chart-figure"><?= $shareChart ?></figure>
      <?php endif; ?>
    </section>

    <?php if ($summary['requests'] > 0): ?>
      <section class="admin-section">
        <h2>When the allowance goes, UTC</h2>
        <?php $clockChart = Chart::hours($byHour, 'GitHub requests by hour of day, UTC', 'requests'); ?>
        <?php if ($clockChart !== ''): ?>
          <figure class="chart-figure">
            <?= $clockChart ?>
            <figcaption>Requests by hour of day, added up across the window &mdash; not the
              same chart as the one above, which is one column per actual hour.</figcaption>
          </figure>
        <?php else: ?>
          <p class="hint">Not enough traffic yet to show a shape.</p>
        <?php endif; ?>
      </section>

      <section class="admin-section">
        <h2>What the allowance is spent on</h2>
        <div class="table-scroll">
          <table class="admin-table">
            <thead><tr><th>Endpoint</th><th class="num">Requests</th><th class="num">Share</th></tr></thead>
            <tbody>
              <?php foreach ($endpoints as $row): ?>
                <tr>
                  <td class="with-bar">
                    <span class="bar" style="width:<?= h(Chart::barWidth($row['n'], $endpointMax)) ?>"></span>
                    <span class="bar-label"><?= h($row['label']) ?></span>
                  </td>
                  <td class="num"><?= AdminUi::count($row['n']) ?></td>
                  <td class="num"><?= $summary['requests'] > 0 ? (int) round($row['n'] / $summary['requests'] * 100) : 0 ?>%</td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p class="hint">One repository read is the repository itself, its recent commits,
           its opening commit and its file tree &mdash; four, plus a page of history for
           a long one. Source files are not here because they cost nothing.</p>
      </section>
    <?php endif; ?>

    <section class="admin-section">
      <h2>Repositories searched</h2>
      <?php if (empty($repos)): ?>
        <p class="hint">None in this window.
          <?php if ($reposEver > 0): ?>
            <?= AdminUi::count($reposEver) ?> have been searched altogether &mdash;
            try a longer window.
          <?php endif; ?>
        </p>
      <?php else: ?>
        <div class="table-scroll">
          <table class="admin-table">
            <thead>
              <tr>
                <th>Repository</th>
                <th class="num">Requests</th>
                <th class="num">Refused</th>
                <th>Last read</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($repos as $row): ?>
                <tr>
                  <td class="with-bar">
                    <span class="bar" style="width:<?= h(Chart::barWidth($row['requests'], $repoMax)) ?>"></span>
                    <span class="bar-label">
                      <?php
                        // Encoded segment by segment rather than whole, so the
                        // slash survives and everything else that could end up
                        // in a repository name does not.
                        $repoPath = implode('/', array_map('rawurlencode', explode('/', $row['repo'])));
                      ?>
                      <a href="https://github.com/<?= h($repoPath) ?>"
                         rel="noopener noreferrer"><?= h($row['repo']) ?></a>
                      <?php if ($row['missing'] > 0 && $row['requests'] === $row['missing']): ?>
                        <span class="badge is-missing">gone</span>
                      <?php endif; ?>
                    </span>
                  </td>
                  <td class="num"><?= AdminUi::count($row['requests']) ?></td>
                  <td class="num"><?= $row['blocked'] > 0 ? AdminUi::count($row['blocked']) : '—' ?></td>
                  <td class="when" title="<?= h(AdminUi::when($row['last_seen'])) ?>"><?= h(AdminUi::ago($row['last_seen'])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p class="hint"><?= AdminUi::count($reposEver) ?> distinct repositories have been
           searched altogether. A repository name is recorded the same way a website
           address is, and for the same reason &mdash; it is what was asked about. Nothing
           about who asked is recorded anywhere.</p>
      <?php endif; ?>
    </section>

    <?php if (!empty($blocks)): ?>
      <section class="admin-section">
        <h2>Every refusal</h2>
        <div class="table-scroll">
          <table class="admin-table">
            <thead>
              <tr><th>When</th><th>Repository</th><th>Asking for</th><th class="num">Status</th><th class="num">Left</th><th>Resets</th></tr>
            </thead>
            <tbody>
              <?php foreach ($blocks as $row): ?>
                <tr>
                  <td class="when" title="<?= h(AdminUi::when($row['created_at'])) ?>"><?= h(AdminUi::ago($row['created_at'])) ?></td>
                  <td class="wrap-any"><?= h($row['repo'] !== '' ? $row['repo'] : '—') ?></td>
                  <td><?= h($row['endpoint']) ?></td>
                  <td class="num"><?= (int) $row['status'] ?></td>
                  <td class="num"><?= $row['remaining'] !== null ? (int) $row['remaining'] : '—' ?></td>
                  <td class="when"><?= h($row['reset_at'] !== null ? AdminUi::when($row['reset_at']) : '—') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p class="hint">A refusal with allowance still left is GitHub blocking that
           repository rather than this server: the two arrive as the same 403 and are
           only told apart by the number in the "left" column.</p>
      </section>
    <?php endif; ?>

    <?php if (!empty($recent)): ?>
      <section class="admin-section">
        <h2>The log itself</h2>
        <div class="table-scroll">
          <table class="admin-table">
            <thead><tr><th>When</th><th>Repository</th><th>Asking for</th><th>Answer</th><th class="num">Left</th></tr></thead>
            <tbody>
              <?php foreach ($recent as $row): ?>
                <tr>
                  <td class="when" title="<?= h(AdminUi::when($row['created_at'])) ?>"><?= h(AdminUi::ago($row['created_at'])) ?></td>
                  <td class="wrap-any"><?= h($row['repo'] !== '' ? $row['repo'] : '—') ?></td>
                  <td><?= h($row['endpoint']) ?></td>
                  <td>
                    <span class="badge is-<?= h($row['outcome']) ?>"><?= h($row['outcome']) ?></span>
                    <?= $row['status'] > 0 ? (int) $row['status'] : 'no answer' ?>
                  </td>
                  <td class="num"><?= $row['remaining'] !== null ? (int) $row['remaining'] : '—' ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
    <?php endif; ?>

    <section class="admin-section">
      <h2>What is being kept</h2>
      <p class="hint">
        One row per request this site makes to GitHub's API: the repository as
        <code>owner/name</code>, which endpoint was asked for, the HTTP status, what
        GitHub said was left of the hourly allowance, and a timestamp. Nothing about
        who asked &mdash; no address, no cookie, no session &mdash; and nothing about
        what came back beyond its status. Rows older than
        <?= (int) Db::option('github_retention_days', GitHubLog::DEFAULT_RETENTION_DAYS) ?>
        days are deleted when this page loads<?= $pruned > 0 ? ' — ' . Num::exact($pruned) . ' just now' : '' ?>.
      </p>
    </section>

  <?php endif; ?>
</main>
</body>
</html>
