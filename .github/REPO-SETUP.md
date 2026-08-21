# Repository settings checklist

Everything that cannot live in a file. Files in this repo cover issue templates,
the PR template, CODEOWNERS, CI, Dependabot and labels; the settings below have to
be clicked once in the GitHub UI.

Tick them off after the first push.

---

## Branch protection — `main`

**Settings → Branches → Add branch ruleset** (or classic branch protection).

| Setting | Value | Why |
|---|---|---|
| Require a pull request before merging | on | Nothing lands on `main` directly. |
| Required approvals | 1 | With CODEOWNERS below, that means the owner. |
| Dismiss stale approvals on new commits | on | An approval should describe the code that merges. |
| Require review from Code Owners | on | Makes `.github/CODEOWNERS` binding rather than advisory. |
| Require status checks to pass | on | Select `PHP 7.4`, `PHP 8.1`, `PHP 8.4` and `End-to-end smoke test`. |
| Require branches to be up to date | on | Stops two green PRs merging into a red `main`. |
| Require conversation resolution | on | |
| Block force pushes | on | |
| Restrict deletions | on | |
| Require linear history | optional | Only if you prefer squash/rebase merges. |

> The status check names only appear in the picker after CI has run at least once,
> so open the first PR, let it run, then come back and select them.

## Merge behaviour

**Settings → General → Pull Requests**

- Allow squash merging — **on** (default message: pull request title and description)
- Allow merge commits — off
- Allow rebase merging — off
- Automatically delete head branches — **on**
- Always suggest updating pull request branches — on

## Actions permissions

**Settings → Actions → General**

- Actions permissions: *Allow all actions and reusable workflows* — `actions/checkout`
  and `shivammathur/setup-php` are the only ones used.
- Workflow permissions: **Read repository contents and packages permissions**
  (the read-only default). Each workflow requests what it needs in its own
  `permissions:` block — `ci.yml` takes `contents: read`, `labels.yml` takes
  `issues: write`.
- Allow GitHub Actions to create and approve pull requests: **off**.

## Issues

**Settings → General → Features**

- Issues: on
- Discussions: optional. Turn it on if question-shaped issues become common;
  `.github/ISSUE_TEMPLATE/config.yml` already routes people to the docs first.
- Projects: off unless you want a board
- Wiki: **off** — documentation lives in `docs/` where it is reviewed with the code
- Blank issues: already disabled in `config.yml`; the four templates cover
  bug, wrong reading, signal proposal and feature request.

Run the labels workflow once to create the label set:

**Actions → Sync labels → Run workflow**

## Security

**Settings → Code security**

- Private vulnerability reporting: **on** — this is what `SECURITY.md` and the
  issue-template contact link point at. Without it those links 404.
- Dependabot alerts: on (there are no package dependencies, but Actions are covered)
- Dependabot security updates: on
- Secret scanning: on
- Push protection: on

## Repository metadata

**Settings → General**, and the ⚙ next to *About* on the repo home page.

- Description: `Paste a URL or some code, get a percentage on how likely it is AI-generated — with the evidence shown and the limits stated. No dependencies, runs on shared hosting.`
- Website: `https://vibecodedetector.fanficnow.com`
- Topics: `ai-detection`, `code-analysis`, `php`, `static-analysis`, `no-dependencies`,
  `shared-hosting`, `vibe-coding`, `pdf-generation`, `zero-dependency`
- Include in the home page: Releases off, Packages off, Environments off

## Pages

Leave GitHub Pages **off**. The site is deployed to LWS shared hosting from this
repository's contents by FTP; a second copy on `github.io` would drift and confuse
people about which one is real.

---

## What is already in files

Nothing below needs clicking — it is version-controlled and reviewed like code.

| File | Does |
|---|---|
| `.github/ISSUE_TEMPLATE/*.yml` | Four issue forms, blank issues disabled |
| `.github/ISSUE_TEMPLATE/config.yml` | Routes security reports privately, links the docs |
| `.github/PULL_REQUEST_TEMPLATE.md` | Includes the false-positive question every signal must answer |
| `.github/CODEOWNERS` | Review requirements, per path |
| `.github/workflows/ci.yml` | Lint, tests on PHP 7.4/8.1/8.4, doc drift, asset drift, end-to-end smoke |
| `.github/workflows/labels.yml` | Applies `labels.json` |
| `.github/dependabot.yml` | Monthly Actions updates |
| `SECURITY.md` | Disclosure policy and scope |
| `CODE_OF_CONDUCT.md` | Including the no-accusations rule |
| `CONTRIBUTING.md` | The bar a new signal has to clear |
