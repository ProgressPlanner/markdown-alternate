=== Markdown Alternate ===
Contributors: joostdevalk
Tags: markdown, llm, ai, content, api
Requires at least: 6.0
Tested up to: 7.2
Requires PHP: 7.4
Stable tag: 1.3.0
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
* Added the `markdown_alternate_is_noindexed` filter, to mark posts noindexed by other means than Yoast SEO
* With `markdown_alternate_skip_noindexed` on, the WooCommerce cart, checkout and My account pages get no Markdown version

= 1.3.0 =
* Security: Prevented logged-in, password-protected, and known session-specific Markdown from entering shared caches; invalidated older cached documents and sent private, no-store headers for personalized post and archive responses.
* Moved Markdown response handlers to template_redirect priority 15, after default-priority access checks and canonical redirects, while retaining the canonical-redirect exemption for explicit .md URLs.
* Added Markdown category, tag, and WooCommerce product category, tag, and brand archives, including query-parameter and Accept-header access and alternate discovery links.
* Added archive metadata, descriptions, parent and child term links, and published post listings with a configurable default limit of 100 and a link to the full HTML archive.
* Added archive extension filters for supported taxonomies, frontmatter, body sections, queries, list items, post limits, and HTTP cache eligibility.
* Added opt-in exclusion of Yoast-noindexed term archives, with HTML links retained for withheld archives and noindexed posts.
* Made product archive listings respect catalog visibility, hidden out-of-stock settings, and menu-order/title sorting; included displayed prices, availability, and currency, with shared HTTP caching disabled.
* Handled nested term paths and WordPress installations in subdirectories, rejected mismatched archive paths, and kept archives empty when no supported post types remain.
* Changed frontmatter term URLs to absolute URLs and used HTML links for taxonomies without Markdown support instead of links to missing .md pages.
* Added markdown_alternate_frontmatter_terms for excluding individual categories, tags, and product terms, with examples for category IDs, Yoast noindex, and cache invalidation.
* Kept author and date archives in HTML instead of redirecting Markdown requests to missing pages.
* Added product URL, modified date, GTIN/global identifier, active-sale end date, average rating, and review count to WooCommerce frontmatter when available; retained compatibility with older WooCommerce versions.
* Removed the shop-user author field from product frontmatter.
* Labeled displayed tax-inclusive product prices and gave nonempty long product descriptions their own heading.
* Fixed cover block images running into the first inner heading.
* Omitted aria-hidden images, images inside hidden containers, and data-URI images from Markdown while retaining surrounding text.
* Preserved iframe embeds as links, including titles and captions, while rejecting unsafe URL schemes and leaving image and iframe code examples unchanged.
* Added a translation template, bundled language loading, and instructions for contributing and compiling translations; no completed translations are bundled yet.
* Shared YAML frontmatter and body-section formatting between post and archive renderers.
* Added regression coverage for visitor cache isolation, response headers, routing order, archives, conversion, term exclusions, and WooCommerce metadata and display behavior.
* Excluded development files and Composer manifests from GitHub source archives and release ZIPs while retaining runtime dependencies, licenses, and translations; added archive comparison and dependency smoke checks.
* Added automated GitHub release packaging with an installable ZIP, checksums, and a CycloneDX software bill of materials, including verification of all shipped Composer dependencies.
* Added PHPStan analysis, development-dependency safeguards for committed vendor files, automated WordPress version checks, and pinned plugin readme previews in CI.
* Updated WordPress compatibility metadata and clarified the trailing-slash handling in the installation URL example.

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
