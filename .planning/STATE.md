# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-01-30)

**Core value:** Every post and page should be accessible as clean markdown through a predictable URL pattern (`/post-slug.md`)
**Current focus:** Phase 4 - Extensibility & Fallbacks

## Current Position

Phase: 4 of 4 (Extensibility & Fallbacks)
Plan: 1 of 2 complete in Phase 4
Status: In progress
Last activity: 2026-01-30 - Completed 04-01-PLAN.md

Progress: [████████░░] 87.5% (7/8 plans)

## Performance Metrics

**Velocity:**
- Total plans completed: 7
- Average duration: 1.4 min
- Total execution time: 0.16 hours

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|-------|-------|-------|----------|
| 1 | 2/2 | 3 min | 1.5 min |
| 2 | 2/2 | 2.3 min | 1.2 min |
| 3 | 2/2 | 4 min | 2 min |
| 4 | 1/2 | 2 min | 2 min |

**Recent Trend:**
- Last 5 plans: 02-01 (1 min), 02-02 (1.3 min), 03-01 (2 min), 03-02 (2 min), 04-01 (2 min)
- Trend: Stable

*Updated after each plan completion*

## Accumulated Context

### Decisions

Decisions are logged in PROJECT.md Key Decisions table.
Recent decisions affecting current work:

- Singleton pattern for Plugin class
- PSR-4 autoloading: MarkdownAlternate\\ namespace maps to src/
- Non-greedy regex (.+?)\.md$ for nested page support
- Template redirect priority 1 for early interception
- Lowercase .md extension only (uppercase returns 404)
- 403 Forbidden for password-protected posts
- Static activation methods for hook callbacks
- Activation hooks registered in main plugin file
- ATX-style headers (# style) for markdown output
- Strip unknown HTML tags for clean markdown
- Remove script, style, iframe tags for security
- Use dash (-) for list items consistently
- Categories/tags in both frontmatter (YAML) and footer (readable)
- Featured image omitted when not set (clean YAML)
- YAML values escaped for quotes and backslashes
- Vary: Accept on both markdown responses and 303 redirects
- Simple strpos() check for text/markdown - no quality factor parsing
- URL wins over Accept header (markdown_request check first)
- Singular content only for alternate links (archives skipped for focused scope)
- Priority 5 for wp_head ensures early injection
- Post type whitelist ['post', 'page'] for alternate links
- Filter hook name: markdown_alternate_supported_post_types
- Priority order: URL (.md) > query param (?format=markdown) > Accept header
- Intentional code duplication in helper methods (filter is the contract)

### Pending Todos

None.

### Blockers/Concerns

None.

## Phase 1 Verification

**Status:** Passed (12/12 must-haves verified)
**Report:** .planning/phases/01-core-infrastructure-url-routing/01-VERIFICATION.md

**Key deliverables:**
- Plugin bootstrap with Composer PSR-4 autoloading
- RewriteHandler with .md URL routing
- Edge case handling (trailing slash, case sensitivity, post status, password protection)
- WordPress.org readme.txt and GitHub README.md

## Phase 2 Verification

**Status:** Passed (8/8 must-haves verified, human approved)
**Report:** .planning/phases/02-content-conversion-metadata/02-VERIFICATION.md

**Key deliverables:**
- league/html-to-markdown 5.1.1 installed with secure wrapper
- ContentRenderer with YAML frontmatter (title, date, author, featured_image, categories, tags)
- H1 title heading after frontmatter
- HTML-to-markdown conversion with security (strips script/style/iframe)
- Footer with categories/tags in readable format
- RewriteHandler wired to ContentRenderer

## Phase 3 Verification

**Status:** Passed (4/4 must-haves verified)
**Report:** .planning/phases/03-content-negotiation-discovery/03-VERIFICATION.md

**Key deliverables:**
- Response headers: Content-Type, Vary: Accept, Link (canonical), X-Content-Type-Options
- Accept header negotiation with 303 redirect to .md URLs
- AlternateLinkHandler class for wp_head alternate link injection
- Canonical URL resolution for posts, pages, and archives

## Phase 4 Progress

**Plan 01:** Complete (04-01-SUMMARY.md)
- Query parameter fallback (?format=markdown)
- Filterable post type support via markdown_alternate_supported_post_types filter
- Commits: c94a7c3, 67ec96e

**Plan 02:** Pending
- Caching headers and considerations

## Session Continuity

Last session: 2026-01-30T12:17:00Z
Stopped at: Completed 04-01-PLAN.md
Resume file: None
