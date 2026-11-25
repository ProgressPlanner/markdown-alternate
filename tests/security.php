<?php
/**
 * Cache isolation and request-order regressions, loaded by tests/run.php.
 *
 * @package MarkdownAlternate
 */

use MarkdownAlternate\Output\CachePolicy;
use MarkdownAlternate\Output\ContentRenderer;

/**
 * Model a block or shortcode that prints a visitor-specific logout link.
 *
 * @return WP_Post Fixture post.
 */
function md_alt_personalized_fixture() {
	$post = md_alt_test_fixture( 'ordinary' );
	add_filter(
		'the_content',
		static function () {
			return is_user_logged_in()
				? '<a href="https://example.com/logout?_wpnonce=private-test-nonce">Log out</a>'
				: '<a href="https://example.com/login">Log in</a>';
		}
	);
	return $post;
}

$md_alt_tests['A logged-in cache miss cannot leak a logout nonce to an anonymous visitor'] = static function () {
	$post                                      = md_alt_personalized_fixture();
	$renderer                                  = new ContentRenderer();
	$GLOBALS['md_alt_test_state']['logged_in'] = true;
	md_alt_assert_contains( 'private-test-nonce', $renderer->render( $post ) );
	md_alt_assert_same( false, get_transient( 'md_alt_cache_17' ) );
	$GLOBALS['md_alt_test_state']['logged_in'] = false;
	$anonymous                                 = $renderer->render( $post );
	md_alt_assert_contains( 'Log in', $anonymous );
	md_alt_assert_same( false, strpos( $anonymous, 'private-test-nonce' ) );
	md_alt_assert_same( $anonymous, $renderer->render( $post ) );
	md_alt_assert_same( 2, $GLOBALS['md_alt_test_state']['renders'] );
};

$md_alt_tests['Logged-in requests neither read nor replace the anonymous cache, including after expiration'] = static function () {
	$post     = md_alt_personalized_fixture();
	$renderer = new ContentRenderer();
	$renderer->render( $post );
	$anonymous_cache                           = get_transient( 'md_alt_cache_17' );
	$GLOBALS['md_alt_test_state']['logged_in'] = true;
	md_alt_assert_contains( 'private-test-nonce', $renderer->render( $post ) );
	md_alt_assert_same( $anonymous_cache, get_transient( 'md_alt_cache_17' ) );
	// WordPress returns false from get_transient() once an entry expires.
	delete_transient( 'md_alt_cache_17' );
	md_alt_assert_contains( 'private-test-nonce', $renderer->render( $post ) );
	md_alt_assert_same( false, get_transient( 'md_alt_cache_17' ) );
	$GLOBALS['md_alt_test_state']['logged_in'] = false;
	md_alt_assert_same( false, strpos( $renderer->render( $post ), 'private-test-nonce' ) );
};

$md_alt_tests['Pre-fix caches with a matching integration version are discarded'] = static function () {
	$post = md_alt_personalized_fixture();
	add_filter(
		'markdown_alternate_cache_version',
		static function () {
			return 'integration-v1';
		}
	);
	set_transient(
		'md_alt_cache_17',
		array(
			'markdown' => 'private-test-nonce',
			'modified' => $post->post_modified,
			'version'  => 'integration-v1',
		),
		DAY_IN_SECONDS
	);
	$renderer = new ContentRenderer();
	$output   = $renderer->render( $post );
	md_alt_assert_same( false, strpos( $output, 'private-test-nonce' ) );
	md_alt_assert_contains( 'Log in', $output );
	md_alt_assert_same( $output, $renderer->render( $post ) );
	md_alt_assert_same( 1, $GLOBALS['md_alt_test_state']['renders'] );
};

$md_alt_tests['Known guest sessions bypass shared reads and writes'] = static function () {
	foreach ( array( 'wp-postpass_test', 'wp_woocommerce_session_test', 'woocommerce_items_in_cart', 'woocommerce_cart_hash', 'edd_items_in_cart' ) as $cookie ) {
		$post     = md_alt_test_fixture( 'ordinary' );
		$renderer = new ContentRenderer();
		$renderer->render( $post );
		$cache              = get_transient( 'md_alt_cache_17' );
		$_COOKIE[ $cookie ] = 'dummy-session';
		$post->post_content = '<p>Private guest content</p>';
		md_alt_assert_contains( 'Private guest content', $renderer->render( $post ) );
		md_alt_assert_same( $cache, get_transient( 'md_alt_cache_17' ), $cookie );
		delete_transient( 'md_alt_cache_17' );
		$renderer->render( $post );
		md_alt_assert_same( false, get_transient( 'md_alt_cache_17' ), $cookie );
	}
};

$md_alt_tests['Cache filters cannot opt authenticated or password-protected output back into sharing'] = static function () {
	$post = md_alt_test_fixture( 'ordinary' );
	add_filter(
		'markdown_alternate_cache_enabled',
		static function () {
			return true;
		}
	);
	$GLOBALS['md_alt_test_state']['logged_in'] = true;
	md_alt_assert_same( false, CachePolicy::is_enabled( $post ) );
	$GLOBALS['md_alt_test_state']['logged_in'] = false;
	$post->post_password                       = 'dummy-password';
	md_alt_assert_same( false, CachePolicy::is_enabled( $post ) );
	( new ContentRenderer() )->render( $post );
	md_alt_assert_same( false, get_transient( 'md_alt_cache_17' ) );
};

