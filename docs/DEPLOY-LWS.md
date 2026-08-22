# Deploying to LWS shared hosting

There is no build step. Upload the files, and it runs.

That is not a boast, it is the design constraint: the target is an LWS mutualisé
account with FTP access and a PHP version picker, and nothing in this project may
assume anything more than that. No Composer, no npm, no shell access, no database,
no cron, no writable system temp.

---

## Requirements

| Requirement | Why |
|---|---|
| PHP 7.4 or newer | Typed properties and null coalescing. PHP 8.x is fine and is what LWS defaults to. |
| `curl` **or** `allow_url_fopen` | To fetch the page being analysed. The code prefers cURL and falls back on its own. |
| `mbstring` or `iconv` | Character-set handling on fetched pages and CP1252 conversion for the PDF. Either one is enough. |
| `zlib` | Optional. Compresses PDF content streams; without it certificates are simply larger. |

Nothing else. There is deliberately no dependency to install and nothing to keep updated.

---

## Upload

1. In the LWS panel, point the domain `vibecodedetector.fanficnow.com` at a
   directory — typically `~/vibecodedetector.fanficnow.com/`.
2. Set the PHP version for that domain to **8.1 or newer** (Panneau client →
   Hébergements → Gérer → Version PHP).
3. Upload the contents of this repository into that directory over FTP or the
   file manager. Keep the structure exactly as it is:

```
   index.php            the page
   verify.php           certificate verification
   .htaccess            security headers, denies lib/ and data/
   api/                 analyze.php, website.php, certificate.php
   lib/                 the engine — never served directly
   assets/              css, js, svg
   data/                created on first run, holds the signing key
                        (and, if you add it, api-keys.txt — see below)
```

4. Do **not** upload `tests/`, `tools/`, `docs/` or `.github/`. They are harmless
   if you do — `.htaccess` denies them — but they have no business on a web host.

That is the whole deployment.

---

## After the first request

The first request writes `data/secret.key`, a random 32-byte value that signs
certificates. Check that it appeared, and that it is not reachable:

```
curl -I https://vibecodedetector.fanficnow.com/data/secret.key   # expect 403
curl -I https://vibecodedetector.fanficnow.com/lib/Catalog.php   # expect 403
```

Both must return 403 or 404. If either returns 200, `.htaccess` is not being read:
check that `AllowOverride` is enabled for the directory, which it is by default on
LWS. Until that is fixed the installation is not safe to leave up.

### If `data/` is not writable

The code falls back to a key derived from the installation path so that
certificates still verify against themselves. This is weaker, and it means the key
changes if you move the directory, invalidating previously issued certificates.
Prefer to fix the permissions:

```
chmod 755 data
```

via the file manager, or set the directory to 755 over FTP.

---

## Keeping the key

`data/secret.key` is what makes a certificate from *your* installation verifiable.
It is in `.gitignore` for a reason. If you lose it, previously issued certificates
stop verifying; if you leak it, anyone can forge one. Back it up somewhere private,
and do not copy it between staging and production if you want those to be
distinguishable.

---

## API access

`api/website.php` lets a caller with a key analyse a page or site over plain
HTTP, outside the browser UI. There is no key by default — create
`data/api-keys.txt` by hand (never over git) to turn it on. See
[`docs/API.md`](API.md) for the request format and [`Keeping the key`](#keeping-the-key)
below for why this file lives outside the repo the same way `secret.key` does.

## Resource notes

Shared hosting is not generous, and the analyser is built around that:

- The URL fetcher caps downloads at 3 MB for the page and 768 KB per asset, and
  aborts mid-flight rather than buffering an oversized response.
- It fetches at most four same-origin assets, with a 6-second timeout each.
- Rate limiting is file-based in `data/rate/` — 20 URL analyses and 60 code
  analyses per IP per 10 minutes — and cleans up after itself. It **fails open**:
  if the directory misbehaves the site keeps working rather than locking everyone out.
- Nothing is logged, stored or cached between requests. There is no database
  because there is nothing to put in one.

If `max_execution_time` is low on your plan, a slow remote site can still time
out. That surfaces as a readable error in the browser, not a white page.

---

## Updating

Replace the files. There is no migration, no cache to clear and no state to carry
forward except `data/`, which you should leave alone.

To bump the asset cache-buster, change `VCD_VERSION` in `lib/bootstrap.php`;
the stylesheet and script are requested with it as a query string.
