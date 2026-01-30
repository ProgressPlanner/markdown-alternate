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
     * Register all hooks for URL routing.
     *
     * @return void
     */
    public function register(): void {
        add_action('init', [$this, 'add_rewrite_rules']);
        add_filter('query_vars', [$this, 'add_query_vars']);
        add_action('template_redirect', [$this, 'handle_markdown_request'], 1);
    }

    /**
     * Add rewrite rules for .md URLs.
     *
     * @return void
     */
    public function add_rewrite_rules(): void {
        // Non-greedy pattern to capture nested page slugs correctly
        add_rewrite_rule(
            '(.+?)\.md$',
            'index.php?pagename=$matches[1]&markdown_request=1',
            'top'
        );
    }

    /**
     * Add custom query vars.
     *
     * @param array $vars Existing query vars.
     * @return array Modified query vars.
     */
    public function add_query_vars(array $vars): array {
        $vars[] = 'markdown_request';
        return $vars;
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
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';

        // Enforce lowercase .md extension - let WP 404 if wrong case
        if (!preg_match('/\.md$/', $request_uri) && preg_match('/\.md$/i', $request_uri)) {
            return;
        }

        // Handle trailing slash redirect: /post-slug.md/ -> /post-slug.md
        if (preg_match('/\.md\/$/', $request_uri)) {
            $redirect_url = rtrim($request_uri, '/');
            wp_redirect($redirect_url, 301);
            exit;
        }

        // Get the queried object
        $post = get_queried_object();

        // Validate post exists and is a WP_Post
        if (!$post instanceof WP_Post) {
            return;
        }

        // Check post type - only serve posts and pages
        if (!in_array($post->post_type, ['post', 'page'], true)) {
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

        header('Content-Type: text/markdown; charset=UTF-8');
        echo $markdown;
        exit;
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
