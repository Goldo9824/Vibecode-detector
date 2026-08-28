<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Feedback.php';

/*
 * "This reading looks wrong."
 *
 * Takes the certificate the reading was issued with, plus which way the reader
 * thinks the number is wrong, and files it. The certificate is what makes this
 * safe to leave open: it is a signature over the mode, the address, the score
 * and the verdict, so the only readings that can be disputed are readings this
 * site actually produced, at the numbers it actually gave. Without it there
 * would be nothing between the table and anyone who felt like inventing ten
 * thousand complaints about a competitor's site.
 *
 * It is not authentication, and does not pretend to be: someone can run a
 * reading and then report it. It is provenance. The rate limit is what handles
 * volume, and the certificate id is what stops a reader who clicks twice from
 * counting twice.
 */

header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex');
header('Referrer-Policy: no-referrer');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    vcd_fail('Send this as a POST request.', 405);
}

// Generous, because a person reporting a bad reading is doing the site a
// favour and should never meet a wall for reporting three in a row. Tight
// enough that a script cannot fill the table from one address.
if (!vcd_rate_limit('feedback', 20, 600)) {
    vcd_fail('That is a lot of reports in ten minutes. Give it a moment.', 429);
}

$payload = isset($_POST['p']) ? (string) $_POST['p'] : '';
$sig     = isset($_POST['s']) ? (string) $_POST['s'] : '';

$cert = vcd_cert_open($payload, $sig);
if ($cert === null) {
    vcd_fail('That report does not carry a valid reading. Run the analysis again and report from its result.');
}

$reading = array(
    'cert_id' => isset($cert['id']) ? (string) $cert['id'] : '',
    'mode'    => isset($cert['m']) ? (string) $cert['m'] : '',
    'target'  => isset($cert['t']) ? (string) $cert['t'] : null,
    'score'   => isset($cert['s']) ? (int) $cert['s'] : 0,
    'verdict' => isset($cert['c']) ? (string) $cert['c'] : '',
);
if ($reading['cert_id'] === '' || $reading['mode'] === '') {
    vcd_fail('That reading is from an older version of this tool and cannot be reported. Run it again.');
}

// Pasted code and pasted git logs have no address, and what was pasted is
// never written down anywhere — so a report about one carries the score and
// the verdict and nothing about the subject. That is less useful and still
// worth having: it says the scale is off in that band.
if (!in_array($reading['mode'], array('url', 'site', 'repo'), true)) {
    $reading['target'] = null;
}

$direction = Feedback::normaliseDirection(isset($_POST['direction']) ? (string) $_POST['direction'] : '');
$truth     = Feedback::normaliseTruth(isset($_POST['truth']) ? (string) $_POST['truth'] : '');
$comment   = Feedback::normaliseComment(isset($_POST['comment']) ? (string) $_POST['comment'] : '');

$recorded = Feedback::record($reading, $direction, $truth, $comment);

vcd_json(array(
    'ok'       => true,
    'recorded' => $recorded,
    'message'  => $recorded
        ? 'Filed. Thank you — it goes in with the reading it disagrees with, so the scale can be checked against it.'
        : 'This installation keeps no record, so there was nowhere to file that. The issue tracker is the place instead.',
));
