<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- WordPress API doubles share one test bootstrap.
/**
 * WordPress API doubles for standalone renderer regression tests.
 *
 * @package MarkdownAlternate
 */

// Test doubles intentionally retain WordPress API names and omit repetitive API docs.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, Generic.Files.OneObjectStructurePerFile, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.Commenting.ClassComment.Missing, WordPress.Files.FileName.InvalidClassFileName, Universal.Files.SeparateFunctionsFromOO.Mixed, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

class WP_Post {
	public $ID            = 17;
	public $post_author   = 5;
	public $post_modified = '2026-11-14 12:00:00';
	public $post_date     = '2026-01-30 10:00:00';
	public $post_title    = 'Post Title';
	public $post_content  = '<p>Hello <strong>world</strong>.</p>';
	public $post_type     = 'post';
}

class WP_Term {
	public $name;
	public $url;
	public function __construct( $name, $url ) {
		$this->name = $name;
		$this->url  = $url;
	}
}

class WP_Error {}

define( 'DAY_IN_SECONDS', 86400 );

function md_alt_test_reset() {
	$GLOBALS['md_alt_test_state'] = array(
		'filters'    => array(),
		'cache'      => array(),
		'expiration' => array(),
		'terms'      => array(),
		'author'     => 'Author Name',
		'image'      => false,
		'renders'    => 0,
	);
}

function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['md_alt_test_state']['filters'][ $tag ][ $priority ][] = array( $callback, $accepted_args );
}

function apply_filters( $tag, $value, ...$args ) {
	if ( 'the_content' === $tag ) {
		++$GLOBALS['md_alt_test_state']['renders'];
	}
	$priorities = $GLOBALS['md_alt_test_state']['filters'][ $tag ] ?? array();
	ksort( $priorities );
	foreach ( $priorities as $callbacks ) {
		foreach ( $callbacks as list( $callback, $accepted_args ) ) {
			$value = $callback( ...array_slice( array_merge( array( $value ), $args ), 0, $accepted_args ) );
		}
	}
	return $value;
}

function get_transient( $key ) {
	return $GLOBALS['md_alt_test_state']['cache'][ $key ] ?? false;
}

function set_transient( $key, $value, $expiration ) {
	$GLOBALS['md_alt_test_state']['cache'][ $key ]      = $value;
	$GLOBALS['md_alt_test_state']['expiration'][ $key ] = $expiration;
}

function delete_transient( $key ) {
	unset( $GLOBALS['md_alt_test_state']['cache'][ $key ] );
}

function get_the_title( $post ) {
	return $post->post_title;
}

function get_the_date( $format, $post ) {
	return substr( $post->post_date, 0, 10 );
}

function get_the_author_meta( $field, $author_id ) {
	return $GLOBALS['md_alt_test_state']['author'];
}

function get_the_post_thumbnail_url( $post_id, $size ) {
	return $GLOBALS['md_alt_test_state']['image'];
}

function get_the_terms( $post_id, $taxonomy ) {
	return $GLOBALS['md_alt_test_state']['terms'][ $taxonomy ] ?? false;
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function get_term_link( $term ) {
	return $term->url;
}

function wp_parse_url( $url, $component ) {
	return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- This double implements the wrapper itself.
}

function wp_strip_all_tags( $value ) {
	return strip_tags( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- This double implements the wrapper itself.
}

/**
 * Shared baseline scenarios. Expected Markdown was captured from main at 7afba03.
 *
 * @param string $name Fixture name.
 * @return WP_Post Fixture post.
 */
function md_alt_test_fixture( $name ) {
	md_alt_test_reset();
	$post = new WP_Post();
	if ( 'escaped' === $name ) {
		$post->post_title                                  = 'A &quot;quoted&quot; &amp; \\ title';
		$GLOBALS['md_alt_test_state']['author']            = 'Author &quot;Q&quot;';
		$GLOBALS['md_alt_test_state']['image']             = 'https://example.com/image.jpg?a=1&amp;b=2';
		$GLOBALS['md_alt_test_state']['terms']['category'] = array( new WP_Term( 'A &quot;quoted&quot; \\ category', 'https://example.com/category/test/' ) );
		$GLOBALS['md_alt_test_state']['terms']['post_tag'] = array( new WP_Term( 'tag &amp; name', 'https://example.com/tag/test/' ) );
	} elseif ( 'empty' === $name ) {
		$post->post_content                                = '';
		$post->post_title                                  = '2026-11-14';
		$GLOBALS['md_alt_test_state']['author']            = '';
		$GLOBALS['md_alt_test_state']['terms']['category'] = new WP_Error();
	} elseif ( 'table' === $name ) {
		$post->post_content = '<table><tr><th>Plan</th><th>Price</th></tr><tr><td>Free</td><td>$0</td></tr></table>';
	}
	return $post;
}
