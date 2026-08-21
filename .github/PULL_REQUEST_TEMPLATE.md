<!--
  Thanks for contributing.

  Fill in what applies and delete what does not. A one-line typo fix does not need
  a filled-in checklist; a new signal needs all of it.
-->

## What this changes

<!-- One or two sentences. What is different after this merges? -->

## Why

<!-- The reason, not the mechanism. Link an issue if there is one: Fixes #123 -->

## Type of change

- [ ] New detection signal
- [ ] Adjusts an existing signal's weight or detection logic
- [ ] Bug fix
- [ ] Website or interface change
- [ ] Documentation
- [ ] Build, CI or tooling

---

## If this adds or changes a signal

<!-- Skip this whole section otherwise. -->

**Which signals, and what weight?**

<!-- e.g. adds `cd.stub_returns` at 0.6; raises `ae.indigo` from 0.45 to 0.55 -->

**Who might this wrongly accuse?**

<!--
  Required. Which careful human developers, house styles or linter configs would
  trip this? Convergent evolution is the central false-positive risk in this
  project and a signal that has not been tested against it is not ready.
-->

**How easily is it masked?**

<!-- Does it survive a formatter, a rename, a minifier? -->

- [ ] `lib/Catalog.php` has an entry with a `detail` that explains the reasoning, not just the pattern
- [ ] A fixture in `tests/fixtures/` exercises it
- [ ] A test asserts it fires on the positive fixture **and** does not fire on the negative one
- [ ] `php tools/gen-signals-doc.php` has been run and `docs/SIGNALS.md` is in the diff

---

## Checks

- [ ] `php tests/run.php` passes
- [ ] `php -l` is clean on every changed PHP file
- [ ] No new dependency — no Composer, no npm, no build step, no database
- [ ] Nothing new is written to disk, logged, or retained about an analysis
- [ ] If `lib/Brand.php` changed, `php tools/build-assets.php` has been run

## Scores before and after

<!--
  If this touches the engine, run the fixtures both ways and paste the numbers so
  the effect on scoring is visible in the diff rather than discovered later.

  | Fixture            | Before | After |
  |--------------------|--------|-------|
  | ai-landing.html    | 97%    | 97%   |
  | human-site.html    | 6%     | 6%    |
  | ai-code.js         | 97%    | 97%   |
  | human-code.js      | 3%     | 3%    |
-->

## Notes for the reviewer

<!-- Anything you are unsure about, or deliberately left out. -->
