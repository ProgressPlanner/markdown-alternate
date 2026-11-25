<?php // phpcs:ignore WordPress.Files.FileName -- PSR-4 class filename.
/**
 * Shared cache eligibility for rendered Markdown and HTTP responses.
 *
 * @package MarkdownAlternate
 */

namespace MarkdownAlternate\Output;

use WP_Post;

/**
 * Keep visitor-specific output out of caches shared with other visitors.
 */
class CachePolicy {

	/**
	 * Whether this request can use shared caches for a post.
	 *
	 * @param WP_Post $post The post being rendered.
	 * @return bool Whether shared caching is safe for this request.
	 */
	public static function is_enabled( WP_Post $post ): bool {
		if ( ! self::is_public_request() || '' !== $post->post_password ) {
			return false;
		}

		/**
		 * Filters whether otherwise eligible output can use shared caching.
		 *
		 * Disable for custom sessions or other visitor-dependent content.
		 * The HTTP router also uses this to send private, no-store headers.
		 * Logged-in visitors, password-protected posts and known session
		 * cookies always bypass caching, regardless of this filter.
		 *
		 * @since 1.2.0
		 *
		 * @param bool    $enabled Whether output may be cached. Default true.
		 * @param WP_Post $post    The post being rendered.
		 */
		return (bool) apply_filters( 'markdown_alternate_cache_enabled', true, $post );
	}

	/**
	 * Whether a post or archive request has no known visitor-specific state.
	 *
	 * @return bool Whether the request is eligible for shared caching.
	 */
	public static function is_public_request(): bool {
		if ( is_user_logged_in() ) {
			return false;
		}

		// Guest sessions can personalize ordinary posts and pages too.
		$session_cookies = array(
			'wp-postpass_',
			'wp_woocommerce_session_',
			'woocommerce_items_in_cart',
			'woocommerce_cart_hash',
			'edd_items_in_cart',
		);
		foreach ( array_keys( $_COOKIE ) as $name ) {
			foreach ( $session_cookies as $prefix ) {
				if ( 0 === strpos( (string) $name, $prefix ) ) {
					return false;
				}
			}
		}

		return true;
	}
}
