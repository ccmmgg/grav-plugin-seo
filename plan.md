# Improvement Ideas: grav-plugin-seo Automation

## Context

The plugin currently requires per-page manual entry for almost every SEO field — descriptions, Twitter/Facebook toggles, schema type flags, publisher info, author names, etc. Most of this data either (a) repeats across every page, (b) can be inferred from Grav's existing page metadata, or (c) can be driven from a single site-wide config. The goal is to make the common case require zero per-page editing.

---

## Proposed Improvements

### 1. Global Publisher Config (High value, low effort)

**Problem:** Every article page must manually re-enter `publisher_name` and `publisher_logo_url`. These never change.

**Fix:** Add `publisher_name` and `publisher_logo` keys to `seo.yaml` (and the admin blueprint). `buildArticleMicrodata()` falls back to these globals when the per-page fields are empty.

**Files:** `seo.yaml`, `blueprints.yaml` (admin tab), `seo.php:buildArticleMicrodata()`

---

### 2. Template-to-Schema Auto-Mapping (High value, medium effort)

**Problem:** To enable Article schema you must set `header.articleenabled: true` on every page. But if a page uses the `post` or `article` Grav template, the intent is obvious.

**Fix:** Add a config key `template_schema_map` (YAML dict) in `seo.yaml`:
```yaml
template_schema_map:
  article: article
  post: article
  event: event
  product: product
```
In `onPageInitialized`, check `$page->template()` against the map and auto-enable the matched schema type if the per-page flag is not explicitly set (treat absence as "follow map").

**Files:** `seo.yaml`, `blueprints.yaml`, `seo.php:onPageInitialized()`

---

### 3. Author Auto-Detection for Article Schema (Medium value, low effort)

**Problem:** `header.article.author` must be typed in manually on each page.

**Fix:** Fall-back chain in `buildArticleMicrodata()`:
1. `header.article.author` (explicit, current)
2. `header.author` (standard Grav field many themes already set)
3. `config.plugins.seo.default_author` (new global config key)
4. `site.author.name` (Grav's built-in site config)

**Files:** `seo.php:buildArticleMicrodata()`, `seo.yaml`, `blueprints.yaml`

---

### 4. Global Organization Config (Medium value, medium effort)

**Problem:** Organization schema requires ~20 fields entered per page. Sites typically have one organization and want it emitted site-wide (or on every page).

**Fix:**
- Move all `header.orga.*` fields into a new `organization:` section in `seo.yaml` as site-wide defaults.
- Add a `organization_on_all_pages: false` toggle — when true, emit Organization JSON-LD on every page without needing `header.orgaenabled`.
- `buildOrganizationMicrodata()` merges global config over page-level overrides (page wins).

**Files:** `seo.yaml`, `blueprints.yaml`, `seo.php:buildOrganizationMicrodata()`

---

### 5. Folder-Level SEO Defaults via `_header.md` Inheritance (Medium value, higher effort)

**Problem:** A blog folder with 50 posts all need `articleenabled: true`, same Twitter card type, same publisher info — set 50 times.

**Fix:** Walk `$page->ancestors()` looking for a `_header.md` (or use Grav's existing `header.` inheritance via `content.items` parent defaults). Collect SEO keys from the nearest ancestor that defines them, then apply as defaults before the page-level values override.

This mirrors how Grav already handles `content.items` ordering — familiar pattern.

**Files:** `seo.php:onPageInitialized()` (add ancestor walk before building meta)

---

### 6. Smarter Description Auto-Extraction (Low effort, quality improvement)

**Problem:** The current `cleanMarkdown()` strips everything and takes first 320 chars, which often starts with navigation text or headings rather than meaningful copy.

**Fix:**
- Skip the first heading (already the title).
- Skip list-only paragraphs.
- Take the first non-empty prose paragraph instead of raw char slice.

**Files:** `seo.php:cleanMarkdown()` or a new `extractSummary()` helper

---

### 7. `og:image` Global Fallback (Low effort, quality improvement)

**Problem:** If a page has no images and `header.facebookimg` / `header.twittershareimg` are empty, no OG image is emitted. This looks bad on social shares.

**Fix:** Add `default_social_image` to `seo.yaml`. `applyOpenGraphMeta()` and `applyTwitterMeta()` use it as final fallback after page-level and page-media checks.

**Files:** `seo.yaml`, `blueprints.yaml`, `seo.php:applyOpenGraphMeta()`, `seo.php:applyTwitterMeta()`

---

## Priority Order

| # | Improvement | Effort | Impact |
|---|-------------|--------|--------|
| 7 | Global OG image fallback | Low | High |
| 1 | Global publisher config | Low | High |
| 3 | Author auto-detection | Low | Medium |
| 6 | Smarter description extraction | Low | Medium |
| 2 | Template-to-schema auto-mapping | Medium | High |
| 4 | Global organization config | Medium | Medium |
| 5 | Folder-level inheritance | High | Medium |

---

## Verification

For each change:
1. Test a page with **no SEO frontmatter** — confirm auto-derived values appear correctly in page source.
2. Test a page with **explicit frontmatter** — confirm per-page values still win over global defaults.
3. View source of rendered page and validate JSON-LD at `search.google.com/test/rich-results`.
4. Check admin blueprint renders correctly in Grav admin (no broken field references).
