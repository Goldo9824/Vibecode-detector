# Admin panel

`admin/` is a password-gated dashboard for managing API keys by name and
seeing how the site is used — both the anonymous UI and `api/website.php`.
It is entirely optional: with no database configured, every part of the
site works exactly as it does without this feature, including the
key-authenticated API (`data/api-keys.txt` still works — see `docs/API.md`).

This is the one part of the project that keeps a record of anything. See
[**Privacy: what changes**](#privacy-what-changes) below before turning it on.

## Setup

Two files, neither of them committed to the repo, both under `data/`
(unreachable over HTTP the same way `data/secret.key` is — see
`data/.htaccess`):

### 1. `data/db-config.php` — database connection

```php
<?php
return array(
    'host'     => 'your-db-host',
    'port'     => 3306,
    'database' => 'your-database-name',
    'user'     => 'your-db-user',
    'password' => 'your-db-password',
);
```

Two optional settings can go in the same array:

```php
    'log_visits'            => true,  // default; false stops recording page views
    'visit_retention_days'  => 90,    // default; visit rows older than this are deleted
    'github_retention_days' => 90,    // default; GitHub request rows older than this are deleted
```

MySQL or MariaDB, reachable from this server. Nothing needs to be created in
it by hand — the first time `admin/index.php` connects successfully, it runs
`CREATE TABLE IF NOT EXISTS` for its five tables (`api_keys`, `usage_log`,
`visit_log`, `github_log`, `result_feedback`) and leaves them alone after that. If your host only exposes phpMyAdmin and the
automatic connection fails, the exact statements are in
`Db::ensureSchema()` in `lib/Db.php` — paste them in by hand once.

### 2. `data/admin-password.php` — the login password

```php
<?php
return array(
    'hash' => 'paste a password_hash() output here',
);
```

Generate the hash with:

```
php -r 'echo password_hash("your password here", PASSWORD_DEFAULT), "\n";'
```

There is one shared admin password, no separate accounts. If this file is
missing, `admin/login.php` rejects every attempt — there is no default
password and no way in without it.

## Using it

Visit `/admin/` and log in. From there:

- **Create a key** — give it a name (who or what it's for) and it generates
  a random key, shown to you exactly once in a banner at the top of the
  page. There is no way to retrieve a lost key; revoke it and issue a new
  one instead.
- **Remove a key** — sets it revoked immediately (`api/website.php` stops
  accepting it right away) without deleting its usage history, so you can
  still see what it was used for afterward.
Counters past a thousand are shortened — `17k`, `2.6k`, `1.2M`. They round
*down*, so a figure never overstates itself, and the exact number is in the
element's title, one hover away. See `lib/Num.php`.

- **Usage, last 30 days** — total analyses by mode (live page, whole site,
  GitHub repo, pasted code, git history) across everyone, and the twenty
  most-analysed websites across all callers. Repository reads are counted by
  mode but never appear in the website tables: a repository name is stored
  without a scheme, so it resolves to no host, and counting every scan against
  github.com would claim a website was visited that nobody visited. **See all N websites** under that table opens
  the full list.
- **View usage** on a key's row — the same breakdown restricted to that one
  key: how many requests, and which websites it pointed at.

Any website name in the panel is a link to that website's own page.

Across the top of every page is a row of links to the five views: **Overview**,
**Traffic**, **Websites**, **GitHub** and **Reports**.

Most figures come with a chart beside them, drawn as SVG by `lib/Chart.php`:
columns for a quantity per day, stacked columns where that quantity is made of
parts (analyses by mode, GitHub requests by outcome, reports by direction), a
single horizontal bar for part-to-whole at one moment, and a 24-column strip for
time of day. Deliberately no pie or donut anywhere: a ring is only readable when
the slices are far apart, and most of these questions are asked of numbers that
are close. The bands of a stacked chart are one colour at five strengths rather
than five colours, and every band's own figure is written out beside it — so the
reading never depends on telling two greys apart.

### Keeping it out of search

Four layers, none of which is access control — the password is that, and
nothing else links to `/admin/` from the public site:

1. `Disallow: /admin/` in `robots.txt`.
2. `<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">`
   in the head of every admin page.
3. `X-Robots-Tag: noindex, nofollow, noarchive, nosnippet` as a real HTTP
   header, sent by every admin page in its first lines. This is the one that
   covers the cases the meta tag cannot: the redirect to `login.php` that an
   unauthenticated request gets, which never renders a head at all.
4. `admin/.htaccess`, which sets the same header for anything served out of
   this directory — including a file added here later that forgets the other
   three.

One honest caveat about layer 1: a crawler that obeys `Disallow` never
fetches the page, so it never reads the `noindex` on it. The two do not
stack the way they look like they do. It is kept anyway because nothing
links here, the pages are gated, and a crawler that ignores `robots.txt` is
exactly the one that will read the header.

Everything else that should stay out of the index is left crawlable on
purpose and says so in its own headers instead: `api/analyze.php`,
`api/website.php`, `api/certificate.php` and `verify.php` all send
`X-Robots-Tag: noindex` and are not disallowed, so the `noindex` is actually
read and honoured. The certificate endpoint sets it before it can fail, so
the error response carries it too.

## Websites

`admin/websites.php` is every website that has ever been analysed, not just
the busiest twenty of the last month. Forty rows to a page, with numbered
pages under the table.

- **Search** matches any part of a host name. A typed `_` or `%` is a literal
  one — the term is escaped before it reaches `LIKE`, so the box answers the
  question that was typed.
- **Window** is all time by default, and switches to the last 7, 30 or 90 days.
- **Order** is by when each site was last searched by default — the order they
  came in — and switches to first searched, most analysed, least analysed, or
  alphabetical.

Every one of those is in the query string rather than in a session, so a view
you want again is a URL you can bookmark, and a page number past the end of
the list shows the last page rather than an error.

`admin/websites.php` lists hosts only: pasted code and git history never
appear there, because they carry no website to attribute an analysis to and
the pasted content itself is never written down.

### One website

Clicking a host opens `admin/website.php?host=…`: analyses over time as a
chart, how it was checked (live page vs whole site), whether the request came
through this site or the API, which key was used, and the most recent
analyses with the exact addresses submitted. It takes the same window
switcher as the list.

The chart draws one column per day up to a 90-day window and one per week
past that — a year of daily columns is a bar a pixel wide, which reads as an
empty grid rather than as a measurement. On "all time" it covers from the
first analysis to today, up to a year; the totals above it always cover
everything.

## Traffic

`admin/visits.php` is the traffic view: page views and distinct visitors per
day, when in the day people arrive, which pages they open, which sites send
them, and what they browse with. The window switches between 7, 30 and 90
days, and bots — most of the traffic to a small site — are excluded by
default and can be switched back in.

The charts are SVG written by `lib/Chart.php` on the server. There is no
charting library, for the same reason there is no framework: the deployment
target is an FTP upload, and a build step would end that. They work with
JavaScript off, follow the light and dark themes, and print.

## GitHub

`admin/github.php` is the one thing repository mode has no other way to see:
what it costs, and where the ceiling is.

GitHub gives an unauthenticated caller **60 API requests an hour, per address** —
and on shared hosting that address is the whole site, every visitor put
together. One repository read spends up to `GitHub::MAX_REQUESTS` (8) of them,
which is roughly seven reads an hour for everyone. A read-only token in
`data/github-config.php` raises the allowance to 5,000.

The page answers, in order:

- **The allowance** — what this installation is actually allowed, how many
  repository reads that buys, and the lowest GitHub has said was left.
- **How far it gets in an hour** — the measurement the page exists for. For
  every clock hour that ended in a refusal, how many repositories it had got
  through *first*; reported as the earliest it was ever stopped, the latest it
  ever got, the middle of those, and — separately — the busiest hour that was
  never stopped at all. Read as a pair: an hour that opens on an allowance the
  previous one had already half spent runs out sooner, so a single average
  reads as the ceiling being lower than it is.
- **Hour by hour**, as one column per actual hour, with the hours that were
  refused drawn in the accent. Quiet hours are left out rather than drawn as
  zero — a quiet night is not forty empty bars.
- **Requests per day**, stacked by what came back: answered, no such repo,
  refused, failed.
- **What the allowance is spent on**, by endpoint, and **when it goes**, by
  hour of the day.
- **Repositories searched**, busiest first, with how many requests each cost
  and how often it was refused.
- **Every refusal**, with the repository, the status, what GitHub said was
  left, and when it resets. A 403 with allowance still left is GitHub blocking
  that *repository* rather than this server; the two arrive identically and are
  only told apart by that column.

Each hour that ended in a refusal is counted **up to its first refusal and no
further**. Everything after it is a request answered instantly with "no", and
counting those would put the ceiling higher than it is.

Only API requests appear here, because only API requests spend from the
allowance. Source files come off `raw.githubusercontent.com`, which is free.

## Reports

`admin/feedback.php` is what people say when a reading looks wrong to them.

Under every result on the front page there is now a **Does this reading look
wrong?** block: score too high, score too low, or about right, then optionally
what the reader says the subject actually is and a short note. Two clicks is a
complete report. It appears only when a database is configured; with none, it
points at the issue tracker instead, which works either way.

A report is only accepted with the **certificate** its reading was issued with —
a signature over the mode, the address, the score and the verdict (see
`vcd_cert_token()` in `lib/bootstrap.php`). That is provenance rather than
authentication: it means every row disputes a reading this site actually
produced, at the number it actually gave, rather than whatever a form said.
The certificate id is unique per reading, so a reader who changes their mind
replaces their report instead of voting twice.

The page shows reports per day by direction, **where on the scale** they sit
(the same four bands the meter is painted in), how often a reading is disputed
**per hundred analyses** per mode, what people say the subject really was, the
most-reported websites, and the reports themselves with their notes.

Two figures worth reading together: reports bunched into one band are a
threshold in the wrong place and can be moved; reports spread evenly across all
four are a disagreement about the whole idea, which no threshold fixes. And the
rate matters more than the count — a mode used ten times as often collects ten
times the complaints without being any worse.

Reports are self-selected, and the page says so: a reader who agrees with a
reading has no reason to say so, and the "about right" button is only a partial
answer to that.

## Privacy: what changes

Before this feature, the honest claim on the front page and in
`CONTRIBUTING.md` was absolute: nothing about any analysis is stored,
anywhere, ever. Turning this on changes that, and the site's own copy has
been updated to say so rather than leave a false claim standing. Precisely
what changes:

- **Analyses, once a database is configured:** the mode of each analysis
  (live page / whole site / GitHub repo / pasted code / git history), and for
  the two URL modes the address analysed, for repository mode the repository's
  name; which API key was used, if any; and a timestamp. That's it — never the
  page fetched, never the repository's source, never the pasted content.
- **Visits, once a database is configured:** one row per public page view —
  the path, a timestamp, the referring site's host, a coarse client class
  (desktop / mobile / tablet / bot / other), and a visitor token. The query
  string is dropped rather than trimmed, because on this site that is where
  the address somebody asked about ends up, and a certificate payload
  besides.
- **The visitor token** is the one part that needs explaining, because
  counting people rather than page views needs *something* per person. It is
  an HMAC of the address and user agent under this installation's
  `data/secret.key` **and today's date**, truncated to 16 hex characters. It
  cannot be reversed into an address, and because the date is in the salt it
  is a different value for the same person tomorrow — so it can answer "how
  many different people came today" and nothing else. Somebody who visits on
  Monday and Thursday is two, and the panel says "daily visitors" rather
  than "people" for that reason.
- **GitHub requests, once a database is configured:** one row per request this
  site makes to the GitHub API — the repository as `owner/name`, which endpoint
  was asked for, the HTTP status and what it amounted to, what GitHub said was
  left of the hourly allowance and when it resets, and a timestamp. Nothing
  about who asked for it, and nothing about what came back beyond its status.
  A repository name is recorded for the same reason a website address is: it is
  what was asked about.
- **Reported readings, once a database is configured:** one row per reading
  somebody disagreed with — the reading itself (mode, address where it has one,
  score, verdict), which way they say it is wrong, what they say it really is,
  and their note if they left one. Nothing about who they are: no address, no
  cookie, no session, and no field to leave an email in, because there is
  nowhere for an answer to go. A report about pasted code or a pasted git log
  carries the score and the verdict and nothing about the subject — what was
  pasted is still never written down.
- **Never recorded, with or without a database:** the content of pasted code
  or a pasted git log, the body of a fetched page, an IP address, a cookie,
  or a session tied to a visitor.
- **Deleted on a rolling window:** visit rows older than
  `visit_retention_days` (90 by default) are removed whenever an admin page
  loads, and GitHub request rows older than `github_retention_days` (90 by
  default) whenever `admin/github.php` does. Pruning happens there rather than on the logging path, because
  putting a `DELETE` in front of every visitor to keep a ninety-day window
  accurate to the minute is a bad trade.
- **With no database configured** (the default for anyone who forks this
  project and doesn't set one up), every original claim still holds exactly
  as before: nothing is stored, full stop.

If you want less than this: `'log_visits' => false` stops page views being
recorded while leaving key management and analysis logging working. Going
further — no logging of the anonymous UI, only of `api/website.php` calls,
for instance — is a code change to where `UsageLog::record()` is called from
(`api/analyze.php` and `api/website.php`), not a config flag; the same goes for
`GitHubLog::record()` (called from `GitHub::api()`, the one place every GitHub
request passes through) and `Feedback::record()` (called from
`api/feedback.php`, and only ever with a report somebody deliberately sent); there isn't
one, on purpose, to keep the actual behaviour legible in the places it
happens rather than scattered behind a setting. The same reasoning is why
`VisitLog::record()` is called in the first lines of `index.php`,
`method.php`, `verify.php`, `signs.php` and `catalogue.php` rather than from
`lib/bootstrap.php`: every page
that counts a visit says so in its own opening lines, and a page that does
not appear in that list does not count one.
