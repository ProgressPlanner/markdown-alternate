# Phase 4: Extensibility & Fallbacks - Research

**Researched:** 2026-01-30
**Domain:** WordPress query parameters, custom post type support, filter hooks, rewrite rules for CPTs
**Confidence:** HIGH

## Summary

Phase 4 adds two extensibility features: a query parameter fallback (`?format=markdown`) for clients that cannot send Accept headers or access `.md` URLs, and a filter hook system for enabling custom post type (CPT) support. Both features extend the existing infrastructure from Phases 1-3 rather than introducing new patterns.

The query parameter fallback uses WordPress's existing query var system. Register a new `format` query variable via the `query_vars` filter, then check for `format=markdown` in the `template_redirect` handler before the Accept header check. This is the standard WordPress pattern for format-based output switching.

For custom post type support, the recommended approach uses a single filter hook that allows developers to enable markdown support for all public CPTs at once. The filter takes an array of supported post types (defaulting to `['post', 'page']`) and returns the modified array. CPT permalinks are obtained via `get_permalink()` (which works for all post types), and CPT archives via `get_post_type_archive_link()`. The existing rewrite rule pattern `(.+?)\.md$` already handles CPT URLs since it matches any path segment.

Key CONTEXT.md decisions constrain implementation: CPT markdown URLs match their permalink structure with `.md` appended, one filter enables all public CPTs (no per-type whitelisting required), and CPT archives also get markdown versions when the CPT is enabled.

**Primary recommendation:** Add `format` query var and check for `?format=markdown` early in `template_redirect`; create filter `markdown_alternate_supported_post_types` that returns array of post type slugs; update all post type checks from hardcoded array to filterable array.

## Standard Stack

### Core

| Library/API | Version | Purpose | Why Standard |
|-------------|---------|---------|--------------|
| WordPress `query_vars` filter | WP 1.5.0+ | Register custom `format` query variable | Standard mechanism for public query variables; integrates with WP query system |
| WordPress `get_query_var()` | WP 1.5.0+ | Retrieve `format` query parameter value | Returns registered query var value with optional default |
| WordPress `apply_filters()` | WP 0.71+ | Extensibility hook for post type support | Standard WordPress extensibility pattern; allows third-party modification |
| WordPress `get_post_types()` | WP 2.9.0+ | Retrieve registered public post types | Returns all post types matching criteria; used for bulk CPT enablement |
| WordPress `get_post_type_object()` | WP 3.0.0+ | Get CPT configuration details | Returns `WP_Post_Type` object with rewrite, has_archive, hierarchical properties |
| WordPress `is_singular()` | WP 1.5.0+ | Check if viewing single CPT item | Accepts array of post types to check against |

### Supporting

| Library/Tool | Version | Purpose | When to Use |
|--------------|---------|---------|-------------|
| `get_post_type_archive_link()` | WP 3.0.0+ | Get CPT archive URL | Building `.md` URLs for CPT archives |
| `is_post_type_archive()` | WP 3.0.0+ | Check if viewing CPT archive | Conditional logic for archive alternate links |
| `get_permalink()` | WP 1.0.0+ | Get CPT single item URL | Works for all post types, not just posts/pages |
| `in_array()` | PHP 4+ | Check if post type is in supported array | Validate post type against filtered list |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `?format=markdown` | `?md=1` or `?output=markdown` | `format` is more descriptive and aligns with REST API conventions |
| Single filter for all CPTs | Per-CPT filter registration | Per-CPT adds complexity; single filter with array is simpler |
| Filter returning boolean | Filter returning array | Array allows fine-grained control over which types are enabled |
| Checking `$_GET['format']` | `get_query_var('format')` | Query var is properly registered and works with rewrites |

**Installation:**
```bash
# No new dependencies needed
# Phase 4 uses existing WordPress APIs
```

## Architecture Patterns

### Recommended Project Structure
```
src/
├── Plugin.php                    # Orchestrator (existing)
├── Router/
│   └── RewriteHandler.php        # Extended: add format query var and CPT support
├── Output/
│   └── ContentRenderer.php       # Existing: no changes needed
└── Discovery/
    └── AlternateLinkHandler.php  # Extended: add CPT alternate links
```

