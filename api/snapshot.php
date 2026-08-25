<?php
declare(strict_types=1);

/**
 * The picture at the top of a URL report.
 *
 * The browser asks this for the image rather than asking the renderer
 * directly, so that a visitor who looks at a report never makes a request to
 * a third party — see lib/Snapshot.php for why that matters and what the
 * renderer is told regardless. Nothing here is written down: the bytes are
 * fetched, sent to the one visitor who asked, and gone.
 *
 * Answers 202 while the renderer is still working, which is the normal first
 * answer for a page it has not seen before. The page retries; the report
 * underneath is already complete either way.
 */

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Snapshot.php';

header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; sandbox");

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    vcd_fail('Send this as a GET request.', 405);
}

if (!Snapshot::enabled()) {
    vcd_fail('Page pictures are switched off on this installation.', 404);
}

$target = isset($_GET['u']) ? (string) $_GET['u'] : '';
$token  = isset($_GET['t']) ? (string) $_GET['t'] : '';

if ($target === '' || !Snapshot::tokenValid($target, $token)) {
    vcd_fail('That is not an address this site offered a picture of.', 403);
}

// The signature says this installation offered the picture, not how often it
// may be asked for. Retries are part of the design, so the budget is roomy
// enough to cover a handful of reports each retrying a few times.
if (!vcd_rate_limit('snapshot', 120, 600)) {
    vcd_fail('That is a lot of pictures in ten minutes. Give it a moment.', 429);
}

// Shares the global fetch cap with url-mode analysis: this is another
// outbound request holding a worker while a remote machine thinks about it,
// which is the thing that cap exists to bound.
$slot = vcd_acquire_fetch_slot();
if ($slot === null) {
    vcd_fail('This tool is busy right now. Try again in a few seconds.', 503);
}
if ($slot !== '') {
    register_shutdown_function('vcd_release_fetch_slot', $slot);
}

$shot = Snapshot::capture($target);

if ($shot['state'] === 'pending') {
    header('Retry-After: 3');
    vcd_json(array('ok' => false, 'state' => 'pending', 'error' => $shot['reason']), 202);
}
if ($shot['state'] !== 'ready') {
    vcd_fail($shot['reason'], $shot['state'] === 'off' ? 404 : 502);
}

// Private, because the address being pictured is in the query string and a
// shared cache has no business holding a record of who looked at what. Short,
// because a page changes and this is not worth remembering for long.
header('Cache-Control: private, max-age=600');
header('Content-Type: ' . $shot['type']);
header('Content-Length: ' . strlen($shot['body']));
echo $shot['body'];
