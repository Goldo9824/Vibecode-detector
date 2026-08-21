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

$order = array(
    Catalog::CAT_FINGERPRINT,
    Catalog::CAT_HISTORY,
    Catalog::CAT_SITEWIDE,
    Catalog::CAT_STRUCTURE,
    Catalog::CAT_CODE,
    Catalog::CAT_CONTENT,
    Catalog::CAT_SECURITY,
    Catalog::CAT_AESTHETIC,
    Catalog::CAT_PROVENANCE,
);

$blurbs = array(
    Catalog::CAT_FINGERPRINT => 'A builder naming itself. These are positive identifications rather than inferences, so one is enough to settle the question. Their absence means nothing whatsoever: agentic editors write into an ordinary repository and leave none of this behind.',
    Catalog::CAT_HISTORY     => 'How the code arrived, rather than what it looks like. The strongest evidence available short of a fingerprint, and the hardest to fake after the fact: a convincing forged history means inventing plausible timestamps, authors, mistakes and reverts for every commit. Read from a pasted `git log`.',
    Catalog::CAT_SITEWIDE    => 'What only becomes visible once several pages have been read together. A single page cannot tell you whether a site was built in one pass or accreted over years; ten pages usually can. Available in whole-site mode.',
    Catalog::CAT_STRUCTURE   => 'The shape of the whole rather than the style of the line. These are the hardest signals to produce by accident and the hardest to remove by editing, which is why they carry the most weight after fingerprints.',
    Catalog::CAT_CODE        => 'Line-level habits. Individually weak and easy to mask by renaming or reformatting; convincing only when four or more of them converge across a file.',
    Catalog::CAT_CONTENT     => 'What the page says, as opposed to how it is built. Placeholder people, house-voice marketing copy and navigation that goes nowhere.',
    Catalog::CAT_SECURITY    => 'The most durable family. Syntax correctness in generated code has climbed past 95% while security pass rates have stayed near 55%, so these signals decay far more slowly than the stylistic ones and will outlive most of this document.',
    Catalog::CAT_AESTHETIC   => 'How it looks. Weak by construction and **capped as a group**, because a purple gradient and a default icon set are a reason to look closer and never a conclusion.',
    Catalog::CAT_PROVENANCE  => 'Evidence of a human having been present: outside context a generator has no access to, inconsistency, accretion, mess. These subtract from the score and are weighted as heavily as the signals pointing the other way.',
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
