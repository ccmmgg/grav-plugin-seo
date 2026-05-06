# grav-plugin-seo — Claude instructions

## What this project is

A Grav CMS plugin that manages SEO meta tags, Open Graph / Twitter Card markup, and Schema.org JSON-LD structured data. Originally forked from `paulmassen/grav-plugin-seo`.

## Architecture overview

All output is produced by `seo.php` (the single plugin class `SeoPlugin`). There are two phases:

1. **`onPageInitialized`** — reads page header fields, builds `$meta` array (passed to Grav's head renderer) and `$jsonLdOutput` string (raw `<script>` blocks).
2. **`onOutputGenerated`** — injects the canonical `<link>` and JSON-LD blocks before `</head>` via `str_replace`.

`templates/partials/seo_data.html.twig` is a **deprecated empty stub** — do not add logic there.

## Key conventions

- Each schema type has its own private builder method: `buildArticleMicrodata`, `buildBreadcrumbMicrodata`, `buildEventMicrodata`, `buildMusicEventMicrodata`, `buildOrganizationMicrodata`, `buildPersonMicrodata`, `buildProductMicrodata`, `buildRestaurantMicrodata`. New schema types follow the same pattern.
- Meta tags use three private methods: `applyGoogleMeta`, `applyTwitterMeta`, `applyOpenGraphMeta`. Each accepts and returns `array $meta`.
- Never use `@` error suppression — use `?? null` / `?? []` instead.
- Schema.org context URLs must use `https://schema.org` (not `http`).
- `cleanArray()` strips null/empty values before JSON-encoding — do not pre-filter inside builders.

## Plugin config keys (`seo.yaml`)

Each schema type has a global on/off toggle: `article`, `event`, `musicevent`, `organization`, `person`, `product`, `restaurant`. Builders must check both the page header flag and the plugin config toggle before emitting.

## Things to avoid

- Do not add output logic to Twig templates — the PHP path is canonical.
- Do not use `addInlineJs()` for JSON-LD — it wraps content in a plain `<script>` tag without `type="application/ld+json"`.
- Do not read `$page->header()->someKey` without a `property_exists()` guard or `?? null` fallback — missing keys throw notices.