### Pattern 1: Query Parameter Fallback

**What:** Check for `?format=markdown` query parameter as fallback when Accept header not available.
**When to use:** Clients that cannot send custom headers (browsers, simple HTTP clients, some LLM integrations).
**Example:**
```php
<?php
// Source: WordPress query_vars filter documentation

/**
 * Register format query variable.
 */
public function add_query_vars(array $vars): array {
    $vars[] = 'markdown_request';
    $vars[] = 'format';  // NEW: for ?format=markdown fallback
    return $vars;
}

/**
 * Handle query parameter fallback.
 * Check BEFORE Accept header detection in template_redirect.
 */
public function handle_format_parameter(): void {
    // Skip if already a markdown request (URL wins)
    if (get_query_var('markdown_request')) {
        return;
    }

    // Check format query parameter
    $format = get_query_var('format', '');
    if ($format !== 'markdown') {
        return;
    }

    // Get current post/archive and serve markdown
    $this->serve_markdown_for_current_content();
}
```

### Pattern 2: Filterable Post Type Support

**What:** Use `apply_filters()` to allow developers to add/remove supported post types.
**When to use:** Checking if a post type should have markdown support throughout the plugin.
**Example:**
```php
<?php
// Source: WordPress Custom Hooks Plugin Handbook

/**
 * Get array of post types that support markdown output.
 *
 * @return array Post type slugs that have markdown support enabled.
 */
private function get_supported_post_types(): array {
    $default_types = ['post', 'page'];

    /**
     * Filters the post types that support markdown output.
     *
     * @since 1.0.0
     *
     * @param array $post_types Array of post type slugs.
     */
    return apply_filters('markdown_alternate_supported_post_types', $default_types);
}

/**
 * Check if a post type supports markdown output.
 *
 * @param string $post_type The post type to check.
 * @return bool True if supported, false otherwise.
 */
private function is_supported_post_type(string $post_type): bool {
    return in_array($post_type, $this->get_supported_post_types(), true);
}
```

### Pattern 3: Enable All Public CPTs via Filter

**What:** Helper function for theme/plugin developers to enable all public CPTs at once.
**When to use:** Documentation example showing how to enable CPT support.
**Example:**
```php
<?php
// Source: CONTEXT.md decision - one filter to enable all public CPTs

/**
 * Enable markdown support for all public custom post types.
 * Add this to your theme's functions.php or a custom plugin.
 */
add_filter('markdown_alternate_supported_post_types', function($post_types) {
    // Get all public post types
    $public_types = get_post_types(['public' => true], 'names');

    // Merge with existing supported types (removes duplicates)
    return array_unique(array_merge($post_types, $public_types));
});
```

### Pattern 4: CPT Archive URL Construction

**What:** Build `.md` URLs for CPT archive pages.
**When to use:** When current page is a post type archive.
**Example:**
```php
<?php
// Source: WordPress get_post_type_archive_link() documentation

private function get_current_canonical_url(): ?string {
    // ... existing singular checks ...

    if (is_post_type_archive()) {
        $post_type = get_query_var('post_type');
        // Handle array case (WP can return array for post_type query var)
        if (is_array($post_type)) {
            $post_type = reset($post_type);
        }

        // Check if this CPT is supported
        if (!$this->is_supported_post_type($post_type)) {
            return null;
        }

        return get_post_type_archive_link($post_type);
    }

    // ... existing category/tag/date checks ...
}
```

### Anti-Patterns to Avoid

