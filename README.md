<p align="center">
  <img src="assets/img/mark-512.svg" alt="" width="96" height="96">
</p>

<h1 align="center">Vibe Code Detector</h1>

<p align="center">
  <strong><a href="https://vibecodedetector.fanficnow.com">vibecodedetector.fanficnow.com</a></strong>
</p>

<p align="center">
  Paste a URL or some source code. Get a percentage, the evidence behind it,
  and an honest account of why you should not trust it too much.
</p>

<p align="center">
  <a href="LICENSE"><img alt="MIT licence" src="https://img.shields.io/badge/licence-MIT-17140f"></a>
  <img alt="PHP 7.4+" src="https://img.shields.io/badge/PHP-7.4%2B-b8402e">
  <img alt="No dependencies" src="https://img.shields.io/badge/dependencies-none-3f6b4a">
</p>

---

## What it does

Three modes, no account, nothing stored.

- **Live page** — fetches a URL and up to four of its own stylesheets and scripts,
  then reads it the way you would with View Source open: builder fingerprints
  first, then structure, then the look of the thing.
- **Whole site** — tick the box and it follows links from that page, reads as
  many as it can manage in about twenty seconds (up to fifty), and compares them
  against each other. This is not the page check run fifty times: a signal only
  counts site-wide when a quarter of the pages carry it, and whether the pages
  *resemble* each other is itself evidence no single page can give you. Honours
  `robots.txt` and finishes inside a shared-hosting request — on a slow site
  that means fewer pages, and the report says how many it managed.
- **Pasted code** — reads a source file in any language for the tells that survive
  in text: comment habits, error handling, naming, dependency incoherence, the
  security profile.
- **Git history** — paste the output of `git log` and it reads how the code
  *arrived*: one enormous opening commit, hundreds of lines in minutes, a trail
  of one-line fixes behind it. This is the strongest evidence the tool has, and
  the hardest to fake after the fact.

