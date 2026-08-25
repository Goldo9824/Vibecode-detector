<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/Brand.php';
require_once __DIR__ . '/lib/VisitLog.php';
require_once __DIR__ . '/lib/Seo.php';

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
  'description' => 'Paste a URL or some source code and get a percentage reading of how likely it is to be AI-generated, with the evidence shown and the limits stated. Free, no account, open source.',
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
      'description' => 'Reads a live page, pasted source, or a git log for the tells of AI generation and returns a percentage with the evidence behind it.',
      'isAccessibleForFree' => true,
      'offers'      => array('@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'EUR'),
      'license'     => VCD_REPO_URL . '/blob/main/LICENSE',
      'codeRepository' => VCD_REPO_URL,
    ),
    // The three questions the page is actually asked, answered in the words it
    // answers them in on the page itself. Nothing here claims more than the
    // page does — a rich result that oversells a detector is the one thing
    // this site must not produce.
    array(
      '@context'   => 'https://schema.org',
      '@type'      => 'FAQPage',
      'mainEntity' => array(
        array(
          '@type' => 'Question',
          'name'  => 'Can you prove a page was written by AI?',
          'acceptedAnswer' => array(
            '@type' => 'Answer',
            'text'  => 'No. Automated detection of AI-generated source performs near chance in peer-reviewed benchmarks. This shows every piece of evidence it used and expects you to read it. Do not accuse anyone of anything on the strength of a number.',
          ),
        ),
        array(
          '@type' => 'Question',
          'name'  => 'What can it look at?',
          'acceptedAnswer' => array(
            '@type' => 'Answer',
            'text'  => 'A live page by URL, a whole site by crawling it, source code you paste in, or a git log. Each one is read for a different set of tells.',
          ),
        ),
        array(
          '@type' => 'Question',
          'name'  => 'Is anything stored about what I check?',
          'acceptedAnswer' => array(
            '@type' => 'Answer',
            'text'  => 'Pasted code and pasted git logs are never written down anywhere. With no database configured, nothing at all is stored. An operator who configures one records the mode of each analysis and, for the URL modes, the address checked — never who asked.',
          ),
        ),
      ),
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
      <a href="signs.php">Visual signs</a>
      <a href="#method">Method</a>
      <a href="#limits">Limits</a>
      <a href="#provenance">Provenance</a>
      <a href="verify.php">Verify</a>
      <a href="<?= h(VCD_REPO_URL) ?>" rel="noopener">Source</a>
    </nav>
  </div>
</header>

<main>
  <div class="shell">

    <section class="hero">
      <h1>Was this written by a person, or generated?</h1>
      <p>Give it a URL, paste some code, or paste a <code>git log</code>. It reads the same tells a reviewer would (builder fingerprints, commit patterns, comment habits, error handling, the shape of the copy) and returns a percentage with every piece of evidence it used.</p>
      <p class="disclaimer">It will not prove anything. Automated detection of AI-generated source performs near chance in peer-reviewed benchmarks, so this shows its working and expects you to read it. Do not accuse anyone of anything on the strength of a number. <a href="#provenance">This site is half vibecoded too</a>, and its own detector cannot tell which half.</p>
    </section>

    <div class="panel" id="analyzer">
      <div class="tabs" role="tablist" aria-label="What to analyse">
        <button class="tab" role="tab" id="tab-url" aria-controls="panel-url" aria-selected="true" type="button">Live page</button>
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
          <p class="eyebrow">Pages read</p>
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

    <section class="band" id="method">
      <div class="prose">
        <p class="eyebrow">Method</p>
        <h2>How the number is arrived at</h2>
        <p>Every signal carries a weight in log-odds, and the weights are summed against a starting assumption that a given page is <em>not</em> generated. The result goes through a logistic curve to become a percentage. That is the whole trick, and it matters far less than which signals are weighted how.</p>
        <p>How often a tell fired counts too. One comment restating the line under it is a coincidence; thirteen of them is a habit, and the habits are the thing being read. Each signal is scored with its occurrence count, worth up to half again its weight and no more &mdash; the difference between one and ten matters, the difference between forty and eighty is the length of the file. A builder&rsquo;s fingerprint is exempt: finding it three times identifies it exactly as well as finding it once.</p>

        <h3>Evidence is not equal, so it is not weighted equally</h3>
        <ol class="ladder">
          <li><strong>Platform fingerprints</strong><span>A builder's own runtime, badge, upload path or generator tag. This is a positive identification and it settles the question by itself.</span></li>
          <li><strong>Repository history</strong><span>One enormous opening commit, hundreds of lines in minutes, a trail of one-line fixes behind it. The hardest thing to fake after the fact, and the reason the third tab exists.</span></li>
          <li><strong>Structural signals</strong><span>Uniform comment density, the same problem solved four ways, fully-built code wired to nothing. Hard to produce by accident, hard to fake.</span></li>
          <li><strong>Code-style tells</strong><span>What-not-why comments, blanket try/catch, swallowed exceptions, tests that assert nothing, emoji in comments.</span></li>
          <li><strong>Content and security</strong><span>Statistically generic testimonials, a database key shipped to the browser, a login the browser grants itself. The security profile decays slowest of all the tells.</span></li>
          <li><strong>Aesthetics</strong><span>Indigo gradients, the default icon set, three identical cards. Counted, capped, and never enough on their own to reach a verdict.</span></li>
        </ol>

        <h3>Rules the scoring will not break</h3>
        <ul>
          <li>Aesthetic evidence is capped as a group. A purple page with no other tells cannot score above 55%.</li>
          <li>Every category has a ceiling. Signals within one are not independent, so eight weak style tells must never outweigh a single hard fingerprint. Fingerprints are the only category with no ceiling.</li>
          <li>Inference stops short of identification. Without a fingerprint no reading passes 92%, however many families of evidence agree.</li>
          <li>A reading never reaches 0% or 100%. Certainty is not available here.</li>
          <li>Short input is explicitly discounted rather than quietly guessed at &mdash; where &ldquo;short&rdquo; means nothing to read, not a short document. A page that builds itself in the browser is read from its bundle.</li>
          <li>Signals pointing at human authorship subtract, and they are weighted as heavily as the ones pointing the other way.</li>
          <li>Repetition is bounded. A count can lift a signal by at most half again, it works inside the category ceilings rather than around them, and it never applies to a fingerprint.</li>
        </ul>

        <p>The full catalogue, with every signal, its weight, and why it earns that weight, is in <a href="<?= h(VCD_REPO_URL) ?>/blob/main/docs/SIGNALS.md" rel="noopener">docs/SIGNALS.md</a>.</p>
      </div>
    </section>

    <section class="band" id="limits">
      <div class="prose">
        <p class="eyebrow">Limits</p>
        <h2>Where this is wrong</h2>

        <h3>Signs run in one direction only</h3>
        <p>Finding a fingerprint identifies a builder. Finding none proves nothing at all, because agentic editors write into an ordinary repository and leave nothing behind in the served page.</p>

        <h3>The tells overlap with good practice</h3>
        <p>Tailwind, semantic HTML, thorough error handling, descriptive names and consistent formatting are what careful developers do. That is the central false-positive risk and no amount of weighting removes it.</p>

        <h3>Masking is cheap</h3>
        <p>Renaming variables, stripping comments or running the file through a formatter erases most of what this reads. Minified bundles are excluded from code-level analysis for exactly this reason: the signal has already been normalised away.</p>

        <h3>What it can only see if you show it</h3>
        <p>The strongest signal available is repository history: one enormous opening commit followed by a trail of micro-fixes. That lives in git, not in a served page, so the URL tab cannot reach it &mdash; you have to paste a <code>git log</code> into the third tab, which means having the repository in the first place. When you do have it, start there and treat the other two tabs as corroboration.</p>
        <p>Even then it reads the shape of the work rather than who did it. A developer who commits carefully while an agent writes the code produces a history that looks entirely human, because in every respect that git records, it is.</p>

        <h3>The better question</h3>
        <p>Authorship is usually not what anyone actually needs to know. Reviewed, tested, understood AI code is just code. The useful question is whether anyone understands this system and can secure it, which is why the security signals are here, and why they are the ones that will still work in five years.</p>
      </div>
    </section>

    <section class="band" id="provenance">
      <div class="prose">
        <p class="eyebrow">Provenance</p>
        <h2>Half of this was vibecoded</h2>

        <p>Roughly half and half. The code was generated by an AI agent: the detection engine, the page you are reading, the PDF writer, the logo, the test suite. The other half is human and it is the half that decided anything &mdash; the research the signal catalogue is built from, which tells were worth trusting and what each one is worth, the calibration, the design decisions, and the bug reports that fixed what the machine got wrong.</p>

        <p>So: a half-vibecoded app for detecting vibecoded apps.</p>

        <p>Point the detector at this site and it returns <strong id="self-score">73%, likely AI-generated</strong>. It read 55% for most of this project's life, and the number moved because the detector got better at reading code &mdash; not because the site changed. It now catches its own JavaScript.</p>

        <ul>
          <li><strong>Nothing to fingerprint.</strong> Agentic editors write into an ordinary repository. No badge, no builder subdomain, no injected runtime. Signs run in one direction only, and this site is the direction they do not run in.</li>
          <li><strong>The tells were avoided on purpose.</strong> No what-comments, no docblock on every trivial function, no indigo gradient, no Inter, no three-card grid. That is masking, and masking is cheap. It took no particular effort.</li>
        </ul>

        <p>What fires is small and fair: formal error messages, heavy em-dash use, a border-left accent, vocabulary in the front-end script that names nothing in particular. Every one of those is genuinely present. The reading is no longer flattering and it has not been adjusted, because a detector that quietly exempts the site it runs on is worth nothing at all.</p>

        <p>Which is also the argument for the thing this site keeps insisting on. The score is the least interesting item on the results page; the evidence underneath it is the point. If reading this makes you trust the number less, that is the correct response, and it is why the number was never shown to you on its own.</p>
      </div>
    </section>

  </div>
</main>

<footer class="colophon">
  <div class="shell colophon-grid">
    <div>
      <p><strong>Vibe Code Detector</strong> is free and open source. No account, no cookies, no third-party analytics, nothing loaded from anyone else&rsquo;s server. Pasted code and git history are read once and discarded, never stored; live-page and whole-site checks record only the address and mode analysed, for operator visibility, never the page content. Page views are counted &mdash; the path, the referring site, and a token that is salted with today&rsquo;s date so that it counts visitors today and cannot recognise anyone tomorrow. No address, no cookie, no session.</p>
      <p>Built to run on ordinary shared hosting: plain PHP, no dependencies, no build step.</p>
      <p><a href="#provenance">Half of this site was vibecoded</a>, and it scores 73% on its own detector.</p>
    </div>
    <div class="links">
      <a href="<?= h(VCD_REPO_URL) ?>" rel="noopener">Source and issue tracker &rarr;</a>
      <a href="<?= h(VCD_REPO_URL) ?>/issues/new?template=false_positive.yml" rel="noopener">Report a wrong reading &rarr;</a>
      <a href="signs.php">The visual field guide &rarr;</a>
      <a href="<?= h(VCD_REPO_URL) ?>/blob/main/docs/SIGNALS.md" rel="noopener">The signal catalogue &rarr;</a>
      <a href="verify.php">Verify a certificate &rarr;</a>
      <span class="studio">A Landfall studio product</span>
    </div>
  </div>
</footer>

<script src="assets/js/app.js?v=<?= h(VCD_VERSION) ?>" defer></script>
</body>
</html>