- **Using `$_GET['format']` directly:** Bypasses WordPress query var registration; doesn't work with rewrites
- **Hardcoding post types everywhere:** Replace all `['post', 'page']` arrays with filterable method call
- **Checking `has_archive` before serving single CPT:** `has_archive` is for archives; single CPT items don't need it
- **Forgetting to check CPT is supported in all locations:** Update `RewriteHandler`, `AlternateLinkHandler`, and anywhere post types are validated
- **Caching the filtered post types array incorrectly:** Call `get_supported_post_types()` each time; filter may return different values based on context

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Query parameter reading | `$_GET['format']` | `get_query_var('format')` | Properly integrated with WP query system |
| Post type enumeration | Manual array of all types | `get_post_types(['public' => true])` | WordPress maintains the registry |
| Post type object inspection | Manual database queries | `get_post_type_object($type)` | Returns cached `WP_Post_Type` object |
| CPT archive URL building | String concatenation | `get_post_type_archive_link($type)` | Handles rewrite slugs, query string mode |
| Filter hook system | Custom event dispatcher | `apply_filters()` / `add_filter()` | WordPress native extensibility |

**Key insight:** WordPress already has all the APIs needed for CPT support. The main task is replacing hardcoded `['post', 'page']` checks with a filterable array and ensuring the existing URL handling (which uses `(.+?)\.md$` pattern) works for CPT permalink structures.

## Common Pitfalls

### Pitfall 1: Format Parameter Conflicts with Other Plugins

**What goes wrong:** Another plugin uses `?format=` for something else.
**Why it happens:** `format` is a common query parameter name.
**How to avoid:** Use a more specific parameter name if conflicts arise, e.g., `md_format` or check `format` value strictly equals `markdown`.
**Warning signs:** Unexpected behavior when other plugins are active; format parameter processed by wrong handler.

### Pitfall 2: CPT Without Public Rewrite Slugs

**What goes wrong:** CPT has `publicly_queryable = true` but `rewrite = false`; URLs don't work.
**Why it happens:** Some CPTs are queryable via query string (`?post_type=cpt&p=123`) but lack pretty permalinks.
**How to avoid:** Check `$post_type_obj->rewrite` is not false before adding to supported types; or document this limitation.
**Warning signs:** CPT shows in admin but `.md` URLs return 404.

### Pitfall 3: Hierarchical CPT URL Mismatch

**What goes wrong:** Hierarchical CPT item at `/products/category/item` gets wrong `.md` URL.
**Why it happens:** Not using `get_permalink()` which handles hierarchical structures.
**How to avoid:** Always use `get_permalink($post)` for URL construction; it handles hierarchical CPTs correctly.
**Warning signs:** Non-hierarchical CPTs work; hierarchical CPTs return 404 on `.md` URLs.

### Pitfall 4: Filter Called Before Plugins Loaded

**What goes wrong:** Third-party plugin's `add_filter()` runs after our post type check.
**Why it happens:** Checking supported types too early (e.g., in constructor instead of when needed).
**How to avoid:** Get filtered post types lazily, when actually checking, not at plugin load time.
**Warning signs:** Filter hook added but CPT still not supported.

### Pitfall 5: CPT Archive Without has_archive

**What goes wrong:** CPT archive `.md` URL returns 404 even though single items work.
**Why it happens:** CPT registered with `has_archive = false`; no archive page exists.
**How to avoid:** Check `$post_type_obj->has_archive` before adding archive alternate link; skip archive support for CPTs without archives.
**Warning signs:** Single CPT `.md` works; archive `.md` returns 404.

### Pitfall 6: Invalid Format Parameter Values

**What goes wrong:** `?format=markdown123` or `?format=MARKDOWN` treated unexpectedly.
**Why it happens:** Not doing strict value checking on the format parameter.
**How to avoid:** Use strict equality check: `$format === 'markdown'` (lowercase only, exact match).
**Warning signs:** Unexpected formats trigger markdown output; case variations behave inconsistently.

## Code Examples

### Complete Query Parameter Fallback Handler