$md_alt_tests['Unrelated cookies retain caching and integrations can disable custom sessions'] = static function () {
	$post                             = md_alt_test_fixture( 'ordinary' );
	$_COOKIE['wordpress_test_cookie'] = 'test';
	md_alt_assert_same( true, CachePolicy::is_enabled( $post ) );
	add_filter(
		'markdown_alternate_cache_enabled',
		static function () {
			return false;
		}
	);
	md_alt_assert_same( false, CachePolicy::is_enabled( $post ) );
};

/**
 * Run a terminating router request in its own process.
 *
 * @param string $route Request route.
 * @param string $mode  Visitor or redirect scenario.
 * @return array Captured response.
 */
function md_alt_router_request( $route, $mode ) {
	$command     = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/router-request.php' ) . ' ' . escapeshellarg( $route ) . ' ' . escapeshellarg( $mode );
	$descriptors = array(
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);
	$process     = proc_open( $command, $descriptors, $pipes ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Isolate exit() in a CLI test child.
	$output      = stream_get_contents( $pipes[1] );
	$error       = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	fclose( $pipes[2] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	md_alt_assert_same( 0, proc_close( $process ), $error );
	$response = json_decode( $output, true );
	md_alt_assert_same( true, is_array( $response ), $output );
	return $response;
}

foreach ( array( 'md', 'format', 'accept' ) as $md_alt_route ) {
	$md_alt_tests[ 'Access checks run before ' . $md_alt_route . ' responses' ] = static function () use ( $md_alt_route ) {
		foreach ( array( 'deny10', 'deny14' ) as $mode ) {
			$response = md_alt_router_request( $md_alt_route, $mode );
			md_alt_assert_same( 403, $response['status'] );
			md_alt_assert_same( 'Access denied', $response['body'] );
			md_alt_assert_same( 0, $response['renders'] );
			md_alt_assert_same( array(), $response['cache'] );
		}
	};
}

foreach ( array( 'md', 'format' ) as $md_alt_route ) {
	$md_alt_tests[ 'Private HTTP and transient cache policy agree for ' . $md_alt_route ] = static function () use ( $md_alt_route ) {
		foreach ( array( 'logged-in', 'session', 'password', 'filter-disabled' ) as $mode ) {
			$response = md_alt_router_request( $md_alt_route, $mode );
			md_alt_assert_same( 200, $response['status'], $mode );
			md_alt_assert_contains( 'private, no-store', $response['headers']['Cache-Control'] );
			md_alt_assert_same( true, $response['page_cache_disabled'] );
			md_alt_assert_same( array(), $response['cache'] );
		}
		$response = md_alt_router_request( $md_alt_route, 'anonymous' );
		md_alt_assert_same( 200, $response['status'] );
		md_alt_assert_same( 'text/markdown; charset=UTF-8', $response['headers']['Content-Type'] );
		md_alt_assert_same( true, isset( $response['cache']['md_alt_cache_17'] ) );
	};
}

$md_alt_tests['Canonical redirects precede format and Accept handling, but do not replace .md URLs'] = static function () {
	foreach ( array( 'format', 'accept' ) as $route ) {
		$response = md_alt_router_request( $route, 'canonical' );
		md_alt_assert_same( 301, $response['status'] );
		md_alt_assert_contains( '/post/', $response['headers']['Location'] );
		md_alt_assert_same( 0, $response['renders'] );
	}
	$response = md_alt_router_request( 'md', 'canonical' );
	md_alt_assert_same( 200, $response['status'] );
	md_alt_assert_contains( '# Post Title', $response['body'] );
};

$md_alt_tests['Accept negotiation and explicit format precedence remain unchanged'] = static function () {
	$response = md_alt_router_request( 'accept', 'anonymous' );
	md_alt_assert_same( 303, $response['status'] );
	md_alt_assert_same( 'https://example.com/post.md', $response['headers']['Location'] );
	md_alt_assert_same( 0, $response['renders'] );
	$response = md_alt_router_request( 'format', 'also-accept' );
	md_alt_assert_same( 200, $response['status'] );
	md_alt_assert_contains( '# Post Title', $response['body'] );
	$response = md_alt_router_request( 'html', 'anonymous' );
	md_alt_assert_same( 'HTML template', $response['body'] );
	md_alt_assert_same( 0, $response['renders'] );
};

$md_alt_tests['Author and date archives retain HTML when Markdown is requested'] = static function () {
	foreach ( array( 'author', 'year', 'month', 'day' ) as $archive ) {
		$response = md_alt_router_request( 'accept', $archive );
		md_alt_assert_same( 200, $response['status'], $archive );
		md_alt_assert_same( 'HTML template', $response['body'], $archive );
		md_alt_assert_same( false, isset( $response['headers']['Location'] ), $archive );
		md_alt_assert_same( 0, $response['renders'], $archive );
	}
};
