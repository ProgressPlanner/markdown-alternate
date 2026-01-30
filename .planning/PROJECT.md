# Markdown Alternate

## What This Is

A WordPress plugin that provides markdown versions of posts and pages for LLMs and users who prefer clean, structured content over HTML. It exposes markdown via content negotiation and dedicated `.md` URLs.

## Core Value

Every post and page should be accessible as clean markdown through a predictable URL pattern (`/post-slug.md`) — enabling LLMs and developers to consume content without HTML noise.

## Requirements

### Validated

(None yet — ship to validate)

### Active

- [ ] Add `<link rel="alternate" type="text/markdown">` to post/page `<head>`
- [ ] Serve markdown at `/post-slug.md` URLs via WordPress rewrite rules
- [ ] Serve markdown on original URL when `Accept: text/markdown` header is present
- [ ] Markdown output includes: title, date, author, featured image URL, body content
- [ ] Markdown output includes categories and tags at the end
- [ ] Works for posts and pages without configuration

### Out of Scope

- Admin settings/options page — keep it simple, no configuration needed
- Custom post types — focus on posts and pages for v1
- Markdown-to-HTML conversion (reverse direction) — only HTML-to-markdown
- Caching layer — rely on WordPress/server caching
- Custom markdown templates — fixed output format

## Context

**Target users:** LLMs consuming web content, developers building tools that parse content, users who prefer markdown.

**Content negotiation:** The `Accept: text/markdown` header (MIME type `text/markdown` per RFC 7763) triggers markdown response on the original URL. The dedicated `.md` URL always serves markdown regardless of headers.

**Markdown output format:**
```markdown
# Post Title

**Date:** January 30, 2026
**Author:** Author Name
**Featured Image:** https://example.com/image.jpg

[Post content converted to markdown]

---
**Categories:** Category A, Category B
**Tags:** tag1, tag2, tag3
```

## Constraints

- **PHP Version**: PHP 7.4+ — minimum supported version
- **Code Style**: WordPress Coding Standards with exceptions: no Yoda conditions, short array syntax `[]`
- **Architecture**: Namespaced, object-oriented PHP with Composer autoloader
- **Documentation**: readme.txt (WordPress.org format) + README.md (GitHub/local)
- **Dependencies**: Minimize external dependencies; use built-in WordPress functions where possible

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| .md extension URLs | Clean, intuitive URL pattern that's widely understood | — Pending |
| No admin settings | Simplicity; plugin should just work | — Pending |
| Posts and pages only | Focused scope for v1; custom post types can come later | — Pending |

---
*Last updated: 2026-01-30 after initialization*
