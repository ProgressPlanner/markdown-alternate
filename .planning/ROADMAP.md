# Roadmap: Markdown Alternate

## Overview

This WordPress plugin delivers markdown versions of posts and pages through dedicated `.md` URLs and HTTP content negotiation. The roadmap progresses from core infrastructure (URL routing), through content conversion (the core value), to discovery mechanisms (headers and alternate links), and finally extensibility (custom post types and fallbacks). Four sequential phases ensure each capability builds on proven foundations.

## Phases

**Phase Numbering:**
- Integer phases (1, 2, 3, 4): Planned milestone work
- Decimal phases (e.g., 2.1): Urgent insertions (marked with INSERTED)

- [x] **Phase 1: Core Infrastructure & URL Routing** - Plugin loads, routes `.md` URLs, serves basic markdown output
- [x] **Phase 2: Content Conversion & Metadata** - HTML-to-markdown conversion with complete post metadata
- [x] **Phase 3: Content Negotiation & Discovery** - Accept header support and programmatic discovery via alternate links
- [x] **Phase 4: Extensibility & Fallbacks** - Query parameter fallback and custom post type support

## Phase Details

### Phase 1: Core Infrastructure & URL Routing
**Goal**: Plugin loads correctly and serves markdown at `/post-slug.md` URLs for posts and pages
**Depends on**: Nothing (first phase)
**Requirements**: INFR-01, INFR-02, INFR-03, URL-01, TECH-01
**Success Criteria** (what must be TRUE):
  1. Visiting `/any-post-slug.md` returns markdown text (not 404)
  2. Visiting `/any-page-slug.md` returns markdown text (not 404)
  3. Plugin activates without errors on fresh WordPress install
  4. After deactivation, `.md` URLs return 404 (clean uninstall)
**Plans**: 2 plans

Plans:
- [x] 01-01-PLAN.md — Project setup with Composer, plugin bootstrap, and documentation
- [x] 01-02-PLAN.md — URL routing with rewrite rules and markdown request handler

### Phase 2: Content Conversion & Metadata
**Goal**: Markdown output is complete, properly formatted, and includes all post metadata
**Depends on**: Phase 1
**Requirements**: CONT-01, CONT-02, CONT-03, CONT-04, CONT-05, CONT-06, CONT-07, TECH-02
**Success Criteria** (what must be TRUE):
  1. Markdown output includes YAML frontmatter with title, date, author, categories, tags, and featured image URL
  2. Post body HTML is converted to clean markdown (headings, lists, links, images preserved)
  3. Shortcodes in content are rendered (not raw `[shortcode]` tags in output)
  4. Gutenberg blocks are rendered (not raw `<!-- wp:block -->` comments in output)
  5. Posts without featured images omit that field (no "Featured Image: false")
**Plans**: 2 plans

Plans:
- [x] 02-01-PLAN.md — Install league/html-to-markdown and create MarkdownConverter wrapper
- [x] 02-02-PLAN.md — Create ContentRenderer for frontmatter/body and wire into RewriteHandler

### Phase 3: Content Negotiation & Discovery
**Goal**: Markdown is discoverable via HTTP headers and programmatically accessible via alternate links
**Depends on**: Phase 2
**Requirements**: URL-02, URL-03, TECH-03, TECH-04
**Success Criteria** (what must be TRUE):
  1. Request to `/post-slug/` with `Accept: text/markdown` header returns markdown (not HTML)
  2. HTML page head contains `<link rel="alternate" type="text/markdown" href="...">` tag
  3. Response includes `Content-Type: text/markdown; charset=UTF-8` header
  4. Response includes `Vary: Accept` header for cache compatibility
**Plans**: 2 plans

Plans:
- [x] 03-01-PLAN.md — Response headers and Accept header content negotiation
- [x] 03-02-PLAN.md — Alternate link discovery via wp_head

### Phase 4: Extensibility & Fallbacks
**Goal**: Plugin works in edge cases (no Accept headers) and is extensible for custom post types
**Depends on**: Phase 3
**Requirements**: URL-04, TECH-05
**Success Criteria** (what must be TRUE):
  1. Visiting `/post-slug/?format=markdown` returns markdown (query parameter fallback)
  2. Developer can enable custom post types via documented filter hook
  3. Custom post type with filter enabled serves markdown at `.md` URL
**Plans**: 2 plans

Plans:
- [x] 04-01-PLAN.md — Query parameter fallback and filterable custom post type support
- [x] 04-02-PLAN.md — Documentation for query parameter and custom post type extensibility

## Progress

**Execution Order:**
Phases execute in numeric order: 1 -> 2 -> 3 -> 4

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. Core Infrastructure & URL Routing | 2/2 | Complete | 2026-01-30 |
| 2. Content Conversion & Metadata | 2/2 | Complete | 2026-01-30 |
| 3. Content Negotiation & Discovery | 2/2 | Complete | 2026-01-30 |
| 4. Extensibility & Fallbacks | 2/2 | Complete | 2026-01-30 |

---
*Roadmap created: 2026-01-30*
*Last updated: 2026-01-30 — Phase 4 complete, milestone complete*
