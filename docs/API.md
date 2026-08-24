# Key-authenticated API

`api/website.php` is a plain HTTP endpoint for analysing a live page or whole
site programmatically, gated behind an API key that only the operator of the
installation sets. It exists alongside `api/analyze.php` (which is what the
browser UI calls, anonymous and IP-rate-limited) for callers you have handed
a key to directly — scripts, another service, someone you trust with
higher-volume access.

## Setting up a key

Two ways to do this — pick one, or use both at once, since `api/website.php`
checks both:

**Managed, with names and usage stats:** configure a database and use the
[admin panel](ADMIN.md) at `/admin/` — create a key with a name, revoke it
later without touching a file, and see which websites it's been used against.

**Manual, no database needed:** create `data/api-keys.txt` on the server, one
key per line:

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

If neither a database key nor `data/api-keys.txt` matches, the endpoint refuses
the request with 401. There is no default key.

## Handing a key to someone

Give them the key and point them at **[`llms.txt`](../llms.txt)**, at the
root of the site (`https://your-install.example/llms.txt`). It's written
directly for an AI agent to read and follow — the request format, the exact
response shape, every error code, and the ground rules for calling the
endpoint responsibly — so if the recipient is having an AI make the calls on
their behalf, that file alone is enough context to hand it.

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

Each signal carries its evidence twice. `evidence` is the flat list of strings
it has always been. `excerpts` is the same evidence with everything needed to
check it: the line it was found on, the document it came from, how many times
that pattern fired, and the lines above and below it — `context`, with
`match: true` marking the one that fired. Alongside them, `occurrences` is how
often the signal fired in total (which can be more than the four excerpts
published), `repetition` is the multiplier that count earned, and
`scoredWeight` is the weight actually used in the score. `lib/Evidence.php`
masks credential-shaped strings before any of it is published, in the context
lines as well as the match.

`snapshot` is present for any installation that offers page pictures: `url` is
an address on this site that answers with an image of the front page,
`provider` names who rendered it, and `width`/`height` are the viewport it was
rendered at. The address carries a signature, so it works as given and cannot
be edited to point at another page. Expect **202** while the renderer is still
working — wait a few seconds and ask again — and no `snapshot` key at all where
the operator has switched pictures off. See
[SNAPSHOTS.md](SNAPSHOTS.md).

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
