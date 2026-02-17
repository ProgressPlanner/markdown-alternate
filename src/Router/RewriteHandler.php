<?php
/**
 * URL rewrite handler for markdown requests.
 *
 * @package MarkdownAlternate
 */

namespace MarkdownAlternate\Router;

use WP_Post;
use MarkdownAlternate\Output\ContentRenderer;

/**
 * Handles URL rewriting and markdown request processing.
 */
class RewriteHandler {

    /**
     * Cached post for markdown request (Nginx compatibility).
     *
     * @var WP_Post|null
     */
    private ?WP_Post $markdown_post = null;

    /**
     * Register all hooks for URL routing.
     *
     * @return void
     */
    public function register(): void {
        add_action('init', [$this, 'add_rewrite_rules']);
        add_filter('query_vars', [$this, 'add_query_vars']);
        // Parse .md URLs directly from REQUEST_URI (Nginx compatibility)
        add_action('parse_request', [$this, 'parse_markdown_url']);
        // Prevent canonical redirect for .md URLs
        add_filter('redirect_canonical', [$this, 'prevent_markdown_redirect'], 10, 2);
        // Order: format parameter -> Accept negotiation -> markdown request (URL wins first)
        add_action('template_redirect', [$this, 'handle_format_parameter'], 1);
        add_action('template_redirect', [$this, 'handle_accept_negotiation'], 1);
        add_action('template_redirect', [$this, 'handle_markdown_request'], 1);
    }

    /**
     * Add rewrite rules for .md URLs.
     *
     * @return void
     */
    public function add_rewrite_rules(): void {
        // Specific rule for index.md (front page)
        add_rewrite_rule(
            '^index\.md$',
            'index.php?pagename=index&markdown_request=1',
            'top'
        );

        // Non-greedy pattern to capture nested page slugs correctly
        add_rewrite_rule(
            '(.+?)\.md$',
            'index.php?pagename=$matches[1]&markdown_request=1',
            'top'
        );
    }

    /**
     * Prevent canonical redirect for .md URLs.
     *
     * WordPress tries to redirect to the canonical URL when the current URL
     * doesn't match. We need to prevent this for .md requests.
     *
     * @param string $redirect_url  The redirect URL.
     * @param string $requested_url The requested URL.
     * @return string|false The redirect URL or false to prevent redirect.
     */
    public function prevent_markdown_redirect($redirect_url, $requested_url) {
        if (get_query_var('markdown_request')) {
            return false;
        }
        return $redirect_url;
    }

    /**
     * Parse markdown URLs directly from REQUEST_URI.
     *
     * Provides Nginx compatibility by detecting .md URLs before WordPress
     * rewrite rules are applied. Works on servers where add_rewrite_rule()
     * doesn't function properly (Nginx, some managed hosts).
     *
     * @param \WP $wp WordPress environment instance.
     * @return void
     */
    public function parse_markdown_url(\WP $wp): void {
        $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';

        // Extract path without query string
        $path = wp_parse_url($request_uri, PHP_URL_PATH);
        if ($path === false || $path === null) {
            return;
        }

        // Check if URL ends with .md (case-sensitive, lowercase only)
        if (!preg_match('/^\/(.+)\.md$/', $path, $matches)) {
            return;
        }

        $path_without_md = $matches[1];

        // Handle /index.md - could be front page or a page with slug "index"
        if ($path_without_md === 'index') {
            // First try to resolve as a regular page with slug "index"
            $clean_url = home_url('/index');
            $post_id   = url_to_postid($clean_url);

            // If no page with slug "index" exists, treat as front page
            if (!$post_id) {
                $page_on_front = get_option('page_on_front');
                if ($page_on_front) {
                    $post_id = (int) $page_on_front;
                } else {
                    // No static front page set - let WordPress show its normal behavior
                    return;
                }
            }
        } else {
            // Try to resolve the post by replacing .md with known permalink extensions,
            // then fall back to no extension (original behavior).
            $extensions_to_try = array_merge( UrlConverter::PERMALINK_EXTENSIONS, [ '' ] );
            $post_id = 0;

            foreach ($extensions_to_try as $ext) {
                $clean_url = home_url('/' . $path_without_md . $ext);
                $post_id   = url_to_postid($clean_url);

                if ($post_id) {
                    break;
                }
            }

            if (!$post_id) {
                return; // Let WordPress show its normal 404
            }
        }

        $post = get_post($post_id);

        if (!$post || $post->post_status !== 'publish') {
            return;
        }

        if (!$this->is_supported_post_type($post->post_type)) {
            return;
        }

        // Cache post for handle_markdown_request
        $this->markdown_post = $post;

        // Set query vars for WordPress
        $wp->query_vars['p']                = $post->ID;
        $wp->query_vars['markdown_request'] = '1';
        // Remove incorrect pagename that the rewrite rule may have set
        unset($wp->query_vars['pagename']);
    }

