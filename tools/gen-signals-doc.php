#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Writes docs/SIGNALS.md from lib/Catalog.php.
 *
 *     php tools/gen-signals-doc.php          write the file
 *     php tools/gen-signals-doc.php --check  fail if it is out of date
 *
 * The catalogue is the source of truth. A signal whose documentation drifts
 * from its weight is worse than an undocumented one, so CI runs --check.
 */

require_once dirname(__DIR__) . '/lib/Catalog.php';

$check = in_array('--check', array_slice($argv, 1), true);
$path  = dirname(__DIR__) . '/docs/SIGNALS.md';

// Both the order and the per-category prose live in the catalogue itself, so
// that this file and the page at /catalogue cannot describe the same evidence
// differently. Markdown gets its emphasis added back on the way out.
$order  = Catalog::order();
$blurbs = Catalog::blurbs();
$blurbs[Catalog::CAT_AESTHETIC] = str_replace(
    'capped as a group', '**capped as a group**', $blurbs[Catalog::CAT_AESTHETIC]
);
$blurbs[Catalog::CAT_HISTORY] = str_replace(
    'a pasted git log', 'a pasted `git log`', $blurbs[Catalog::CAT_HISTORY]
);

$all = Catalog::all();
$cats = Catalog::categories();

$out  = "# The signal catalogue\n\n";
$out .= "<!-- Generated from lib/Catalog.php by tools/gen-signals-doc.php. Do not edit by hand. -->\n\n";
$out .= "Every signal the detector can fire, what it means, and what it is worth.\n\n";
$out .= "Weights are in **log-odds**, which is what makes them summable. Scoring starts from a\n";
$out .= "prior of −1.0 (the assumption that a given subject is *not* generated) and adds the\n";
$out .= "weight of each signal found, positive for AI, negative for human. The total goes through\n";
$out .= "a logistic curve to become the percentage. As a rough guide, a weight of 0.7 doubles the\n";
$out .= "odds; 4.5 ends the argument.\n\n";
$out .= sprintf("There are **%d signals** across %d categories.\n\n", count($all), count($order));

// Contents
$out .= "| Category | Signals | Direction |\n|---|---|---|\n";
foreach ($order as $cat) {
    $n = 0;
    foreach ($all as $meta) {
        if ($meta['category'] === $cat) $n++;
    }
    $dir = ($cat === Catalog::CAT_PROVENANCE) ? 'lowers the score' : 'raises the score';
    $out .= sprintf("| [%s](#%s) | %d | %s |\n", $cats[$cat], strtolower(str_replace(' ', '-', $cats[$cat])), $n, $dir);
}
$out .= "\n---\n";

foreach ($order as $cat) {
    $rows = array();
    foreach ($all as $id => $meta) {
        if ($meta['category'] === $cat) {
            $meta['id'] = $id;
            $rows[$id] = $meta;
        }
    }
    if (!$rows) {
        continue;
    }
    // Heaviest first, id breaking ties. Without the tiebreaker this file is not
    // reproducible: sorts are stable only from PHP 8.0, most weights are shared
    // by several signals, and --check then fails on 7.4 against a doc generated
    // on 8.x purely because of tie ordering.
    uasort($rows, function ($a, $b) {
        if ($a['weight'] === $b['weight']) {
            return strcmp($a['id'], $b['id']);
        }
        return $b['weight'] <=> $a['weight'];
    });

    $out .= "\n## " . $cats[$cat] . "\n\n";
    $out .= $blurbs[$cat] . "\n\n";

    foreach ($rows as $id => $meta) {
        $out .= sprintf(
            "### %s\n\n`%s` · weight **%s** (%s)\n\n%s\n\n",
            $meta['label'],
            $id,
            rtrim(rtrim(number_format((float) $meta['weight'], 2), '0'), '.'),
            Catalog::strengthOf((float) $meta['weight']),
            $meta['detail']
        );
    }
}

$out .= "\n---\n\n## Rules the scoring will not break\n\n";
$out .= "These are enforced in `Report::score()` and covered by `tests/run.php`.\n\n";
$out .= "1. **Aesthetic evidence is capped as a group** at 1.0 log-odds, and a subject with no\n";
$out .= "   non-aesthetic AI signals cannot score above 55% no matter how purple it is.\n";
$out .= "2. **No reading reaches 0% or 100%.** The scale is clamped to 3–97.\n";
$out .= "3. **Thin input is pulled toward the middle** rather than guessed at, and reports\n";
$out .= "   insufficient confidence.\n";
$out .= "4. **Confidence never exceeds 'moderate' without a platform fingerprint**, because\n";
$out .= "   pattern-reading without repository history does not earn more than that.\n";
$out .= "5. **Human signals are first-class** and weighted on the same scale.\n\n";
$out .= "## Adding a signal\n\n";
$out .= "Add the entry to `lib/Catalog.php`, fire it from `SiteAnalyzer` or `CodeAnalyzer`,\n";
$out .= "add a fixture case to `tests/`, then run `php tools/gen-signals-doc.php` to refresh\n";
$out .= "this file. See [CONTRIBUTING.md](../CONTRIBUTING.md) for what a new signal has to\n";
$out .= "justify before it is worth adding.\n";

if ($check) {
    $current = is_readable($path) ? (string) file_get_contents($path) : '';
    if ($current !== $out) {
        fwrite(STDERR, "docs/SIGNALS.md is out of date. Run: php tools/gen-signals-doc.php\n");
        exit(1);
    }
    echo "docs/SIGNALS.md is up to date\n";
    exit(0);
}

if (!is_dir(dirname($path))) {
    mkdir(dirname($path), 0755, true);
}
file_put_contents($path, $out);
echo 'wrote docs/SIGNALS.md (', strlen($out), " bytes, ", count($all), " signals)\n";
