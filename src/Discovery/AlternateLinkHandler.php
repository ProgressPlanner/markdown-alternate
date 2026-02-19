<?php
/**
 * Alternate link tag injection for markdown discovery.
 *
 * @package MarkdownAlternate
 */

namespace MarkdownAlternate\Discovery;

use MarkdownAlternate\Router\UrlConverter;

/**
 * Handles alternate link tag injection in HTML page head.
 *
 * Adds <link rel="alternate" type="text/markdown"> tags to enable
 * programmatic discovery of markdown versions by LLMs and tools.
 */
class AlternateLinkHandler {

    /**
     * Register hooks.
     *
     * @return void
     */
    public function register(): void {
        add_action( 'wp_head', [ $this, 'output_alternate_link' ], 5 );
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
        $default_types = [ 'post', 'page' ];
        return apply_filters( 'markdown_alternate_supported_post_types', $default_types );
    }

    /**
     * Check if a post type is supported for markdown output.
     *
     * @param string $post_type The post type to check.
     * @return bool True if supported, false otherwise.
     */
    private function is_supported_post_type( string $post_type ): bool {
        return in_array( $post_type, $this->get_supported_post_types(), true );
    }


    /**
     * Output alternate link tag for markdown version.
     *
     * Only outputs for published posts and pages (supported post types).
     * Skips non-singular content, drafts, private posts, and unsupported types.
     *
     * @return void
     */
    public function output_alternate_link(): void {
        // Only for singular content (posts and pages).
        if ( ! is_singular() ) {
            return;
        }

        // Get the current post.
        $post = get_queried_object();
        if ( ! $post instanceof \WP_Post ) {
            return;
        }

        // Only for published posts.
        if ( get_post_status( $post ) !== 'publish' ) {
            return;
        }

        // Hide for password protected posts.
        if ( post_password_required( $post ) ) {
            return;
        }

        // Only for supported post types.
        if ( ! $this->is_supported_post_type( $post->post_type ) ) {
            return;
        }

        // Build the .md URL.
        $md_url = UrlConverter::convert_to_markdown_url( get_permalink( $post ) );

        // Output the alternate link tag.
        printf(
            '<link rel="alternate" type="text/markdown" href="%s" />' . "\n",
            esc_url( $md_url )
        );
    }
}
