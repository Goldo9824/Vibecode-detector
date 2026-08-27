# Key-authenticated API

`api/website.php` is a plain HTTP endpoint for analysing a live page, a whole
site, or a public GitHub repository programmatically, gated behind an API key
that only the operator of the installation sets. It exists alongside `api/analyze.php` (which is what the
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
| `url` | one of `url` or `repo` | The page to fetch and analyse. |
| `repo` | one of `url` or `repo` | A public GitHub repository, as `owner/name` or any github.com URL for it — same as the "GitHub repo" tab in the UI. Sending both `url` and `repo` is refused rather than guessed at. |
| `crawl` | no | Only meaningful with `url`. Any truthy value follows links from that page and analyses the whole site, same as the "Read the whole site" checkbox in the UI. Slower, and costs the site being read more too. |

`GET` and `POST` are both accepted; every parameter can be sent as a query
parameter either way.

```
curl -H "X-Api-Key: vcd-key-9f3a7c2e4b1d6a80c5e2f1b3a9d7c6e4" \
  "https://your-install.example/api/website.php?repo=owner/name"
```

### Response

The same JSON shape `api/analyze.php` returns: a score, a verdict, a
confidence level, the fired signals with excerpts, and a signed `cert` token
that `POST /api/certificate.php` (or `/verify`) can turn into a PDF or check
independently. See `lib/Report.php` for the exact fields.

`mode` says which reading it was — `url`, `site` or `repo` — and `target` is
the address or, in repo mode, `github.com/owner/name`. A repo response carries
its scope in `stats`: `commits` against `commitsRead`, and `files` against
`filesRead`. Those pairs are the difference between what exists and what was
looked at, and they are published rather than smoothed over because a
repository is sampled, not read.

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

### Errors

| Status | Meaning |
|---|---|
| 401 | Missing or invalid `X-Api-Key`. |
| 429 | This key has exceeded its rate limit. |
| 503 | The shared fetch-concurrency slot pool is full; retry shortly. |
| 400 | The URL couldn't be fetched (bad host, timeout, too large, blocked by robots.txt, etc.) — same failure modes as the UI's Live page tab. In repo mode: no such public repository, an empty one, one on another forge, or GitHub rate-limiting this server. |

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

### Repository reads have a second limit that is not yours

A `repo` request also spends up to eight requests from **GitHub's** hourly
allowance, which belongs to the installation's server address rather than to
the key. Unauthenticated that is 60 an hour for every API caller and every
browser visitor put together, which is roughly seven repository reads. An
operator who expects the mode to be used should configure a token — see
[Repository reads in docs/DEPLOY-LWS.md](DEPLOY-LWS.md#github-repository-reads-optional)
— which raises it to 5,000. No API key can buy more of this budget, and no
per-key limit protects it, so a caller that loops over repositories will take
the mode down for everybody until the hour rolls over.
