# The picture of the front page

A URL report carries a picture of the page it read, above the evidence.

It is there to answer the question a wall of excerpts cannot: *is this even the
page I meant?* A reader who can see the front page can tell a parked domain from
a product site, a redirect from a hit, and a generated landing page from a
hand-built one, before reading a single signal. It is context, not evidence:
nothing in the score is computed from it, and switching it off changes no
reading this tool has ever produced.

## How it works

This server cannot render a page. Shared hosting has no headless browser, and
the project has no dependencies to install one with, so the shot is taken
somewhere else. The browser never talks to that somewhere else:

```
browser  ──►  api/snapshot.php  ──►  the renderer
         ◄──   image bytes      ◄──
```

Everything follows from passing it through rather than pointing the image tag
straight at the renderer:

- the visitor's address, user agent and referrer stay on this server;
- the content policy in `.htaccess` keeps saying `img-src 'self'`, because that
  is still true;
- only bytes that are actually an image, by magic number rather than by the
  renderer's say-so, are ever served from this domain.

The address of the page is signed into the request (`t=`), so the endpoint
answers for pages this installation offered a picture of and nothing else.
Without that it would be an open image proxy on somebody else's hosting bill.

**Nothing is written to disk**, at either end. The image is fetched, streamed to
the one visitor who asked for it, and forgotten. There is no cache directory, no
record of which pages were pictured, and nothing to clean up — the same as the
analysis itself.

## Who does the rendering

Two answers, and they differ in exactly one way that matters: who learns which
addresses are being checked.

| | **Your own renderer** (`self`) | **A hosted service** |
|---|---|---|
| Who is told the address | nobody outside your hosting | that service |
| Setup | one file on a server you run | none |
| Needs | a machine with Chromium | nothing |
| Default | **yes** | only when no renderer of your own is configured |

`self` is the default because it is the answer that gives nothing away. An
installation with no `data/snapshot-config.php` at all has never been asked the
question, so it falls back to the hosted default and names it under every
picture — that keeps a fresh clone working out of the box.

An endpoint configured **without** its secret is a different case: something was
configured and is wrong. Falling back there would send addresses to a third
party on the strength of a typo, so the picture disappears instead and you find
a broken feature rather than a broken promise.

## Running your own renderer

`tools/shot-server.php` is the whole thing: one file, no dependencies beyond PHP
and a Chromium binary. Nothing is installed and nothing is stored.

**On your server:**

```bash
# 1. a secret the two machines will share
openssl rand -hex 32

# 2. the renderer, on whatever port you like
VCD_SHOT_SECRET=<that secret> php -S 0.0.0.0:8791 tools/shot-server.php
```

That is the entire server side. If you would rather it lived behind the web
server you already run, copy the file into a docroot and set `VCD_SHOT_SECRET`
in the vhost or pool config instead — it is an ordinary PHP script and does not
care which server calls it.

**On the site:**

```php
<?php // data/snapshot-config.php
return array(
    'endpoint' => 'https://shots.example.com/shot-server.php',
    'secret'   => '<the same secret>',
);
```

There is no `'provider' => 'self'` line to add: that is already the default, so
an endpoint and a secret are the whole configuration.

### What the renderer needs

Chromium, and nothing else. It is found automatically if it is on the path under
any of the usual names (`chromium`, `chromium-browser`, `chrome`,
`google-chrome`, `google-chrome-stable`, `headless_shell`); otherwise say where
it is with `VCD_SHOT_BROWSER`. On a Debian or Ubuntu box that is:

```bash
apt-get install -y chromium        # or chromium-browser, depending on the release
```

### Settings, all optional

| Environment variable | What it does |
|---|---|
| `VCD_SHOT_SECRET` | **Required.** The shared secret. Without it the renderer refuses to answer at all, because an unauthenticated one on a public address is a screenshot service for the whole internet. |
| `VCD_SHOT_BROWSER` | Path to Chromium, when it is not on the path under a name it knows. |
| `VCD_SHOT_BROWSER_FLAGS` | Extra Chromium flags — a `--proxy-server`, a `--lang`, a `--user-agent`. One per line or separated by semicolons, so a flag whose value contains spaces still works. |
| `VCD_SHOT_MAX_CONCURRENT` | How many renders may run at once. Default 4; each one is a whole browser. |
| `VCD_SHOT_ALLOW_PRIVATE` | Set to `1` to allow screenshots of private and loopback addresses. For testing on your own machine only — leave it unset anywhere reachable. |

### What it refuses

- **Anything unsigned, or signed with the wrong secret** — 401. The signature
  covers the address, the size and an expiry, so a request lifted from a log is
  neither replayable five minutes later nor editable into a request for a
  different page.
