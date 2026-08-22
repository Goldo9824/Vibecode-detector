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
    'log_visits'           => true,   // default; false stops recording page views
    'visit_retention_days' => 90,     // default; visit rows older than this are deleted
```

MySQL or MariaDB, reachable from this server. Nothing needs to be created in
it by hand — the first time `admin/index.php` connects successfully, it runs
`CREATE TABLE IF NOT EXISTS` for its three tables (`api_keys`, `usage_log`,
`visit_log`) and leaves them alone after that. If your host only exposes phpMyAdmin and the
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
- **Usage, last 30 days** — total analyses by mode (live page, whole site,
  pasted code, git history) across everyone, and the most-analysed websites
  across all callers.
- **View usage** on a key's row — the same breakdown restricted to that one
  key: how many requests, and which websites it pointed at.

The panel is disallowed in `robots.txt` and every admin page sets
`X-Robots-Tag`-equivalent `noindex, nofollow`, but that is not access
control — the password is. Nothing else links to `/admin/` from the public
site.

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

## Privacy: what changes

Before this feature, the honest claim on the front page and in
`CONTRIBUTING.md` was absolute: nothing about any analysis is stored,
anywhere, ever. Turning this on changes that, and the site's own copy has
been updated to say so rather than leave a false claim standing. Precisely
what changes:

- **Analyses, once a database is configured:** the mode of each analysis
  (live page / whole site / pasted code / git history), and for the two URL
  modes, the address analysed; which API key was used, if any; and a
  timestamp. That's it.
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
- **Never recorded, with or without a database:** the content of pasted code
  or a pasted git log, the body of a fetched page, an IP address, a cookie,
  or a session tied to a visitor.
- **Deleted on a rolling window:** visit rows older than
  `visit_retention_days` (90 by default) are removed whenever an admin page
  loads. Pruning happens there rather than on the logging path, because
  putting a `DELETE` in front of every visitor to keep a ninety-day window
  accurate to the minute is a bad trade.
- **With no database configured** (the default for anyone who forks this
  project and doesn't set one up), every original claim still holds exactly
  as before: nothing is stored, full stop.

If you want less than this: `'log_visits' => false` stops page views being
recorded while leaving key management and analysis logging working. Going
further — no logging of the anonymous UI, only of `api/website.php` calls,
for instance — is a code change to where `UsageLog::record()` is called from
(`api/analyze.php` and `api/website.php`), not a config flag; there isn't
one, on purpose, to keep the actual behaviour legible in the places it
happens rather than scattered behind a setting. The same reasoning is why
`VisitLog::record()` is called in the first lines of `index.php`,
`verify.php` and `signs.php` rather than from `lib/bootstrap.php`: every page
that counts a visit says so in its own opening lines, and a page that does
not appear in that list does not count one.
