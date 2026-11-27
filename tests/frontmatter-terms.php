<?php
/**
 * Term exclusion regressions, loaded by tests/run.php.
 *
 * @package MarkdownAlternate
 */

use MarkdownAlternate\Output\ContentRenderer;

$md_alt_tests['Term exclusions work for categories, tags and product taxonomies'] = static function () {
	foreach ( array( 'category', 'post_tag', 'product_cat', 'product_tag', 'product_brand' ) as $taxonomy ) {
		$post = md_alt_test_fixture( 'ordinary' );
		if ( 0 === strpos( $taxonomy, 'product_' ) ) {
			$post->post_type = 'product';
		}
		$kept     = new WP_Term( 'Kept &amp; quoted "term"', 'https://example.com/kept/' );
		$excluded = new WP_Term( 'Excluded term', 'https://example.com/excluded/' );
		$GLOBALS['md_alt_test_state']['terms'][ $taxonomy ] = array( $excluded, $kept );
		add_filter(
			'markdown_alternate_frontmatter_taxonomies',
			static function () use ( $taxonomy ) {
				return array( $taxonomy => 'terms' );
			}
		);
		add_filter(
			'markdown_alternate_frontmatter_terms',
			static function ( $terms, $filtered_taxonomy, $filtered_post ) use ( $taxonomy, $post, $kept ) {
				md_alt_assert_same( $taxonomy, $filtered_taxonomy );
				md_alt_assert_same( $post, $filtered_post );
				return array_filter(
					$terms,
					static function ( $term ) use ( $kept ) {
						return $kept === $term;
					}
				);
			},
			10,
			3
		);
		$output = ( new ContentRenderer() )->render( $post );
		md_alt_assert_contains( 'terms:', $output );
		md_alt_assert_contains( 'https://example.com/kept', $output );
		md_alt_assert_contains( 'Kept & quoted \"term\"', $output );
		md_alt_assert_same( false, strpos( $output, 'Excluded term' ), $taxonomy );
		md_alt_assert_same( false, strpos( $output, 'https://example.com/excluded' ), $taxonomy );
	}
};

$md_alt_tests['Excluding every category omits its field and leaves tags intact'] = static function () {
	$post = md_alt_test_fixture( 'escaped' );
	add_filter(
		'markdown_alternate_frontmatter_terms',
		static function ( $terms, $taxonomy ) {
			return 'category' === $taxonomy ? array() : $terms;
		},
		10,
		2
	);
	$output = ( new ContentRenderer() )->render( $post );
	md_alt_assert_same( false, strpos( $output, 'categories:' ) );
	md_alt_assert_contains( 'tags:', $output );
	md_alt_assert_contains( 'tag & name', $output );
};

$md_alt_tests['Invalid term filter returns retain defaults and malformed entries are ignored'] = static function () {
	foreach ( array( null, false, new WP_Error(), 'invalid' ) as $invalid ) {
		$post     = md_alt_test_fixture( 'escaped' );
		$renderer = new ContentRenderer();
		$expected = $renderer->render( $post );
		delete_transient( 'md_alt_cache_' . $post->ID );
		add_filter(
			'markdown_alternate_frontmatter_terms',
			static function () use ( $invalid ) {
				return $invalid;
			}
		);
		md_alt_assert_same( $expected, $renderer->render( $post ) );
	}
	$post = md_alt_test_fixture( 'escaped' );
	add_filter(
		'markdown_alternate_frontmatter_terms',
		static function ( $terms ) {
			return array_merge( array( null, new WP_Error(), 'invalid' ), $terms );
		}
	);
	md_alt_assert_same( $expected, ( new ContentRenderer() )->render( $post ) );
};

$md_alt_tests['Changing the cache version applies updated term exclusion rules'] = static function () {
	$post     = md_alt_test_fixture( 'escaped' );
	$renderer = new ContentRenderer();
	$original = $renderer->render( $post );
	add_filter(
		'markdown_alternate_frontmatter_terms',
		static function () {
			return array();
		}
	);
	md_alt_assert_same( $original, $renderer->render( $post ) );
	add_filter(
		'markdown_alternate_cache_version',
		static function ( $version ) {
			return $version . '|term-exclusions-v1';
		}
	);
	$output = $renderer->render( $post );
	md_alt_assert_same( false, strpos( $output, 'categories:' ) );
	md_alt_assert_same( false, strpos( $output, 'tags:' ) );
	md_alt_assert_same( $output, $renderer->render( $post ) );
};
