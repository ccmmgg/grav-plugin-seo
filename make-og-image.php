#!/usr/bin/env php
<?php
// make-og-image.php
// Resize + crop any image to 1200x630 for Open Graph / iMessage previews.
// Optionally composites a transparent PNG logo over the hero.
// Requires only PHP + GD (no ImageMagick needed).
//
// Usage:
//   php make-og-image.php hero.jpg
//   php make-og-image.php hero.jpg -o og.jpg
//   php make-og-image.php hero.jpg -l logo.png
//   php make-og-image.php hero.jpg -l logo.png -lg SouthEast -ls 0.2
//   php make-og-image.php hero.jpg -l logo.png --lo 0.6
//   php make-og-image.php hero.jpg -l logo.png --lg SouthEast --lx -40 --ly -30
//   php make-og-image.php hero.jpg -g North -q 90

const OG_W = 1200;
const OG_H = 630;

function usage(): void {
    echo "Usage: php make-og-image.php <input> [-o output] [-q quality] [-g gravity] [-l logo] [-lg logo-gravity] [-ls logo-scale]\n";
    echo "  -o   Output file (default: <input-basename>-og.jpg)\n";
    echo "  -q   JPEG quality 1-100 (default: 85)\n";
    echo "  -g   Hero crop gravity: Center North South East West ... (default: Center)\n";
    echo "  -l   Logo PNG to composite over the hero\n";
    echo "  -lg  Logo placement gravity (default: Center)\n";
    echo "  -ls  Logo width as fraction of 1200 e.g. 0.25 (default: 0.25)\n";
    echo "  --lo Logo opacity 0.0-1.0 (default: 1.0)\n";
    echo "  --lx Logo X offset in px from gravity anchor (default: 0)\n";
    echo "  --ly Logo Y offset in px from gravity anchor (default: 0)\n";
    exit(1);
}

function load_image(string $path): GdImage {
    $type = exif_imagetype($path);
    $img = match ($type) {
        IMAGETYPE_JPEG => imagecreatefromjpeg($path),
        IMAGETYPE_PNG  => imagecreatefrompng($path),
        IMAGETYPE_GIF  => imagecreatefromgif($path),
        IMAGETYPE_WEBP => imagecreatefromwebp($path),
        default        => throw new RuntimeException("Unsupported image type (JPEG, PNG, GIF, WEBP only)"),
    };
    // Fix EXIF orientation (common on drone/phone photos)
    if ($type === IMAGETYPE_JPEG) {
        $exif = @exif_read_data($path);
        $img  = apply_exif_rotation($img, $exif['Orientation'] ?? 1);
    }
    return $img;
}

function apply_exif_rotation(GdImage $img, int $orientation): GdImage {
    return match ($orientation) {
        3 => imagerotate($img, 180, 0),
        6 => imagerotate($img, -90, 0),
        8 => imagerotate($img, 90, 0),
        default => $img,
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
        $axis === 'x' && str_contains($gravity, 'west')  => 0,
        $axis === 'x' && str_contains($gravity, 'east')  => $slack,
        $axis === 'y' && str_contains($gravity, 'north') => 0,
        $axis === 'y' && str_contains($gravity, 'south') => $slack,
        default => intdiv($slack, 2),
    };
}

function apply_opacity(GdImage $img, float $opacity): GdImage {
    $w   = imagesx($img);
    $h   = imagesy($img);
    $out = imagecreatetruecolor($w, $h);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    for ($x = 0; $x < $w; $x++) {
        for ($y = 0; $y < $h; $y++) {
            $c     = imagecolorat($img, $x, $y);
            $alpha = ($c >> 24) & 0x7F;                        // 0=opaque, 127=transparent
            $new_a = (int)(127 - (127 - $alpha) * $opacity);  // scale toward transparent
            imagesetpixel($out, $x, $y, imagecolorallocatealpha(
                $out,
                ($c >> 16) & 0xFF,
                ($c >> 8)  & 0xFF,
                $c         & 0xFF,
                $new_a
            ));
        }
    }
    return $out;
}

