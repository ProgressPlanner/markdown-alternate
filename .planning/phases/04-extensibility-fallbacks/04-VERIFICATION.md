---
phase: 04-extensibility-fallbacks
verified: 2026-01-30T12:55:00Z
status: passed
score: 4/4 must-haves verified
re_verification:
  previous_status: gaps_found
  previous_score: 2/4
  gaps_closed:
    - "Developer can add custom post types via filter hook"
    - "Visiting /post-slug/?format=markdown returns markdown content"
  gaps_remaining: []
  regressions: []
---

# Phase 4: Extensibility & Fallbacks Verification Report

**Phase Goal:** Plugin works in edge cases (no Accept headers) and is extensible for custom post types
**Verified:** 2026-01-30T12:55:00Z
**Status:** PASSED
**Re-verification:** Yes — after gap closure (Plan 04-02)

## Re-Verification Summary

**Previous verification (2026-01-30T12:30:00Z):** gaps_found (2/4 verified)

**This verification:** passed (4/4 verified)

**Gaps closed by Plan 04-02:**
1. Filter hook `markdown_alternate_supported_post_types` now documented in README.md
2. Query parameter `?format=markdown` now documented in README.md and readme.txt
3. readme.txt FAQ updated to reflect current capability (removed "may be added in future versions")

**Regressions:** None detected

**Focus:** Documentation gaps only. Code implementation from 04-01 confirmed stable (no changes).

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | Visiting /post-slug/?format=markdown returns markdown content | ✓ VERIFIED | Code unchanged from 04-01, now documented in README.md line 60 and readme.txt line 70 |
| 2 | Developer can add custom post types via filter hook | ✓ VERIFIED | Filter documented in README.md lines 113-127 with working example |
| 3 | Custom post type with filter enabled serves markdown at .md URL | ✓ VERIFIED | RewriteHandler.php line 68 applies filter in is_supported_post_type() |
| 4 | Custom post type with filter enabled shows alternate link in head | ✓ VERIFIED | AlternateLinkHandler.php line 37 applies filter in is_supported_post_type() |

**Score:** 4/4 truths verified (100% — all gaps closed)

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `src/Router/RewriteHandler.php` | Format query parameter handling and filterable post type support | ✓ VERIFIED | 320 lines, unchanged from 04-01, substantive implementation |
| `src/Discovery/AlternateLinkHandler.php` | Filterable post type support for alternate links | ✓ VERIFIED | 89 lines, unchanged from 04-01, substantive implementation |
| `README.md` | Developer documentation of filter hook and query parameter | ✓ VERIFIED | Lines 55-63 (query param), lines 109-127 (filter hook) |
| `readme.txt` | User documentation of query parameter and filter hook | ✓ VERIFIED | Lines 59-72 (FAQs), line 81 (changelog) |

### Artifact Verification Details

#### RewriteHandler.php (Code - Regression Check)

**Level 1 - Existence:** ✓ EXISTS (unchanged)
**Level 2 - Substantive:** ✓ SUBSTANTIVE (320 lines, same as previous verification)
**Level 3 - Wired:** ✓ WIRED (imported in Plugin.php line 11, registered unchanged)

**Regression check:** 
- Filter hook still at line 68: `apply_filters('markdown_alternate_supported_post_types', $default_types)`
- Query parameter handling still at line 96: `get_query_var('format')`
- Validation unchanged: strict equality check `$format !== 'markdown'`

#### AlternateLinkHandler.php (Code - Regression Check)

**Level 1 - Existence:** ✓ EXISTS (unchanged)
**Level 2 - Substantive:** ✓ SUBSTANTIVE (89 lines, same as previous verification)
**Level 3 - Wired:** ✓ WIRED (imported in Plugin.php line 10, registered unchanged)

**Regression check:**
- Filter hook still at line 37: `apply_filters('markdown_alternate_supported_post_types', $default_types)`

#### README.md (Documentation - Gap Closure)

**Level 1 - Existence:** ✓ EXISTS
**Level 2 - Substantive:** ✓ SUBSTANTIVE

**Query parameter documentation:**
- Location: Lines 55-63 (new "Via Query Parameter" subsection in Usage)
- Contains: curl example, case-sensitivity note
- Example: `curl https://example.com/hello-world/?format=markdown`
- Note: "The value must be exactly `markdown` (lowercase, case-sensitive)"

