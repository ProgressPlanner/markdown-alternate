# Phase 2: Content Conversion & Metadata - Research

**Researched:** 2026-01-30
**Domain:** HTML-to-Markdown conversion with WordPress post metadata extraction
**Confidence:** HIGH

## Summary

Phase 2 transforms the plugin from serving raw post content to delivering properly converted markdown with complete metadata. The core challenge is two-fold: (1) converting WordPress HTML content to clean markdown using an established library, and (2) assembling post metadata into YAML frontmatter format.

The standard approach uses `league/html-to-markdown` (26.6M+ downloads, actively maintained by The PHP League) for HTML-to-markdown conversion. Before conversion, content must pass through WordPress's `the_content` filter chain to render shortcodes and Gutenberg blocks into HTML. Metadata is extracted using WordPress core functions (`get_the_date()`, `get_the_author()`, `get_the_post_thumbnail_url()`, `get_the_terms()`) and formatted as YAML frontmatter.

Key considerations: shortcodes are processed at priority 11 on `the_content`, blocks at priority 9. The converter must handle posts without featured images gracefully (the function returns `false`). Security requires stripping potentially dangerous HTML tags before conversion.

**Primary recommendation:** Install `league/html-to-markdown ^5.1`, apply `the_content` filter to render shortcodes/blocks before conversion, extract metadata using WordPress functions with null checks, and output YAML frontmatter followed by converted content.

## Standard Stack

### Core

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| league/html-to-markdown | ^5.1.1 | Convert HTML to Markdown | The definitive PHP library: 26.6M downloads, PHP League maintained, DOM-based (not regex), extensible |
| WordPress Content Filter | WP 5.0+ | Render shortcodes and blocks | `apply_filters('the_content', $content)` processes the complete filter chain |

### Supporting

| Library/Function | Version | Purpose | When to Use |
|------------------|---------|---------|-------------|
| `get_the_date()` | WP 1.5+ | Retrieve formatted publication date | Extract date for frontmatter; accepts format string |
| `get_the_author()` | WP 2.0+ | Retrieve author display name | Extract author for frontmatter; requires post context |
| `get_the_post_thumbnail_url()` | WP 4.4+ | Get featured image URL | Extract featured image; returns `false` if none |
| `get_the_terms()` | WP 2.3+ | Get taxonomy terms for post | Extract categories/tags; accepts post ID + taxonomy |
| `wp_kses_post()` | WP 2.9+ | Sanitize content for allowed HTML | Security: sanitize before conversion if needed |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| league/html-to-markdown | Custom regex conversion | Fragile, maintenance burden, edge cases |
| league/html-to-markdown | html-to-markdown (Rust via FFI) | Requires FFI extension, complexity overkill |
| `apply_filters('the_content')` | Manual `do_blocks()` + `do_shortcode()` | Misses other filter callbacks, incomplete rendering |
| YAML frontmatter | Inline metadata (Bold text format) | Less structured, harder for parsers, not static-site compatible |

**Installation:**
```bash
composer require league/html-to-markdown
```

## Architecture Patterns

### Recommended Project Structure

```
src/
├── Plugin.php                    # Existing: Core orchestrator
├── Router/
│   └── RewriteHandler.php        # Existing: URL routing
├── Converter/
│   └── MarkdownConverter.php     # NEW: Wraps league/html-to-markdown
└── Output/
    ├── ContentRenderer.php       # NEW: Processes post content through filters
    └── MarkdownFormatter.php     # NEW: Assembles frontmatter + body
```

### Pattern 1: Content Pipeline

**What:** Process content through discrete stages: filter -> sanitize -> convert -> format.
**When to use:** When transforming content with multiple processing steps.
**Example:**
```php
// Source: WordPress Developer Documentation + league/html-to-markdown
namespace MarkdownAlternate\Output;

class ContentRenderer {
    public function render(WP_Post $post): string {
        // Stage 1: Get raw content and apply WordPress filters
        $content = $post->post_content;
        $content = apply_filters('the_content', $content);

        // Stage 2: Convert HTML to Markdown
        $converter = new \MarkdownAlternate\Converter\MarkdownConverter();
        $markdown_body = $converter->convert($content);

        // Stage 3: Assemble with frontmatter
        $formatter = new MarkdownFormatter();
        return $formatter->format($post, $markdown_body);
    }
}
```

### Pattern 2: Null-Safe Metadata Extraction

