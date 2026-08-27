#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Renders the GitHub social preview card to assets/img/social-preview.png.
 *
 *     php tools/build-social.php
 *
 * 1280x640 is GitHub's specified size. Everything is drawn at 3x and resampled
 * down, because GD antialiases text but not filled polygons, and the mark is
 * all filled polygons. Supersampling gets both for free.
 *
 * Needs PHP with GD and FreeType. That is a build-time requirement for this one
 * script and nothing else in the project depends on it — the site itself still
 * runs on a bare PHP install with no extensions worth naming.
 */

require_once dirname(__DIR__) . '/lib/Brand.php';

const W = 1280;
const H = 640;
const SS = 3; // supersample factor

// ---------------------------------------------------------------- environment

if (!function_exists('imagecreatetruecolor')) {
    fwrite(STDERR, "GD is not available in this PHP build.\n");
    exit(1);
}
if (!function_exists('imagettftext')) {
    fwrite(STDERR, "GD was built without FreeType, so text cannot be drawn.\n");
    exit(1);
}

/**
 * Fonts are looked up rather than hardcoded so this runs on more than one
 * machine. The card wants a monospace for the wordmark, matching the site
 * masthead, and a serif italic for the line underneath it.
 */
function findFont(array $candidates, string $what): string
{
    $roots = array(
        '/usr/share/fonts/truetype', '/usr/share/fonts', '/usr/local/share/fonts',
        '/Library/Fonts', '/System/Library/Fonts', getenv('HOME') . '/.fonts',
    );
    foreach ($candidates as $rel) {
        foreach ($roots as $root) {
            $path = $root . '/' . $rel;
            if (is_readable($path)) {
                return $path;
            }
        }
        if (is_readable($rel)) {
            return $rel;
        }
    }
    fwrite(STDERR, "Could not find a font for {$what}. Tried: " . implode(', ', $candidates) . "\n");
    exit(1);
}

$fontMono   = findFont(array('dejavu/DejaVuSansMono-Bold.ttf', 'liberation/LiberationMono-Bold.ttf'), 'the wordmark');
$fontItalic = findFont(array('liberation/LiberationSerif-Italic.ttf', 'freefont/FreeSerifItalic.ttf'), 'the tagline');
$fontSmall  = findFont(array('dejavu/DejaVuSansMono.ttf', 'liberation/LiberationMono-Regular.ttf'), 'the domain line');

// --------------------------------------------------------------------- canvas

$img = imagecreatetruecolor(W * SS, H * SS);
imagealphablending($img, true);

$rgb = function (array $c) use ($img) {
    return imagecolorallocate($img, $c[0], $c[1], $c[2]);
};

$paper = $rgb(Brand::PAPER_WEB);
$ink   = $rgb(Brand::INK_WEB);
$red   = $rgb(Brand::RED_WEB);
$grey  = $rgb(Brand::GREY_WEB);
$rule  = $rgb(Brand::RULE_WEB);

imagefilledrectangle($img, 0, 0, W * SS, H * SS, $paper);

// A hairline frame, the same device the certificate uses.
$inset = 40 * SS;
imagesetthickness($img, (int) round(1.5 * SS));
imagerectangle($img, $inset, $inset, W * SS - $inset, H * SS - $inset, $rule);
imagesetthickness($img, 1);

// ----------------------------------------------------------------- primitives

/** A stroked segment with round caps, built from a quad plus two discs. */
function thickLine($img, float $x1, float $y1, float $x2, float $y2, float $w, int $color): void
{
    $dx = $x2 - $x1;
    $dy = $y2 - $y1;
    $len = sqrt($dx * $dx + $dy * $dy);
    if ($len > 0.0001) {
        $nx = -$dy / $len * $w / 2;
        $ny = $dx / $len * $w / 2;
        imagefilledpolygon($img, array(
            (int) round($x1 + $nx), (int) round($y1 + $ny),
            (int) round($x2 + $nx), (int) round($y2 + $ny),
            (int) round($x2 - $nx), (int) round($y2 - $ny),
            (int) round($x1 - $nx), (int) round($y1 - $ny),
        ), 4, $color);
    }
    $d = (int) round($w);
    imagefilledellipse($img, (int) round($x1), (int) round($y1), $d, $d, $color);
    imagefilledellipse($img, (int) round($x2), (int) round($y2), $d, $d, $color);
}

/** @param array<int,array{0:float,1:float}> $pts */
function thickPath($img, array $pts, float $w, int $color): void
{
    for ($i = 1, $n = count($pts); $i < $n; $i++) {
        thickLine($img, $pts[$i - 1][0], $pts[$i - 1][1], $pts[$i][0], $pts[$i][1], $w, $color);
    }
}

/**
 * The mark, from the same geometry lib/Brand.php gives the SVG and the PDF.
 * The ring is a filled disc with a smaller disc punched out of it, because GD
 * has no usable thick-arc stroke.
 */
function drawMark($img, float $x, float $y, float $size, int $ink, int $accent, int $paper): void
{
    $s = $size / 100.0;
    $cx = $x + Brand::CX * $s;
    $cy = $y + Brand::CY * $s;
    $outer = (Brand::R + 3.0) * $s;
    $inner = (Brand::R - 3.0) * $s;

    imagefilledellipse($img, (int) round($cx), (int) round($cy), (int) round($outer * 2), (int) round($outer * 2), $ink);
    imagefilledellipse($img, (int) round($cx), (int) round($cy), (int) round($inner * 2), (int) round($inner * 2), $paper);

    $map = function (array $p) use ($x, $y, $s) {
        return array($x + $p[0] * $s, $y + $p[1] * $s);
    };

    thickPath($img, array_map($map, Brand::jaggedPath()), 5.5 * $s, $ink);
    thickPath($img, array_map($map, Brand::wavePath(96)), 5.5 * $s, $accent);
}