Either way you get a score out of 100, a verdict, a confidence level, and **every
signal that fired with the excerpt that triggered it**. You can then download a
signed one-page PDF certificate, which anyone can check at
[/verify](https://vibecodedetector.fanficnow.com/verify).

## What it does not do

It does not prove anything, and the interface says so before it says anything else.

Peer-reviewed benchmarks put off-the-shelf detection of AI-generated *source* at
around chance. Pan et al., *Assessing AI Detectors in Identifying AI-Generated Code*
(ICSE-SEET 2024), measured five detectors at roughly 0.5 accuracy across 5,069
human-written Python solutions.
Linters and formatters normalise away the statistical signal these tools depend on,
masking is cheap, and every stylistic tell weakens as models improve and as human
developers adopt the same frameworks.

So this tool is built to be *read*, not quoted. The number is the least
interesting thing on the results page.

**Do not use it to accuse anyone of anything.** False-positive harm in academic and
hiring contexts is real and falls hardest on people who did nothing wrong.

## How it scores

Every signal carries a weight in log-odds. Scoring starts from a prior of −1.0 — the
assumption that a given subject is *not* generated — sums the weights of what was
found, and pushes the total through a logistic curve.

Evidence is ranked, because evidence is not equal:

| Tier | Example | Weight |
|---|---|---|
| Platform fingerprint | `cdn.gpteng.co`, a `lovable-tagger` marker, a builder's generator meta tag | 4.5 — decisive |
| Repository history | a big-bang first commit, 600 lines in four minutes, a run of "fix typo" | 0.6–1.4 |
| Site-wide | every page one template with the words swapped; or pages from visibly different eras | 0.7–1.2 |
| Structural | uniform comment density, the same problem solved several ways, code wired to nothing | 0.6–1.1 |
| Code style | what-not-why comments, swallowed exceptions, tests that assert nothing | 0.4–1.3 |
| Content & security | generic testimonials, placeholder secrets, textbook-insecure defaults | 0.4–0.9 |
| Aesthetic | indigo gradients, Inter, three identical cards | 0.25–0.45, **capped as a group** |
| Human authorship | ticket references, exasperated comments, commented-out code, mixed indentation | subtracts |

Six rules the scoring will not break:

1. Aesthetic evidence is capped. A subject with nothing but aesthetic tells cannot
   exceed **55%**, however purple it is.
2. **Every category has a ceiling.** Signals within a category are not independent —
   code that swallows its exceptions usually over-wraps them too — so tripping eight
   weak style tells must never outweigh one hard fingerprint. Fingerprints are the
   only category with no ceiling.
3. No reading reaches 0% or 100%. The scale is clamped to 3–97.
4. Thin input is pulled toward the middle and reports insufficient confidence,
   rather than being quietly guessed at.
5. Confidence never exceeds *moderate* without a platform fingerprint — pattern
   reading without repository history has not earned more than that.
6. Human signals are first-class and weighted on the same scale as the rest.

All 96 signals, with their weights and reasoning, are in
**[docs/SIGNALS.md](docs/SIGNALS.md)** — generated from `lib/Catalog.php`, so the
documentation cannot drift from the code.

### The strongest signal needs you to show it

Repository history. One enormous opening commit followed by a trail of "fix typo"
commits is far harder to fake retroactively than anything in a served page or a
pasted file — which is why the third tab exists. It cannot be reached from a URL,
so you need the repository in hand. When you have it, start there.

Even then it reads the shape of the work, not who did it: a developer who commits
carefully while an agent writes the code produces a history that looks entirely
human, because in every respect git records, it is. Where trust is not
adversarial, ask the developer.

## Half of this was vibecoded

Roughly half and half, and it is worth being precise about which half.

**The AI half** wrote the code: the detection engine, the website, the PDF writer,
the logo, the test suite. **The human half** decided things: the research the signal
catalogue is built on, which tells are worth trusting and what each one is worth, the
calibration, the design direction, and the bug reports that fixed what the machine
got wrong.

So: **a half-vibecoded app for detecting vibecoded apps.** That is on the [front page
of the site](https://vibecodedetector.fanficnow.com/#provenance), not buried here,
because it is the most useful thing this project has to say about its own reliability.

Point the detector at this site and it returns **73% — Likely AI-generated**. It read
55% for most of this project's life; the number moved because the detector got better
at reading code, not because the site changed. It now catches its own JavaScript.

- **Nothing to fingerprint.** Agentic editors write into an ordinary repository. No
  badge, no builder subdomain, no injected runtime. Signs run in one direction only,
  and this repo is the direction they do not run in.
- **The tells were avoided on purpose.** No what-comments, no docblock on every
  trivial function, no indigo gradient, no Inter, no three-card grid. That is
  masking, and masking is cheap. It took no particular effort.

What fires is small and fair: formal error messages, heavy em-dash use, a `border-left`
accent, vocabulary in the front-end script that names nothing in particular. Every one
is genuinely present. The reading is no longer flattering and it has not been adjusted,
because a detector that quietly exempts the site it runs on is worth nothing at all.

None of it is special-cased away, and a detector that exempted itself would be worth
less than one that takes the hit.

## Running it locally

```bash
git clone https://github.com/goldo9824/vibecode-detector.git
cd vibecode-detector
php -S localhost:8000
```

Then open <http://localhost:8000>. There is nothing to install first.

```bash
php tests/run.php                       # the whole suite, ~360 assertions
php tools/gen-signals-doc.php           # regenerate docs/SIGNALS.md
php tools/build-assets.php              # regenerate the SVG files from lib/Brand.php
php tools/build-social.php              # regenerate the 1280x640 social preview card
```

## Deploying

Built for LWS shared hosting: upload the folder over FTP and it runs. No Composer,
no npm, no build step, no database, no cron.

The PDF certificates are generated by a hand-written PDF 1.4 writer
(`lib/Pdf.php`) using the standard-14 fonts, precisely so that there is nothing to
install on the host.

Full instructions, including the two 403 checks to run afterwards, are in
**[docs/DEPLOY-LWS.md](docs/DEPLOY-LWS.md)**.

## API access

`api/website.php` gives a caller with an API key programmatic access to the
Live page / Whole site check, with a much higher rate limit than the
anonymous UI. There is no key by default; the operator sets one by hand in
`data/api-keys.txt`, which never goes in the repo. See
**[docs/API.md](docs/API.md)** for setup, and hand **[`llms.txt`](llms.txt)**
to anyone you give a key to — it's written for an AI agent to read and call
the endpoint correctly on its own.

## Layout

```
index.php          the page
verify.php         certificate verification
llms.txt           instructions for an AI agent calling api/website.php
api/               analyze.php, website.php, certificate.php
lib/
  Catalog.php      every signal, its weight and its reasoning — the source of truth
  Report.php       scoring, verdict bands, guard rails
  SiteAnalyzer.php live-page analysis
  CodeAnalyzer.php source analysis
  GitAnalyzer.php  repository-history analysis
  Crawler.php      polite same-origin crawl, robots.txt and a time budget
  SiteSurvey.php   multi-page aggregation and cross-page comparison
  Fetcher.php      HTTP with SSRF protection
  Pdf.php          a small PDF 1.4 writer
  Brand.php        the mark, as geometry
  Certificate.php  certificate layout
assets/            css, js, svg
tests/             fixtures and the runner
tools/             doc and asset generators
```

## Privacy

No account, no tracking, no analytics, no database, no logs. Pasted code is read
once in memory and discarded; it is never written to disk. Certificates are signed
rather than stored, which is why verification is a signature check and not a
lookup — there is nothing to look up.

## Contributing

New signals are welcome, especially ones that are hard to mask. A signal has to
justify its weight and come with a fixture; see
**[CONTRIBUTING.md](CONTRIBUTING.md)**.

If the tool got something wrong, that is the most useful bug there is:

- [Report a wrong reading](https://github.com/goldo9824/vibecode-detector/issues/new?template=false_positive.yml)
- [Propose a signal](https://github.com/goldo9824/vibecode-detector/issues/new?template=signal_proposal.yml)
- [Report a bug](https://github.com/goldo9824/vibecode-detector/issues/new?template=bug_report.yml)

## Credits

The detection reference this is built on is summarised in
[docs/REFERENCE.md](docs/REFERENCE.md), with sources.

The indigo problem has a named origin: on 7 August 2025 Tailwind co-creator Adam
Wathan apologised for making every button in Tailwind UI `bg-indigo-500` five years
earlier, "leading to every AI generated UI on earth also being indigo".

Built by **Landfall studio**.

## Licence

MIT. See [LICENSE](LICENSE).

---

<p align="center"><em>A Landfall studio product</em></p>
