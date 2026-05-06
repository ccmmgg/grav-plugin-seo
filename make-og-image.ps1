# make-og-image.ps1
# Resize + crop any image to 1200x630 for Open Graph / iMessage previews.
# Optionally composites a transparent PNG logo over the hero.
#
# Usage:
#   .\make-og-image.ps1 hero.jpg
#   .\make-og-image.ps1 hero.jpg -Out og.jpg
#   .\make-og-image.ps1 hero.jpg -Logo logo.png
#   .\make-og-image.ps1 hero.jpg -Logo logo.png -LogoGravity SouthEast -LogoScale 0.2
#   .\make-og-image.ps1 hero.jpg -Logo logo.png -LogoOpacity 0.6
#   .\make-og-image.ps1 hero.jpg -Logo logo.png -LogoGravity SouthEast -LogoOffsetX -40 -LogoOffsetY -30
#   .\make-og-image.ps1 hero.jpg -Gravity North -Quality 90

param(
    [Parameter(Mandatory, Position = 0)]
    [string]$Image,

    [string]$Out = '',

    [ValidateRange(1, 100)]
    [int]$Quality = 85,

    [ValidateSet('Center','North','South','East','West','NorthEast','NorthWest','SouthEast','SouthWest')]
    [string]$Gravity = 'Center',

    [string]$Logo = '',

    [ValidateSet('Center','North','South','East','West','NorthEast','NorthWest','SouthEast','SouthWest')]
    [string]$LogoGravity = 'Center',

    [ValidateRange(0.05, 1.0)]
    [double]$LogoScale = 0.25,

    [ValidateRange(0.0, 1.0)]
    [double]$LogoOpacity = 1.0,

    [int]$LogoOffsetX = 0,
    [int]$LogoOffsetY = 0
)

$ErrorActionPreference = 'Stop'

$resolvedImage = Resolve-Path -LiteralPath $Image
if (-not (Test-Path -LiteralPath $resolvedImage)) {
    Write-Error "File not found: $Image"
    exit 1
}

if ($Out -eq '') {
    $dir  = [System.IO.Path]::GetDirectoryName($resolvedImage)
    $base = [System.IO.Path]::GetFileNameWithoutExtension($resolvedImage)
    $Out  = Join-Path $dir "$base-og.jpg"
}

$resolvedOut = $ExecutionContext.SessionState.Path.GetUnresolvedProviderPathFromPSPath($Out)

if ($Logo -ne '') {
    $resolvedLogo = Resolve-Path -LiteralPath $Logo
    $logoW = [int](1200 * $LogoScale)
    $gx    = if ($LogoOffsetX -ge 0) { "+$LogoOffsetX" } else { "$LogoOffsetX" }
    $gy    = if ($LogoOffsetY -ge 0) { "+$LogoOffsetY" } else { "$LogoOffsetY" }
    $geom  = "$gx$gy"

    & magick $resolvedImage -auto-orient `
        -resize '1200x630^' -gravity $Gravity -extent '1200x630' `
        '(' $resolvedLogo -resize "${logoW}x" -alpha set -evaluate multiply $LogoOpacity ')' `
        -gravity $LogoGravity -geometry $geom -composite `
        -strip -quality $Quality -colorspace sRGB `
        $resolvedOut
} else {
    & magick $resolvedImage -auto-orient `
        -resize '1200x630^' -gravity $Gravity -extent '1200x630' `
        -strip -quality $Quality -colorspace sRGB `
        $resolvedOut
}

$size = [math]::Round((Get-Item -LiteralPath $resolvedOut).Length / 1KB, 1)
Write-Host "OK  $resolvedOut  ($size KB)"