    /**
     * Add custom query vars.
     *
     * @param array $vars Existing query vars.
     * @return array Modified query vars.
     */
    public function add_query_vars(array $vars): array {
        $vars[] = 'markdown_request';
        $vars[] = 'format';
        return $vars;
    }

    /**
     * Get supported post types for markdown output.
     *
     * Returns an array of post types that can be served as markdown.
     * Developers can extend this via the 'markdown_alternate_supported_post_types' filter.
     *
     * @return array List of supported post type names.
     */
    private function get_supported_post_types(): array {
        $default_types = ['post', 'page'];
        return apply_filters('markdown_alternate_supported_post_types', $default_types);
    }

    /**
     * Check if a post type is supported for markdown output.
     *
     * @param string $post_type The post type to check.
     * @return bool True if supported, false otherwise.
     */
    private function is_supported_post_type(string $post_type): bool {
        return in_array($post_type, $this->get_supported_post_types(), true);
    }

    /**
     * Handle format query parameter fallback.
     *
     * Serves markdown when ?format=markdown is present on singular content.
     * URL (.md) takes precedence over query parameter.
     *
     * @return void
     */
    public function handle_format_parameter(): void {
        // Skip if already a markdown request (URL wins over query parameter)
        if (get_query_var('markdown_request')) {
            return;
        }

        // Check for format=markdown query parameter (case-sensitive, strict equality)
        $format = get_query_var('format');
        if ($format !== 'markdown') {
            return;
        }

        // Only for singular content
        if (!is_singular()) {
            return;
        }

        // Get the queried object
        $post = get_queried_object();

        // Validate post exists and is a WP_Post
        if (!$post instanceof WP_Post) {
            return;
        }

        // Check post type - only serve supported post types
        if (!$this->is_supported_post_type($post->post_type)) {
            return;
        }

        // Check post status - only serve published posts
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

        // Render and serve the markdown content
        $renderer = new ContentRenderer();
        $markdown = $renderer->render($post);

        $this->set_response_headers($post, $markdown);
        echo $markdown;
        exit;
    }

    /**
     * Handle markdown requests.
     *
     * Processes requests for .md URLs and serves markdown content.
     *
     * @return void
     */
    public function handle_markdown_request(): void {
        // Check if this is a markdown request
        if (!get_query_var('markdown_request')) {
            return;
        }

        // Get the request URI
        $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';

        // Enforce lowercase .md extension - let WP 404 if wrong case
        if (!preg_match('/\.md$/', $request_uri) && preg_match('/\.md$/i', $request_uri)) {
            return;
        }

        // Handle trailing slash redirect: /post-slug.md/ -> /post-slug.md
        if (preg_match('/\.md\/$/', $request_uri)) {
            $path = wp_parse_url($request_uri, PHP_URL_PATH);
            $path = ($path !== false && $path !== null) ? rtrim($path, '/') : '';
            $query = wp_parse_url($request_uri, PHP_URL_QUERY);
            $redirect_url = home_url($path);
            if (!empty($query)) {
                $redirect_url .= '?' . $query;
            }
            wp_safe_redirect(esc_url_raw($redirect_url), 301);
            exit;
        }

        // Get post - use cached post from parse_markdown_url (Nginx) or queried object (Apache)
        $post = $this->markdown_post ?? get_queried_object();

        // Validate post exists and is a WP_Post
        if (!$post instanceof WP_Post) {
            return;
        }

        // Check post type - only serve supported post types
        if (!$this->is_supported_post_type($post->post_type)) {
            return;
        }

        // Check post status - only serve published posts
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

        // Render and serve the markdown content
        $renderer = new ContentRenderer();
        $markdown = $renderer->render($post);

        $this->set_response_headers($post, $markdown);
        echo $markdown;
        exit;
    }

