<?php
/**
 * Post type support utility for markdown output.
 *
 * @package MarkdownAlternate
 */

namespace MarkdownAlternate;

/**
 * Shared utility for post type validation.
 *
 * Provides a single source of truth for determining which post types
 * support markdown output.
 */
class PostTypeSupport {

    /**
     * Get supported post types for markdown output.
     *
     * Returns an array of post types that can be served as markdown.
     * Developers can extend this via the 'markdown_alternate_supported_post_types' filter.
     *
     * @return array List of supported post type names.
     */
    public static function get_supported_types(): array {
        $default_types = [ 'post', 'page' ];
        return apply_filters( 'markdown_alternate_supported_post_types', $default_types );
    }

    /**
     * Check if a post type is supported for markdown output.
     *
     * @param string $post_type The post type to check.
     * @return bool True if supported, false otherwise.
     */
    public static function is_supported( string $post_type ): bool {
        return in_array( $post_type, self::get_supported_types(), true );
    }
}