- **Anything that is not a public web page** — 403. The site checks this before
  it signs anything, but the renderer is the machine with the browser on it, so
  it resolves the host and checks the addresses itself rather than trusting the
  caller.
- **More than `VCD_SHOT_MAX_CONCURRENT` renders at once** — 503, which the site
  reads as "still working" and retries.

### Keeping it running

`php -S` in a terminal stops when you close it. On a systemd box:

```ini
# /etc/systemd/system/vcd-shot.service
[Unit]
Description=Vibe Code Detector page renderer
After=network.target

[Service]
Environment=VCD_SHOT_SECRET=<that secret>
ExecStart=/usr/bin/php -S 127.0.0.1:8791 /srv/vcd/shot-server.php
Restart=always
User=www-data

[Install]
WantedBy=multi-user.target
```

Bind it to `127.0.0.1` like that and put your existing web server in front of it
for TLS, or bind `0.0.0.0` and give the site an `http://` endpoint on a network
you trust. The signature protects the request either way; TLS is what stops the
returned picture being read in transit.

## What a hosted renderer is told

Only relevant when you are using one — with a renderer of your own, nothing
below applies and no third party is involved at any point.

A hosted service is told the address being analysed. That is a real disclosure,
and the reason the report names the service underneath the picture rather than
presenting the shot as something this site produced.

It is not a *new* kind of disclosure — this server was already going to fetch
that page — but it is one more party who learns which address somebody asked
about, so it is stated on the page and in
[the privacy section of the README](../README.md#privacy).

An operator who wants neither a hosted service nor a renderer of their own
switches pictures off, and reports go back to being text and evidence only:

```php
<?php // data/snapshot-config.php
return array('enabled' => false);
```

## Configuring it

`data/snapshot-config.php` is optional and, like everything in `data/`, is never
served over HTTP. Every key has a default; an installation that wants its own
renderer needs only the first two.

```php
<?php
return array(
    'endpoint' => 'https://shots.example.com/shot-server.php',  // your renderer
    'secret'   => '<shared with it>',

    'enabled'  => true,     // false removes the picture from every report
    'provider' => 'self',   // self (default) | mshots | thumio | microlink | custom
    'label'    => '',       // what to call the renderer under the picture
    'width'    => 1200,
    'height'   => 900,
    'timeout'  => 20,       // seconds; 8 for a hosted service, which does not start a browser
);
```

### Using a hosted service instead

Set `provider` to one of these and drop `endpoint`/`secret`:

| `provider` | Service | Key needed |
|---|---|---|
| `mshots` | WordPress.com mShots | no — the fallback default, because there is nothing to sign up for |
| `thumio` | Thum.io | no, at its free tier |
| `microlink` | Microlink | no, at its free tier |
| `custom` | whatever you point it at | up to the service |

A service that is not in the table needs no patch, only a template and a name to
put under the picture:

```php
<?php
return array(
    'provider' => 'custom',
    'label'    => 'Urlbox',
    'template' => 'https://api.urlbox.io/v1/{key}/png?url={enc}&width={w}&height={h}',
    'key'      => 'your-key',
);
```

`{url}` is the address, `{enc}` the same percent-encoded, `{w}` and `{h}` the
viewport, `{key}` whatever is in `key`, and — for a renderer of your own —
`{endpoint}`, `{exp}` and `{sig}`. The template is used verbatim otherwise, so a
service with a different parameter spelling needs no code.

## Why a hosted picture sometimes takes a moment

Hosted services queue a page they have not rendered before and answer the first
request with a placeholder while they work. `api/snapshot.php` recognises that
answer and returns **202** rather than a broken image; the page waits a couple of
seconds and asks again, up to three times, and then gives up quietly.

A renderer of your own does not do this — it renders on the spot, in a second or
two — so the placeholder test is not applied to it at all. It either has the page
or says it does not, and a 5xx from it (busy, or a render that failed) is
retried the same way.

The report underneath is complete either way, which is the point of putting the
picture above the evidence but never inside it.

## Rate limits and worker pressure

A picture is an outbound request that holds a PHP worker while another machine
thinks, which is exactly what `VCD_MAX_CONCURRENT_FETCHES` exists to bound, so
the endpoint takes a slot from the same pool url-mode analysis does. Per visitor
it has its own budget of 120 requests per ten minutes — roomier than the analysis
limits because retries are part of the design.

Your own renderer has a matching cap at its end (`VCD_SHOT_MAX_CONCURRENT`,
default 4), held as a lock rather than a counter so that a render which dies
gives its slot back.
