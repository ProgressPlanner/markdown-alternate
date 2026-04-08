=== Markdown Alternate ===
Contributors: joostdevalk
Tags: markdown, llm, ai, content, api
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Expose WordPress posts and pages as clean Markdown via .md URLs, content negotiation, and a query parameter fallback.

== Description ==

Markdown Alternate gives your existing WordPress content a clean Markdown version without requiring editors to maintain a second format.

It is useful for:

* AI and LLM retrieval workflows
* Developer tools and content pipelines
* Headless or hybrid publishing setups
* Readers and systems that prefer clean text over full HTML

After activation, supported content can be requested in multiple ways:

* Append `.md` to a post or page URL
* Send an `Accept: text/markdown` header to the normal permalink
* Use `?format=markdown` when custom headers are not practical

**Key benefits:**

* Zero configuration after activation
* Works with posts and pages out of the box
* Supports nested pages and date-based permalinks
* Caches generated Markdown for better performance
* Lets developers enable custom post types
* Integrates with Yoast SEO `llms.txt` generation when Yoast SEO is active
* Integrates with WooCommerce — exposes products at `.md` URLs with price, SKU, stock, attributes, and variations

**Good to know:**

* Pretty permalinks must be enabled
* The plugin uses your published WordPress content as the source of truth
* Markdown output includes useful front matter such as title, date, author, featured image, categories, and tags when available

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/markdown-alternate/` or install it through WordPress.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to 'Settings > Permalinks' and make sure your permalink structure is not set to 'Plain'.
4. Open any published post or page URL with `.md` appended.

Example:

`https://example.com/hello-world.md`

== Frequently Asked Questions ==

= What problem does this solve? =

It gives your WordPress content a Markdown representation that is easier to consume in AI pipelines, developer tooling, documentation workflows, and other text-first use cases.

= How do I access the Markdown version of a post? =

Use any of these methods:

* Append `.md` to the URL, for example `https://example.com/hello-world.md`
* Request the normal URL with `Accept: text/markdown`
* Use `?format=markdown`, for example `https://example.com/hello-world/?format=markdown`

= Why do I get 404 errors on `.md` URLs? =

Pretty permalinks must be enabled. Go to 'Settings > Permalinks' and select any structure other than 'Plain'. If you just activated the plugin, saving permalinks once can also help refresh rewrite rules.

= Do I need to configure anything? =

No. The plugin works immediately after activation for posts and pages.

= Does it work with custom post types? =

Yes. Developers can enable custom post types with the `markdown_alternate_supported_post_types` filter.

Example:

`add_filter( 'markdown_alternate_supported_post_types', function( $types ) {
    $types[] = 'your_custom_type';
    return $types;
} );`

= Is the output cached? =

Yes. Markdown output is cached using WordPress transients for 24 hours by default. The cache is automatically bypassed if the post has changed. Developers can adjust the cache duration with the `markdown_alternate_cache_expiration` filter.

= What does the Markdown output include? =

The output includes converted post content plus front matter such as the title, publication date, author, featured image URL, categories, and tags when available.

= Does it work with Yoast SEO llms.txt? =

Yes. When Yoast SEO is active and generating `llms.txt`, Markdown Alternate can rewrite supported post URLs to their `.md` versions.

= Does it work with WooCommerce? =

Yes. When WooCommerce is active, products are automatically served at `.md` URLs. The output includes price, sale price, SKU, stock status, product categories and tags in the frontmatter, plus a summary block, attributes, and variations (for variable products) in the body.

= How can my plugin add custom fields or sections to the markdown output? =

Two filters let you extend any post's markdown output:

* `markdown_alternate_frontmatter` — add YAML keys.
* `markdown_alternate_content_sections` — add ordered body sections (lower `priority` runs first; the title is `0`, the post content is `100`).

Example:

`add_filter( 'markdown_alternate_frontmatter', function( $data, $post ) {
    $data['cook_time'] = get_post_meta( $post->ID, '_cook_time', true );
    return $data;
}, 10, 2 );

add_filter( 'markdown_alternate_content_sections', function( $sections, $post ) {
    $sections['my_ingredients'] = [
        'priority' => 120,
        'markdown' => "## Ingredients\n\n- Flour\n- Sugar",
    ];
    return $sections;
}, 10, 2 );`

If your data lives outside `post_content` (e.g. postmeta), call `delete_transient( 'md_alt_cache_' . $post_id )` from your own update hooks so cached output stays fresh.

== Changelog ==

= 1.2.0 =
* Added WooCommerce integration: products are exposed at `.md` URLs with price, SKU, stock, attributes, and variations
* Added the `markdown_alternate_frontmatter` filter so integrations can add YAML frontmatter keys
* Added the `markdown_alternate_content_sections` filter so integrations can add ordered body sections
* Added the `markdown_alternate_cache_version` filter for integration-driven cache invalidation

= 1.1.0 =
* Added transient caching with a 24 hour default and post-modified validation
* Added the `markdown_alternate_cache_expiration` filter for cache control
* Improved privacy by hiding alternate link tags for password-protected posts
* Added Yoast SEO `llms.txt` integration for supported content types
* Improved plugin messaging and documentation

= 1.0.0 =
* Initial release
* Support for posts and pages
* Clean `.md` URL endpoints
* Content negotiation via the `Accept` header
* Query parameter fallback via `?format=markdown`
* Custom post type support via filter hook
