# Markdown Alternate

Expose WordPress posts and pages as clean Markdown via predictable `.md` URLs, content negotiation, and a simple query parameter fallback.

Markdown Alternate helps publishers, developers, and AI-facing sites offer a lightweight alternative to HTML without maintaining a second copy of their content.

## Why this plugin exists

HTML is great for browsers, but not always ideal for:

- LLM and agent retrieval pipelines
- developer tools that want structured, readable content
- documentation workflows built around Markdown
- users who prefer clean text over theme-heavy HTML

Markdown Alternate turns your existing WordPress content into Markdown on demand, using the same canonical content you already publish.

## What it does

- Serves posts and pages at `.md` URLs
- Supports nested pages and date-based permalinks
- Supports content negotiation via `Accept: text/markdown`
- Supports `?format=markdown` when headers are not practical
- Adds alternate discovery links for supported content
- Caches generated Markdown for better performance
- Lets developers enable support for custom post types
- Integrates with Yoast SEO `llms.txt` generation when Yoast SEO is active

## Example URLs

```text
https://example.com/hello-world.md
https://example.com/about/team.md
https://example.com/2024/01/my-post.md
https://example.com/hello-world/?format=markdown
```

Or request the original URL with a Markdown accept header:

```bash
curl -H "Accept: text/markdown" https://example.com/hello-world/
```

## Sample output

```markdown
---
title: "Post Title"
date: 2026-01-30
author: "Author Name"
featured_image: "https://example.com/image.jpg"
categories:
  - name: "Category A"
    url: "/category/category-a.md"
tags:
  - name: "tag1"
    url: "/tag/tag1.md"
---
# Post Title

Post content converted to markdown...
```

## Installation

### Standard WordPress install

1. Upload the plugin to `/wp-content/plugins/markdown-alternate/`
2. Activate it from **Plugins** in wp-admin
3. Make sure pretty permalinks are enabled in **Settings → Permalinks**
4. Open any post or page URL with `.md` appended

### Development install

```bash
git clone https://github.com/ProgressPlanner/markdown-alternate.git
cd markdown-alternate
composer install
```

## Usage

### 1. Use `.md` URLs

Append `.md` to any supported post or page URL:

```bash
curl https://example.com/hello-world.md
curl https://example.com/about/team.md
```

### 2. Use content negotiation

Request Markdown from the normal permalink:

```bash
curl -H "Accept: text/markdown" https://example.com/hello-world/
```

### 3. Use the query parameter fallback

For clients that cannot easily send custom headers:

```bash
curl https://example.com/hello-world/?format=markdown
```

The `format` value must be exactly `markdown`.

## Who it is for

### Site owners and publishers

Use Markdown Alternate to make your content easier to consume in AI pipelines, internal knowledge tooling, or lightweight publishing workflows.

### Developers

Use it for headless workflows, ingestion jobs, indexing, summarization, or apps that benefit from cleaner source material than full HTML.

## Developer hooks

### Enable custom post types

By default, Markdown Alternate supports `post` and `page`. You can add more post types with the `markdown_alternate_supported_post_types` filter:

```php
add_filter( 'markdown_alternate_supported_post_types', function( $types ) {
    $types[] = 'book';
    $types[] = 'portfolio';

    return $types;
} );
```

### Adjust cache duration

Markdown output is cached using transients for 24 hours by default. You can change that with `markdown_alternate_cache_expiration`:

```php
add_filter( 'markdown_alternate_cache_expiration', function( $seconds ) {
    return HOUR_IN_SECONDS;
} );
```

## Content processing notes

The plugin is built to produce cleaner Markdown than a naive HTML dump.

- Shortcodes and blocks are rendered before conversion
- HTML entities in titles and metadata are decoded
- Syntax-highlighting wrapper markup is stripped from code blocks when possible
- Supported taxonomy links are exposed as Markdown-friendly URLs in metadata

## Requirements

- WordPress 6.0 or higher
- PHP 7.4 or higher
- Pretty permalinks enabled

## Roadmap ideas

Potential future improvements include broader post type support defaults, richer metadata output, and automated test coverage.

## License

GPL-2.0-or-later

See [`LICENSE`](LICENSE) for details.

## Author

Created by [Joost de Valk](https://joost.blog).
