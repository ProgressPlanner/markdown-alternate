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
- Serves category and tag archives at `.md` URLs, listing their posts
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
https://example.com/category/news.md
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
    url: "https://example.com/category/category-a.md"
tags:
  - name: "tag1"
    url: "https://example.com/tag/tag1.md"
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

## WooCommerce products

With WooCommerce active, published products are available at their `.md` URL and
through the same query parameter and Accept-header routes as posts and pages.
Standalone variations are not exposed as separate Markdown pages.

Product output includes typed metadata for the product URL, last modified date,
SKU, GTIN (WooCommerce 9.2+), currency, product type, prices, sale end date,
stock, and rating with review count (when ratings are enabled and the product has
reviews); the post author is left out. It also includes product categories, tags,
and registered brands; a price/availability summary; the formatted short
description; visible attributes; and available
variations. Password protection and publication checks still apply.

The body uses WooCommerce's displayed prices, including tax settings, currency,
price ranges, active sales, and pricing filters. Numeric frontmatter prices are
catalog values before display-tax adjustments, so they do not depend on who
requests the page; `prices_include_tax` says whether those values include tax.
Variable products use `price` and `price_max` for the minimum and maximum catalog
prices. A future sale is not reported as an active sale. SKUs in the body are
code spans so they can be copied exactly.

Product categories, tags, and brands are served at their `.md` URL too, which
product frontmatter links to. They list their products in catalog order, with the
displayed price and availability, leaving out products hidden from the catalog
(and out-of-stock products when the shop hides them).

Product responses bypass the shared Markdown transient cache and send private,
no-store HTTP headers because prices can depend on the current customer. This
also keeps stock, price, and variation updates fresh. Posts and pages retain their
existing caching behavior. Large variable products take more work to render because
the integration lists all available variations.

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

### Skip posts marked noindex

By default a post keeps its markdown copy even when an SEO plugin marks it noindex. To withhold the `.md` URL, the `?format=markdown` fallback, the Accept redirect and the alternate link for posts that Yoast SEO marks noindex, opt in with `markdown_alternate_skip_noindexed`:

```php
add_filter( 'markdown_alternate_skip_noindexed', '__return_true' );
```

The filter receives the post as its second argument. The check reads Yoast's effective robots value, including an empty robots array or filters that remove noindex; stale per-post metadata does not override that value. Without Yoast active, nothing is skipped. During Yoast llms.txt generation, skipped posts keep their HTML URLs instead of links to unavailable Markdown copies. Regenerate llms.txt after enabling the filter to update an existing file. Markdown responses retain their canonical Link header; this opt-in does not add a blanket noindex header.

### Category, tag, and other term archives

Category and tag archives are served at their `.md` URL. The output has
frontmatter with the term's name, HTML URL, taxonomy, number of posts, and parent;
the term description; its child terms; and its published posts, newest first. Only
the first 100 posts are listed, followed by a link to the HTML archive for the rest.
The `?format=markdown` fallback, the Accept redirect, and the alternate link work
on these archives as they do on posts.

Frontmatter links to a term point to its `.md` URL only when its taxonomy is
served; other terms link to their HTML archive. Serve another taxonomy with
`markdown_alternate_supported_taxonomies`:

```php
add_filter( 'markdown_alternate_supported_taxonomies', function( $taxonomies ) {
    $taxonomies[] = 'genre';
    return $taxonomies;
} );
```

| Filter | Value to return |
| --- | --- |
| `markdown_alternate_supported_taxonomies` | Taxonomies whose archives are served; defaults to `category` and `post_tag` (WooCommerce adds `product_cat`, `product_tag`, and `product_brand`). |
| `markdown_alternate_term_frontmatter_content_lines` | Typed fields of a term archive, in the same format as posts. |
| `markdown_alternate_term_sections` | Body sections: `title` (0), `description` (100), `children` (110), and `items` (120). |
| `markdown_alternate_term_query_args` | `WP_Query` arguments for the listed posts. |
| `markdown_alternate_term_item` | The Markdown list item of one post. |
| `markdown_alternate_term_posts_limit` | How many posts are listed; default 100. |
| `markdown_alternate_term_cache_enabled` | `false` to send private, no-store headers for an archive. |
| `markdown_alternate_skip_noindexed_terms` | `true` to withhold archives that Yoast SEO marks noindex. |

Term archives are not stored in the Markdown transient cache: they change
whenever a post is published in them.

### Extend frontmatter

The rendering filters receive the current `WP_Post` as their second argument.
Return an array from each filter and register callbacks with two accepted arguments
when you need the post. Normal post and page output stays unchanged without filters.

