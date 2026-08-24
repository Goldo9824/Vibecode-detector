<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Certificate.php';

// Set before anything can go wrong, so the failure response carries it too:
// an unverifiable certificate URL is the last thing that should be indexed.
header('X-Robots-Tag: noindex');

$payload = isset($_REQUEST['p']) ? (string) $_REQUEST['p'] : '';
$sig     = isset($_REQUEST['s']) ? (string) $_REQUEST['s'] : '';

$cert = vcd_cert_open($payload, $sig);

if ($cert === null) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo "This certificate could not be verified.\n\n";
    echo "Certificates are signed by the server that issued them. Either this one was\n";
    echo "altered after it was issued, or it came from a different installation.\n";
    exit;
}

$pdf = new Certificate($cert);
$body = $pdf->render();

$name = 'vibe-code-certificate-' . strtolower((string) ($cert['id'] ?? 'report')) . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . strlen($body));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');

echo $body;
