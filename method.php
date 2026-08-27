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
VisitLog::record('/method');

/**
 * How the number is arrived at, where it is wrong, and who wrote this.
 *
 * These three sections used to sit underneath the analyser on the front page,
 * where they were the first thing a visitor scrolled past and the last thing
 * they read. The front page is the tool now. This is the argument, and an
 * argument that has to be scrolled past to reach a text box was never being
 * read on the strength of its position.
 *
 * The order is deliberate and is not the flattering one: how it works, then
 * everywhere it does not, then the fact that half of this was generated too.
 */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?= Seo::head(array(
  'title'       => 'Method and limits — how the reading is arrived at',
  'description' => 'How this detector weights evidence, the guardrails the scoring will not break, every place the reading is wrong, and an account of which half of this site was itself generated.',
  'path'        => '/method.php',
  'type'        => 'article',
  'socialTitle' => 'How the number is arrived at, and where it is wrong',
  'socialDescription' => 'The weighting, the guardrails, the limits, and the half of this site that was vibecoded.',
  'jsonLd'      => array(
    Seo::siteSchema(),
    array(
      '@context'         => 'https://schema.org',
      '@type'            => 'Article',
      'headline'         => 'How the number is arrived at, and where it is wrong',
      'description'      => 'The weighting behind the reading, the rules the scoring will not break, the limits of automated AI-code detection, and an account of this project\'s own authorship.',
      'mainEntityOfPage' => Seo::url('/method.php'),
      'inLanguage'       => 'en',
      'isPartOf'         => array('@type' => 'WebSite', 'name' => Seo::SITE_NAME, 'url' => Seo::url('/')),
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
              'text'  => 'A live page by URL, a whole site by crawling it, a public GitHub repository by name, source code you paste in, or a git log. Each one is read for a different set of tells.',
            ),
          ),
          array(
            '@type' => 'Question',
            'name'  => 'Is anything stored about what I check?',
            'acceptedAnswer' => array(
              '@type' => 'Answer',
              'text'  => 'Pasted code and pasted git logs are never written down anywhere. With no database configured, nothing at all is stored. An operator who configures one records the mode of each analysis and, for the URL and repository modes, the address or repository name checked — never who asked.',
            ),
          ),
        ),
      ),
    array(
      '@context'        => 'https://schema.org',
      '@type'           => 'BreadcrumbList',
      'itemListElement' => array(
        array('@type' => 'ListItem', 'position' => 1, 'name' => 'Vibe Code Detector', 'item' => Seo::url('/')),
        array('@type' => 'ListItem', 'position' => 2, 'name' => 'Method and limits', 'item' => Seo::url('/method.php')),
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
      <?= Brand::markSvg(28, 'currentColor', 'var(--red)', 'aria-hidden="true"') ?>
      <span class="brand-name">Vibe Code Detector<span class="brand-tag">reads the tells, shows its working</span></span>
    </a>
    <nav>
      <a href="./">Analyse something</a>
      <a href="method.php" aria-current="page">Method</a>
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
      <p class="eyebrow">Method and limits</p>
      <h1>How the number is arrived at</h1>
      <p>And, at greater length, everywhere it is wrong. The score is the least interesting thing this tool produces; what follows is the reasoning it is made of, so that you can disagree with the reasoning rather than the number.</p>
    </section>

    <section class="band" id="method">
      <div class="prose">
        <h2>The weighting</h2>
        <p>Every signal carries a weight in log-odds, and the weights are summed against a starting assumption that a given page is <em>not</em> generated. The result goes through a logistic curve to become a percentage. That is the whole trick, and it matters far less than which signals are weighted how.</p>
        <p>How often a tell fired counts too. One comment restating the line under it is a coincidence; thirteen of them is a habit, and the habits are the thing being read. Each signal is scored with its occurrence count, worth up to half again its weight and no more &mdash; the difference between one and ten matters, the difference between forty and eighty is the length of the file. A builder&rsquo;s fingerprint is exempt: finding it three times identifies it exactly as well as finding it once.</p>

        <h3>Evidence is not equal, so it is not weighted equally</h3>
        <ol class="ladder">
          <li><strong>Platform fingerprints</strong><span>A builder's own runtime, badge, upload path or generator tag. This is a positive identification and it settles the question by itself.</span></li>
          <li><strong>Repository history</strong><span>One enormous opening commit, hundreds of lines in minutes, a trail of one-line fixes behind it. The hardest thing to fake after the fact, and the reason two of these tabs exist.</span></li>
          <li><strong>Repository contents</strong><span>What is in the tree rather than what the commits did to it: an assistant's own configuration file committed with the code, a pile of session summaries, a <code>.env</code> in version control, nothing tested.</span></li>
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

        <p>The full catalogue &mdash; every signal, its weight, and why it earns that weight &mdash; is <a href="catalogue.php">on this site, at /catalogue</a>. It is generated from the same file the scorer reads, so it cannot describe a weight the code does not use.</p>
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
        <p>The strongest signal available is repository history: one enormous opening commit followed by a trail of micro-fixes. That lives in git, not in a served page, so the URL tab cannot reach it. If the project is public on GitHub, the repository tab reaches it for you; if it is not, you have to paste a <code>git log</code>, which means having the repository in the first place. Either way, start there and treat the page tabs as corroboration.</p>
        <p>The repository tab reads a sample and says so. It takes the newest and oldest hundred commits, the file tree, and a few source files in full &mdash; enough to see the shape of the thing, not enough to claim it has read the codebase. A habit missing from three files is not a habit missing from a project.</p>
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

        <p>Point the detector at this site and it comes back somewhere in the AI-leaning band. The exact figure is not printed here on purpose: it has read 55%, then 73%, then lower again as the site was redesigned, and a number typed into a paragraph goes stale the week after it is typed. <a href="./">Run it yourself</a> &mdash; the address bar is two clicks away, and a claim you can check in ten seconds is worth more than one you have to take from us.</p>
        <p>What moves it is worth knowing either way. It went up when the detector got better at reading JavaScript and started catching its own. It came down when the front page stopped being four screens of prose under a text box, because there was less copy for the content signals to read. Neither movement was an adjustment made in the detector's favour, and none of the signals that fire on this site have ever been exempted.</p>

        <ul>
          <li><strong>Nothing to fingerprint.</strong> Agentic editors write into an ordinary repository. No badge, no builder subdomain, no injected runtime. Signs run in one direction only, and this site is the direction they do not run in.</li>
          <li><strong>The tells were avoided on purpose.</strong> No what-comments, no docblock on every trivial function, no indigo gradient, no Inter, no three-card grid. That is masking, and masking is cheap. It took no particular effort.</li>
        </ul>

        <p>What fires is small and fair: formal error messages, heavy em-dash use, a border-left accent, vocabulary in the front-end script that names nothing in particular. Every one of those is genuinely present. The reading is no longer flattering and it has not been adjusted, because a detector that quietly exempts the site it runs on is worth nothing at all.</p>

        <p>Which is also the argument for the thing this site keeps insisting on. The score is the least interesting item on the results page; the evidence underneath it is the point. If reading this makes you trust the number less, that is the correct response, and it is why the number was never shown to you on its own.</p>
      </div>
    </section>
    <section class="band">
      <div class="prose">
        <p class="eyebrow">Next</p>
        <h2>Read the evidence, not the number</h2>
        <p><a href="./">Run something through the detector &rarr;</a></p>
        <p><a href="catalogue.php">All <?= h(Num::exact(count(Catalog::all()))) ?> signals, with their weights and reasoning &rarr;</a></p>
        <p><a href="signs.php">The visual field guide &rarr;</a></p>
        <p><a href="<?= h(VCD_REPO_URL) ?>/issues/new?template=false_positive.yml" rel="noopener">Report a wrong reading &rarr;</a></p>
      </div>
    </section>

  </div>
</main>

<footer class="colophon">
  <div class="shell colophon-grid">
    <div>
      <p><strong>Vibe Code Detector</strong> is free, open source, and keeps nothing.</p>
      <p><a href="#provenance">Half of this site was vibecoded</a>, and its own detector cannot tell which half.</p>
    </div>
    <div class="links">
      <a href="./">Analyse something</a>
      <a href="signs.php">The visual field guide</a>
      <a href="catalogue.php">The signal catalogue</a>
      <a href="verify.php">Verify a certificate</a>
      <a href="<?= h(VCD_REPO_URL) ?>" rel="noopener">Source and issue tracker</a>
      <span class="studio">A Landfall studio product</span>
    </div>
  </div>
</footer>

</body>
</html>
