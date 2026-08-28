# Contributing

Thanks for wanting to help.

The most valuable contribution to this project is not code. It is **telling us when
the detector got it wrong** — [report a wrong reading](https://github.com/goldo9824/vibecode-detector/issues/new?template=false_positive.yml).
A tool whose entire premise is that detection is unreliable needs a steady supply of
cases where it visibly is.

---

## Getting set up

```bash
git clone https://github.com/goldo9824/vibecode-detector.git
cd vibecode-detector
php -S localhost:8000
```

That is the whole setup. There is nothing to install.

```bash
php tests/run.php                     # the suite — ~140 assertions, runs in under a second
php tools/gen-signals-doc.php         # regenerate docs/SIGNALS.md from lib/Catalog.php
php tools/gen-signals-doc.php --check # what CI runs
php tools/build-assets.php            # regenerate assets/img/*.svg from lib/Brand.php
```

---

## The constraints

These are not preferences. Changing them changes what the project is.

**No dependencies.** No Composer, no npm, no build step, no bundler, no framework.
The deployment target is LWS shared hosting with FTP and a PHP version picker. CI
fails the build if a `composer.json` or `package.json` appears. If you need a
library, write the twelve functions you actually need — that is what `lib/Pdf.php`
is and why it exists.

**PHP 7.4 is the floor.** No `match`, no enums, no `readonly`, no nullsafe operator,
no `str_contains`. CI runs the suite on 7.4, 8.1 and 8.4.

**Nothing is stored, by default.** No database, no logs, no analytics, no record of
any analysis. Pasted code is read once in memory and discarded. Certificates are
signed, not saved, which is why verification is a signature check rather than a
lookup. A change that starts retaining anything about what people analyse needs a
very good argument and a conversation first.

The one deliberate, opt-in exception is `admin/` (see `docs/ADMIN.md`): an operator
who configures a database gets a password-gated panel for managing named API keys
and seeing usage. That records four things — the mode and, for URL and repository
checks, the address or repository name of each analysis; one row per page view,
holding a path, a timestamp, a referring host and a visitor token salted with the
day so it can count people today and recognise nobody tomorrow; one row per request
this site makes to the GitHub API, holding the repository, the endpoint, the status
and what GitHub said was left of the hourly allowance; and one row per reading
somebody deliberately reported as wrong, holding that reading and what they said
about it. Never the pasted content, never the fetched page, never a repository's
source, never an address, never a cookie. With no database configured, every one of
those code paths is inert and the claim above is exactly true.

The same rule applies to the two newer logs. `GitHubLog::record()` is called from
`GitHub::api()` — the one place every GitHub API request passes through — so that
what is recorded is visible where it happens rather than configured somewhere else.
`Feedback::record()` is called from `api/feedback.php` and only ever with a report
somebody deliberately sent, carrying the certificate the reading was issued with:
a report can only dispute a reading this site actually produced.

If you add a page that should be counted, call `VisitLog::record('/its-path')` in
its opening lines the way `index.php` does, rather than moving the call into
`lib/bootstrap.php`. Every page that counts a visit saying so in its own first
lines is the property that makes the claim above checkable by reading.

**The site does not wear the tells it detects.** No indigo-to-violet, no Inter, no
three-card grid, no default icon set. This is only half a joke: it is also the
reason the detector cannot be quietly tuned to exempt its own author's habits.

---

## Adding a detection signal

This is the interesting part of the codebase, so it has the most exacting bar.

### 1. Add the entry to `lib/Catalog.php`

This is the single source of truth — the analyzers, the PDF certificate and the
documentation all read from it.

```php
'cd.your_signal' => self::mk($c, $ai, 0.6, 'Short label, sentence case',
    'What this means and why a generator does it where a human would not. This '
  . 'text is shown to users on the results page and printed on certificates, so '
  . 'write it for the person being judged by it, not for yourself.'),
```

Id prefixes: `fp.` fingerprint, `gh.` repository history, `rp.` repository
contents, `xs.` site-wide, `st.` structural, `cd.` code style, `ct.` content,
`se.` security, `ae.` aesthetic, `hu.` human authorship.

### 2. Choose a weight honestly

Weights are log-odds, and they are summed.

| Weight | Means | Use for |
|---|---|---|
| 4.5 | decisive | A builder naming itself. Nothing else. |
| ~1.0 | strong | Structural signals; things that survive editing |
| ~0.6 | moderate | Most code-style tells |
| ~0.3 | weak | Aesthetics, and anything a formatter would erase |

If you are unsure, go lower. An underweighted signal costs a little accuracy; an
overweighted one produces a confident wrong accusation, which is the failure mode
this project exists to avoid.

### 3. Answer the false-positive question

**Required in the PR.** Which careful human developers would this wrongly accuse?
Which house styles, linter configs or frameworks trip it?

Tailwind, semantic HTML, Prettier, descriptive naming and thorough error handling
are all modern best practice. Good human code looks generated. A signal that has not
been thought about in those terms is not ready, and "I could not think of any" is
almost always an answer that needs more looking.

### 4. Fire it, with evidence

```php
$this->r->flag('cd.your_signal', array(
    'line ' . ($i + 1) . ': ' . trim($line),
));
```

Always pass evidence. The excerpt is what lets someone check your reasoning instead
of trusting the number, and that is the whole design. Redact anything
credential-shaped before it goes in — see `checkSecurity()` for how.

### 5. Test both directions

A test that it fires is half a test. Add the case to a fixture in `tests/fixtures/`
and assert **both**:

```php
ok($r->has('cd.your_signal'), 'catches the thing');       // on the positive fixture
ok(!$r->has('cd.your_signal'), 'does not fire on human code');  // on the negative one
```

### 6. Regenerate the docs

```bash
php tools/gen-signals-doc.php
```

`docs/SIGNALS.md` must be in your diff. CI checks it.

The page at `/catalogue` renders `lib/Catalog.php` directly, so it needs nothing
regenerating — but CI does check that every id in the catalogue appears on it, so a
signal that renders as nothing will be caught there rather than by a reader.

---

## Changing a weight

Weights are load-bearing. If you change one, paste the fixture scores before and
after into the PR:

| Fixture | Before | After |
|---|---|---|
| `ai-landing.html` | 97% | ? |
| `human-site.html` | 6% | ? |
| `ai-code.js` | 97% | ? |
| `human-code.js` | 3% | ? |

```bash
php -r 'require "lib/bootstrap.php";
foreach (["url"=>"ai-landing.html","url2"=>"human-site.html"] as $f) { /* … */ }'
```

The guard rails in `Report::score()` — the aesthetic cap, the 3–97 clamp, the thin-input
discount, the confidence ceiling — are deliberate and covered by tests. Removing one
needs a reasoned argument in the PR, not just a passing build.

---

## Style

Match what is there.

- Comments explain **why**, never what. A codebase that flags what-comments should
  not contain them, and reviewers will point at this.
- No docblock on trivial functions. Same reason.
- No emoji in source. Same reason.
- Catch what you can actually handle; do not wrap everything in `try` out of habit.
- Four spaces in PHP, two in HTML/CSS/JS/YAML. `.editorconfig` has the details.

Fixtures in `tests/fixtures/` are specimens, not code. Do not reformat them, do not
tidy them, do not fix their bad practices. Those are the thing being measured.

---

## Pull requests

- Branch from `main`.
- One concern per PR.
- `php tests/run.php` passes and `php -l` is clean on every changed file.
- Fill in the template, especially the false-positive question if a signal is involved.

---

## Reporting security problems

Do not open a public issue. Use
[a private advisory](https://github.com/goldo9824/vibecode-detector/security/advisories/new).
See [SECURITY.md](SECURITY.md) for what is in scope — the URL fetcher and the
certificate signing are the parts that matter.

---

## Conduct

Be decent. [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md) has the long version.

One project-specific rule: **do not use the issue tracker to accuse people or
projects of being AI-generated.** Reports naming a third party's work as "obviously
vibecoded" will be closed. False-positive harm is the thing this project is built to
reduce, and it would be absurd to host it here.
