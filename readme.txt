=== Markdown Alternate ===
Contributors: joostdevalk
Tags: markdown, content, api, llm
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Provides markdown versions of posts and pages via .md URLs.

== Description ==

Markdown Alternate exposes your WordPress content as clean markdown through predictable URLs. Simply append `.md` to any post or page URL to get the markdown version.

This plugin is designed for:

* LLMs consuming web content
* Developers building tools that parse content
* Users who prefer markdown over HTML

**Features:**

* Access any post at `/post-slug.md`
* Access any page at `/page-slug.md`
* Nested pages work: `/parent/child.md`
* Date-based permalinks supported: `/2024/01/my-post.md`
* Performance: Markdown output is cached for 24 hours
* Zero configuration required

**Requirements:**

* Pretty permalinks must be enabled (not "Plain" structure)
* PHP 7.4 or higher
* WordPress 6.0 or higher

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/markdown-alternate/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Visit any post or page URL with `.md` extension

That's it! No configuration needed.

== Frequently Asked Questions ==

= Why do I get 404 errors on .md URLs? =

Ensure pretty permalinks are enabled. Go to Settings > Permalinks and select any structure other than "Plain".

= Do I need to configure anything? =

No. The plugin works immediately after activation with zero configuration.

= Is the output cached? =

Yes. Markdown output is cached using WordPress transients for 24 hours. The cache is automatically cleared if the post is modified. Developers can adjust the cache duration using the `markdown_alternate_cache_expiration` filter.

= What content is included in the markdown output? =

The markdown output includes the post title, publication date, author, featured image URL (if set), the post content converted to markdown, and categories/tags.

= Does it work with custom post types? =

Yes! By default, only posts and pages are supported. Developers can enable custom post types using a filter hook:

`add_filter( 'markdown_alternate_supported_post_types', function( $types ) {
    $types[] = 'your_custom_type';
    return $types;
} );`

= What if my client cannot send Accept headers? =

Use the `format` query parameter: `https://example.com/hello-world/?format=markdown`

This works on any supported post URL. The value must be exactly `markdown` (lowercase).

== Changelog ==

= 1.1.0 =
* Performance: Implemented transient caching (24h default) with post-modified validation
* Privacy: Hide alternate link tags in HTML head for password-protected posts
* Extensibility: Added `markdown_alternate_cache_expiration` filter

= 1.0.0 =
* Initial release
* Support for posts and pages
* Clean .md URL endpoints
* Content negotiation via Accept header
* Query parameter fallback via ?format=markdown
* Custom post type support via filter hook
