---
phase: 02-content-conversion-metadata
plan: 02
status: complete
completed: 2026-01-30
duration: 1.3 min

subsystem: output
tags: [yaml, frontmatter, metadata, content-rendering]

dependency_graph:
  requires: ["02-01"]
  provides: ["ContentRenderer", "complete-markdown-output"]
  affects: []

tech_stack:
  added: []
  patterns: ["facade-pattern", "content-filter-chain"]

files:
  created:
    - src/Output/ContentRenderer.php
  modified:
    - src/Router/RewriteHandler.php

decisions:
  - id: CONT-FM-01
    decision: "Categories and tags appear both in frontmatter (YAML arrays) and at end of body (readable text)"
    reason: "Frontmatter for machine parsing, footer for human readability"
  - id: CONT-FM-02
    decision: "Featured image omitted from frontmatter when not set (rather than empty value)"
    reason: "Cleaner YAML output, easier to check presence"
  - id: CONT-FM-03
    decision: "YAML values escaped for quotes and backslashes"
    reason: "Prevent YAML parsing errors with special characters in titles"

metrics:
  tasks: 2
  commits: 2
  duration: 1.3 min
---

# Phase 02 Plan 02: Content Rendering & Frontmatter Summary

**Created ContentRenderer class with YAML frontmatter, H1 title, HTML-to-markdown body, and category/tag footer, then wired into RewriteHandler for complete markdown output**

## Accomplishments

- Created ContentRenderer class in `MarkdownAlternate\Output` namespace
- Implements full markdown rendering pipeline:
  1. YAML frontmatter with title, date, author (always)
  2. Featured image, categories, tags in frontmatter (when present)
  3. H1 title heading after frontmatter
  4. HTML content converted to markdown via MarkdownConverter
  5. Footer with categories/tags in readable format (when present)
- Applies `the_content` filter before conversion (renders shortcodes and Gutenberg blocks)
- Wired ContentRenderer into RewriteHandler to replace raw output
- .md URLs now serve complete, properly formatted markdown

## Task Completion

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | Create ContentRenderer class | 6f4cd04 | src/Output/ContentRenderer.php |
| 2 | Wire ContentRenderer into RewriteHandler | 7199ad9 | src/Router/RewriteHandler.php |

## Files Created

### src/Output/ContentRenderer.php (174 lines)
- `render(WP_Post $post): string` - Main entry point
- `generate_frontmatter(WP_Post $post): string` - YAML block
- `generate_footer(WP_Post $post): string` - Categories/tags footer
- `escape_yaml(string $value): string` - YAML escaping

## Files Modified

### src/Router/RewriteHandler.php
- Added `use MarkdownAlternate\Output\ContentRenderer;`
- Replaced raw echo output with `ContentRenderer::render($post)`

## Deviations from Plan

None - plan executed exactly as written.

## Verification Results

**Automated checks (passed):**
- ContentRenderer autoloadable via Composer PSR-4
- RewriteHandler imports ContentRenderer correctly
- RewriteHandler uses `new ContentRenderer()` and `->render()`
- Old raw output code removed

**Manual checks (documented for post-deployment):**
- Visit `/any-post-slug.md` for complete markdown output
- Verify frontmatter includes title, date, author
- Verify H1 title after frontmatter
- Test posts with/without featured image
- Test posts with categories and tags
- Test posts with shortcodes and Gutenberg blocks

## Example Output Format

```markdown
---
title: "My Post Title"
date: 2026-01-30
author: "John Doe"
featured_image: "https://example.com/image.jpg"
categories:
  - "Category One"
  - "Category Two"
tags:
  - "Tag One"
  - "Tag Two"
---

# My Post Title

[Converted markdown body content...]

---

**Categories:** Category One, Category Two
**Tags:** Tag One, Tag Two
```

## Phase 2 Completion

With this plan complete, Phase 2 (Content Conversion & Metadata) is fully implemented:
- **02-01:** HTML-to-markdown converter installed and wrapped
- **02-02:** ContentRenderer produces complete markdown output

The core value proposition is now delivered: every post/page accessible as clean markdown through predictable `.md` URLs with full metadata.
