<?php
declare(strict_types=1);

/**
 * Writes the static SVG files from lib/Brand.php so the mark has exactly one
 * definition. Run after changing the geometry:
 *
 *     php tools/build-assets.php
 *
 * CI re-runs it and fails if the committed files come out different.
 */

require_once dirname(__DIR__) . '/lib/Brand.php';

$root = dirname(__DIR__);
$dir  = $root . '/assets/img';

if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
    fwrite(STDERR, "Cannot create $dir\n");
    exit(1);
}

$files = array(
    // Inherits colour from the page, so it works in both themes.
    $dir . '/logo.svg' => Brand::markSvg(120, 'currentColor', '#b8402e', 'role="img" aria-label="Vibe Code Detector"'),

    // Standalone: a favicon has no page to inherit from.
    $dir . '/favicon.svg' => str_replace(
        '<svg ',
        '<svg style="background:#f6f2e9" ',
        Brand::markSvg(64, '#17140f', '#b8402e')
    ),

    // Social card mark on the paper ground, large.
    $dir . '/mark-512.svg' => str_replace(
        '<svg ',
        '<svg style="background:#f6f2e9" ',
        Brand::markSvg(512, '#17140f', '#b8402e', 'role="img" aria-label="Vibe Code Detector"')
    ),
);

foreach ($files as $path => $svg) {
    $out = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n" . $svg . "\n";
    if (file_put_contents($path, $out) === false) {
        fwrite(STDERR, "Cannot write $path\n");
        exit(1);
    }
    echo 'wrote ', str_replace($root . '/', '', $path), ' (', strlen($out), " bytes)\n";
}
