<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/AdminAuth.php';
require_once dirname(__DIR__) . '/lib/UsageLog.php';
require_once dirname(__DIR__) . '/lib/AdminUi.php';
require_once dirname(__DIR__) . '/lib/Pager.php';
require_once dirname(__DIR__) . '/lib/Chart.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');

AdminAuth::requireLogin();

/**
 * Every website that has ever been analysed, forty at a time.
 *
 * The panel's front page shows the twenty busiest of the last thirty days,
 * which answers "what is this being pointed at lately" and nothing else. This
 * page is the whole list: all time by default, searchable, and in whatever
 * order you ask for. Every one of those is in the query string rather than in
 * a session, so a view you want again is a URL you can keep.
 */

/** Windows offered. 0 is all time, which is what a page called "all websites" should open on. */
$ranges = array(0 => 'All time', 7 => '7 days', 30 => '30 days', 90 => '90 days');
$days = isset($_GET['days']) ? (int) $_GET['days'] : 0;
if (!array_key_exists($days, $ranges)) {
    $days = 0;
}

$sorts = UsageLog::sorts();
$sort = UsageLog::normaliseSort(isset($_GET['sort']) ? (string) $_GET['sort'] : 'recent');

$search = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
if (strlen($search) > 190) {
    $search = substr($search, 0, 190);
}

$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;

$pdo = Db::connect();
$error = '';
$rows = array();
$total = 0;
$analyses = 0;
$totalPages = 1;

if ($pdo === null) {
    $error = 'No database configured, so nothing is being recorded. See docs/ADMIN.md.';
} else {
    try {
        Db::ensureSchema($pdo);

        $total = UsageLog::hostTotal($pdo, $days, $search);
        $totalPages = Pager::totalPages($total, Pager::PER_PAGE);
        // A page number past the end is a stale bookmark, not an error: show
        // the last page that exists rather than an empty table.
        $page = Pager::clamp($page, $totalPages);

        $rows = UsageLog::hostPage(
            $pdo,
            $days,
            $search,
            $sort,
            Pager::PER_PAGE,
            Pager::offset($page, Pager::PER_PAGE)
        );
        foreach ($rows as $row) {
            $analyses += $row['n'];
        }
    } catch (Throwable $e) {
        $error = 'Connected to the database, but a query failed: ' . $e->getMessage();
    }
}

/** Query-string state shared by every link on this page, minus the page number. */
$state = array('q' => $search, 'sort' => $sort, 'days' => $days);
$defaults = array('sort' => 'recent', 'days' => 0);

$maxOnPage = 0;
foreach ($rows as $row) {
    $maxOnPage = max($maxOnPage, $row['n']);
}

