#!/usr/bin/env bash
# make-og-image.sh
# Resize + crop any image to 1200x630 for Open Graph / iMessage previews.
#
# Usage:
#   ./make-og-image.sh photo.jpg
#   ./make-og-image.sh photo.jpg -o preview.jpg
#   ./make-og-image.sh photo.jpg -q 90
#   ./make-og-image.sh photo.png -g North   # crop from top instead of center

set -euo pipefail

usage() {
    echo "Usage: $(basename "$0") <input> [-o output] [-q quality] [-g gravity]"
    echo "  -o  Output file (default: <input-basename>-og.jpg)"
    echo "  -q  JPEG quality 1-100 (default: 85)"
    echo "  -g  Crop gravity: Center North South East West (default: Center)"
    exit 1
}

[[ $# -eq 0 ]] && usage

SRC="$1"; shift
OUT=""
QUALITY=85
GRAVITY="Center"

while [[ $# -gt 0 ]]; do
    case "$1" in
        -o) OUT="$2";     shift 2 ;;
        -q) QUALITY="$2"; shift 2 ;;
        -g) GRAVITY="$2"; shift 2 ;;
        *)  usage ;;
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

magick "$SRC" \
    -resize "1200x630^" \
    -gravity "$GRAVITY" \
    -extent 1200x630 \
    -strip \
    -quality "$QUALITY" \
    -colorspace sRGB \
    "$OUT"

SIZE=$(du -k "$OUT" | cut -f1)
echo "OK  $OUT  (${SIZE} KB)"
