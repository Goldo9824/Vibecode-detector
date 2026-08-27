# How the site describes itself

Everything a search engine and a link scraper read comes from one place:
`Seo::head()` in `lib/Seo.php`, called once in the head of each public page.

It was hand-written per page before, and had drifted the way hand-written
head tags always do — the front page had a Twitter card and no canonical,
the field guide had its canonical URL sitting in `og:url` and no Twitter
title, and the verify page had neither. A search engine reading three pages
of one site should not be able to tell they were written on three different
days.

## What every public page gets

| Tag | Why |
|---|---|
| `<title>`, `meta description` | The two lines of the search result. |
| `link rel="canonical"` | One address per page. Without it, `/verify` and `/verify.php` are two pages with the same words on them, and every certificate payload is a third. |
| `meta robots` | `index, follow, max-image-preview:large, max-snippet:-1` on a public page; whatever the page asked for otherwise. |
| `og:*` | Title, description, type, url, site name, locale, and a 1280×640 image with alt text. |
| `twitter:*` | A large summary card, with its own title and description rather than falling through to the OG ones by accident. |
| JSON-LD | Below. |

Every URL it writes is absolute and comes from `vcd_site_url()`, so a fork on
another domain — or in a subdirectory — advertises its own addresses rather
than this installation's. A relative `og:image` is not something a scraper
can fetch.

## Structured data

Three blocks on the front page, four on the field guide, three on the
catalogue, all of them `application/ld+json`:

- **WebSite**, on every page, so a result carries the site's name rather than
  one guessed out of the domain.
- **WebApplication** on the front page: what it is, that it is free, what it
  runs on, where the source is.
- **FAQPage** on the front page: the three questions the page is actually
  asked, answered in the words it already answers them in further down. In
  particular the first answer is the disclaimer, not a sales line — a rich
  result that oversells a detector is the one thing this site must not
  produce.
- **Article**, **ItemList** and **BreadcrumbList** on `signs.php`. The list is
  built from the `$signs` array that renders the page, so a sign added to the
  guide cannot go missing from the markup.
- **Article** and **BreadcrumbList** on `catalogue.php`, with the signal count
  in the description read from `Catalog::all()` rather than typed, so it cannot
  advertise a number the catalogue does not hold.

`Seo::jsonLd()` encodes with `JSON_HEX_TAG`, which is what stops a
description containing `</script>` from ending the block early. That is the
only way structured data turns into an injection, and there is a test for it.

## The sitemap

`sitemap.php`, served at `/sitemap.xml` by a rewrite in `.htaccess`. PHP
rather than a static file for the same reason as above: a committed
`sitemap.xml` would send every fork's crawler to this domain. On a host with
no `mod_rewrite` there is simply no sitemap, which costs nothing a crawler
cannot work out by following links.

It lists three pages: the front page, the field guide and the catalogue. The
verify form has one useful state and a different URL per certificate; the admin
panel is gated; the API is for callers who were given a key.

Its `lastmod` is the newest mtime among the files that decide what those pages
say — which now includes `lib/Catalog.php`, because a signal added there
changes `/catalogue` without anybody touching `catalogue.php`.

## What is deliberately not indexed

See [**docs/ADMIN.md**](ADMIN.md#keeping-it-out-of-search) for the admin
panel. In short: `/admin/` is disallowed *and* sends `X-Robots-Tag`;
everything else that should stay out of the index — the API endpoints and
`/verify` — is left crawlable on purpose so that the `noindex` header on it
is actually read.

`robots.txt` is the one file that names this domain in a way nothing can work
out at runtime, because it is static and served before any PHP runs. Forking
this? Change the domain on its `Sitemap:` line and nothing else.
