# Key-authenticated API

`api/website.php` is a plain HTTP endpoint for analysing a live page or whole
site programmatically, gated behind an API key that only the operator of the
installation sets. It exists alongside `api/analyze.php` (which is what the
browser UI calls, anonymous and IP-rate-limited) for callers you have handed
a key to directly — scripts, another service, someone you trust with
higher-volume access.

## Setting up a key

Create `data/api-keys.txt` on the server, one key per line:

```
vcd-key-9f3a7c2e4b1d6a80c5e2f1b3a9d7c6e4
# given to jane@example.com, 2026-08-22
vcd-key-02b4e8f1c9a3d7b6e5f4a2c1d8b9e6f7
```

Blank lines and lines starting with `#` are ignored, so you can leave a note
next to each key. There is no key-generation tool built in — pick any long
random string, for example:

```
php -r 'echo bin2hex(random_bytes(24)), "\n";'
```

This file is **never committed** (`.gitignore` excludes it, same as
`data/secret.key`) and is unreachable over HTTP the same way every other file
under `data/` is (see `data/.htaccess` and the root `.htaccess` rewrite
denial). Removing a line and re-saving the file revokes that key immediately
— there is no caching, so the very next request sees the change.

If `data/api-keys.txt` does not exist or is empty, the endpoint refuses every
request with 401. There is no default key.

## Calling it

```
curl -H "X-Api-Key: vcd-key-9f3a7c2e4b1d6a80c5e2f1b3a9d7c6e4" \
  "https://your-install.example/api/website.php?url=https://target.example"
```

The key goes in the `X-Api-Key` header, never in the query string or a POST
field, so it doesn't end up in access logs or browser history.

| Parameter | Required | Meaning |
|---|---|---|
| `url` | yes | The page to fetch and analyse. |
| `crawl` | no | Any truthy value follows links from that page and analyses the whole site, same as the "Read the whole site" checkbox in the UI. Slower, and costs the site being read more too. |

`GET` and `POST` are both accepted; `url`/`crawl` can be sent as query
parameters either way.

### Response

The same JSON shape `api/analyze.php` returns for url mode: a score, a
verdict, a confidence level, the fired signals with excerpts, and a signed
`cert` token that `POST /api/certificate.php` (or `/verify`) can turn into a
PDF or check independently. See `lib/Report.php` for the exact fields.

### Errors

| Status | Meaning |
|---|---|
| 401 | Missing or invalid `X-Api-Key`. |
| 429 | This key has exceeded its rate limit. |
| 503 | The shared fetch-concurrency slot pool is full; retry shortly. |
| 400 | The URL couldn't be fetched (bad host, timeout, too large, blocked by robots.txt, etc.) — same failure modes as the UI's Live page tab. |

## Rate limit

`VCD_LIMIT_API_URL` in `lib/bootstrap.php` — 500 requests per 10 minutes,
**per key**, not per IP. One key is meant to be shared across whatever
machines its holder runs, so the budget travels with the key rather than
being split (or multiplied) by how many addresses use it. It sits far above
the anonymous UI's limit (`VCD_LIMIT_URL`, 40 per 10 minutes) because a caller
trusted enough to hold a key is trusted more than an anonymous visitor — the
real backstop against overload is the same global fetch-concurrency cap
(`VCD_MAX_CONCURRENT_FETCHES`) that protects the rest of the site, not this
number. Raise or lower it by editing the constant; there is no per-key
override.
