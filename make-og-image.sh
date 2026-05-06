#!/usr/bin/env bash
# make-og-image.sh
# Resize + crop any image to 1200x630 for Open Graph / iMessage previews.
# Optionally composites a transparent PNG logo over the hero.
#
# Usage:
#   ./make-og-image.sh hero.jpg
#   ./make-og-image.sh hero.jpg -o og.jpg
#   ./make-og-image.sh hero.jpg -l logo.png
#   ./make-og-image.sh hero.jpg -l logo.png -lg SouthEast -ls 0.2
#   ./make-og-image.sh hero.jpg -l logo.png -lo 0.6
#   ./make-og-image.sh hero.jpg -l logo.png -lg SouthEast -lx -40 -ly -30
#   ./make-og-image.sh hero.jpg -g North -q 90

set -euo pipefail

usage() {
    echo "Usage: $(basename "$0") <input> [-o output] [-q quality] [-g gravity] [-l logo] [-lg logo-gravity] [-ls logo-scale]"
    echo "  -o   Output file (default: <input-basename>-og.jpg)"
    echo "  -q   JPEG quality 1-100 (default: 85)"
    echo "  -g   Hero crop gravity: Center North South East West ... (default: Center)"
    echo "  -l   Logo PNG to composite over the hero"
    echo "  -lg  Logo placement gravity (default: Center)"
    echo "  -ls  Logo width as fraction of 1200 e.g. 0.25 (default: 0.25)"
    echo "  -lo  Logo opacity 0.0-1.0 (default: 1.0)"
    echo "  -lx  Logo X offset in px from gravity anchor (default: 0)"
    echo "  -ly  Logo Y offset in px from gravity anchor (default: 0)"
    exit 1
}

[[ $# -eq 0 ]] && usage

SRC="$1"; shift
OUT=""
QUALITY=85
GRAVITY="Center"
LOGO=""
LOGO_GRAVITY="Center"
LOGO_SCALE="0.25"
LOGO_OPACITY="1.0"
LOGO_OFFSET_X=0
LOGO_OFFSET_Y=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        -o)  OUT="$2";             shift 2 ;;
        -q)  QUALITY="$2";         shift 2 ;;
        -g)  GRAVITY="$2";         shift 2 ;;
        -l)  LOGO="$2";            shift 2 ;;
        -lg) LOGO_GRAVITY="$2";    shift 2 ;;
        -ls) LOGO_SCALE="$2";      shift 2 ;;
        -lo) LOGO_OPACITY="$2";    shift 2 ;;
        -lx) LOGO_OFFSET_X="$2";   shift 2 ;;
        -ly) LOGO_OFFSET_Y="$2";   shift 2 ;;
        *)   usage ;;
    esac
done

if [[ ! -f "$SRC" ]]; then
    echo "Error: file not found: $SRC" >&2
    exit 1
fi

if [[ -z "$OUT" ]]; then
    DIR="$(dirname "$SRC")"
    BASE="$(basename "$SRC")"
    BASE="${BASE%.*}"
    OUT="$DIR/$BASE-og.jpg"
fi

LOGO_W=$(printf '%.0f' "$(echo "1200 * $LOGO_SCALE" | bc)")
GEOM=$(printf '%+d%+d' "$LOGO_OFFSET_X" "$LOGO_OFFSET_Y")

if [[ -n "$LOGO" ]]; then
    magick "$SRC" -auto-orient \
        -resize '1200x630^' -gravity "$GRAVITY" -extent '1200x630' \
        \( "$LOGO" -resize "${LOGO_W}x" -alpha set -evaluate multiply "$LOGO_OPACITY" \) \
        -gravity "$LOGO_GRAVITY" -geometry "$GEOM" -composite \
        -strip -quality "$QUALITY" -colorspace sRGB \
        "$OUT"
else
    magick "$SRC" -auto-orient \
        -resize '1200x630^' -gravity "$GRAVITY" -extent '1200x630' \
        -strip -quality "$QUALITY" -colorspace sRGB \
        "$OUT"
fi

SIZE=$(du -k "$OUT" | cut -f1)
echo "OK  $OUT  (${SIZE} KB)"
