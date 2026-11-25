<?php
/**
 * Standalone tests for the rendering extension API. Run with php tests/run.php.
 *
 * @package MarkdownAlternate
 */

// These tests read local fixtures and write CLI diagnostics without WordPress filesystem services.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

use MarkdownAlternate\Output\ContentRenderer;
use MarkdownAlternate\PostTypeSupport;
use MarkdownAlternate\Integration\YoastLlmsTxt;

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
			$fields['taxed']    = array(
				'type'    => 'boolean',
				'content' => true,
			);
			$fields['exempt']   = array(
				'type'    => 'boolean',
				'content' => false,
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
	// An integration that serves product category archives registers the taxonomy.
	add_filter(
		'markdown_alternate_supported_taxonomies',
		static function ( $taxonomies ) {
			$taxonomies[] = 'product_cat';
			return $taxonomies;
		}
	);
	$output = ( new ContentRenderer() )->render( $post );
	foreach ( array( 'price: 12.50', 'currency: "EUR"', 'stock: 0', "taxed: true\n", "exempt: false\n", 'categories:', 'brands:', 'url: "https://example.com/product-category/books.md"', 'value: 0', "custom_flag: true\n---" ) as $fragment ) {
		md_alt_assert_contains( $fragment, $output );
	}
	// A taxonomy whose archives are not served links to its HTML archive, never to a .md URL that would 404.
	md_alt_assert_contains( 'url: "https://example.com/brand/test/"', $output );
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

$md_alt_tests['Yoast effective robots noindex marks the post noindexed'] = static function () {
	$post                                  = md_alt_test_fixture( 'ordinary' );
	$GLOBALS['md_alt_test_state']['yoast'] = array(
		'index'  => 'noindex',
		'follow' => 'follow',
	);
	md_alt_assert_same( true, PostTypeSupport::is_noindexed( $post ), 'noindex' );
};

$md_alt_tests['Yoast effective robots index wins over a stale per-post meta key'] = static function () {
	$post                                  = md_alt_test_fixture( 'ordinary' );
	$GLOBALS['md_alt_test_state']['yoast'] = array(
		'index'  => 'index',
		'follow' => 'follow',
	);
	$GLOBALS['md_alt_test_state']['meta'][ $post->ID ]['_yoast_wpseo_meta-robots-noindex'] = '1';
	md_alt_assert_same( false, PostTypeSupport::is_noindexed( $post ), 'index' );
};

$md_alt_tests['Without an indexable the per-post meta key decides'] = static function () {
	$post = md_alt_test_fixture( 'ordinary' );
	md_alt_assert_same( false, PostTypeSupport::is_noindexed( $post ), 'no meta' );
	$GLOBALS['md_alt_test_state']['meta'][ $post->ID ]['_yoast_wpseo_meta-robots-noindex'] = '1';
	md_alt_assert_same( true, PostTypeSupport::is_noindexed( $post ), 'meta noindex' );
	$GLOBALS['md_alt_test_state']['meta'][ $post->ID ]['_yoast_wpseo_meta-robots-noindex'] = '2';
	md_alt_assert_same( false, PostTypeSupport::is_noindexed( $post ), 'meta forced index' );
};

$md_alt_tests['A failing Yoast surface falls back to the per-post meta key'] = static function () {
	$post                                  = md_alt_test_fixture( 'ordinary' );
	$GLOBALS['md_alt_test_state']['yoast'] = 'throw';
	md_alt_assert_same( false, PostTypeSupport::is_noindexed( $post ), 'throw, no meta' );
	$GLOBALS['md_alt_test_state']['meta'][ $post->ID ]['_yoast_wpseo_meta-robots-noindex'] = '1';
	md_alt_assert_same( true, PostTypeSupport::is_noindexed( $post ), 'throw, meta noindex' );
};

$md_alt_tests['Effective robots without noindex override stale per-post metadata'] = static function () {
	$post                                  = md_alt_test_fixture( 'ordinary' );
	$GLOBALS['md_alt_test_state']['yoast'] = array( 'follow' => 'nofollow' );
	md_alt_assert_same( false, PostTypeSupport::is_noindexed( $post ), 'no index key, no meta' );
	$GLOBALS['md_alt_test_state']['meta'][ $post->ID ]['_yoast_wpseo_meta-robots-noindex'] = '1';
	md_alt_assert_same( false, PostTypeSupport::is_noindexed( $post ), 'no index key, meta noindex' );
	$GLOBALS['md_alt_test_state']['yoast'] = array();
	md_alt_assert_same( false, PostTypeSupport::is_noindexed( $post ), 'empty robots, meta noindex' );
	$GLOBALS['md_alt_test_state']['yoast'] = array( 'noindex', 'follow' );
	md_alt_assert_same( true, PostTypeSupport::is_noindexed( $post ), 'unkeyed noindex directive' );
};

$md_alt_tests['Noindexed posts keep their markdown unless the site opts in'] = static function () {
	$post                                  = md_alt_test_fixture( 'ordinary' );
	$GLOBALS['md_alt_test_state']['yoast'] = array( 'index' => 'noindex' );
	md_alt_assert_same( false, PostTypeSupport::should_skip_noindexed( $post ), 'default off' );
	add_filter(
		'markdown_alternate_skip_noindexed',
		static function () {
			return true;
		}
	);
	md_alt_assert_same( true, PostTypeSupport::should_skip_noindexed( $post ), 'opted in' );
};

$md_alt_tests['Opting in never skips an indexed post and receives the post'] = static function () {
	$post                                  = md_alt_test_fixture( 'ordinary' );
	$GLOBALS['md_alt_test_state']['yoast'] = array( 'index' => 'index' );
	add_filter(
		'markdown_alternate_skip_noindexed',
		static function ( $skip, $filtered_post ) use ( $post ) {
			md_alt_assert_same( false, $skip, 'default' );
			md_alt_assert_same( $post, $filtered_post, 'post' );
			return true;
		},
		10,
		2
	);
	md_alt_assert_same( false, PostTypeSupport::should_skip_noindexed( $post ), 'indexed' );
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

$md_alt_tests['llms.txt keeps HTML links for skipped posts on both generation paths'] = static function () {
	$post = md_alt_test_fixture( 'ordinary' );
	$GLOBALS['md_alt_test_state']['posts'][ $post->ID ] = $post;
	$GLOBALS['md_alt_test_state']['yoast']              = array( 'index' => 'noindex' );
	$integration                                        = new YoastLlmsTxt();
	$presentation                                       = (object) array(
		'model' => (object) array(
			'object_type'     => 'post',
			'object_sub_type' => 'post',
			'object_id'       => $post->ID,
		),
	);
	$url = 'https://example.com/post/';
	md_alt_assert_same( 'https://example.com/post.md', $integration->rewrite_canonical( $url, $presentation ), 'default canonical' );
	md_alt_assert_same( 'https://example.com/post.md', $integration->rewrite_post_link( $url, $post ), 'default permalink' );
	md_alt_assert_same( 0, $GLOBALS['md_alt_test_state']['yoast_calls'], 'default avoids Yoast lookup' );
	add_filter(
		'markdown_alternate_skip_noindexed',
		static function () {
			return true;
		}
	);
	md_alt_assert_same( $url, $integration->rewrite_canonical( $url, $presentation ), 'skipped canonical' );
	md_alt_assert_same( $url, $integration->rewrite_post_link( $url, $post ), 'skipped post permalink' );
	md_alt_assert_same( $url, $integration->rewrite_post_link( $url, $post->ID ), 'skipped page permalink by ID' );
	$GLOBALS['md_alt_test_state']['yoast'] = array( 'index' => 'index' );
	md_alt_assert_same( 'https://example.com/post.md', $integration->rewrite_canonical( $url, $presentation ), 'indexed canonical' );
	md_alt_assert_same( 'https://example.com/post.md', $integration->rewrite_post_link( $url, $post ), 'indexed permalink' );
};

$md_alt_tests['llms.txt noindex lookups leave nested Yoast URLs unchanged without recursion'] = static function () {
	$post = md_alt_test_fixture( 'ordinary' );
	$GLOBALS['md_alt_test_state']['posts'][ $post->ID ] = $post;
	$GLOBALS['md_alt_test_state']['yoast']              = array( 'index' => 'index' );
	$integration                                        = new YoastLlmsTxt();
	$url          = 'https://example.com/post/';
	$presentation = (object) array(
		'model' => (object) array(
			'object_type'     => 'post',
			'object_sub_type' => 'post',
			'object_id'       => $post->ID,
		),
	);
	add_filter(
		'markdown_alternate_skip_noindexed',
		static function () {
			return true;
		}
	);
	$GLOBALS['md_alt_test_state']['yoast_callback'] = static function () use ( $integration, $post, $url, $presentation ) {
		if ( $GLOBALS['md_alt_test_state']['yoast_calls'] > 1 ) {
			throw new RuntimeException( 'Recursive Yoast lookup.' );
		}
		$GLOBALS['md_alt_test_state']['nested_permalink'] = $integration->rewrite_post_link( $url, $post );
		$GLOBALS['md_alt_test_state']['nested_canonical'] = $integration->rewrite_canonical( $url, $presentation );
	};
	md_alt_assert_same( 'https://example.com/post.md', $integration->rewrite_post_link( $url, $post ), 'outer permalink' );
	md_alt_assert_same( 1, $GLOBALS['md_alt_test_state']['yoast_calls'], 'one lookup' );
	md_alt_assert_same( $url, $GLOBALS['md_alt_test_state']['nested_permalink'], 'nested permalink' );
	md_alt_assert_same( $url, $GLOBALS['md_alt_test_state']['nested_canonical'], 'nested canonical' );
	unset( $GLOBALS['md_alt_test_state']['yoast_callback'] );
	$GLOBALS['md_alt_test_state']['yoast'] = 'throw';
	md_alt_assert_same( 'https://example.com/post.md', $integration->rewrite_post_link( $url, $post ), 'fallback after exception' );
	$GLOBALS['md_alt_test_state']['meta'][ $post->ID ]['_yoast_wpseo_meta-robots-noindex'] = '1';
	md_alt_assert_same( $url, $integration->rewrite_post_link( $url, $post ), 'guard reset after exception' );
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
