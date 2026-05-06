# make-og-image.ps1
# Resize + crop any image to 1200x630 for Open Graph / iMessage previews.
#
# Usage:
#   .\make-og-image.ps1 photo.jpg
#   .\make-og-image.ps1 photo.jpg -Out preview.jpg
#   .\make-og-image.ps1 photo.jpg -Quality 90
#   .\make-og-image.ps1 photo.png -Gravity North   # crop from top instead of center

param(
    [Parameter(Mandatory, Position = 0)]
    [string]$Input,

    [string]$Out = '',

    [ValidateRange(1, 100)]
    [int]$Quality = 85,

    [ValidateSet('Center','North','South','East','West','NorthEast','NorthWest','SouthEast','SouthWest')]
    [string]$Gravity = 'Center'
)

$ErrorActionPreference = 'Stop'

# Resolve input path
$src = Resolve-Path $Input
if (-not (Test-Path $src)) {
    Write-Error "File not found: $Input"
    exit 1
}

# Build output path
if ($Out -eq '') {
    $dir  = [System.IO.Path]::GetDirectoryName($src)
    $base = [System.IO.Path]::GetFileNameWithoutExtension($src)
    $Out  = Join-Path $dir "$base-og.jpg"
}

# Run ImageMagick:
#   1. Resize so the shortest dimension fills 1200x630 (^ = fill mode)
#   2. Crop the overflow from the chosen gravity point
#   3. Remove EXIF/color profiles to keep file small
magick `"$src`" `
    -resize "1200x630^" `
    -gravity $Gravity `
    -extent 1200x630 `
    -strip `
    -quality $Quality `
    -colorspace sRGB `
    `"$Out`"

$size = [math]::Round((Get-Item $Out).Length / 1KB, 1)
Write-Host "OK  $Out  ($size KB)"