**What:** Extract metadata with explicit null/false checks before inclusion.
**When to use:** When optional fields like featured image may not exist.
**Example:**
```php
// Source: WordPress Developer Documentation
private function extract_metadata(WP_Post $post): array {
    $meta = [
        'title' => get_the_title($post),
        'date' => get_the_date('Y-m-d', $post),
        'author' => get_the_author_meta('display_name', $post->post_author),
    ];

    // Featured image - returns false if not set
    $thumbnail = get_the_post_thumbnail_url($post->ID, 'full');
    if ($thumbnail) {
        $meta['featured_image'] = $thumbnail;
    }

    // Categories - returns false or WP_Error if none
    $categories = get_the_terms($post->ID, 'category');
    if ($categories && !is_wp_error($categories)) {
        $meta['categories'] = wp_list_pluck($categories, 'name');
    }

    // Tags - returns false or WP_Error if none
    $tags = get_the_terms($post->ID, 'post_tag');
    if ($tags && !is_wp_error($tags)) {
        $meta['tags'] = wp_list_pluck($tags, 'name');
    }

    return $meta;
}
```

### Pattern 3: YAML Frontmatter Generation

**What:** Output metadata as YAML block delimited by `---`.
**When to use:** For static site generator compatibility and structured metadata.
**Example:**
```php
// Source: Hugo/Jekyll frontmatter conventions
private function generate_frontmatter(array $meta): string {
    $yaml = "---\n";

    // Simple string values
    foreach (['title', 'date', 'author', 'featured_image'] as $key) {
        if (isset($meta[$key])) {
            // Escape quotes in values
            $value = str_replace('"', '\"', $meta[$key]);
            $yaml .= "{$key}: \"{$value}\"\n";
        }
    }

    // Array values (categories, tags)
    foreach (['categories', 'tags'] as $key) {
        if (!empty($meta[$key])) {
            $yaml .= "{$key}:\n";
            foreach ($meta[$key] as $item) {
                $yaml .= "  - \"{$item}\"\n";
            }
        }
    }

    $yaml .= "---\n";
    return $yaml;
}
```

### Anti-Patterns to Avoid

- **Using `get_the_content()` without filters:** Returns raw content with unprocessed shortcodes/blocks
- **Assuming featured image exists:** `get_the_post_thumbnail_url()` returns `false` when no image set
- **Ignoring WP_Error returns:** `get_the_terms()` can return `WP_Error` on invalid taxonomy
- **Not escaping YAML values:** Titles with quotes or colons break YAML parsing
- **Converting unsanitized HTML:** Security risk if content contains malicious scripts

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| HTML to Markdown | Custom regex parsing | league/html-to-markdown | 170+ edge cases handled, tables, lists, nested structures |
| Shortcode rendering | Manual regex to strip `[shortcode]` | `apply_filters('the_content', $content)` | WordPress handles nested, complex shortcodes |
| Block rendering | Strip `<!-- wp:* -->` comments | `do_blocks()` via the_content filter | Blocks have dynamic rendering logic |
| YAML generation | Simple string concatenation | Proper escaping with quoted strings | Special characters break parsers |
| Date formatting | Manual date parsing | `get_the_date($format, $post)` | WordPress handles timezone, i18n |

**Key insight:** The `the_content` filter chain does heavy lifting. Don't try to manually process shortcodes and blocks - WordPress has years of edge case handling built in. Just apply the filter and convert the resulting HTML.

## Common Pitfalls

### Pitfall 1: Raw Shortcodes in Output

**What goes wrong:** Markdown output contains `[gallery ids="1,2,3"]` instead of actual content.
**Why it happens:** Using `$post->post_content` directly without applying `the_content` filter.
**How to avoid:** Always apply the content filter before conversion:
```php
$content = apply_filters('the_content', $post->post_content);
```
**Warning signs:** Square bracket syntax in markdown output, galleries/embeds missing.

### Pitfall 2: Gutenberg Block Comments in Output

**What goes wrong:** Markdown contains `<!-- wp:paragraph -->` comments.
**Why it happens:** Block comments are stripped by `do_blocks()` at priority 9 on `the_content`. Skipping the filter leaves them.
**How to avoid:** Same as above - apply the full content filter chain.
**Warning signs:** HTML comments with `wp:` prefix in output.

### Pitfall 3: Featured Image Shows "false" or Causes Error

**What goes wrong:** Output shows "featured_image: false" or PHP warning about boolean to string conversion.
**Why it happens:** `get_the_post_thumbnail_url()` returns `false` when no featured image is set.
**How to avoid:** Check return value before including in frontmatter:
```php
$thumbnail = get_the_post_thumbnail_url($post->ID, 'full');
if ($thumbnail) {
    $meta['featured_image'] = $thumbnail;
}
// If false, simply omit the field
```
**Warning signs:** Boolean "false" appearing in YAML, PHP warnings in logs.

