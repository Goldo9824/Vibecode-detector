<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/Brand.php';
require_once __DIR__ . '/lib/VisitLog.php';
require_once __DIR__ . '/lib/Seo.php';
require_once __DIR__ . '/lib/ParamsControl.php';

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

    <section class="console" id="analyzer">

      <h1 class="console-title">Was this written by a person, or generated?</h1>

      <div class="segmented" role="tablist" aria-label="What to analyse">
        <button class="tab" role="tab" id="tab-auto" aria-controls="panel-auto" aria-selected="true" type="button">Auto</button>
        <button class="tab" role="tab" id="tab-url" aria-controls="panel-url" aria-selected="false" type="button">Live page</button>
        <button class="tab" role="tab" id="tab-repo" aria-controls="panel-repo" aria-selected="false" type="button">Repository</button>
        <button class="tab" role="tab" id="tab-code" aria-controls="panel-code" aria-selected="false" type="button">Source</button>
        <button class="tab" role="tab" id="tab-git" aria-controls="panel-git" aria-selected="false" type="button">History</button>
      </div>

      <div class="tabpanel" role="tabpanel" id="panel-auto" aria-labelledby="tab-auto">
        <form id="form-auto" novalidate>
          <label class="visually-hidden" for="input">Anything to read</label>
          <div class="entry entry-auto">
            <textarea id="input" name="input" rows="1" spellcheck="false" placeholder="Paste an address, a repo, some source, or a git log&hellip;"></textarea>
            <?= ParamsControl::render('auto', array(ParamsControl::pages(), ParamsControl::files())) ?>
            <button class="go" type="submit" aria-label="Read this">
              <span class="go-mark" aria-hidden="true">&rarr;</span>
              <span class="spinner" id="spin-auto" hidden>&middot;&middot;&middot;</span>
            </button>
          </div>
          <p class="reads" id="reads" aria-live="polite"><span class="reads-idle">It works out which of the four it is.</span></p>
        </form>
      </div>

      <div class="tabpanel" role="tabpanel" id="panel-url" aria-labelledby="tab-url" hidden>
        <form id="form-url" novalidate>
          <label class="visually-hidden" for="url">Address of the page to read</label>
          <div class="entry">
            <input type="url" id="url" name="url" placeholder="Paste an address&hellip;" autocomplete="url" spellcheck="false">
            <?= ParamsControl::render('url', array(ParamsControl::pages())) ?>
            <button class="go" type="submit" aria-label="Analyse this page">
              <span class="go-mark" aria-hidden="true">&rarr;</span>
              <span class="spinner" id="spin-url" hidden>&middot;&middot;&middot;</span>
            </button>
          </div>
        </form>
      </div>

      <div class="tabpanel" role="tabpanel" id="panel-repo" aria-labelledby="tab-repo" hidden>
        <form id="form-repo" novalidate>
          <label class="visually-hidden" for="repo">A public GitHub repository</label>
          <div class="entry">
            <input type="text" id="repo" name="repo" placeholder="owner/name on GitHub&hellip;" autocomplete="off" spellcheck="false">
            <?= ParamsControl::render('repo', array(ParamsControl::files())) ?>
            <button class="go" type="submit" aria-label="Read this repository">
              <span class="go-mark" aria-hidden="true">&rarr;</span>
              <span class="spinner" id="spin-repo" hidden>&middot;&middot;&middot;</span>
            </button>
          </div>
          <p class="hint">Public repositories only. Reads the history, the tree and a few files.</p>
        </form>
      </div>

      <div class="tabpanel" role="tabpanel" id="panel-code" aria-labelledby="tab-code" hidden>
        <form id="form-code" novalidate>
          <label class="visually-hidden" for="code">Source to read</label>
          <div class="entry entry-block">
            <textarea id="code" name="code" spellcheck="false" placeholder="Paste a file&hellip; any language, the longer the better"></textarea>
            <div class="entry-foot">
              <button class="go" type="submit" aria-label="Analyse this source">
                <span class="go-mark" aria-hidden="true">&rarr;</span>
                <span class="spinner" id="spin-code" hidden>&middot;&middot;&middot;</span>
              </button>
            </div>
          </div>
          <p class="hint">Read once and discarded. Never written down.</p>
        </form>
      </div>

      <div class="tabpanel" role="tabpanel" id="panel-git" aria-labelledby="tab-git" hidden>
        <form id="form-git" novalidate>
          <label class="visually-hidden" for="gitlog">Output of git log</label>
          <div class="entry entry-block">
            <textarea id="gitlog" name="log" spellcheck="false" placeholder="Paste the output of git log&hellip;"></textarea>
            <div class="entry-foot">
              <button class="go" type="submit" aria-label="Analyse this history">
                <span class="go-mark" aria-hidden="true">&rarr;</span>
                <span class="spinner" id="spin-git" hidden>&middot;&middot;&middot;</span>
              </button>
            </div>
          </div>
          <p class="hint hint-run">Run <code id="git-command">git log --numstat --pretty=format:'%H|%at|%an|%s'</code> and paste what it prints.</p>
        </form>
      </div>

      <!--
        Two things worth pointing the tool at, one of which is this project.
        They fill the field and run rather than describing what a run would be
        like, because a claim you can check in one click is worth more than a
        screenshot of one.
      -->
      <div class="tries" id="tries" hidden>
        <span class="tries-label">Try</span>
        <button type="button" class="try" data-mode="url" data-value="<?= h(vcd_site_url()) ?>/signs.php">this site&rsquo;s own field guide</button>
        <button type="button" class="try" data-mode="repo" data-value="goldo9824/vibecode-detector">the repository behind it</button>
      </div>

      <div id="error" class="error" hidden role="alert"></div>

    </section>

    <p class="caveat">This proves nothing &mdash; automated detection runs near chance. <a href="method.php">Read the evidence, not the number.</a></p>

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

  </div>
</main>

<footer class="colophon">
  <div class="shell colophon-grid">
    <div>
      <p><strong>Vibe Code Detector</strong> &mdash; free, open source, no account, no cookies, nothing stored but the address you asked about.</p>
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
