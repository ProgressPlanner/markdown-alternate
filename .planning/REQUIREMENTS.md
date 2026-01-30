# Requirements: Markdown Alternate

**Defined:** 2026-01-30
**Core Value:** Every post and page should be accessible as clean markdown through a predictable URL pattern

## v1 Requirements

Requirements for initial release. Each maps to roadmap phases.

### URL & Routing

- [x] **URL-01**: Plugin serves markdown at `/post-slug.md` URLs via WordPress rewrite rules
- [x] **URL-02**: Plugin serves markdown when `Accept: text/markdown` header is present on original URL
- [x] **URL-03**: Plugin adds `<link rel="alternate" type="text/markdown">` to post/page `<head>`
- [x] **URL-04**: Plugin serves markdown via `?format=markdown` query parameter as fallback

### Content Output

- [x] **CONT-01**: Markdown output includes post title as H1
- [x] **CONT-02**: Markdown output includes publication date
- [x] **CONT-03**: Markdown output includes author name
- [x] **CONT-04**: Markdown output includes featured image URL (if set)
- [x] **CONT-05**: Markdown output includes post body converted from HTML
- [x] **CONT-06**: Markdown output includes categories and tags at the end
- [x] **CONT-07**: Markdown output uses YAML frontmatter format for metadata

### Technical

- [x] **TECH-01**: Plugin flushes rewrite rules on activation and deactivation
- [x] **TECH-02**: Plugin processes shortcodes and blocks before HTML-to-markdown conversion
- [x] **TECH-03**: Plugin sends `Content-Type: text/markdown; charset=UTF-8` header
- [x] **TECH-04**: Plugin sends `Vary: Accept` header for cache compatibility
- [x] **TECH-05**: Plugin supports custom post types via filter hook

### Infrastructure

- [x] **INFR-01**: Plugin uses Composer autoloader with PSR-4 namespacing
- [x] **INFR-02**: Plugin includes readme.txt for WordPress.org
- [x] **INFR-03**: Plugin includes README.md for GitHub/local use

## v2 Requirements

Deferred to future release. Tracked but not in current roadmap.

### Discovery

- **DISC-01**: Plugin generates `/llms.txt` endpoint for LLM content discovery
- **DISC-02**: Plugin provides feed endpoint at `/feed/markdown/`

### Enhanced Output

- **OUTP-01**: Plugin handles Gutenberg blocks with enhanced markdown conversion
- **OUTP-02**: Plugin supports custom frontmatter fields via filter

## Out of Scope

Explicitly excluded. Documented to prevent scope creep.

| Feature | Reason |
|---------|--------|
| Admin settings page | Zero-config by design — works out of the box |
| Markdown-to-HTML conversion | One direction only (HTML -> Markdown) |
| Custom output templates | Fixed format for simplicity and consistency |
| Caching layer | Rely on WordPress/server caching |
| Multisite-specific features | Standard WordPress APIs should work; explicit support deferred |

## Traceability

Which phases cover which requirements. Updated during roadmap creation.

| Requirement | Phase | Status |
|-------------|-------|--------|
| URL-01 | Phase 1 | Complete |
| URL-02 | Phase 3 | Complete |
| URL-03 | Phase 3 | Complete |
| URL-04 | Phase 4 | Complete |
| CONT-01 | Phase 2 | Complete |
| CONT-02 | Phase 2 | Complete |
| CONT-03 | Phase 2 | Complete |
| CONT-04 | Phase 2 | Complete |
| CONT-05 | Phase 2 | Complete |
| CONT-06 | Phase 2 | Complete |
| CONT-07 | Phase 2 | Complete |
| TECH-01 | Phase 1 | Complete |
| TECH-02 | Phase 2 | Complete |
| TECH-03 | Phase 3 | Complete |
| TECH-04 | Phase 3 | Complete |
| TECH-05 | Phase 4 | Complete |
| INFR-01 | Phase 1 | Complete |
| INFR-02 | Phase 1 | Complete |
| INFR-03 | Phase 1 | Complete |

**Coverage:**
- v1 requirements: 19 total
- Mapped to phases: 19
- Unmapped: 0

---
*Requirements defined: 2026-01-30*
*Last updated: 2026-01-30 — Phase 4 complete, all v1 requirements satisfied*
