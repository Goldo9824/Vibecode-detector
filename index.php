<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/Brand.php';
require_once __DIR__ . '/lib/VisitLog.php';
require_once __DIR__ . '/lib/Seo.php';
require_once __DIR__ . '/lib/Num.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Counted only when this installation has a database configured, and switched
// off with 'log_visits' => false in data/db-config.php. Called here rather
// than from bootstrap so that every page which counts a visit says so in its
// own first lines — see lib/VisitLog.php for what a row does and does not hold.
VisitLog::record('/');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?= Seo::head(array(
  'title'       => 'Vibe Code Detector — how likely is this AI-generated?',
  'description' => 'Paste a URL, a public GitHub repository, some source code or a git log, and get a percentage reading of how likely it is to be AI-generated — with every piece of evidence shown. Free, no account, open source.',
  'path'        => '/',
  'socialTitle' => 'Vibe Code Detector',
  'socialDescription' => 'A percentage reading of how likely a page or a snippet is to be AI-generated — with the evidence shown and the limits stated.',
  'jsonLd'      => array(
    Seo::siteSchema(),
    // What the thing is, in the vocabulary a search engine already has a slot
    // for. The price is stated because "free" is the question people search
    // with, and a tool that says nothing is assumed to want a card number.
    array(
      '@context'    => 'https://schema.org',
      '@type'       => 'WebApplication',
      'name'        => 'Vibe Code Detector',
      'url'         => Seo::url('/'),
      'applicationCategory' => 'DeveloperApplication',
      'operatingSystem'     => 'Any',
      'browserRequirements' => 'Requires JavaScript',
      'description' => 'Reads a live page, a public GitHub repository, pasted source, or a git log for the tells of AI generation and returns a percentage with the evidence behind it.',
      'isAccessibleForFree' => true,
      'offers'      => array('@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'EUR'),
      'license'     => VCD_REPO_URL . '/blob/main/LICENSE',
      'codeRepository' => VCD_REPO_URL,
    ),
  ),
)) ?>
<link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/css/site.css?v=<?= h(VCD_VERSION) ?>">
</head>
<body>

<header class="masthead-wrap">
  <div class="shell masthead">
    <a class="brand" href="./">
      <?= Brand::markSvg(34, 'currentColor', 'var(--red)', 'aria-hidden="true"') ?>
      <span class="brand-name">Vibe Code Detector<span class="brand-tag">reads the tells, shows its working</span></span>
    </a>
    <nav>
      <a href="method.php">Method</a>
      <a href="signs.php">Visual signs</a>
      <a href="catalogue.php">Signals</a>
      <a href="verify.php">Verify</a>
      <a href="<?= h(VCD_REPO_URL) ?>" rel="noopener">Source</a>
    </nav>
  </div>
</header>

<main>
  <div class="shell shell-tool">

    <section class="hero">
      <h1>Was this written by a person, or generated?</h1>
      <p>Give it a URL, a public GitHub repository, some source, or a <code>git log</code>. It reads the tells a reviewer would and returns a percentage &mdash; with every piece of evidence behind it.</p>
      <p class="disclaimer">It will not prove anything. Automated detection of AI-generated source performs near chance in peer-reviewed benchmarks, so this shows its working and expects you to read it. Do not accuse anyone of anything on the strength of a number. <a href="method.php#provenance">This site is half vibecoded too</a>, and its own detector cannot tell which half.</p>
    </section>

    <div class="panel" id="analyzer">
      <div class="tabs" role="tablist" aria-label="What to analyse">
        <button class="tab" role="tab" id="tab-url" aria-controls="panel-url" aria-selected="true" type="button">Live page</button>
        <button class="tab" role="tab" id="tab-repo" aria-controls="panel-repo" aria-selected="false" type="button">GitHub repo</button>
        <button class="tab" role="tab" id="tab-code" aria-controls="panel-code" aria-selected="false" type="button">Paste code</button>
        <button class="tab" role="tab" id="tab-git" aria-controls="panel-git" aria-selected="false" type="button">Git history</button>
      </div>

      <div class="tabpanel" role="tabpanel" id="panel-url" aria-labelledby="tab-url">
        <form id="form-url" novalidate>
          <label class="field" for="url">Address of the page to read</label>
          <input type="url" id="url" name="url" placeholder="example.com" autocomplete="url" spellcheck="false">
          <label class="check" for="crawl">
            <input type="checkbox" id="crawl" name="crawl" value="1">
            <span><strong>Read the whole site</strong> &mdash; follows links from this page and reads as many as it can manage in about twenty seconds, up to fifty, then compares them against each other. Takes a while, and finds things one page cannot.</span>
          </label>
          <div class="actions">
            <button class="btn" type="submit">Analyse page</button>
            <span class="spinner" id="spin-url" hidden>reading&hellip;</span>
          </div>
          <p class="hint">Fetches the page and up to four of its own stylesheets and scripts. Nothing else is requested, and the page itself is never stored. Whole-site reads honour robots.txt, stop at fifty pages, and stop sooner if the site is slow &mdash; the report says how many it managed.</p>
        </form>
      </div>

      <div class="tabpanel" role="tabpanel" id="panel-repo" aria-labelledby="tab-repo" hidden>
        <form id="form-repo" novalidate>
          <label class="field" for="repo">A public GitHub repository</label>
          <input type="text" id="repo" name="repo" placeholder="owner/name, or a github.com link" autocomplete="off" spellcheck="false">
          <div class="actions">
            <button class="btn" type="submit">Read the repository</button>
            <span class="spinner" id="spin-repo" hidden>reading&hellip;</span>
          </div>
          <p class="hint">Reads three things at once: the commit history, the file tree, and a few source files in full. This is the strongest reading available here &mdash; the history tab needs you to have the repository checked out, and this does not. Public repositories only; nothing private is reachable and nothing is stored but the name.</p>
        </form>
      </div>

      <div class="tabpanel" role="tabpanel" id="panel-code" aria-labelledby="tab-code" hidden>
        <form id="form-code" novalidate>
          <label class="field" for="code">Source to read &mdash; any language, the longer the better</label>
          <textarea id="code" name="code" spellcheck="false" placeholder="Paste a file here. Several hundred lines gives a far steadier reading than twenty."></textarea>
          <div class="actions">
            <button class="btn" type="submit">Analyse code</button>
            <span class="spinner" id="spin-code" hidden>reading&hellip;</span>
          </div>
          <p class="hint">Sent to the server, read once, and discarded. It is never written to disk or logged.</p>
        </form>
      </div>

      <div class="tabpanel" role="tabpanel" id="panel-git" aria-labelledby="tab-git" hidden>
        <form id="form-git" novalidate>
          <label class="field" for="gitlog">Output of <code>git log</code> &mdash; the strongest signal there is</label>
          <p class="hint hint-top">Run this in the repository and paste everything it prints:</p>
          <pre class="command" id="git-command"><code>git log --numstat --pretty=format:'%H|%at|%an|%s'</code></pre>
          <textarea id="gitlog" name="log" spellcheck="false" placeholder="Paste the output here. Plain `git log` and `git log --oneline` also work, with less to go on."></textarea>
          <div class="actions">
            <button class="btn" type="submit">Analyse history</button>
            <span class="spinner" id="spin-git" hidden>reading&hellip;</span>
          </div>
          <p class="hint">Nothing leaves your machine except the log itself, and it is read once and discarded. Commit messages can carry private information &mdash; check what you are pasting.</p>
        </form>
      </div>

      <div id="error" class="error" hidden role="alert"></div>
    </div>

    <section id="results" hidden aria-live="polite">
      <div class="panel">
        <div class="subject" id="r-subject"></div>
        <div class="meter"><div class="meter-fill" id="r-meter" style="width:0"></div></div>
        <div class="meter-scale"><span>hand-written</span><span>inconclusive</span><span>AI-generated</span></div>

        <div class="verdict">
          <div class="score" id="r-score">0<span>%</span></div>
          <div>
            <p class="eyebrow">Verdict</p>
            <h2 id="r-verdict"></h2>
            <p id="r-summary"></p>
            <div class="meta-row">
              <span id="r-confidence"></span>
              <span id="r-counts"></span>
            </div>
          </div>
        </div>

        <div class="evidence">
          <h3>What it found</h3>
          <div id="r-signals"></div>
        </div>

        <div class="pages" id="r-pages" hidden>
          <p class="eyebrow" id="r-pages-title">Pages read</p>
          <ol id="r-pages-list"></ol>
        </div>

        <div class="trend" id="r-trend" hidden>
          <p class="eyebrow">Shape of the history</p>
          <svg id="r-trend-svg" class="trend-svg" viewBox="0 0 400 90" preserveAspectRatio="none" role="img" aria-label="Lines added and removed per section of the history, oldest to newest"></svg>
          <div class="trend-scale"><span>oldest</span><span>newest</span></div>
        </div>

        <div class="notes">
          <p class="eyebrow">Read this before repeating the number</p>
          <ul id="r-notes"></ul>
        </div>

        <div class="cert-bar">
          <a class="btn" id="r-cert" href="#">Download certificate (PDF)</a>
          <p>A signed one-page PDF stating the reading, the evidence and the caveats. Anyone can check it at <a href="verify.php">/verify</a>.</p>
        </div>
      </div>
    </section>

    <nav class="signposts" aria-label="The rest of the site">
      <a href="method.php">How the number is arrived at</a>
      <a href="method.php#limits">Where this is wrong</a>
      <a href="signs.php">The visual field guide</a>
      <a href="catalogue.php">All <?= h(Num::exact(count(Catalog::all()))) ?> signals</a>
      <a href="method.php#provenance">Half of this site was vibecoded</a>
    </nav>

  </div>
</main>

<footer class="colophon">
  <div class="shell colophon-grid">
    <div>
      <p><strong>Vibe Code Detector</strong> is free and open source. No account, no cookies, no third-party analytics, nothing loaded from anyone else&rsquo;s server. Pasted code and git history are read once and discarded, never stored; live-page and whole-site checks record only the address and mode analysed, for operator visibility, never the page content. Page views are counted &mdash; the path, the referring site, and a token that is salted with today&rsquo;s date so that it counts visitors today and cannot recognise anyone tomorrow. No address, no cookie, no session.</p>
      <p>Built to run on ordinary shared hosting: plain PHP, no dependencies, no build step.</p>
      <p><a href="method.php#provenance">Half of this site was vibecoded</a>, and its own detector cannot tell which half.</p>
    </div>
    <div class="links">
      <a href="<?= h(VCD_REPO_URL) ?>" rel="noopener">Source and issue tracker &rarr;</a>
      <a href="<?= h(VCD_REPO_URL) ?>/issues/new?template=false_positive.yml" rel="noopener">Report a wrong reading &rarr;</a>
      <a href="signs.php">The visual field guide &rarr;</a>
      <a href="catalogue.php">The signal catalogue &rarr;</a>
      <a href="verify.php">Verify a certificate &rarr;</a>
      <span class="studio">A Landfall studio product</span>
    </div>
  </div>
</footer>

<script src="assets/js/app.js?v=<?= h(VCD_VERSION) ?>" defer></script>
</body>
</html>
