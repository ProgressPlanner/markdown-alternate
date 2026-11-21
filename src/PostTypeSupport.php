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

	/**
	 * Check whether the markdown alternate should be withheld for a noindexed post.
	 *
	 * Off by default: a noindexed post keeps its markdown copy, because SEO
	 * settings are independent of markdown output. A site that wants posts it
	 * keeps out of search to stay off agent channels too (no .md URL, no
	 * ?format=markdown, no Accept redirect, no alternate link) opts in with
	 * the 'markdown_alternate_skip_noindexed' filter. The filter runs first,
	 * so the noindex lookup only happens on sites that opted in.
	 *
	 * @param \WP_Post $post The post to check.
	 * @return bool True if the post is noindexed and the site opted in.
	 */
	public static function should_skip_noindexed( \WP_Post $post ): bool {
		/**
		 * Filters whether posts marked noindex lose their markdown alternate.
		 *
		 * @param bool     $skip Whether to skip noindexed posts. Default false.
		 * @param \WP_Post $post The post being checked.
		 */
		if ( ! apply_filters( 'markdown_alternate_skip_noindexed', false, $post ) ) {
			return false;
		}

		return self::is_noindexed( $post );
	}

	/**
	 * Check whether a post is marked noindex.
	 *
	 * Reads Yoast SEO, then lets integrations and sites add posts that are
	 * kept out of search by other means, such as the WooCommerce cart.
	 *
	 * @param \WP_Post $post The post to check.
	 * @return bool True if the post is marked noindex.
	 */
	public static function is_noindexed( \WP_Post $post ): bool {
		/**
		 * Filters whether a post is treated as noindexed.
		 *
		 * Only consulted when the site opts in with markdown_alternate_skip_noindexed.
		 *
		 * @since 1.3.0
		 *
		 * @param bool     $noindexed Whether Yoast SEO marks the post noindex.
		 * @param \WP_Post $post      The post being checked.
		 */
		return (bool) apply_filters( 'markdown_alternate_is_noindexed', self::is_noindexed_by_yoast( $post ), $post );
	}

	/**
	 * Check whether Yoast SEO marks a post noindex.
	 *
	 * Prefers Yoast's effective robots value, which folds in the per-post
	 * setting, the post type default and the wpseo_robots filters, so the
	 * answer matches the robots meta on the HTML page. Falls back to the
	 * per-post meta key when the surface finds no indexable or throws; that
	 * fallback does not see the post type default. Without Yoast active the
	 * answer is false, even if a former install left its meta key behind.
	 *
	 * @param \WP_Post $post The post to check.
	 * @return bool True if Yoast marks the post noindex.
	 */
	private static function is_noindexed_by_yoast( \WP_Post $post ): bool {
		if ( ! function_exists( 'YoastSEO' ) ) {
			return false;
		}

		try {
			$meta = YoastSEO()->meta->for_post( $post->ID );
			if ( $meta ) {
				$robots = $meta->robots;
				if ( is_array( $robots ) ) {
					// An empty or filtered array is authoritative too: do not revive stale meta.
					return in_array( 'noindex', $robots, true );
				}
			}
		} catch ( \Throwable $e ) {
			// PHP 7.4 requires the catch variable; fall back to the meta key below.
			unset( $e );
		}

		return '1' === get_post_meta( $post->ID, '_yoast_wpseo_meta-robots-noindex', true );
	}
}