$firstRow = $total === 0 ? 0 : Pager::offset($page, Pager::PER_PAGE) + 1;
$lastRow = Pager::offset($page, Pager::PER_PAGE) + count($rows);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Websites — Vibe Code Detector</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="../assets/img/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="../assets/css/site.css?v=<?= h(VCD_VERSION) ?>">
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= h(VCD_VERSION) ?>">
</head>
<body>
<main class="shell admin-shell wide">
  <div class="admin-head">
    <h1>Websites</h1>
    <a href="index.php">&larr; Back to admin</a>
  </div>

  <?php if ($error !== ''): ?>
    <p class="error-box"><?= h($error) ?></p>
  <?php endif; ?>

  <?php if ($pdo !== null): ?>

    <form method="get" class="search-form" role="search">
      <input type="hidden" name="sort" value="<?= h($sort) ?>">
      <input type="hidden" name="days" value="<?= (int) $days ?>">
      <label class="field" for="q">Search websites</label>
      <div class="search-row">
        <input type="search" id="q" name="q" value="<?= h($search) ?>"
               placeholder="part of a host name, e.g. example.com" maxlength="190" autocomplete="off">
        <button class="btn" type="submit">Search</button>
        <?php if ($search !== ''): ?>
          <a class="btn-quiet" href="<?= h('websites.php' . Pager::query(array('sort' => $sort, 'days' => $days), $defaults)) ?>">Clear</a>
        <?php endif; ?>
      </div>
    </form>

    <div class="range-bar">
      <span class="range-label">Window</span>
      <?php foreach ($ranges as $value => $label): ?>
        <a class="range<?= $value === $days ? ' is-on' : '' ?>"
           href="<?= h('websites.php' . Pager::query(array('q' => $search, 'sort' => $sort, 'days' => $value), $defaults)) ?>"><?= h($label) ?></a>
      <?php endforeach; ?>
    </div>

    <div class="range-bar">
      <span class="range-label">Order</span>
      <?php foreach ($sorts as $key => $def): ?>
        <a class="range<?= $key === $sort ? ' is-on' : '' ?>"
           href="<?= h('websites.php' . Pager::query(array('q' => $search, 'sort' => $key, 'days' => $days), $defaults)) ?>"><?= h($def['label']) ?></a>
      <?php endforeach; ?>
    </div>

    <section class="admin-section">
      <div class="stat-row">
        <div class="stat">
          <span class="n"><?= number_format($total) ?></span>
          <span class="l"><?= $search !== '' ? 'Websites matching' : 'Websites analysed' ?></span>
        </div>
        <div class="stat">
          <span class="n"><?= number_format($analyses) ?></span>
          <span class="l">Analyses on this page</span>
        </div>
        <div class="stat">
          <span class="n"><?= number_format($page) ?><span class="of"> / <?= number_format($totalPages) ?></span></span>
          <span class="l">Page</span>
        </div>
      </div>

      <?php if (empty($rows)): ?>
        <?php if ($search !== ''): ?>
          <p class="hint">Nothing matches “<?= h($search) ?>”<?= $days > 0 ? ' in the last ' . (int) $days . ' days' : '' ?>.
             <a href="<?= h('websites.php' . Pager::query(array('sort' => $sort), $defaults)) ?>">Show every website &rarr;</a></p>
        <?php else: ?>
          <p class="hint">No live-page or whole-site checks have been recorded yet. Pasted
             code and git history are not listed here — they carry no website to
             attribute an analysis to, and are never written down.</p>
        <?php endif; ?>
      <?php else: ?>
        <p class="hint">Showing <?= number_format($firstRow) ?>–<?= number_format($lastRow) ?>
           of <?= number_format($total) ?>, <?= h(strtolower((string) $sorts[$sort]['label'])) ?> first.</p>

        <div class="table-scroll">
          <table class="admin-table">
            <thead>
              <tr>
                <th>Website</th>
                <th class="num">Analyses</th>
                <th>First searched</th>
                <th>Last searched</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $row): ?>
                <tr>
                  <td class="with-bar">
                    <span class="bar" style="width:<?= h(Chart::barWidth($row['n'], $maxOnPage)) ?>"></span>
                    <span class="bar-label">
                      <a href="<?= h('website.php?host=' . rawurlencode($row['target_host']) . '&days=' . (int) $days) ?>"><?= h($row['target_host']) ?></a>
                    </span>
                  </td>
                  <td class="num"><?= number_format($row['n']) ?></td>
                  <td class="when"><?= h(AdminUi::day($row['first_seen'])) ?></td>
                  <td class="when" title="<?= h(AdminUi::when($row['last_seen'])) ?>"><?= h(AdminUi::ago($row['last_seen'])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <?= AdminUi::pagination($page, $totalPages, $state, $defaults, 'websites.php') ?>
      <?php endif; ?>
    </section>

    <section class="admin-section">
      <h2>What is in this list</h2>
      <p class="hint">One row per host that a live-page or whole-site check was pointed at,
         with the number of checks and when they happened. Pasted code and git history
         never appear: there is no website to attribute them to, and the content itself
         is not written down anywhere. Nothing here says who asked — that is not
         recorded. See <code>docs/ADMIN.md</code>.</p>
    </section>

  <?php endif; ?>
</main>
</body>
</html>