```php
<?php
// Source: WordPress Developer Documentation + existing project patterns

/**
 * Handle format query parameter fallback.
 *
 * Checks for ?format=markdown and serves markdown content.
 * Runs before Accept header check; after .md URL check.
 */
public function handle_format_parameter(): void {
    // Skip if already a markdown request via .md URL (URL wins)
    if (get_query_var('markdown_request')) {
        return;
    }

    // Check format query parameter (strict equality)
    $format = get_query_var('format', '');
    if ($format !== 'markdown') {
        return;
    }

    // Must be on singular or archive content
    if (!is_singular() && !is_archive()) {
        return;
    }

    // For singular content
    if (is_singular()) {
        $post = get_queried_object();
        if (!$post instanceof \WP_Post) {
            return;
        }

        // Check post type is supported
        if (!$this->is_supported_post_type($post->post_type)) {
            return;
        }

        // Check post status
        if (get_post_status($post) !== 'publish') {
            return;
        }

        // Check password protection
        if (post_password_required($post)) {
            status_header(403);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'This content is password protected.';
            exit;
        }

        // Serve markdown
        $this->serve_markdown($post);
    }

    // Archive handling would go here (if implemented)
}
```

### Complete Filterable Post Type Support

```php
<?php
// Source: WordPress Custom Hooks Plugin Handbook

namespace MarkdownAlternate\Router;

/**
 * Trait for post type support checking.
 * Can be used in RewriteHandler and AlternateLinkHandler.
 */
trait PostTypeSupport {

    /**
     * Get array of post types that support markdown output.
     *
     * Uses apply_filters() for extensibility. Third-party plugins
     * and themes can add custom post types via the filter.
     *
     * @return array Post type slugs that have markdown support enabled.
     */
    protected function get_supported_post_types(): array {
        $default_types = ['post', 'page'];

        /**
         * Filters the post types that support markdown output.
         *
         * @since 1.0.0
         *
         * @param array $post_types Array of post type slugs. Default: ['post', 'page'].
         */
        return apply_filters('markdown_alternate_supported_post_types', $default_types);
    }

    /**
     * Check if a post type supports markdown output.
     *
     * @param string $post_type The post type slug to check.
     * @return bool True if the post type is supported, false otherwise.
     */
    protected function is_supported_post_type(string $post_type): bool {
        return in_array($post_type, $this->get_supported_post_types(), true);
    }
}
```

### Developer Usage Examples (for Documentation)

```php
<?php
/**
 * Example 1: Enable markdown for a specific custom post type.
 * Add to your theme's functions.php or a custom plugin.
 */
add_filter('markdown_alternate_supported_post_types', function($post_types) {
    $post_types[] = 'product';  // WooCommerce products
    $post_types[] = 'event';    // Custom events CPT
    return $post_types;
});

/**
 * Example 2: Enable markdown for ALL public custom post types.
 * One-liner solution per CONTEXT.md decision.
 */
add_filter('markdown_alternate_supported_post_types', function($post_types) {
    $public_types = get_post_types(['public' => true], 'names');
    return array_unique(array_merge($post_types, $public_types));
});

/**
 * Example 3: Disable markdown for pages, keep only posts.
 */
add_filter('markdown_alternate_supported_post_types', function($post_types) {
    return array_diff($post_types, ['page']);
});

/**
 * Example 4: Check if markdown is available for current post type.
 * Useful for conditionally showing .md links in templates.
 */
function my_theme_has_markdown_support() {
    if (!is_singular()) {
        return false;
    }
    $post_type = get_post_type();
    $supported = apply_filters('markdown_alternate_supported_post_types', ['post', 'page']);
    return in_array($post_type, $supported, true);
}
```

### Updated Post Type Check in RewriteHandler

