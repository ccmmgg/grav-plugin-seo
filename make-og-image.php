#!/usr/bin/env php
<?php
// make-og-image.php
// Resize + crop any image to 1200x630 for Open Graph / iMessage previews.
// Requires only PHP + GD (no ImageMagick needed).
//
// Usage:
//   php make-og-image.php photo.jpg
//   php make-og-image.php photo.jpg -o preview.jpg
//   php make-og-image.php photo.jpg -q 90
//   php make-og-image.php photo.png -g North

const OG_W = 1200;
const OG_H = 630;

function usage(): void {
    echo "Usage: php make-og-image.php <input> [-o output] [-q quality] [-g gravity]\n";
    echo "  -o  Output file (default: <input-basename>-og.jpg)\n";
    echo "  -q  JPEG quality 1-100 (default: 85)\n";
    echo "  -g  Crop gravity: Center North South East West (default: Center)\n";
    exit(1);
}

function load_image(string $path): GdImage {
    $type = exif_imagetype($path);
    return match ($type) {
        IMAGETYPE_JPEG => imagecreatefromjpeg($path),
        IMAGETYPE_PNG  => imagecreatefrompng($path),
        IMAGETYPE_GIF  => imagecreatefromgif($path),
        IMAGETYPE_WEBP => imagecreatefromwebp($path),
        default        => throw new RuntimeException("Unsupported image type (JPEG, PNG, GIF, WEBP only)"),
    };
}

function flatten_alpha(GdImage $img): GdImage {
    $w   = imagesx($img);
    $h   = imagesy($img);
    $out = imagecreatetruecolor($w, $h);
    $white = imagecolorallocate($out, 255, 255, 255);
    imagefill($out, 0, 0, $white);
    imagecopy($out, $img, 0, 0, 0, 0, $w, $h);
    return $out;
}

function crop_offset(int $scaled, int $target, string $gravity, string $axis): int {
    $slack = $scaled - $target;
    if ($slack <= 0) return 0;
    return match (true) {
        $axis === 'x' && str_contains($gravity, 'West')  => 0,
        $axis === 'x' && str_contains($gravity, 'East')  => $slack,
        $axis === 'y' && str_contains($gravity, 'North') => 0,
        $axis === 'y' && str_contains($gravity, 'South') => $slack,
        default => intdiv($slack, 2),
    };
}

// --- Parse args ---
if ($argc < 2) usage();

$src     = $argv[1];
$opts    = getopt('o:q:g:', [], $rest_index);
$out     = $opts['o'] ?? '';
$quality = (int) ($opts['q'] ?? 85);
$gravity = ucfirst(strtolower($opts['g'] ?? 'center'));

$valid_gravities = ['Center', 'North', 'South', 'East', 'West', 'Northeast', 'Northwest', 'Southeast', 'Southwest'];
if (!in_array($gravity, $valid_gravities, true)) {
    fwrite(STDERR, "Invalid gravity '$gravity'. Valid: " . implode(', ', $valid_gravities) . "\n");
    exit(1);
}

if (!file_exists($src)) {
    fwrite(STDERR, "File not found: $src\n");
    exit(1);
}

if ($out === '') {
    $dir  = dirname($src);
    $base = pathinfo($src, PATHINFO_FILENAME);
    $out  = $dir . DIRECTORY_SEPARATOR . $base . '-og.jpg';
}

// --- Load + flatten alpha ---
$img  = load_image($src);
$type = exif_imagetype($src);
if (in_array($type, [IMAGETYPE_PNG, IMAGETYPE_GIF], true)) {
    $img = flatten_alpha($img);
}

$src_w = imagesx($img);
$src_h = imagesy($img);

// --- Compute scale to cover OG_W x OG_H ---
$scale    = max(OG_W / $src_w, OG_H / $src_h);
$scaled_w = (int) ceil($src_w * $scale);
$scaled_h = (int) ceil($src_h * $scale);

// --- Compute crop origin ---
$crop_x = crop_offset($scaled_w, OG_W, $gravity, 'x');
$crop_y = crop_offset($scaled_h, OG_H, $gravity, 'y');

// --- Resample directly into 1200x630 canvas ---
$dst = imagecreatetruecolor(OG_W, OG_H);
imagecopyresampled(
    $dst, $img,
    0, 0,
    (int) ($crop_x / $scale), (int) ($crop_y / $scale),
    OG_W, OG_H,
    (int) (OG_W / $scale), (int) (OG_H / $scale)
);

imagedestroy($img);

// --- Save ---
imagejpeg($dst, $out, $quality);
imagedestroy($dst);

$kb = round(filesize($out) / 1024, 1);
echo "OK  $out  ({$kb} KB)\n";
