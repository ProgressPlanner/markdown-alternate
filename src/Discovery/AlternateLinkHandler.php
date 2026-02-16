<?php
/**
 * Alternate link tag injection for markdown discovery.
 *
 * @package MarkdownAlternate
 */

namespace MarkdownAlternate\Discovery;

use MarkdownAlternate\PostTypeSupport;

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

        // Only for published content.
        if ( get_post_status( $post ) !== 'publish' ) {
            return;
        }

        // Only for supported post types.
        if ( ! PostTypeSupport::is_supported( $post->post_type ) ) {
            return;
        }

        // Build the .md URL.
        $md_url = rtrim( get_permalink( $post ), '/' ) . '.md';

        // Output the alternate link tag.
        printf(
            '<link rel="alternate" type="text/markdown" href="%s" />' . "\n",
            esc_url( $md_url )
        );
    }
}
