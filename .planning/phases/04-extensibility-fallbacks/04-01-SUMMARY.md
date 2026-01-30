---
phase: 04-extensibility-fallbacks
plan: 01
subsystem: api
tags: [wordpress, filter-hooks, query-parameters, extensibility, custom-post-types]

# Dependency graph
requires:
  - phase: 03-content-negotiation-discovery
    provides: RewriteHandler with Accept negotiation, AlternateLinkHandler for discovery
provides:
  - Query parameter fallback (?format=markdown)
  - Filterable post type support via markdown_alternate_supported_post_types filter
  - CPT extensibility for both routing and discovery
affects: [04-02 caching, future CPT support]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "WordPress filter pattern for extensibility"
    - "Query parameter as Accept header fallback"

key-files:
  created: []
  modified:
    - src/Router/RewriteHandler.php
    - src/Discovery/AlternateLinkHandler.php

key-decisions:
  - "Filter hook name: markdown_alternate_supported_post_types"
  - "Default supported types: ['post', 'page']"
  - "Priority order: URL (.md) > query param (?format=markdown) > Accept header"
  - "Intentional code duplication in helper methods (filter is the contract)"

patterns-established:
  - "Filter pattern for extending plugin functionality"
  - "Query parameter fallback for non-header-capable clients"

# Metrics
duration: 2min
completed: 2026-01-30
---

# Phase 4 Plan 1: Extensibility Fallbacks Summary

**Query parameter fallback (?format=markdown) and filterable custom post type support via markdown_alternate_supported_post_types filter**

## Performance

- **Duration:** 2 min
- **Started:** 2026-01-30T12:15:00Z
- **Completed:** 2026-01-30T12:17:00Z
- **Tasks:** 2
- **Files modified:** 2

## Accomplishments

- Format query parameter support for clients that cannot send Accept headers
- Filterable post type support via `markdown_alternate_supported_post_types` filter
- Consistent filter usage across both RewriteHandler and AlternateLinkHandler
- Clear priority order: URL > query param > Accept header

## Task Commits

Each task was committed atomically:

1. **Task 1: Add format query parameter fallback and filterable post types to RewriteHandler** - `c94a7c3` (feat)
2. **Task 2: Add filterable post types to AlternateLinkHandler** - `67ec96e` (feat)

## Files Created/Modified

- `src/Router/RewriteHandler.php` - Added format query var, get_supported_post_types(), is_supported_post_type(), handle_format_parameter()
- `src/Discovery/AlternateLinkHandler.php` - Added get_supported_post_types(), is_supported_post_type() for filterable alternate links

## Decisions Made

- **Filter hook name:** `markdown_alternate_supported_post_types` - clear, namespaced, descriptive
- **Priority order:** URL (.md) wins over query param, query param wins over Accept header. Rationale: explicit URL is strongest signal, query param is fallback for non-header-capable clients
- **Intentional duplication:** Both classes have their own helper methods. The filter hook name is the contract; implementations are internal details. A trait would add complexity for minimal benefit.
- **Case-sensitive format value:** `?format=markdown` required exactly (not `Markdown` or `MARKDOWN`)

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

None.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Filter hook ready for developers to extend with custom post types
- Query parameter fallback enables simpler client integrations
- Ready for Plan 02 (caching headers and rate limiting)

---
*Phase: 04-extensibility-fallbacks*
*Completed: 2026-01-30*
