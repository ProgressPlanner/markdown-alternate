<?php
/**
 * Standalone tests for the rendering extension API. Run with php tests/run.php.
 *
 * @package MarkdownAlternate
 */

// These tests read local fixtures and write CLI diagnostics without WordPress filesystem services.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

use MarkdownAlternate\Output\ContentRenderer;

require __DIR__ . '/bootstrap.php';
require dirname( __DIR__ ) . '/vendor/autoload.php';

/**
 * Assert strict equality, including whitespace and types.
 *
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @param string $label    Failure context.
 * @throws RuntimeException When the actual value differs from the expected value.
 * @return void
 */
function md_alt_assert_same( $expected, $actual, $label = '' ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $label . ' expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export, WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI failure diagnostics must preserve exact strings.
	}
}

/**
 * Assert that an output contains a specific fragment.
 *
 * @param string $fragment Expected fragment.
 * @param string $output   Rendered document.
 * @return void
 */
function md_alt_assert_contains( $fragment, $output ) {
	md_alt_assert_same( true, false !== strpos( $output, $fragment ), $fragment );
}

$md_alt_tests = array();
foreach ( array( 'ordinary', 'escaped', 'empty', 'table' ) as $md_alt_fixture ) {
	$md_alt_tests[ 'Unchanged output: ' . $md_alt_fixture ] = static function () use ( $md_alt_fixture ) {
		$post = md_alt_test_fixture( $md_alt_fixture );
		md_alt_assert_same( file_get_contents( __DIR__ . '/fixtures/' . $md_alt_fixture . '.md' ), ( new ContentRenderer() )->render( $post ) );
	};
}

$md_alt_tests['Typed metadata and taxonomy hooks remain compatible with product extensions'] = static function () {
	$post            = md_alt_test_fixture( 'ordinary' );
	$post->post_type = 'product';
	$GLOBALS['md_alt_test_state']['terms']['product_cat']   = array( new WP_Term( 'Books', 'https://example.com/product-category/books/' ) );
	$GLOBALS['md_alt_test_state']['terms']['product_brand'] = array( new WP_Term( 'Brand', 'https://example.com/brand/test/' ) );
	add_filter(
		'markdown_alternate_frontmatter_taxonomies',
		static function ( $taxonomies, $filtered_post ) use ( $post ) {
			md_alt_assert_same( $post, $filtered_post );
			return array(
				'product_cat'   => 'categories',
				'product_brand' => 'brands',
			);
		},
		10,
		2
	);
	add_filter(
		'markdown_alternate_frontmatter_content_lines',
		static function ( $fields, $filtered_post ) use ( $post ) {
			md_alt_assert_same( $post, $filtered_post );
			md_alt_assert_same( 'Post Title', $fields['title']['content'] );
			$fields['price']    = array(
				'type'    => 'number',
				'content' => '12.50',
			);
			$fields['currency'] = array(
				'type'    => 'string',
				'content' => 'EUR',
			);
			$fields['stock']    = array(
				'type'    => 'number',
				'content' => 0,
			);
			$fields['specs']    = array(
				'type'  => 'array',
				'items' => array(
					array(
						'name'  => array(
							'type'    => 'string',
							'content' => 'A "quote" and \\ slash',
						),
						'value' => array(
							'type'    => 'number',
							'content' => 0,
						),
					),
				),
			);
			return $fields;
		},
		10,
		2
	);
	add_filter(
		'markdown_alternate_frontmatter_lines',
		static function ( $lines, $filtered_post ) use ( $post ) {
			md_alt_assert_same( $post, $filtered_post );
			md_alt_assert_same( '---', $lines[0] );
			md_alt_assert_same( false, '---' === end( $lines ) );
			$lines[] = 'custom_flag: true';
			return $lines;
		},
		10,
		2
	);
	$output = ( new ContentRenderer() )->render( $post );
	foreach ( array( 'price: 12.50', 'currency: "EUR"', 'stock: 0', 'categories:', 'brands:', 'url: "/product-category/books.md"', 'value: 0', "custom_flag: true\n---" ) as $fragment ) {
		md_alt_assert_contains( $fragment, $output );
	}
	md_alt_assert_contains( 'name: "A \\"quote\\" and \\\\ slash"', $output );
};

$md_alt_tests['Body sections are ordered, stable on ties, removable and validated'] = static function () {
	$post = md_alt_test_fixture( 'ordinary' );
	add_filter(
		'markdown_alternate_content_sections',
		static function ( $sections, $filtered_post ) use ( $post ) {
			md_alt_assert_same( $post, $filtered_post );
			unset( $sections['title'] );
			$sections['last']             = array(
				'priority' => 200,
				'markdown' => 'Last',
			);
			$sections['first']            = array(
				'priority' => 10,
				'markdown' => 'First',
			);
			$sections['second']           = array(
				'priority' => 10,
				'markdown' => 'Second',
			);
			$sections['default_priority'] = array( 'markdown' => 'Default' );
			$sections['empty']            = array( 'markdown' => " \n" );
			$sections['invalid']          = false;
			$sections['invalid_markdown'] = array( 'markdown' => array() );
			return $sections;
		},
		10,
		2
	);
	$output = ( new ContentRenderer() )->render( $post );
	$body   = explode( "\n---\n\n", $output, 2 )[1];
	md_alt_assert_same( "First\n\nSecond\n\nHello **world**.\n\nDefault\n\nLast", $body );
};

$md_alt_tests['Identity filters retain the empty-body separator'] = static function () {
	$post = md_alt_test_fixture( 'empty' );
	add_filter(
		'markdown_alternate_content_sections',
		static function ( $sections ) {
			return $sections;
		}
	);
	md_alt_assert_same( file_get_contents( __DIR__ . '/fixtures/empty.md' ), ( new ContentRenderer() )->render( $post ) );
};

