# Phase 3: Content Negotiation & Discovery - Context

**Gathered:** 2026-01-30
**Status:** Ready for planning

<domain>
## Phase Boundary

HTTP-level discovery mechanisms for markdown content. Accept header content negotiation returns markdown when requested, alternate link tags enable programmatic discovery. Proper HTTP headers ensure cache compatibility and standard compliance.

</domain>

<decisions>
## Implementation Decisions

### Response headers
- No Cache-Control header — let server/CDN defaults apply
- Include Link header pointing to canonical HTML version: `Link: <html-url>; rel="canonical"`
- Include `X-Content-Type-Options: nosniff` for security
- Required: `Content-Type: text/markdown; charset=UTF-8`
- Required: `Vary: Accept` (minimum)

### Conflict resolution
- URL wins over Accept header — `/post.md` always serves markdown regardless of Accept header
- Accept header on regular URLs triggers 303 redirect to .md URL (not direct markdown response)
- Use 303 See Other status code for format redirects
- Accept header negotiation works on ALL content types including archives (category, tag, date pages)

### Claude's Discretion
- Vary header composition beyond Accept (e.g., Accept-Encoding)
- How to generate markdown for archive pages (index-style listing)
- Accept header parsing strictness (quality factors, wildcards)
- Alternate link injection hook priority and placement

</decisions>

<specifics>
## Specific Ideas

- Redirect approach keeps URLs clean — each format has its own URL
- Archives with markdown support means category.md, tag.md could list posts in markdown format

</specifics>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope

</deferred>

---

*Phase: 03-content-negotiation-discovery*
*Context gathered: 2026-01-30*
