# Phase 4: Extensibility & Fallbacks - Context

**Gathered:** 2026-01-30
**Status:** Ready for planning

<domain>
## Phase Boundary

Plugin works in edge cases (no Accept headers) and is extensible for custom post types. Includes query parameter fallback (`?format=markdown`) and filter hook for CPT support.

</domain>

<decisions>
## Implementation Decisions

### Custom Post Type URLs
- CPT markdown URLs match their permalink structure with .md appended to final segment
- Example: `/products/category/item` becomes `/products/category/item.md`
- Hierarchical CPTs supported — .md goes at end of full path
- One filter to enable all public CPTs at once (no need to whitelist each individually)
- CPT archives also get markdown versions when CPT is enabled

### Claude's Discretion
- Query parameter name and accepted values (`?format=markdown` vs `?md=1` etc.)
- Filter hook naming convention and signature
- Error handling for invalid format values
- How to detect and handle disabled/deleted CPTs
- Rewrite rule structure for CPT support

</decisions>

<specifics>
## Specific Ideas

No specific requirements — open to standard approaches for the query parameter fallback and filter implementation.

</specifics>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope

</deferred>

---

*Phase: 04-extensibility-fallbacks*
*Context gathered: 2026-01-30*