$md_alt_tests['Cache tokens, post modification and explicit deletion invalidate output'] = static function () {
	$post     = md_alt_test_fixture( 'ordinary' );
	$revision = 'v1';
	add_filter(
		'markdown_alternate_cache_version',
		static function ( $version, $filtered_post ) use ( &$revision, $post ) {
			md_alt_assert_same( $post, $filtered_post );
			return $revision;
		},
		10,
		2
	);
	add_filter(
		'markdown_alternate_frontmatter_content_lines',
		static function ( $fields ) use ( &$revision ) {
			$fields['revision'] = array(
				'type'    => 'string',
				'content' => $revision,
			);
			return $fields;
		}
	);
	add_filter(
		'markdown_alternate_cache_expiration',
		static function () {
			return 60;
		}
	);
	$renderer = new ContentRenderer();
	$first    = $renderer->render( $post );
	md_alt_assert_same( $first, $renderer->render( $post ) );
	md_alt_assert_same( 1, $GLOBALS['md_alt_test_state']['renders'] );
	md_alt_assert_same( 60, $GLOBALS['md_alt_test_state']['expiration']['md_alt_cache_17'] );
	$revision = 'v2';
	md_alt_assert_contains( 'revision: "v2"', $renderer->render( $post ) );
	md_alt_assert_same( 2, $GLOBALS['md_alt_test_state']['renders'] );
	$post->post_modified = '2026-11-14 12:01:00';
	$renderer->render( $post );
	md_alt_assert_same( 3, $GLOBALS['md_alt_test_state']['renders'] );
	delete_transient( 'md_alt_cache_17' );
	$renderer->render( $post );
	md_alt_assert_same( 4, $GLOBALS['md_alt_test_state']['renders'] );
};

$md_alt_tests['Pre-hook caches regenerate once and then cache normally'] = static function () {
	$post = md_alt_test_fixture( 'ordinary' );
	set_transient(
		'md_alt_cache_17',
		array(
			'markdown' => 'OLD',
			'modified' => $post->post_modified,
		),
		DAY_IN_SECONDS
	);
	$renderer = new ContentRenderer();
	$output   = $renderer->render( $post );
	md_alt_assert_same( file_get_contents( __DIR__ . '/fixtures/ordinary.md' ), $output );
	md_alt_assert_same( $output, $renderer->render( $post ) );
	md_alt_assert_same( 1, $GLOBALS['md_alt_test_state']['renders'] );
};

$md_alt_tests['Invalid top-level filter results preserve defaults'] = static function () {
	$post = md_alt_test_fixture( 'ordinary' );
	foreach ( array( 'markdown_alternate_frontmatter_taxonomies', 'markdown_alternate_frontmatter_content_lines', 'markdown_alternate_frontmatter_lines', 'markdown_alternate_content_sections' ) as $tag ) {
		add_filter(
			$tag,
			static function () {
				return null;
			}
		);
	}
	md_alt_assert_same( file_get_contents( __DIR__ . '/fixtures/ordinary.md' ), ( new ContentRenderer() )->render( $post ) );
};

$md_alt_tests['Content filters still run before conversion and section filters'] = static function () {
	$post               = md_alt_test_fixture( 'ordinary' );
	$post->post_content = '[example]';
	add_filter(
		'the_content',
		static function ( $content ) {
			return str_replace( '[example]', '<strong>Expanded</strong>', $content );
		}
	);
	add_filter(
		'markdown_alternate_content_sections',
		static function ( $sections ) {
			md_alt_assert_same( '**Expanded**', $sections['content']['markdown'] );
			return $sections;
		}
	);
	md_alt_assert_contains( "# Post Title\n\n**Expanded**", ( new ContentRenderer() )->render( $post ) );
};

$md_alt_tests['Disabling cache ignores existing entries and never stores customer output'] = static function () {
	$post     = md_alt_test_fixture( 'ordinary' );
	$renderer = new ContentRenderer();
	$renderer->render( $post );
	$original_cache = get_transient( 'md_alt_cache_17' );
	add_filter(
		'markdown_alternate_cache_enabled',
		static function () {
			return false;
		}
	);
	$post->post_content = '<p>Customer price one</p>';
	md_alt_assert_contains( 'Customer price one', $renderer->render( $post ) );
	$post->post_content = '<p>Customer price two</p>';
	md_alt_assert_contains( 'Customer price two', $renderer->render( $post ) );
	md_alt_assert_same( $original_cache, get_transient( 'md_alt_cache_17' ) );
	delete_transient( 'md_alt_cache_17' );
	$renderer->render( $post );
	md_alt_assert_same( false, get_transient( 'md_alt_cache_17' ) );
};

$md_alt_tests['Integration registration leaves hooks unchanged without WooCommerce'] = static function () {
	md_alt_test_fixture( 'ordinary' );
	$plugin = ( new ReflectionClass( \MarkdownAlternate\Plugin::class ) )->newInstanceWithoutConstructor();
	$plugin->register_integrations();
	md_alt_assert_same( array(), $GLOBALS['md_alt_test_state']['filters'] );
};

$md_alt_failures = 0;
foreach ( $md_alt_tests as $md_alt_name => $md_alt_test ) {
	try {
		$md_alt_test();
		fwrite( STDOUT, 'PASS ' . $md_alt_name . "\n" );
	} catch ( Throwable $md_alt_error ) {
		++$md_alt_failures;
		fwrite( STDERR, 'FAIL ' . $md_alt_name . ': ' . $md_alt_error->getMessage() . "\n" );
	}
}
fwrite( STDOUT, count( $md_alt_tests ) . ' tests, ' . $md_alt_failures . " failures\n" );
exit( $md_alt_failures > 0 ? 1 : 0 );
