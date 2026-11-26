=== Markdown Alternate ===
Contributors: joostdevalk
Tags: markdown, llm, ai, content, api
Requires at least: 6.0
Tested up to: 7.2
Requires PHP: 7.4
Stable tag: 1.2.0
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

* Append `.md` to a post or page URL, or to a category or tag archive URL
* Send an `Accept: text/markdown` header to the normal permalink
* Use `?format=markdown` when custom headers are not practical

**Key benefits:**

* Zero configuration after activation
* Works with posts and pages out of the box
* Supports nested pages and date-based permalinks
* Caches generated Markdown for better performance
* Lets developers enable custom post types
* Integrates with Yoast SEO `llms.txt` generation when Yoast SEO is active

**Good to know:**

* Pretty permalinks must be enabled
* The plugin uses your published WordPress content as the source of truth
* Markdown output includes useful front matter such as title, date, author, featured image, categories, and tags when available

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/markdown-alternate/` or install it through WordPress.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to 'Settings > Permalinks' and make sure your permalink structure is not set to 'Plain'.
4. Open any published post or page URL with `.md` appended.

For example, the Markdown version of `https://example.com/hello-world/` is `https://example.com/hello-world.md` (remove the trailing slash before adding `.md`).

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

Yes. Eligible anonymous Markdown output is cached using WordPress transients for 24 hours by default. Logged-in visitors, password-protected posts, and visitors with known session or cart cookies bypass shared caching and receive private, no-store responses. Old cache entries are regenerated after the cache-isolation fix. The cache is also regenerated if the post has changed. Developers can adjust the duration with `markdown_alternate_cache_expiration` and disable caching for custom visitor-dependent output with `markdown_alternate_cache_enabled`.

= What does the Markdown output include? =

The output includes converted post content plus front matter such as the title, publication date, author, featured image URL, categories, and tags when available.

= Does it work with Yoast SEO llms.txt? =

Yes. When Yoast SEO is active and generating `llms.txt`, Markdown Alternate can rewrite supported post URLs to their `.md` versions.

= Can posts marked noindex skip the Markdown version? =

Yes, as an opt-in. With `add_filter( 'markdown_alternate_skip_noindexed', '__return_true' );`, posts that Yoast SEO marks noindex get no `.md` URL, no query parameter fallback, no Accept redirect and no alternate link. Their links in newly generated Yoast llms.txt files keep the HTML URL. Regenerate llms.txt after enabling the filter. By default posts keep their Markdown version.

== Changelog ==

= Unreleased =
* Excluded development files from GitHub source archives and release ZIPs while retaining runtime dependencies, licenses, and translations.
* Added a translation template and loading support for contributed translations.
* Label tax-inclusive displayed product prices and give long product descriptions their own heading.
* Kept author and date archives in HTML instead of redirecting Markdown requests to missing pages
* Fixed cover block images running into the first inner heading
* Omitted aria-hidden and data-URI images from Markdown
* Preserved iframe embeds as links instead of dropping their content
* Added Markdown versions of category and tag archives, and of WooCommerce product category, tag and brand archives
* Changed frontmatter links to terms of taxonomies that are not served to point to their HTML archive instead of a `.md` URL that returned 404
* Changed frontmatter links to terms to absolute URLs, like the links in the body
* Added product URL, modified date, GTIN, sale end date and ratings to WooCommerce product frontmatter
* Removed the post author from WooCommerce product frontmatter
* Prevented visitor-specific Markdown from entering shared caches and invalidated previously generated entries
* Moved Markdown response handlers after default-priority access checks and canonical redirects
* Added cache-isolation, response-header, and routing-order regression tests

= 1.2.0 =
* Added WooCommerce product metadata, formatted prices, short descriptions, visible attributes, and variations
* Bypassed shared and HTTP caching for customer-dependent product output
* Added real WooCommerce integration and HTTP regression tests
* Added filters for frontmatter taxonomies, typed fields, and serialized YAML lines
* Added ordered markdown body sections for integrations
* Added cache version tokens for data outside post content
* Preserved default post and page output, with standalone regression coverage
* Added the `markdown_alternate_skip_noindexed` filter to withhold markdown for posts Yoast SEO marks noindex
* Preserved HTML links in newly generated Yoast llms.txt files for posts excluded from Markdown
* Preserved Markdown table structure and non-ASCII characters in Markdown URLs
* Fixed static front page requests using `?format=markdown`
* Prevented shared caches from storing Accept-header redirects
* Fixed repeated escaping of frontmatter values

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