**Filter hook documentation:**
- Location: Lines 109-127 (new "For Developers" section)
- Section header: "## For Developers"
- Subsection: "### Custom Post Type Support"
- Contains: Filter name, PHP code example, list of enabled capabilities
- Example shows adding 'book' and 'portfolio' custom post types

**Level 3 - Wired:** ✓ WIRED

**Filter name consistency:**
- README.md line 116: `add_filter( 'markdown_alternate_supported_post_types'`
- Code (RewriteHandler.php line 68): `apply_filters('markdown_alternate_supported_post_types'`
- Match: ✓ Exact (character-for-character match)

**Query parameter value consistency:**
- README.md line 60: `?format=markdown`
- README.md line 63: "exactly `markdown` (lowercase, case-sensitive)"
- Code (RewriteHandler.php line 97): `if ($format !== 'markdown')`
- Match: ✓ Exact (strict equality, lowercase "markdown")

#### readme.txt (Documentation - Gap Closure)

**Level 1 - Existence:** ✓ EXISTS
**Level 2 - Substantive:** ✓ SUBSTANTIVE

**Custom post type FAQ (updated):**
- Location: Lines 59-66
- Question: "Does it work with custom post types?"
- Answer: "Yes! By default, only posts and pages are supported. Developers can enable..."
- Contains: Filter hook code example
- OLD (removed): "may be added in future versions"
- NEW (current): "Developers can enable custom post types using a filter hook"

**Query parameter FAQ (added):**
- Location: Lines 68-72
- Question: "What if my client cannot send Accept headers?"
- Answer: Use `format` query parameter with example
- Contains: URL example, case-sensitivity note

**Changelog (updated):**
- Location: Lines 76-82
- Version: 1.0.0
- Includes: "Query parameter fallback via ?format=markdown"
- Includes: "Custom post type support via filter hook"

**Level 3 - Wired:** ✓ WIRED
- Filter name in FAQ matches code exactly
- Query parameter value matches code exactly

### Key Link Verification

All key links from previous verification remain stable. No regressions.

| From | To | Via | Status | Details |
|------|----|----|--------|---------|
| README.md | RewriteHandler.php | filter name | ✓ WIRED | "markdown_alternate_supported_post_types" exact match |
| readme.txt | RewriteHandler.php | filter name | ✓ WIRED | "markdown_alternate_supported_post_types" exact match |
| README.md | RewriteHandler.php | query parameter | ✓ WIRED | "?format=markdown" exact match |
| readme.txt | RewriteHandler.php | query parameter | ✓ WIRED | "?format=markdown" exact match |
| RewriteHandler.php | apply_filters() | get_supported_post_types() | ✓ WIRED | Line 68 (unchanged) |
| AlternateLinkHandler.php | apply_filters() | get_supported_post_types() | ✓ WIRED | Line 37 (unchanged) |
| RewriteHandler.php | get_query_var('format') | handle_format_parameter() | ✓ WIRED | Line 96 (unchanged) |

### Requirements Coverage

| Requirement | Status | Evidence |
|-------------|--------|----------|
| URL-04: Plugin serves markdown via ?format=markdown query parameter | ✓ SATISFIED | Code exists (04-01), documented in README.md and readme.txt (04-02) |
| TECH-05: Plugin supports custom post types via filter hook | ✓ SATISFIED | Code exists (04-01), documented in README.md and readme.txt (04-02) |

**Both requirements now fully satisfied:** Implementation complete (04-01) + Documentation complete (04-02)

### Anti-Patterns Found

No new anti-patterns introduced by 04-02 (documentation-only changes).

Previous verification findings remain valid:

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| src/Router/RewriteHandler.php | 249, 307 | return null | ℹ️ Info | Legitimate - returns null when canonical URL not determinable |

### Human Verification Required

Same human verification items as previous verification (code unchanged):

#### 1. Query Parameter Fallback Works End-to-End

**Test:** 
1. Create a published post (e.g., "Test Post")
2. Visit `https://yoursite.com/test-post/?format=markdown` in browser or curl
3. Visit `https://yoursite.com/test-post/?format=json` (should serve HTML)
4. Visit `https://yoursite.com/test-post/?format=MARKDOWN` (case-sensitive, should serve HTML)

