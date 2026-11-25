<?php
/**
 * Archive regressions using real WordPress queries and a controlled Yoast surface.
 *
 * Run with wp eval-file in an isolated site with Markdown Alternate active and
 * Yoast SEO inactive. Also run by the WooCommerce integration suite in CI.
 *
 * @package MarkdownAlternate
 */

use MarkdownAlternate\Output\ContentRenderer;
use MarkdownAlternate\Output\TermRenderer;
use MarkdownAlternate\Router\RewriteHandler;
use MarkdownAlternate\Router\UrlConverter;
use MarkdownAlternate\TaxonomySupport;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || function_exists( 'YoastSEO' ) ) {
	throw new RuntimeException( 'Run in an isolated WP-CLI site without Yoast SEO active.' );
}

// Conditional declaration keeps the environment check above meaningful.
if ( ! function_exists( 'YoastSEO' ) ) {
	/**
	 * Supply effective robots values without requiring Yoast's indexable database.
	 *
	 * @return object Yoast meta surface double.
	 */
	function YoastSEO() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Third-party API double.
		return (object) array(
			'meta' => new class() {
				/**
				 * Get a term's robots value.
				 *
				 * @param int $term_id Term ID.
				 * @return object Term meta surface.
				 */
				public function for_term( $term_id ) {
					return (object) array( 'robots' => array( 'index' => in_array( $term_id, $GLOBALS['md_alt_noindexed_terms'], true ) ? 'noindex' : 'index' ) );
				}
			},
		);
	}
}

/**
 * Assert an archive regression condition.
 *
 * @param bool   $condition Condition.
 * @param string $message  Failure context.
 * @return void
 * @throws RuntimeException When the condition is false.
 */
function md_alt_term_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI test diagnostics.
	}
	WP_CLI::log( 'PASS ' . $message );
}

