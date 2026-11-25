<?php
/**
 * Disposable request process for the real router with WordPress API doubles.
 *
 * @package MarkdownAlternate
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, Squiz.Commenting.FunctionComment.Missing, WordPress.Security.EscapeOutput.OutputNotEscaped
// Request doubles override a namespaced header() and global WordPress functions in one CLI fixture.
// phpcs:disable Universal.Namespaces, Generic.CodeAnalysis.UnusedFunctionParameter.Found, WordPress.WP.AlternativeFunctions.json_encode_json_encode

namespace MarkdownAlternate\Router {
	/**
	 * Capture headers, which PHP CLI does not retain.
	 *
	 * @param string $header Header emitted by the router.
	 * @return void
	 */
	function header( $header ) {
		list( $name, $value )                        = explode( ':', $header, 2 );
		$GLOBALS['md_alt_response_headers'][ $name ] = trim( $value );
	}
}

namespace {
	require __DIR__ . '/bootstrap.php';
	require dirname( __DIR__ ) . '/vendor/autoload.php';

	// WordPress provides this compatibility function on PHP 7.4 too.
	if ( ! function_exists( 'str_ends_with' ) ) {
		function str_ends_with( $haystack, $needle ) {
			return '' === $needle || substr( $haystack, -strlen( $needle ) ) === $needle;
		}
	}

	function get_query_var( $key ) {
		return $GLOBALS['md_alt_query_vars'][ $key ] ?? '';
	}
	function get_queried_object() {
		return $GLOBALS['md_alt_router_post'];
	}
	function is_singular() {
		return ! in_array( $GLOBALS['md_alt_mode'], array( 'author', 'year', 'month', 'day' ), true );
	}
	function is_author() {
		return 'author' === $GLOBALS['md_alt_mode'];
	}
	function is_date() {
		return in_array( $GLOBALS['md_alt_mode'], array( 'year', 'month', 'day' ), true );
	}
	function is_year() {
		return 'year' === $GLOBALS['md_alt_mode'];
	}
	function is_month() {
		return 'month' === $GLOBALS['md_alt_mode'];
	}
	function is_day() {
		return 'day' === $GLOBALS['md_alt_mode'];
	}
	function get_queried_object_id() {
		return 5;
	}
	function get_author_posts_url( $author_id ) {
		return 'https://example.com/author/test/';
	}
	function get_year_link( $year ) {
		return 'https://example.com/' . $year . '/';
	}
	function get_month_link( $year, $month ) {
		return sprintf( 'https://example.com/%04d/%02d/', $year, $month );
	}
	function get_day_link( $year, $month, $day ) {
		return sprintf( 'https://example.com/%04d/%02d/%02d/', $year, $month, $day );
	}
	function is_category() {
		return false;
	}
	function is_tag() {
		return false;
	}
	function is_tax() {
		return false;
	}
	function get_post_status( $post ) {
		return 'publish';
	}
	function post_password_required( $post ) {
		return false; // The password scenario represents a visitor who unlocked it.
	}
	function get_permalink( $post ) {
		return 'https://example.com/post/';
	}
	function home_url( $path ) {
		return 'https://example.com' . $path;
	}
	function wp_unslash( $value ) {
		return $value;
	}
	function esc_url_raw( $value ) {
		return $value;
	}
	function sanitize_text_field( $value ) {
		return $value;
	}
	function nocache_headers() {
		$GLOBALS['md_alt_response_headers']['Cache-Control'] = 'no-cache';
	}
	function status_header( $status ) {
		$GLOBALS['md_alt_response_status'] = $status;
	}
	function wp_safe_redirect( $url, $status = 302 ) {
		status_header( $status );
		$GLOBALS['md_alt_response_headers']['Location'] = $url;
	}

	$md_alt_router_post = md_alt_test_fixture( 'ordinary' );
	$md_alt_route       = $argv[1];
	$md_alt_mode        = $argv[2];
	$md_alt_query_vars  = array();
	if ( is_date() ) {
		$md_alt_query_vars = array(
			'year'     => 2001,
			'monthnum' => 2,
			'day'      => 3,
		);
	}
	$md_alt_response_headers = array();
	$md_alt_response_status  = 200;
	$_SERVER['REQUEST_URI']  = 'md' === $md_alt_route ? '/post.md' : '/post/';
	if ( 'md' === $md_alt_route ) {
		$md_alt_query_vars['markdown_request'] = '1';
	} elseif ( 'format' === $md_alt_route ) {
		$md_alt_query_vars['format'] = 'markdown';
	}
	if ( 'accept' === $md_alt_route || 'also-accept' === $md_alt_mode ) {
		$_SERVER['HTTP_ACCEPT'] = 'text/markdown';
	}
	$GLOBALS['md_alt_test_state']['logged_in'] = 'logged-in' === $md_alt_mode;
	if ( 'session' === $md_alt_mode ) {
		$_COOKIE['wp_woocommerce_session_test'] = 'dummy-session';
	} elseif ( 'password' === $md_alt_mode ) {
		$md_alt_router_post->post_password = 'dummy-password';
	} elseif ( 'filter-disabled' === $md_alt_mode ) {
		add_filter(
			'markdown_alternate_cache_enabled',
			static function () {
				return false;
			}
		);
	}

	ob_start();
	register_shutdown_function(
		static function () {
			echo json_encode(
				array(
					'body'                => ob_get_clean(),
					'status'              => $GLOBALS['md_alt_response_status'],
					'headers'             => $GLOBALS['md_alt_response_headers'],
					'cache'               => $GLOBALS['md_alt_test_state']['cache'],
					'renders'             => $GLOBALS['md_alt_test_state']['renders'],
					'page_cache_disabled' => defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE,
				)
			);
		}
	);
	( new \MarkdownAlternate\Router\RewriteHandler() )->register();
	if ( 'deny10' === $md_alt_mode || 'deny14' === $md_alt_mode ) {
		add_action(
			'template_redirect',
			static function () {
				status_header( 403 );
				echo 'Access denied';
				exit;
			},
			'deny10' === $md_alt_mode ? 10 : 14
		);
	} elseif ( 'canonical' === $md_alt_mode ) {
		add_action(
			'template_redirect',
			static function () {
				$target = 'https://example.com/post/' . ( 'format' === $GLOBALS['md_alt_route'] ? '?format=markdown' : '' );
				$target = apply_filters( 'redirect_canonical', $target, 'https://example.com/post' );
				if ( $target ) {
					wp_safe_redirect( $target, 301 );
					exit;
				}
			},
			10
		);
	}
	do_action( 'template_redirect' );
	echo 'HTML template';
}