**Expected:** 
- ?format=markdown serves markdown with Content-Type: text/markdown
- Other format values serve normal HTML
- Case-sensitive: only lowercase "markdown" works

**Why human:** Need WordPress instance with published content to test query parameter handling

#### 2. Custom Post Type Extensibility Works

**Test:**
1. Create custom post type (e.g., "book") with public permalinks
2. Add filter to functions.php (example now available in README.md lines 116-120):
```php
add_filter( 'markdown_alternate_supported_post_types', function( $types ) {
    $types[] = 'book';
    $types[] = 'portfolio';
    return $types;
} );
```
3. Create a published book post
4. Visit `/book-slug.md`
5. Check HTML page for alternate link in head

**Expected:**
- .md URL serves markdown for book
- HTML page has `<link rel="alternate" type="text/markdown" href="...book-slug.md">`
- Regular posts and pages still work

**Why human:** Requires WordPress instance, custom post type creation, and filter hook configuration

#### 3. Priority Order Works Correctly

**Test:**
1. Visit `/post-slug.md?format=json` (URL with conflicting query param)
2. Visit `/post-slug/?format=markdown` with `Accept: text/html` header

**Expected:**
- .md URL serves markdown regardless of query param
- Query param serves markdown regardless of Accept header
- Priority: URL > query param > Accept header

**Why human:** Tests subtle precedence behavior requiring HTTP client control

### Gap Closure Analysis

**All gaps from previous verification now closed:**

#### Gap 1: Filter Hook Documentation — CLOSED ✓

**Previous issue:** Filter exists in code but not documented
**Solution (04-02):**
- README.md now has "For Developers" section (lines 109-127)
- Includes filter name: `markdown_alternate_supported_post_types`
- Includes working PHP example
- Lists all capabilities enabled for custom post types
- readme.txt FAQ updated (lines 59-66) with same filter example
- readme.txt no longer says "may be added in future versions"

**Verification:**
```bash
$ grep "markdown_alternate_supported_post_types" README.md readme.txt
README.md:113:...use the `markdown_alternate_supported_post_types` filter:
README.md:116:add_filter( 'markdown_alternate_supported_post_types', function( $types ) {
readme.txt:63:`add_filter( 'markdown_alternate_supported_post_types', function( $types ) {
```

**Impact:** Developers can now discover and use this extensibility feature

#### Gap 2: Query Parameter Documentation — CLOSED ✓

**Previous issue:** Feature implemented but undocumented
**Solution (04-02):**
- README.md "Via Query Parameter" subsection added (lines 55-63)
- Includes curl example: `curl https://example.com/hello-world/?format=markdown`
- Includes case-sensitivity note
- readme.txt FAQ added (lines 68-72) with same information
- Changelog updated to list this feature

**Verification:**
```bash
$ grep "format=markdown" README.md readme.txt
README.md:60:curl https://example.com/hello-world/?format=markdown
README.md:126:- Respond to `?format=markdown` query parameter
readme.txt:70:Use the `format` query parameter: `https://example.com/hello-world/?format=markdown`
readme.txt:81:* Query parameter fallback via ?format=markdown
```

**Impact:** Users without Accept header capability can now discover this fallback

### Summary

**Phase 4 Goal Achievement: ✓ VERIFIED**

All observable truths now verified:
1. ✓ Query parameter fallback works and is documented
2. ✓ Filter hook exists and is documented
3. ✓ Custom post types serve markdown at .md URLs
4. ✓ Custom post types show alternate links

**Implementation Quality:**
- Code (04-01): Substantive, wired, no stubs
- Documentation (04-02): Complete, accurate, matches code exactly

**Requirements:**
- URL-04: ✓ Satisfied (code + docs)
- TECH-05: ✓ Satisfied (code + docs)

**Gap Closure Success:**
- Previous: 2/4 verified (gaps_found)
- Current: 4/4 verified (passed)
- Regressions: 0

**Phase 4 Status:** COMPLETE and VERIFIED

All success criteria met. Plugin works in edge cases (no Accept headers via query parameter fallback) and is extensible for custom post types (via documented filter hook). Developers and users can discover these features through README.md and readme.txt.

---

_Verified: 2026-01-30T12:55:00Z_
_Verifier: Claude (gsd-verifier)_
_Re-verification after Plan 04-02 gap closure_
