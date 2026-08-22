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

MySQL or MariaDB, reachable from this server. Nothing needs to be created in
it by hand — the first time `admin/index.php` connects successfully, it runs
`CREATE TABLE IF NOT EXISTS` for its two tables (`api_keys`, `usage_log`) and
leaves them alone after that. If your host only exposes phpMyAdmin and the
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

## Privacy: what changes

Before this feature, the honest claim on the front page and in
`CONTRIBUTING.md` was absolute: nothing about any analysis is stored,
anywhere, ever. Turning this on changes that, and the site's own copy has
been updated to say so rather than leave a false claim standing. Precisely
what changes:

- **Recorded, once a database is configured:** the mode of each analysis
  (live page / whole site / pasted code / git history), and for the two URL
  modes, the address analysed; which API key was used, if any; and a
  timestamp. That's it.
- **Never recorded, with or without a database:** the content of pasted code
  or a pasted git log, the body of a fetched page, or anything that
  identifies who is asking — no IP address, no cookie, no session tied to a
  visitor. This answers "how much is this tool used, and against what",
  never "who is using it".
- **With no database configured** (the default for anyone who forks this
  project and doesn't set one up), every original claim still holds exactly
  as before: nothing is stored, full stop.

If you want less than this — no logging of the anonymous UI, only of
`api/website.php` calls, for instance — that's a code change to where
`UsageLog::record()` is called from (`api/analyze.php` and
`api/website.php`), not a config flag; there isn't one, on purpose, to keep
the actual behaviour legible in the two places it happens rather than
scattered behind a setting.
