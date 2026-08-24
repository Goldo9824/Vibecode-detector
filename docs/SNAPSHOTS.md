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
the project has no dependencies to install one with, so the shot is taken by a
rendering service. The browser never talks to that service:

```
browser  ──►  api/snapshot.php  ──►  the renderer
         ◄──   image bytes      ◄──
```

Everything follows from passing it through rather than pointing the `<img>`
straight at the renderer:

- the visitor's address, user agent and referrer stay on this server;
- the content policy in `.htaccess` keeps saying `img-src 'self'`, because that
  is still true;
- only bytes that are actually an image, by magic number rather than by the
  renderer's say-so, are ever served from this domain.

The address of the page is signed into the request (`t=`), so the endpoint
answers for pages this installation offered a picture of and nothing else.
Without that it would be an open image proxy on somebody else's hosting bill.

**Nothing is written to disk.** The image is fetched, streamed to the one
visitor who asked for it, and forgotten. There is no cache directory, no record
of which pages were pictured, and nothing to clean up — the same as the
analysis itself.

## What the renderer is told

The address being analysed. That is a real disclosure, and the reason the
report names the renderer underneath the picture rather than presenting it as
something this site produced.

It is not a *new* kind of disclosure — this server was already going to fetch
that page — but it is one more party who learns which address somebody asked
about, so it is stated on the page and in
[the privacy section of the README](../README.md#privacy).

An operator who does not want to make it switches pictures off, and reports go
back to being text and evidence only:

```php
<?php // data/snapshot-config.php
return array('enabled' => false);
```

## Configuring it

`data/snapshot-config.php` is optional and, like everything in `data/`, is
never served over HTTP. With no file at all the default renderer is used.

```php
<?php
return array(
    'enabled'  => true,        // false removes the picture from every report
    'provider' => 'mshots',    // mshots | thumio | microlink | custom
    'key'      => '',          // if the provider needs one
    'width'    => 1200,
    'height'   => 900,
    'timeout'  => 8,           // seconds to wait for the renderer
);
```

| `provider` | Service | Key needed |
|---|---|---|
| `mshots` | WordPress.com mShots | no — the default, because there is nothing to sign up for |
| `thumio` | Thum.io | no, at its free tier |
| `microlink` | Microlink | no, at its free tier |
| `custom` | whatever you point it at | up to the service |

A service that is not in the table needs no patch, only a template and a name
to put under the picture:

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
viewport, `{key}` whatever is in `key`. The template is used verbatim
otherwise, so a service with a different parameter spelling needs no code.

## Why the picture sometimes takes a moment

These services queue a page they have not rendered before and answer the first
request with a placeholder while they work. `api/snapshot.php` recognises that
answer and returns **202** rather than a broken image; the page waits a couple
of seconds and asks again, up to three times, and then gives up quietly. The
report underneath is complete either way — which is the point of putting the
picture above the evidence but never inside it.

## Rate limits and worker pressure

A picture is an outbound request that holds a PHP worker while a remote machine
thinks, which is exactly what `VCD_MAX_CONCURRENT_FETCHES` exists to bound, so
the endpoint takes a slot from the same pool url-mode analysis does. Per
visitor it has its own budget of 120 requests per ten minutes — roomier than
the analysis limits because retries are part of the design.