### Pitfall 4: YAML Parsing Errors from Special Characters

**What goes wrong:** YAML frontmatter fails to parse due to special characters in title.
**Why it happens:** Titles like `My Post: A Study in "Quotes"` break YAML syntax.
**How to avoid:** Always quote string values and escape internal quotes:
```php
$value = str_replace('"', '\"', $meta['title']);
$yaml .= "title: \"{$value}\"\n";
```
**Warning signs:** YAML parse errors in consumers, truncated frontmatter.

### Pitfall 5: Categories/Tags Return WP_Error

**What goes wrong:** PHP fatal error or unexpected output when post has no categories.
**Why it happens:** `get_the_terms()` returns `WP_Error` for invalid taxonomy, `false` for no terms.
**How to avoid:** Check both conditions:
```php
$terms = get_the_terms($post->ID, 'category');
if ($terms && !is_wp_error($terms)) {
    // Safe to use
}
```
**Warning signs:** PHP errors, empty arrays displayed as "Array" in output.

### Pitfall 6: XSS Vectors Passed Through Conversion

**What goes wrong:** Malicious `<script>` tags survive conversion and appear in markdown.
**Why it happens:** HTML-to-markdown converter preserves unrecognized tags by default.
**How to avoid:** Configure converter with security options:
```php
$converter = new HtmlConverter([
    'strip_tags' => true,
    'remove_nodes' => 'script style iframe'
]);
```
**Warning signs:** Script tags in markdown output, security scanner alerts.

## Code Examples

### Complete MarkdownConverter Class

```php
<?php
// Source: league/html-to-markdown documentation
namespace MarkdownAlternate\Converter;

use League\HTMLToMarkdown\HtmlConverter;

class MarkdownConverter {
    private HtmlConverter $converter;

    public function __construct() {
        $this->converter = new HtmlConverter([
            'header_style' => 'atx',         // Use # style headers
            'strip_tags' => true,            // Remove unknown HTML tags
            'remove_nodes' => 'script style iframe', // Security: remove dangerous tags
            'hard_break' => false,           // Standard markdown line breaks
            'list_item_style' => '-',        // Use - for list items
        ]);
    }

    public function convert(string $html): string {
        // Normalize whitespace
        $html = trim($html);

        if (empty($html)) {
            return '';
        }

        return $this->converter->convert($html);
    }
}
```

### Complete ContentRenderer Class

```php
<?php
// Source: WordPress Developer Documentation
namespace MarkdownAlternate\Output;

use WP_Post;
use MarkdownAlternate\Converter\MarkdownConverter;

class ContentRenderer {
    private MarkdownConverter $converter;

    public function __construct() {
        $this->converter = new MarkdownConverter();
    }

    public function render(WP_Post $post): string {
        // Extract and format frontmatter
        $frontmatter = $this->generate_frontmatter($post);

        // Get content and apply WordPress filters (renders shortcodes, blocks)
        $content = $post->post_content;
        $content = apply_filters('the_content', $content);

        // Convert HTML to Markdown
        $body = $this->converter->convert($content);

        // Combine frontmatter and body
        return $frontmatter . "\n" . $body;
    }

    private function generate_frontmatter(WP_Post $post): string {
        $yaml = "---\n";

        // Title (required)
        $title = get_the_title($post);
        $yaml .= 'title: "' . $this->escape_yaml($title) . "\"\n";

        // Date (required)
        $date = get_the_date('Y-m-d', $post);
        $yaml .= "date: {$date}\n";

        // Author (required)
        $author = get_the_author_meta('display_name', $post->post_author);
        $yaml .= 'author: "' . $this->escape_yaml($author) . "\"\n";

        // Featured image (optional)
        $thumbnail = get_the_post_thumbnail_url($post->ID, 'full');
        if ($thumbnail) {
            $yaml .= "featured_image: {$thumbnail}\n";
        }

        // Categories (optional)
        $categories = get_the_terms($post->ID, 'category');
        if ($categories && !is_wp_error($categories)) {
            $yaml .= "categories:\n";
            foreach ($categories as $cat) {
                $yaml .= '  - "' . $this->escape_yaml($cat->name) . "\"\n";
            }
        }

        // Tags (optional)
        $tags = get_the_terms($post->ID, 'post_tag');
        if ($tags && !is_wp_error($tags)) {
            $yaml .= "tags:\n";
            foreach ($tags as $tag) {
                $yaml .= '  - "' . $this->escape_yaml($tag->name) . "\"\n";
            }
        }

        $yaml .= "---\n";

        return $yaml;
    }

    private function escape_yaml(string $value): string {
        // Escape backslashes first, then quotes
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }
}
```