```php
<?php
// Source: Existing RewriteHandler.php, updated for CPT support

public function handle_markdown_request(): void {
    // ... existing checks for markdown_request query var ...

    $post = get_queried_object();

    if (!$post instanceof \WP_Post) {
        return;
    }

    // CHANGED: Use filterable post type check instead of hardcoded array
    if (!$this->is_supported_post_type($post->post_type)) {
        return;
    }

    // ... rest of existing handler ...
}
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Hardcoded post/page support | Filterable `apply_filters()` array | WordPress extensibility pattern | Allows third-party CPT integration |
| Direct `$_GET['format']` | Registered query var via `query_vars` filter | WordPress 1.5+ | Works with rewrites, proper WP integration |
| Per-type filter registration | Single filter returning array | Modern WordPress pattern | Simpler API, easier to use |
| Manual post type checks | `get_post_types()` for enumeration | WordPress 2.9+ | Centralized registry, consistent behavior |

**Deprecated/outdated:**
- Checking `is_post_type_hierarchical()` manually: Use `$post_type_obj->hierarchical` property instead
- Using global `$wp_post_types` directly: Use `get_post_type_object()` wrapper function

## Open Questions

1. **Format parameter value strictness**
   - What we know: CONTEXT.md gives discretion on accepted values
   - What's unclear: Should we accept `md`, `markdown`, `text/markdown`, etc.?
   - Recommendation: Accept only `markdown` (lowercase, exact match) for simplicity; document clearly

2. **CPT archive markdown content format**
   - What we know: CPT archives should have markdown versions when CPT is enabled
   - What's unclear: What content should CPT archive markdown contain?
   - Recommendation: Defer archive rendering to future phase; this phase focuses on single CPT items

3. **Query parameter with existing rewrites**
   - What we know: `?format=markdown` should work on any URL
   - What's unclear: Does it work on `.md` URLs too (e.g., `/post.md?format=markdown`)?
   - Recommendation: URL wins; `.md` URL always serves markdown regardless of query parameter

4. **Error handling for invalid format values**
   - What we know: CONTEXT.md gives discretion on error handling
   - What's unclear: Should `?format=invalid` return error or be silently ignored?
   - Recommendation: Silently ignore invalid format values; let WordPress continue normal processing

## Sources

### Primary (HIGH confidence)
- [query_vars Filter - WordPress Developer Reference](https://developer.wordpress.org/reference/hooks/query_vars/)
- [get_query_var() - WordPress Developer Reference](https://developer.wordpress.org/reference/functions/get_query_var/)
- [apply_filters() - WordPress Developer Reference](https://developer.wordpress.org/reference/functions/apply_filters/)
- [Custom Hooks - WordPress Plugin Handbook](https://developer.wordpress.org/plugins/hooks/custom-hooks/)
- [get_post_types() - WordPress Developer Reference](https://developer.wordpress.org/reference/functions/get_post_types/)
- [get_post_type_object() - WordPress Developer Reference](https://developer.wordpress.org/reference/functions/get_post_type_object/)
- [WP_Post_Type Class - WordPress Developer Reference](https://developer.wordpress.org/reference/classes/wp_post_type/)
- [get_post_type_archive_link() - WordPress Developer Reference](https://developer.wordpress.org/reference/functions/get_post_type_archive_link/)
- [is_post_type_archive() - WordPress Developer Reference](https://developer.wordpress.org/reference/functions/is_post_type_archive/)
- [is_singular() - WordPress Developer Reference](https://developer.wordpress.org/reference/functions/is_singular/)
- [add_rewrite_rule() - WordPress Developer Reference](https://developer.wordpress.org/reference/functions/add_rewrite_rule/)

### Secondary (MEDIUM confidence)
- [Namespacing WordPress Hooks in Plugins - Tanner Record](https://www.tannerrecord.com/namespacing-wordpress-hooks-in-plugins/)
- [Custom Post Type Supports with apply_filters - ClassicPress Forums](https://forums.classicpress.net/t/custom-post-types-supports-while-using-apply-filters-and-add-filter/3729)
- [WordPress Naming Conventions - CMARIX](https://www.cmarix.com/qanda/wordpress-naming-conventions-best-practices/)

### Tertiary (LOW confidence)
- Project CONTEXT.md decisions (user-provided constraints)
- Existing codebase patterns from Phases 1-3

## Metadata

**Confidence breakdown:**
- Standard Stack: HIGH - All WordPress APIs are well-documented and stable
- Architecture: HIGH - Follows existing project patterns; filter hooks are standard WordPress pattern
- Pitfalls: MEDIUM - Some CPT edge cases depend on specific registration settings; tested against common patterns

**Research date:** 2026-01-30
**Valid until:** 60 days (WordPress APIs stable; filter patterns well-established)

---
*Phase 4 research for: Markdown Alternate WordPress plugin*
*Requirements: URL-04, TECH-05*
