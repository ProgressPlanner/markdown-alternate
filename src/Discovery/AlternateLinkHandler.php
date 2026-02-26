<?php
/**
 * Alternate link tag injection for markdown discovery.
 *
 * @package MarkdownAlternate
 */

namespace MarkdownAlternate\Discovery;

use MarkdownAlternate\PostTypeSupport;
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
     * Output alternate link tag for markdown version.
     *
     * Only outputs for published posts and pages (supported post types).
     * Skips non-singular content, drafts, private posts, and unsupported types.
     *
     * @return void
     */
    public function output_alternate_link(): void {
        if ( ! is_singular() ) {
            return;
        }

        $post = get_queried_object();
        if ( ! $post instanceof \WP_Post ) {
            return;
        }

        if ( get_post_status( $post ) !== 'publish' ) {
            return;
        }

        if ( post_password_required( $post ) ) {
            return;
        }

        if ( ! PostTypeSupport::is_supported( $post->post_type ) ) {
            return;
        }

        $md_url = UrlConverter::convert_to_markdown_url( get_permalink( $post ) );

        printf(
            '<link rel="alternate" type="text/markdown" href="%s" />' . "\n",
            esc_url( $md_url )
        );
    }
}