| Filter | Value to return |
| --- | --- |
| `markdown_alternate_frontmatter_taxonomies` | A map of taxonomy names to frontmatter keys; defaults to `category => categories` and `post_tag => tags`. |
| `markdown_alternate_frontmatter_content_lines` | Typed fields keyed by their frontmatter names, before serialization. |
| `markdown_alternate_frontmatter_lines` | Serialized YAML lines, including the opening `---`, before the closing `---` is added. |

Scalar fields use `type` and `content`. Use `string` or `url` for quoted values,
`number` for unquoted numbers, `boolean` for `true`/`false`, and `date` for an
unquoted date. Supply raw values:
the renderer decodes HTML entities and escapes quoted values once. Empty strings
and zero are retained; fields without a type or with null content are skipped.

```php
add_filter( 'markdown_alternate_frontmatter_content_lines', function( $fields, $post ) {
    if ( 'recipe' === $post->post_type ) {
        $fields['cook_time'] = array(
            'type'    => 'number',
            'content' => (int) get_post_meta( $post->ID, '_cook_time', true ),
        );
    }
    return $fields;
}, 10, 2 );
```

For a list of records such as categories, use `type => array` and `items` containing
maps of typed scalar fields:

```php
$fields['specifications'] = array(
    'type'  => 'array',
    'items' => array(
        array(
            'name'  => array( 'type' => 'string', 'content' => 'Weight' ),
            'value' => array( 'type' => 'string', 'content' => '2 kg' ),
        ),
    ),
);
```

Use the taxonomy filter to replace or extend the default taxonomy mapping. The
final `markdown_alternate_frontmatter_lines` filter is for custom YAML structures;
its callbacks are responsible for valid YAML and must not add the closing delimiter.

### Add or reorder body sections

`markdown_alternate_content_sections` receives sections keyed by name. Each has
`priority` and `markdown` fields. The defaults are `title` at priority `0` and
`content` at `100`. Lower priorities render first; ties preserve insertion order,
including on PHP 7.4. Use unique section keys, and unset a key to remove a section.
Missing priorities default to `100`; empty or malformed entries are skipped when
the list is changed. Markdown strings are joined with two newlines.

```php
add_filter( 'markdown_alternate_content_sections', function( $sections, $post ) {
    $summary = get_post_meta( $post->ID, '_markdown_summary', true );
    if ( '' !== $summary ) {
        $sections['my_plugin_summary'] = array(
            'priority' => 50,
            'markdown' => "## Summary\n\n" . $summary,
        );
    }
    return $sections;
}, 10, 2 );
```

An unchanged section list retains the existing output exactly, including spacing
for empty posts. Returning a non-array from a rendering filter falls back to its
default value.

### Disable caching for customer-dependent output

Return `false` from `markdown_alternate_cache_enabled` to bypass both transient
reads and writes. The filter receives the `WP_Post` as its second argument. HTTP
responses also send private, no-store headers and mark the page as uncacheable.
The WooCommerce integration disables this cache for products; posts and pages
still default to cached output.

### Invalidate integration data

Frontmatter and section filters run only when the cached document is regenerated.
If metadata, taxonomy terms, or another source changes without changing the post's
modification timestamp, delete `md_alt_cache_{post_id}` from your update hook, or
return a changing string from `markdown_alternate_cache_version`:

```php
add_filter( 'markdown_alternate_cache_version', function( $version, $post ) {
    return $version . '|my_plugin:' . get_post_meta( $post->ID, '_my_plugin_revision', true );
}, 10, 2 );
```

Append your token to the existing value so multiple integrations can participate.
The token is stored with the cached document; it does not change the transient key.
Old cache entries without a version token are regenerated once. Cache duration
continues to use `markdown_alternate_cache_expiration`.

### Run renderer regression tests

Run `php tests/run.php` after installing dependencies. These standalone tests use
WordPress API doubles and the real Markdown converter, and compare default output
with fixtures captured from `main` at commit `7afba03`. CI runs them on PHP 7.4–8.3;
they do not replace a full WordPress integration test.

### Run WooCommerce integration tests

Use an isolated test WordPress installation with WooCommerce and this plugin
active, and run:

```bash
wp eval-file /path/to/markdown-alternate/tests/woocommerce-integration.php --path=/path/to/test-wordpress
```

The test creates and removes fixture products, terms, and a tax rate, and restores
the shop settings it changes. Do not run it against a production shop. To include
HTTP checks, start `wp server` for that installation and set `MD_ALT_TEST_HTTP=1`.
The site's configured URL must point to that server. CI tests WordPress 6.0 with
WooCommerce 7.0.1 on PHP 7.4, plus current WordPress and WooCommerce on PHP 8.3.

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

Potential future improvements include broader post type support defaults, additional integration coverage.

## License

GPL-2.0-or-later

See [`LICENSE`](LICENSE) for details.

## Author

Created by [Joost de Valk](https://joost.blog).