    /**
     * Set all required HTTP headers for markdown response.
     *
     * Sets 200 status, Content-Type, Vary, Link (canonical), X-Content-Type-Options,
     * and X-Markdown-Tokens headers.
     *
     * @param WP_Post $post     The post being served.
     * @param string  $markdown The rendered markdown content.
     * @return void
     */
    private function set_response_headers(WP_Post $post, string $markdown): void {
        // Override any 404 status WordPress may have set
        status_header(200);

        // Required by TECH-03: text/markdown MIME type
        header('Content-Type: text/markdown; charset=UTF-8');

        // Required by TECH-04: Vary header for cache compatibility
        header('Vary: Accept');

        // From CONTEXT.md: Link header with canonical HTML URL
        $canonical_url = get_permalink($post);
        header('Link: <' . $canonical_url . '>; rel="canonical"');

        // From CONTEXT.md: Security header to prevent MIME sniffing
        header('X-Content-Type-Options: nosniff');

        // Estimated token count for the markdown content (~4 chars per token)
        header('X-Markdown-Tokens: ' . (int) (strlen($markdown) / 4));
    }

    /**
     * Handle Accept header content negotiation.
     *
     * Redirects to .md URL when Accept: text/markdown is present on HTML URLs.
     * URL always wins over Accept header - .md URLs serve markdown regardless.
     *
     * @return void
     */
    public function handle_accept_negotiation(): void {
        // Skip if already a markdown request (URL wins over Accept header)
        if (get_query_var('markdown_request')) {
            return;
        }

        // Check Accept header for text/markdown
        $accept = isset($_SERVER['HTTP_ACCEPT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_ACCEPT'])) : '';
        if (strpos($accept, 'text/markdown') === false) {
            return;
        }

        // Get canonical URL for current content
        $canonical = $this->get_current_canonical_url();
        if (!$canonical) {
            return;
        }

        // Build markdown URL and redirect safely
        $md_url = UrlConverter::convert_to_markdown_url($canonical);
        $md_url = esc_url_raw($md_url);
        if ($md_url === '') {
            return;
        }

        // 303 See Other redirect with Vary header for caching
        status_header(303);
        header('Vary: Accept');
        wp_safe_redirect($md_url, 303);
        exit;
    }


    /**
     * Get canonical URL for current content.
     *
     * Supports singular posts/pages, category/tag archives, author archives,
     * and date archives.
     *
     * @return string|null The canonical URL or null if not determinable.
     */
    private function get_current_canonical_url(): ?string {
        // Singular posts and pages
        if (is_singular()) {
            $post = get_queried_object();
            return $post ? get_permalink($post) : null;
        }

        // Category, tag, and custom taxonomy archives
        if (is_category() || is_tag() || is_tax()) {
            $term = get_queried_object();
            if (!$term) {
                return null;
            }
            $link = get_term_link($term);
            return is_wp_error($link) ? null : $link;
        }

        // Author archives
        if (is_author()) {
            return get_author_posts_url(get_queried_object_id());
        }

        // Date archives
        if (is_date()) {
            if (is_year()) {
                return get_year_link(get_query_var('year'));
            }
            if (is_month()) {
                return get_month_link(get_query_var('year'), get_query_var('monthnum'));
            }
            if (is_day()) {
                return get_day_link(get_query_var('year'), get_query_var('monthnum'), get_query_var('day'));
            }
        }

        return null;
    }

    /**
     * Register rewrite rules statically.
     *
     * Used by activation hook to ensure rules are registered before flush.
     *
     * @return void
     */
    public static function register_rules(): void {
        $handler = new self();
        $handler->add_rewrite_rules();
    }
}