### Integration with RewriteHandler

```php
<?php
// Source: Existing plugin code + new integration
// In RewriteHandler::handle_markdown_request()

// After post validation checks...
$renderer = new \MarkdownAlternate\Output\ContentRenderer();
$markdown = $renderer->render($post);

header('Content-Type: text/markdown; charset=UTF-8');
echo $markdown;
exit;
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Inline metadata (`**Date:** Jan 30`) | YAML frontmatter | Industry standard since Jekyll ~2008 | Machine-parseable, static site compatible |
| `get_the_content()` | `apply_filters('the_content', $content)` | Always needed for shortcodes | Shortcodes render properly |
| Manual block stripping | `do_blocks()` via filter chain | WP 5.0 (2018) | Gutenberg blocks render to HTML |
| league/html-to-markdown 4.x | league/html-to-markdown 5.1.1 | May 2021 | Improved table support, PHP 8 compatibility |

**Deprecated/outdated:**
- Parsing markdown with regex: Use proper DOM-based conversion
- `strip_shortcodes()` for cleaning: Apply filter to render instead of strip
- Manual `do_blocks()` + `do_shortcode()` calls: Use full `the_content` filter chain

## Open Questions

1. **Title duplication in frontmatter vs H1**
   - What we know: YAML frontmatter includes title; some users may want it as H1 in body too
   - What's unclear: Should title appear in both frontmatter AND as H1 in content body?
   - Recommendation: Include in frontmatter only; consumers can render H1 from frontmatter. Avoid duplication.

2. **Date format in frontmatter**
   - What we know: ISO 8601 (`Y-m-d`) is most portable
   - What's unclear: Should we include time component (`Y-m-d\TH:i:s`)?
   - Recommendation: Use `Y-m-d` (date only) for simplicity; add time in v2 if requested.

3. **Custom taxonomy handling**
   - What we know: Phase 2 covers standard categories and tags only
   - What's unclear: How to handle custom taxonomies in future
   - Recommendation: Design taxonomy extraction to be extensible; add filter hook in v2 for custom taxonomies.

## Sources

### Primary (HIGH confidence)
- [league/html-to-markdown GitHub](https://github.com/thephpleague/html-to-markdown) - Official repository, configuration options
- [league/html-to-markdown Packagist](https://packagist.org/packages/league/html-to-markdown) - Version 5.1.1, 26.6M downloads
- [WordPress the_content hook](https://developer.wordpress.org/reference/hooks/the_content/) - Filter chain documentation
- [WordPress do_blocks()](https://developer.wordpress.org/reference/functions/do_blocks/) - Block rendering function
- [WordPress get_the_post_thumbnail_url()](https://developer.wordpress.org/reference/functions/get_the_post_thumbnail_url/) - Featured image retrieval
- [WordPress get_the_terms()](https://developer.wordpress.org/reference/functions/get_the_terms/) - Taxonomy term retrieval
- [WordPress get_the_author()](https://developer.wordpress.org/reference/functions/get_the_author/) - Author retrieval
- [WordPress get_the_date()](https://developer.wordpress.org/reference/functions/get_the_date/) - Date retrieval

### Secondary (MEDIUM confidence)
- [WordPress default-filters.php](https://github.com/WordPress/wordpress-develop/blob/trunk/src/wp-includes/default-filters.php) - Filter priority reference
- [Hugo Front Matter](https://gohugo.io/content-management/front-matter/) - YAML frontmatter conventions
- [SSW Rules: Frontmatter Best Practices](https://www.ssw.com.au/rules/best-practices-for-frontmatter-in-markdown) - Industry guidance

### Tertiary (LOW confidence)
- Project research from `.planning/research/STACK.md`, `.planning/research/PITFALLS.md` - Internal documentation

## Metadata

**Confidence breakdown:**
- Standard Stack: HIGH - Verified against Packagist, WordPress documentation
- Architecture: HIGH - Based on established WordPress patterns and league library docs
- Pitfalls: HIGH - Documented in project research, verified with WordPress Developer Reference

**Research date:** 2026-01-30
**Valid until:** 60 days (library and WordPress APIs are stable)

---
*Phase 2 research for: Markdown Alternate WordPress plugin*
*Requirements: CONT-01, CONT-02, CONT-03, CONT-04, CONT-05, CONT-06, CONT-07, TECH-02*
