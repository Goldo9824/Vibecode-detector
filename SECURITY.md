# Security policy

## Reporting

**Do not open a public issue for a security problem.**

Report privately through
[GitHub Security Advisories](https://github.com/goldo9824/vibecode-detector/security/advisories/new).

Expect an acknowledgement within a few days. If a fix is needed it will be made in
private and released together with the advisory. You will be credited unless you
would rather not be.

## Supported versions

The deployed site at vibecodedetector.fanficnow.com, and the `main` branch. There
are no release branches to back-port to.

## In scope, in priority order

**1. The URL fetcher (`lib/Fetcher.php`).** This is the sharp edge of the whole
project: an anonymous stranger hands the server a URL and the server requests it.
Anything that gets it to reach somewhere it should not is the highest-severity class
of bug here.

Defences currently in place:

- scheme allow-list (`http`, `https` only) and a port allow-list;
- every resolved A and AAAA record checked against private, reserved, loopback,
  link-local and carrier-grade-NAT ranges, including IPv4-mapped IPv6;
- redirects followed manually, one hop at a time, with the full check reapplied at
  every hop and a redirect-loop guard;
- assets fetched same-origin only;
- hard caps on response size, aborted mid-download rather than buffered, plus
  connection and total timeouts.

Known and accepted: there is a **DNS-rebinding window** between the resolution check
and the connection, because PHP's HTTP clients do not expose connect-to-IP pinning
without extension-level support that shared hosting may not have. Reports that
demonstrate a practical exploit are welcome and will be taken seriously.

**2. Certificate integrity (`lib/bootstrap.php`, `api/certificate.php`).**
Certificates are HMAC-SHA256 signed over a compact payload and verified with
`hash_equals`. The PDF is rendered from the *verified* payload, and the signal list
is rebuilt from catalogue ids rather than from posted text, so a tampered link
cannot inject arbitrary content into a certificate. Anything that lets a certificate
be forged, or that gets unverified input into the rendered PDF, is in scope.

**3. Key handling.** `data/secret.key` is generated with `random_bytes(32)` on first
use, written `0600`, and denied by `.htaccess` in two independent ways. Any path
that exposes it is in scope. If the data directory is not writable the code falls
back to a key derived from the installation path — this is documented, weaker, and
reports about it are welcome as hardening suggestions rather than vulnerabilities.

**4. Output handling.** Analysed content is echoed back as evidence excerpts. It is
JSON-encoded and inserted with `textContent`, never `innerHTML`, and the page is
served under a CSP with `default-src 'none'`. Any route to XSS through analysed
content is in scope.

**5. Resource exhaustion.** Per-IP rate limiting is file-based and deliberately
fails open. Reports of a way to pin the server's CPU or fill its disk are in scope.

**6. Admin panel (`admin/`, `lib/AdminAuth.php`).** Password-gated, session-based,
with a per-form CSRF token and a rate-limited login. `data/admin-password.php` and
`data/db-config.php` are denied the same two ways as `data/secret.key`, and no
admin route is reachable without a valid session. In scope: anything that reaches
a dashboard action without a valid session or a valid CSRF token, a session fixation
or timing issue in the password check, or SQL injection in `lib/ApiKeys.php` /
`lib/UsageLog.php` (both use prepared statements throughout — a report showing that
assumption broken anywhere is high priority). Out of scope: brute-forcing the
password itself, which is rate-limited but not otherwise this project's problem to
solve — pick a strong one.

## Out of scope

- **That the detector is inaccurate.** It is, it says so, and that is a
  [wrong reading report](https://github.com/goldo9824/vibecode-detector/issues/new?template=false_positive.yml),
  not a vulnerability.
- Missing headers already set by `.htaccess` on a correctly configured host — check
  `curl -I` before reporting.
- Rate limits being bypassable by changing IP. They are a courtesy, not a control.
- Vulnerabilities in a fork, or in a deployment that skipped the two 403 checks in
  [docs/DEPLOY-LWS.md](docs/DEPLOY-LWS.md).
- Automated scanner output with no demonstrated impact.
- Social engineering, physical access, or anything requiring hosting-panel access.

## For self-hosters

After deploying, confirm both of these return 403 or 404:

```
curl -I https://your-domain/data/secret.key
curl -I https://your-domain/lib/Catalog.php
```

If either returns 200, `AllowOverride` is not enabled and the installation is not
safe to leave up. Back up `data/secret.key` privately: losing it invalidates every
certificate you have issued, and leaking it lets anyone forge one.

If you have set up the [admin panel](docs/ADMIN.md), also confirm:

```
curl -I https://your-domain/data/db-config.php
curl -I https://your-domain/data/admin-password.php
```

Both must return 403 or 404 — they carry your database password and your admin
login hash respectively.