$md_alt_term_ids                   = array();
$md_alt_term_post_id               = 0;
$md_alt_term_request_uri           = $_SERVER['REQUEST_URI'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Preserve the original value for cleanup.
$GLOBALS['md_alt_noindexed_terms'] = array();
$md_alt_term_home                  = static function ( $home ) {
	return rtrim( $home, '/' ) . '/blog';
};
$md_alt_term_types                 = static function () {
	return array( 'page' );
};

try {
	foreach ( array( 'parent', 'child' ) as $md_alt_term_name ) {
		$md_alt_created = wp_insert_term(
			'Markdown regression ' . $md_alt_term_name . ' ' . wp_generate_uuid4(),
			'category',
			array( 'parent' => $md_alt_term_ids[0] ?? 0 )
		);
		if ( is_wp_error( $md_alt_created ) ) {
			throw new RuntimeException( $md_alt_created->get_error_message() );
		}
		$md_alt_term_ids[] = (int) $md_alt_created['term_id'];
	}
	$md_alt_term_parent  = get_term( $md_alt_term_ids[0], 'category' );
	$md_alt_term_child   = get_term( $md_alt_term_ids[1], 'category' );
	$md_alt_term_post_id = wp_insert_post(
		array(
			'post_title'    => 'Archive regression post',
			'post_status'   => 'publish',
			'post_category' => array( $md_alt_term_child->term_id ),
		)
	);
	md_alt_term_assert( $md_alt_term_post_id > 0, 'Archive regression fixture created' );
	if ( '1' === getenv( 'MD_ALT_TEST_HTTP' ) ) {
		$md_alt_admin_ids = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			)
		);
		md_alt_term_assert( ! empty( $md_alt_admin_ids ), 'HTTP cache tests have an administrator fixture' );
		$md_alt_auth_cookie = wp_generate_auth_cookie( (int) $md_alt_admin_ids[0], time() + 600, 'logged_in' );
		foreach ( array( array( LOGGED_IN_COOKIE => $md_alt_auth_cookie ), array( 'wp_woocommerce_session_fixture' => 'test-session' ) ) as $md_alt_cookies ) {
			$md_alt_response = wp_remote_get(
				UrlConverter::convert_to_markdown_url( get_term_link( $md_alt_term_child ) ),
				array( 'cookies' => $md_alt_cookies )
			);
			md_alt_term_assert( 200 === wp_remote_retrieve_response_code( $md_alt_response ), 'Visitor-specific archive request returns Markdown' );
			md_alt_term_assert( false !== strpos( wp_remote_retrieve_header( $md_alt_response, 'cache-control' ), 'private, no-store' ), 'Visitor-specific archives forbid shared HTTP caching' );
		}
	}

	// Exercise the public request parser with root and subdirectory home URLs.
	foreach ( array( false, true ) as $md_alt_subdirectory ) {
		if ( $md_alt_subdirectory ) {
			add_filter( 'option_home', $md_alt_term_home );
		}
		foreach ( array( $md_alt_term_parent, $md_alt_term_child ) as $md_alt_routing_term ) {
			$md_alt_request_path    = wp_parse_url( UrlConverter::convert_to_markdown_url( get_term_link( $md_alt_routing_term ) ), PHP_URL_PATH );
			$_SERVER['REQUEST_URI'] = $md_alt_request_path;
			$md_alt_wp              = new WP();
			( new RewriteHandler() )->parse_markdown_url( $md_alt_wp );
			md_alt_term_assert( '1' === ( $md_alt_wp->query_vars['markdown_request'] ?? null ), 'Archive route resolves ' . $md_alt_request_path );
		}
		$_SERVER['REQUEST_URI'] = '/wrong-parent/' . $md_alt_term_child->slug . '.md';
		$md_alt_wp              = new WP();
		( new RewriteHandler() )->parse_markdown_url( $md_alt_wp );
		md_alt_term_assert( empty( $md_alt_wp->query_vars['markdown_request'] ), 'Wrong archive path stays unresolved' );
		remove_filter( 'option_home', $md_alt_term_home );
	}

	$md_alt_term_renderer = new TermRenderer();
	$md_alt_child_md      = UrlConverter::convert_to_markdown_url( get_term_link( $md_alt_term_child ) );
	$md_alt_parent_md     = UrlConverter::convert_to_markdown_url( get_term_link( $md_alt_term_parent ) );
	md_alt_term_assert( false !== strpos( $md_alt_term_renderer->render( $md_alt_term_parent ), '](' . $md_alt_child_md . ')' ), 'Indexed children keep Markdown links' );
	$GLOBALS['md_alt_noindexed_terms'] = $md_alt_term_ids;
	md_alt_term_assert( false !== strpos( $md_alt_term_renderer->render( $md_alt_term_child ), $md_alt_parent_md ), 'Noindex keeps Markdown links before opting in' );
	add_filter( 'markdown_alternate_skip_noindexed_terms', '__return_true' );
	md_alt_term_assert( TaxonomySupport::should_skip_noindexed( $md_alt_term_child ), 'Opted-in noindexed archive is withheld' );
	md_alt_term_assert( false !== strpos( $md_alt_term_renderer->render( $md_alt_term_parent ), '](' . get_term_link( $md_alt_term_child ) . ')' ), 'Withheld children link to HTML' );
	md_alt_term_assert( false !== strpos( $md_alt_term_renderer->render( $md_alt_term_child ), 'url: "' . get_term_link( $md_alt_term_parent ) . '"' ), 'Withheld parents link to HTML' );
	$md_alt_post_output = ( new ContentRenderer() )->render( get_post( $md_alt_term_post_id ) );
	md_alt_term_assert( false === strpos( $md_alt_post_output, '.md"' ) && false !== strpos( $md_alt_post_output, $md_alt_term_child->slug . '/"' ), 'Post frontmatter links withheld terms to HTML' );
	remove_filter( 'markdown_alternate_skip_noindexed_terms', '__return_true' );

	md_alt_term_assert( false !== strpos( $md_alt_term_renderer->render( $md_alt_term_child ), '[Archive regression post](' ), 'Supported posts appear in archives' );
	add_filter( 'markdown_alternate_supported_post_types', $md_alt_term_types, 100 );
	$md_alt_term_output = $md_alt_term_renderer->render( $md_alt_term_child );
	md_alt_term_assert( false === strpos( $md_alt_term_output, '[Archive regression post](' ) && false !== strpos( $md_alt_term_output, "count: 0\n" ), 'Archives with no supported post types are empty' );
	remove_filter( 'markdown_alternate_supported_post_types', $md_alt_term_types, 100 );
	WP_CLI::success( 'Term archive regression tests passed.' );
} finally {
	remove_filter( 'option_home', $md_alt_term_home );
	remove_filter( 'markdown_alternate_skip_noindexed_terms', '__return_true' );
	remove_filter( 'markdown_alternate_supported_post_types', $md_alt_term_types, 100 );
	if ( null === $md_alt_term_request_uri ) {
		unset( $_SERVER['REQUEST_URI'] );
	} else {
		$_SERVER['REQUEST_URI'] = $md_alt_term_request_uri;
	}
	if ( $md_alt_term_post_id ) {
		wp_delete_post( $md_alt_term_post_id, true );
		delete_transient( 'md_alt_cache_' . $md_alt_term_post_id );
	}
	foreach ( array_reverse( $md_alt_term_ids ) as $md_alt_term_id ) {
		wp_delete_term( $md_alt_term_id, 'category' );
	}
	unset( $GLOBALS['md_alt_noindexed_terms'] );
}