/** Advance width of one glyph in a monospaced face, measured exactly. */
function monoAdvance(string $font, float $size): float
{
    $one = imagettfbbox($size, 0, $font, 'MM');
    $two = imagettfbbox($size, 0, $font, 'MMM');
    return ($two[2] - $two[0]) - ($one[2] - $one[0]);
}

/** Draw monospaced text with extra tracking, the way the site masthead sets it. */
function trackedText($img, float $size, float $x, float $y, int $color, string $font, string $text, float $tracking): float
{
    $adv = monoAdvance($font, $size);
    $len = strlen($text);
    for ($i = 0; $i < $len; $i++) {
        imagettftext($img, $size, 0, (int) round($x), (int) round($y), $color, $font, $text[$i]);
        $x += $adv + $tracking;
    }
    return $len > 0 ? ($len * $adv + ($len - 1) * $tracking) : 0.0;
}

function trackedWidth(string $font, float $size, string $text, float $tracking): float
{
    $len = strlen($text);
    if ($len === 0) return 0.0;
    return $len * monoAdvance($font, $size) + ($len - 1) * $tracking;
}

function proportionalWidth(string $font, float $size, string $text): float
{
    $b = imagettfbbox($size, 0, $font, $text);
    return (float) ($b[2] - $b[0]);
}

// ------------------------------------------------------------------- contents

$name    = 'VIBE CODE DETECTOR';
$tagline = 'a vibecoded vibecode detector';
$domain  = 'vibecodedetector.fanficnow.com';

$markSize   = 292.0 * SS;
$gap        = 62.0 * SS;

$nameSize   = 52.0 * SS;
$nameTrack  = 8.0 * SS;
$tagSize    = 40.0 * SS;
$domainSize = 20.0 * SS;
$domainTrack = 2.8 * SS;

/**
 * Fit the lockup to the frame rather than trusting the nominal sizes.
 *
 * Font metrics are not knowable ahead of time: findFont() falls back to a
 * different family on a machine without DejaVu, and GD sizes text in points at
 * 96dpi, so a hand-tuned size that fits here silently runs off the edge
 * somewhere else. Measuring and scaling makes the card correct everywhere.
 */
$maxContentW = (W - 2 * 40 - 2 * 44) * SS; // frame inset, then breathing room

$measure = function () use (&$markSize, &$gap, &$nameSize, &$nameTrack, &$tagSize,
                           &$domainSize, &$domainTrack, $fontMono, $fontItalic, $fontSmall,
                           $name, $tagline, $domain) {
    $nameW   = trackedWidth($fontMono, $nameSize, $name, $nameTrack);
    $tagW    = proportionalWidth($fontItalic, $tagSize, $tagline);
    $domainW = trackedWidth($fontSmall, $domainSize, $domain, $domainTrack);
    $textW   = max($nameW, $tagW, $domainW);
    return array($textW, $markSize + $gap + $textW);
};

list($textW, $totalW) = $measure();

if ($totalW > $maxContentW) {
    $scale = $maxContentW / $totalW;
    foreach (array('markSize', 'gap', 'nameSize', 'nameTrack', 'tagSize', 'domainSize', 'domainTrack') as $var) {
        $$var *= $scale;
    }
    list($textW, $totalW) = $measure();
}

// Baseline offsets follow the wordmark, so the block stays proportional at
// whatever size the fit landed on.
$unit = $nameSize / (52.0 * SS);

$x0 = (W * SS - $totalW) / 2;
$cy = H * SS / 2;

drawMark($img, $x0, $cy - $markSize / 2, $markSize, $ink, $red, $paper);

$tx = $x0 + $markSize + $gap;

// Baselines chosen so the three lines sit optically centred as a block.
trackedText($img, $nameSize, $tx, $cy - 40 * SS * $unit, $ink, $fontMono, $name, $nameTrack);
imagettftext($img, $tagSize, 0, (int) round($tx), (int) round($cy + 26 * SS * $unit), $red, $fontItalic, $tagline);
trackedText($img, $domainSize, $tx, $cy + 88 * SS * $unit, $grey, $fontSmall, $domain, $domainTrack);

// A short accent rule between the wordmark and the line under it.
imagefilledrectangle(
    $img,
    (int) round($tx),
    (int) round($cy + 52 * SS * $unit),
    (int) round($tx + 72 * SS * $unit),
    (int) round($cy + 52 * SS * $unit + 2 * SS),
    $rule
);

// -------------------------------------------------------------------- output

$out = imagecreatetruecolor(W, H);
imagecopyresampled($out, $img, 0, 0, 0, 0, W, H, W * SS, H * SS);

$path = dirname(__DIR__) . '/assets/img/social-preview.png';
if (!imagepng($out, $path, 9)) {
    fwrite(STDERR, "Could not write {$path}\n");
    exit(1);
}

imagedestroy($img);
imagedestroy($out);

$size = filesize($path);
printf("wrote assets/img/social-preview.png (%dx%d, %.1f KB)\n", W, H, $size / 1024);