function logo_offset(int $canvas, int $logo_dim, string $gravity, string $axis, int $padding = 20): int {
    return match (true) {
        $axis === 'x' && str_contains($gravity, 'west')  => $padding,
        $axis === 'x' && str_contains($gravity, 'east')  => $canvas - $logo_dim - $padding,
        $axis === 'y' && str_contains($gravity, 'north') => $padding,
        $axis === 'y' && str_contains($gravity, 'south') => $canvas - $logo_dim - $padding,
        default => intdiv($canvas - $logo_dim, 2),
    };
}

// --- Parse args ---
if ($argc < 2) usage();

$src        = $argv[1];
$opts         = getopt('o:q:g:l:', ['lg:', 'ls:', 'lo:', 'lx:', 'ly:']);
$out          = $opts['o']  ?? '';
$quality      = (int)   ($opts['q']  ?? 85);
$gravity      = strtolower($opts['g']  ?? 'center');
$logo_path    = $opts['l']  ?? '';
$logo_grav    = strtolower($opts['lg'] ?? 'center');
$logo_scale   = (float) ($opts['ls'] ?? 0.25);
$logo_opacity = (float) ($opts['lo'] ?? 1.0);
$logo_offset_x = (int)  ($opts['lx'] ?? 0);
$logo_offset_y = (int)  ($opts['ly'] ?? 0);

if (!file_exists($src)) {
    fwrite(STDERR, "File not found: $src\n");
    exit(1);
}

if ($out === '') {
    $dir  = dirname($src);
    $base = pathinfo($src, PATHINFO_FILENAME);
    $out  = $dir . DIRECTORY_SEPARATOR . $base . '-og.jpg';
}

// --- Load + fix rotation + flatten alpha ---
$img  = load_image($src);
$type = exif_imagetype($src);
if (in_array($type, [IMAGETYPE_PNG, IMAGETYPE_GIF], true)) {
    $img = flatten_alpha($img);
}

$src_w = imagesx($img);
$src_h = imagesy($img);

// --- Resize to cover OG canvas ---
$scale    = max(OG_W / $src_w, OG_H / $src_h);
$crop_x   = crop_offset((int)ceil($src_w * $scale), OG_W, $gravity, 'x');
$crop_y   = crop_offset((int)ceil($src_h * $scale), OG_H, $gravity, 'y');

$dst = imagecreatetruecolor(OG_W, OG_H);
imagecopyresampled(
    $dst, $img,
    0, 0,
    (int)($crop_x / $scale), (int)($crop_y / $scale),
    OG_W, OG_H,
    (int)(OG_W / $scale), (int)(OG_H / $scale)
);
imagedestroy($img);

// --- Composite logo ---
if ($logo_path !== '') {
    if (!file_exists($logo_path)) {
        fwrite(STDERR, "Logo not found: $logo_path\n");
        exit(1);
    }
    $logo = imagecreatefrompng($logo_path);
    $lw   = imagesx($logo);
    $lh   = imagesy($logo);

    $target_lw = (int)(OG_W * $logo_scale);
    $target_lh = (int)($lh * $target_lw / $lw);

    $scaled_logo = imagecreatetruecolor($target_lw, $target_lh);
    imagealphablending($scaled_logo, false);
    imagesavealpha($scaled_logo, true);
    $transparent = imagecolorallocatealpha($scaled_logo, 0, 0, 0, 127);
    imagefill($scaled_logo, 0, 0, $transparent);
    imagecopyresampled($scaled_logo, $logo, 0, 0, 0, 0, $target_lw, $target_lh, $lw, $lh);
    imagedestroy($logo);

    if ($logo_opacity < 1.0) {
        $faded = apply_opacity($scaled_logo, $logo_opacity);
        imagedestroy($scaled_logo);
        $scaled_logo = $faded;
    }

    $lx = logo_offset(OG_W, $target_lw, $logo_grav, 'x') + $logo_offset_x;
    $ly = logo_offset(OG_H, $target_lh, $logo_grav, 'y') + $logo_offset_y;

    imagealphablending($dst, true);
    imagecopy($dst, $scaled_logo, $lx, $ly, 0, 0, $target_lw, $target_lh);
    imagedestroy($scaled_logo);
}

// --- Save ---
imagejpeg($dst, $out, $quality);
imagedestroy($dst);

$kb = round(filesize($out) / 1024, 1);
echo "OK  $out  ({$kb} KB)\n";
