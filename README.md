# Markdown Alternate

A WordPress plugin that provides markdown versions of posts and pages for LLMs and users who prefer clean, structured content over HTML. Read the [announcement blog post](https://joost.blog/markdown-alternate/) for the full story.

## Features

- Access any post at `/post-slug.md`
- Access any page at `/page-slug.md`
- Nested pages work: `/parent/child.md`
- Date-based permalinks supported: `/2024/01/my-post.md`
- Content negotiation: Use `Accept: text/markdown` header on any post/page URL
- Zero configuration required

## Installation

### For Users

1. Download the plugin ZIP file
2. Upload to `/wp-content/plugins/markdown-alternate/`
3. Activate the plugin through the 'Plugins' screen in WordPress
4. Visit any post or page URL with `.md` extension

### For Developers

Clone the repository and install dependencies:

```bash
git clone https://github.com/ProgressPlanner/markdown-alternate.git
cd markdown-alternate
composer install
```

## Usage

### Via URL Extension

Simply append `.md` to any post or page URL:

```bash
# Get a post as markdown
curl https://example.com/hello-world.md

# Get a nested page as markdown
curl https://example.com/about/team.md
```

### Via Content Negotiation

Request markdown using the `Accept` header on the original URL:

```bash
curl -H "Accept: text/markdown" https://example.com/hello-world/
```

### Via Query Parameter

For clients that cannot send custom headers, use the `format` query parameter:

```bash
curl https://example.com/hello-world/?format=markdown
```

Note: The value must be exactly `markdown` (lowercase, case-sensitive).

### Markdown Output Format

```markdown
---
title: "Post Title"
date: 2026-01-30
author: "Author Name"
featured_image: "https://example.com/image.jpg"
categories:
  - name: "Category A"
    url: "/category/category-a.md"
  - name: "Category B"
    url: "/category/category-b.md"
tags:
  - name: "tag1"
    url: "/tag/tag1.md"
---
# Post Title

Post content converted to markdown...
```

## Content Processing

The plugin processes WordPress content to produce clean markdown:

- **Code blocks**: Syntax highlighting markup (e.g., `<span class="hljs-keyword">`) added by plugins like Highlight.js or Prism is automatically stripped. The output contains only the raw code with language hints preserved for fenced code blocks.
- **HTML entities**: Entities like `&amp;` and `&#8217;` in titles and metadata are decoded to their actual characters.
- **Shortcodes and blocks**: Standard WordPress shortcodes and Gutenberg blocks are rendered before conversion.

## Requirements

- WordPress 6.0 or higher
- PHP 7.4 or higher
- Pretty permalinks enabled (Settings > Permalinks - any option except "Plain")

## Development

### Project Structure

```
markdown-alternate/
├── markdown-alternate.php    # Main plugin file (bootstrap)
├── composer.json             # PSR-4 autoloading configuration
├── readme.txt                # WordPress.org plugin readme
├── README.md                 # This file
├── src/
│   └── Plugin.php            # Core plugin orchestrator
└── vendor/                   # Composer autoloader (generated)
```

### Running Tests

```bash
composer install
# Tests will be added in future versions
```

## For Developers

### Custom Post Type Support

By default, only posts and pages serve markdown. To enable markdown for custom post types, use the `markdown_alternate_supported_post_types` filter:

```php
add_filter( 'markdown_alternate_supported_post_types', function( $types ) {
    $types[] = 'book';      // Add your custom post type
    $types[] = 'portfolio'; // Add multiple types
    return $types;
} );
```

Once added, the custom post type will:
- Serve markdown at `.md` URLs (e.g., `/my-book.md`)
- Respond to `Accept: text/markdown` headers
- Respond to `?format=markdown` query parameter
- Include `<link rel="alternate">` tag in HTML head

### Caching

Markdown output is cached using WordPress transients for 24 hours by default. The cache is automatically bypassed if the post is modified. You can adjust the cache expiration time (in seconds) using the `markdown_alternate_cache_expiration` filter:

```php
add_filter( 'markdown_alternate_cache_expiration', function( $seconds ) {
    return HOUR_IN_SECONDS; // Cache for 1 hour instead of 24
} );
```

## License

GPL-2.0-or-later

See [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html) for the full license text.

## Author

[Joost de Valk](https://joost.blog)
