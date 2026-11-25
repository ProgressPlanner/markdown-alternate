<?php
/**
 * Real WordPress/WooCommerce regression tests for an isolated test site.
 *
 * Run: wp eval-file /path/to/tests/woocommerce-integration.php
 *
 * @package MarkdownAlternate
 */

use MarkdownAlternate\Output\ContentRenderer;
use MarkdownAlternate\Output\TermRenderer;
use MarkdownAlternate\PostTypeSupport;
use MarkdownAlternate\TaxonomySupport;
use MarkdownAlternate\Router\UrlConverter;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( 'WC_Product' ) ) {
	throw new RuntimeException( 'Run with wp eval-file in an isolated WordPress site with both plugins active.' );
}

/**
 * Fail the integration run on a behavioral regression.
 *
 * @param bool   $condition Assertion result.
 * @param string $message   Failure description.
 * @throws RuntimeException When an assertion fails.
 * @return void
 */
function md_alt_woo_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI test diagnostics.
	}
	WP_CLI::log( 'PASS ' . $message );
}

/**
 * Render a fresh product object through the real pipeline.
 *
 * @param int $id Product ID.
 * @return string Complete Markdown.
 */
function md_alt_woo_render( $id ) {
	return ( new ContentRenderer() )->render( get_post( $id ) );
}

$md_alt_woo_ids     = array();
$md_alt_woo_options = array();
foreach ( array( 'woocommerce_currency', 'woocommerce_hide_out_of_stock_items', 'woocommerce_calc_taxes', 'woocommerce_prices_include_tax', 'woocommerce_tax_display_shop', 'woocommerce_default_country', 'woocommerce_enable_reviews', 'woocommerce_enable_review_rating' ) as $md_alt_option ) {
	$md_alt_woo_options[ $md_alt_option ] = get_option( $md_alt_option );
}
$md_alt_tax_id = 0;
$md_alt_terms  = array();
try {
	update_option( 'woocommerce_currency', 'EUR' );
	update_option( 'woocommerce_calc_taxes', 'no' );
	update_option( 'woocommerce_prices_include_tax', 'no' );
	md_alt_woo_assert( PostTypeSupport::is_supported( 'product' ) && ! PostTypeSupport::is_supported( 'product_variation' ), 'Products enabled; standalone variations excluded' );

	$md_alt_product = new WC_Product_Simple();
	$md_alt_product->set_name( 'Markdown test simple' );
	$md_alt_product->set_status( 'publish' );
	$md_alt_product->set_regular_price( '20' );
	$md_alt_product->set_sale_price( '10' );
	$md_alt_product->set_price( '10' );
	$md_alt_product->set_sku( 'md-alt-test-' . wp_generate_uuid4() );
	$md_alt_product->set_description( '<p>Long <strong>description</strong>.</p>' );
	$md_alt_product->set_short_description( '<p>Short <a href="https://example.com">link</a>.</p><ul><li>One</li><li>Two</li></ul>' );
	$md_alt_product->set_manage_stock( true );
	$md_alt_product->set_stock_quantity( 0 );
	$md_alt_product->set_date_on_sale_to( '2099-06-30 23:59:59' );
	$md_alt_gtin = method_exists( $md_alt_product, 'set_global_unique_id' ) ? (string) wp_rand( 1000000000000, 9999999999999 ) : '';
	if ( '' !== $md_alt_gtin ) {
		$md_alt_product->set_global_unique_id( $md_alt_gtin );
	}
	$md_alt_woo_ids[] = $md_alt_product->save();
	$md_alt_id        = $md_alt_product->get_id();
	foreach ( array(
		'product_cat'   => 'Test category',
		'product_tag'   => 'Test tag',
		'product_brand' => 'Test brand',
	) as $md_alt_taxonomy => $md_alt_term_name ) {
		if ( taxonomy_exists( $md_alt_taxonomy ) ) {
			$md_alt_term = wp_insert_term( $md_alt_term_name . ' ' . wp_generate_uuid4(), $md_alt_taxonomy );
			if ( is_wp_error( $md_alt_term ) ) {
				throw new RuntimeException( 'Could not create test product terms.' );
			}
			$md_alt_terms[] = array( $md_alt_taxonomy, $md_alt_term['term_id'] );
			wp_set_object_terms( $md_alt_id, array( (int) $md_alt_term['term_id'] ), $md_alt_taxonomy );
		}
	}
	$md_alt_output = md_alt_woo_render( $md_alt_id );
	md_alt_woo_assert( false !== strpos( $md_alt_output, 'categories:' ) && false !== strpos( $md_alt_output, 'Test category' ) && false !== strpos( $md_alt_output, 'tags:' ) && false !== strpos( $md_alt_output, 'Test tag' ), 'Product taxonomies use the shared typed frontmatter API' );
	if ( taxonomy_exists( 'product_brand' ) ) {
		md_alt_woo_assert( false !== strpos( $md_alt_output, 'brands:' ) && false !== strpos( $md_alt_output, 'Test brand' ), 'Product brands supported when registered' );
	}

	md_alt_woo_assert( false !== strpos( $md_alt_output, "price: 10\n" ) && false !== strpos( $md_alt_output, 'sale_price: 10' ), 'Active sale uses numeric frontmatter fields' );
	md_alt_woo_assert( false !== strpos( $md_alt_output, '~~€20.00~~' ) && false === strpos( $md_alt_output, 'Original price was' ) && false === strpos( $md_alt_output, '<?xml' ), 'Sale display preserves strikethrough without duplicated accessibility text' );
	md_alt_woo_assert( false !== strpos( $md_alt_output, 'stock_quantity: 0' ), 'Zero stock retained' );
	md_alt_woo_assert( false !== strpos( $md_alt_output, "prices_include_tax: false\n" ), 'Catalog prices state that tax is excluded when taxes are off' );
	md_alt_woo_assert( false !== strpos( $md_alt_output, '**SKU:** `md-alt-test-' ), 'SKU rendered as an unescaped code span' );
	md_alt_woo_assert( false !== strpos( $md_alt_output, 'url: "' . get_permalink( $md_alt_id ) . '"' ), 'Product URL points to the HTML page' );
	md_alt_woo_assert( 1 === preg_match( '/^modified: \d{4}-\d{2}-\d{2}$/m', $md_alt_output ), 'Modified date included' );
	md_alt_woo_assert( false !== strpos( $md_alt_output, "sale_ends: 2099-06-30\n" ), 'Active sale end date included' );
	md_alt_woo_assert( 0 === preg_match( '/^author:/m', $md_alt_output ), 'Product frontmatter has no post author' );
	md_alt_woo_assert( false === strpos( $md_alt_output, 'rating:' ), 'Rating omitted without reviews' );
	if ( '' !== $md_alt_gtin ) {
		md_alt_woo_assert( false !== strpos( $md_alt_output, 'gtin: "' . $md_alt_gtin . '"' ), 'GTIN included when set' );
	}
	md_alt_woo_assert( false !== strpos( $md_alt_output, '[link](https://example.com)' ) && false !== strpos( $md_alt_output, '- One' ), 'Short description keeps links and lists' );
	md_alt_woo_assert( false === strpos( $md_alt_output, '## Attributes' ) && false === strpos( $md_alt_output, '## Variations' ), 'No empty product sections' );
	md_alt_woo_assert( false === get_transient( 'md_alt_cache_' . $md_alt_id ), 'Product output is not stored in the shared cache' );

	$md_alt_product->set_date_on_sale_from( time() + DAY_IN_SECONDS );
	$md_alt_product->set_price( '20' );
	$md_alt_product->save();
	$md_alt_output = md_alt_woo_render( $md_alt_id );
	md_alt_woo_assert( false === strpos( $md_alt_output, 'sale_price:' ) && false === strpos( $md_alt_output, 'sale_ends:' ) && false !== strpos( $md_alt_output, "price: 20\n" ), 'Future sale is not advertised as active' );

	update_option( 'woocommerce_enable_reviews', 'yes' );
	update_option( 'woocommerce_enable_review_rating', 'yes' );
	$md_alt_product->set_rating_counts(
		array(
			4 => 1,
			5 => 1,
		)
	);
	$md_alt_product->set_average_rating( '4.50' );
	$md_alt_product->set_review_count( 2 );
	$md_alt_product->save();
	$md_alt_output = md_alt_woo_render( $md_alt_id );
	md_alt_woo_assert( false !== strpos( $md_alt_output, "rating: 4.50\n" ) && false !== strpos( $md_alt_output, "review_count: 2\n" ), 'Rating and review count included when reviews exist' );
	update_option( 'woocommerce_enable_review_rating', 'no' );
	md_alt_woo_assert( false === strpos( md_alt_woo_render( $md_alt_id ), 'rating:' ), 'Rating omitted when ratings are disabled' );

	$md_alt_visible = new WC_Product_Attribute();
	$md_alt_visible->set_name( 'Material' );
	$md_alt_visible->set_options( array( 'Cotton', 'Linen' ) );
	$md_alt_visible->set_visible( true );
	$md_alt_weight = new WC_Product_Attribute();
	$md_alt_weight->set_name( 'Weight' );
	$md_alt_weight->set_options( array( '1.5 kg (approx.) *heavy*' ) );
	$md_alt_weight->set_visible( true );
	$md_alt_hidden = new WC_Product_Attribute();
	$md_alt_hidden->set_name( 'Internal note' );
	$md_alt_hidden->set_options( array( 'DO NOT EXPOSE' ) );
	$md_alt_hidden->set_visible( false );
	$md_alt_product->set_attributes( array( $md_alt_visible, $md_alt_weight, $md_alt_hidden ) );
	$md_alt_product->save();
	$md_alt_output = md_alt_woo_render( $md_alt_id );
	md_alt_woo_assert( false !== strpos( $md_alt_output, 'Cotton, Linen' ) && false === strpos( $md_alt_output, 'DO NOT EXPOSE' ), 'Only visible attributes included' );
	md_alt_woo_assert( false !== strpos( $md_alt_output, '1.5 kg (approx.) \\*heavy\\*' ), 'Attribute values escape only inline Markdown characters' );

	$md_alt_variable = new WC_Product_Variable();
	$md_alt_variable->set_name( 'Markdown variable' );
	$md_alt_variable->set_status( 'publish' );
	$md_alt_size = new WC_Product_Attribute();
	$md_alt_size->set_name( 'Size' );
	$md_alt_size->set_options( array( 'Small', 'Large', 'Hidden' ) );
	$md_alt_size->set_visible( true );
	$md_alt_size->set_variation( true );
	$md_alt_variable->set_attributes( array( $md_alt_size ) );
	$md_alt_woo_ids[] = $md_alt_variable->save();
	$md_alt_children  = array();
	foreach ( array(
		'Small'  => '10',
		'Large'  => '30',
		'Hidden' => '50',
	) as $md_alt_size_name => $md_alt_price ) {
		$md_alt_variation = new WC_Product_Variation();
		$md_alt_variation->set_parent_id( $md_alt_variable->get_id() );
		$md_alt_variation->set_attributes( array( 'size' => $md_alt_size_name ) );
		$md_alt_variation->set_regular_price( $md_alt_price );
		$md_alt_variation->set_status( 'Hidden' === $md_alt_size_name ? 'private' : 'publish' );
		$md_alt_variation->set_sku( 'md-alt-' . $md_alt_size_name . '-' . wp_generate_uuid4() );
		$md_alt_woo_ids[]                     = $md_alt_variation->save();
		$md_alt_children[ $md_alt_size_name ] = $md_alt_variation;
	}
	WC_Product_Variable::sync( $md_alt_variable->get_id() );
	$md_alt_output     = md_alt_woo_render( $md_alt_variable->get_id() );
	$md_alt_variations = explode( '## Variations', $md_alt_output )[1];
	md_alt_woo_assert( false !== strpos( $md_alt_output, 'price_max: 30' ) && false !== strpos( $md_alt_variations, 'Size: Small' ) && false !== strpos( $md_alt_variations, 'Size: Large' ), 'Variable prices and human-readable variation labels rendered' );
	md_alt_woo_assert( false === strpos( $md_alt_variations, 'Size: Hidden' ), 'Disabled variation excluded' );
	$md_alt_wildcard = new WC_Product_Variation();
	$md_alt_wildcard->set_parent_id( $md_alt_variable->get_id() );
	$md_alt_wildcard->set_attributes( array( 'size' => '' ) );
	$md_alt_wildcard->set_regular_price( '40' );
	$md_alt_woo_ids[] = $md_alt_wildcard->save();
	WC_Product_Variable::sync( $md_alt_variable->get_id() );
	md_alt_woo_assert( false !== strpos( md_alt_woo_render( $md_alt_variable->get_id() ), 'Any Size' ), 'Wildcard variations use a readable attribute label' );
	$md_alt_children['Large']->set_stock_status( 'outofstock' );
	$md_alt_children['Large']->save();
	update_option( 'woocommerce_hide_out_of_stock_items', 'yes' );
	$md_alt_output     = md_alt_woo_render( $md_alt_variable->get_id() );
	$md_alt_variations = explode( '## Variations', $md_alt_output )[1];
	md_alt_woo_assert( false === strpos( $md_alt_variations, 'Size: Large' ), 'Hide-out-of-stock setting respected immediately' );

	$md_alt_price_filter = static function () {
		return '7';
	};
	add_filter( 'woocommerce_product_get_price', $md_alt_price_filter );
	$md_alt_output = md_alt_woo_render( $md_alt_id );
	md_alt_woo_assert( false !== strpos( $md_alt_output, "price: 7\n" ), 'Customer pricing filter reflected immediately' );
	remove_filter( 'woocommerce_product_get_price', $md_alt_price_filter );
	md_alt_woo_assert( false !== strpos( md_alt_woo_render( $md_alt_id ), "price: 20\n" ), 'Customer pricing never leaks into the next render' );

	update_option( 'woocommerce_calc_taxes', 'yes' );
	update_option( 'woocommerce_default_country', 'NL' );
	update_option( 'woocommerce_tax_display_shop', 'incl' );
	$md_alt_tax_id = WC_Tax::_insert_tax_rate(
		array(
			'tax_rate_country'  => 'NL',
			'tax_rate'          => '21',
			'tax_rate_name'     => 'Test VAT',
			'tax_rate_priority' => 1,
			'tax_rate_compound' => 0,
			'tax_rate_shipping' => 1,
			'tax_rate_order'    => 0,
			'tax_rate_class'    => '',
		)
	);
	WC_Cache_Helper::invalidate_cache_group( 'taxes' );
	WC()->customer = new WC_Customer( 0, true );
	WC()->customer->set_billing_country( 'NL' );
	WC()->customer->set_shipping_country( 'NL' );
	$md_alt_output = md_alt_woo_render( $md_alt_id );
	md_alt_woo_assert( false !== strpos( $md_alt_output, '24.20' ), 'Displayed price includes shop tax settings' );
	// Tax-inclusive catalog prices exercise exemption consistently on older WooCommerce too.
	update_option( 'woocommerce_prices_include_tax', 'yes' );
	$md_alt_product->set_regular_price( '24.20' );
	$md_alt_product->set_price( '24.20' );
	$md_alt_product->save();
	WC()->customer->set_is_vat_exempt( true );
	$md_alt_output = md_alt_woo_render( $md_alt_id );
	md_alt_woo_assert( false !== strpos( $md_alt_output, '**Price:** €20.00' ), 'VAT-exempt customer receives their own displayed price' );
	md_alt_woo_assert( false !== strpos( $md_alt_output, "prices_include_tax: true\n" ) && false !== strpos( $md_alt_output, "price: 24.20\n" ), 'Tax-inclusive catalog prices are flagged in frontmatter' );

	$md_alt_post_id   = wp_insert_post(
		array(
			'post_title'   => 'Ordinary post',
			'post_content' => '<p>Ordinary content</p>',
			'post_status'  => 'publish',
		)
	);
	$md_alt_woo_ids[] = $md_alt_post_id;
	$md_alt_output    = md_alt_woo_render( $md_alt_post_id );
	md_alt_woo_assert( false !== get_transient( 'md_alt_cache_' . $md_alt_post_id ) && false === strpos( $md_alt_output, 'product_type:' ), 'Ordinary post output still cached without product metadata' );
	if ( getenv( 'MD_ALT_TEST_HTTP' ) ) {
		$md_alt_permalink = get_permalink( $md_alt_id );
		$md_alt_md_url    = untrailingslashit( $md_alt_permalink ) . '.md';
		foreach ( array( $md_alt_md_url, add_query_arg( 'format', 'markdown', $md_alt_permalink ) ) as $md_alt_url ) {
			$md_alt_response = wp_remote_get( $md_alt_url );
			md_alt_woo_assert( 200 === wp_remote_retrieve_response_code( $md_alt_response ) && false !== strpos( wp_remote_retrieve_body( $md_alt_response ), 'product_type: "simple"' ), 'Product HTTP route returns Markdown: ' . $md_alt_url );
			md_alt_woo_assert( false !== strpos( wp_remote_retrieve_header( $md_alt_response, 'content-type' ), 'text/markdown' ) && false !== strpos( wp_remote_retrieve_header( $md_alt_response, 'cache-control' ), 'no-store' ), 'Product HTTP response prevents shared caching' );
		}
		$md_alt_response = wp_remote_get(
			$md_alt_permalink,
			array(
				'headers'     => array( 'Accept' => 'text/markdown' ),
				'redirection' => 0,
			)
		);
		md_alt_woo_assert( 303 === wp_remote_retrieve_response_code( $md_alt_response ) && wp_remote_retrieve_header( $md_alt_response, 'location' ) === $md_alt_md_url, 'Accept negotiation redirects to product Markdown' );
		wp_update_post(
			array(
				'ID'            => $md_alt_id,
				'post_password' => 'private-test-password',
			)
		);
		$md_alt_response = wp_remote_get( $md_alt_md_url );
		md_alt_woo_assert( 403 === wp_remote_retrieve_response_code( $md_alt_response ) && false === strpos( wp_remote_retrieve_body( $md_alt_response ), 'product_type:' ), 'Password-protected products remain protected' );
		wp_update_post(
			array(
				'ID'            => $md_alt_id,
				'post_status'   => 'draft',
				'post_password' => '',
			)
		);
		$md_alt_response = wp_remote_get( $md_alt_md_url );
		md_alt_woo_assert( 404 === wp_remote_retrieve_response_code( $md_alt_response ) && false === strpos( wp_remote_retrieve_body( $md_alt_response ), 'product_type:' ), 'Unpublished products return 404' );
	}
	// Term archives: product categories, nested, with catalog visibility and the list limit.
	$md_alt_parent_term = wp_insert_term( 'Markdown archive parent ' . wp_generate_uuid4(), 'product_cat' );
	$md_alt_child_term  = wp_insert_term( 'Markdown archive child ' . wp_generate_uuid4(), 'product_cat', array( 'parent' => $md_alt_parent_term['term_id'] ) );
	$md_alt_terms[]     = array( 'product_cat', $md_alt_child_term['term_id'] );
	$md_alt_terms[]     = array( 'product_cat', $md_alt_parent_term['term_id'] );
	$md_alt_archive_ids = array();
	foreach ( array(
		'Archive visible one' => 'visible',
		'Archive visible two' => 'visible',
		'Archive hidden'      => 'hidden',
	) as $md_alt_name => $md_alt_visibility ) {
		$md_alt_archive_product = new WC_Product_Simple();
		$md_alt_archive_product->set_name( $md_alt_name );
		$md_alt_archive_product->set_status( 'publish' );
		$md_alt_archive_product->set_regular_price( '5' );
		$md_alt_archive_product->set_catalog_visibility( $md_alt_visibility );
		$md_alt_archive_product->set_category_ids( array( (int) $md_alt_child_term['term_id'] ) );
		$md_alt_woo_ids[]     = $md_alt_archive_product->save();
		$md_alt_archive_ids[] = $md_alt_archive_product->get_id();
	}
	wp_update_term_count_now( array( (int) $md_alt_child_term['term_id'], (int) $md_alt_parent_term['term_id'] ), 'product_cat' );
	$md_alt_parent = get_term( (int) $md_alt_parent_term['term_id'], 'product_cat' );
	$md_alt_child  = get_term( (int) $md_alt_child_term['term_id'], 'product_cat' );
	md_alt_woo_assert( TaxonomySupport::is_supported( 'product_cat' ) && TaxonomySupport::is_supported( 'product_tag' ) && ! TaxonomySupport::is_supported( 'product_shipping_class' ), 'Product categories and tags are served; other product taxonomies are not' );
	$md_alt_archive = ( new TermRenderer() )->render( $md_alt_child );
	md_alt_woo_assert( false !== strpos( $md_alt_archive, 'taxonomy: "product_cat"' ) && false !== strpos( $md_alt_archive, 'currency: "EUR"' ), 'Product archive frontmatter has taxonomy and currency' );
	md_alt_woo_assert( false !== strpos( $md_alt_archive, 'url: "' . UrlConverter::convert_to_markdown_url( get_term_link( $md_alt_parent ) ) . '"' ), 'Product archive links its parent to Markdown' );
	md_alt_woo_assert( false !== strpos( $md_alt_archive, '- [Archive visible one](' . UrlConverter::convert_to_markdown_url( get_permalink( $md_alt_archive_ids[0] ) ) . '): ' ), 'Product archive lists products with Markdown links' );
	md_alt_woo_assert( false === strpos( $md_alt_archive, 'Archive hidden' ), 'Product archive leaves out products hidden from the catalog' );
	md_alt_woo_assert( false !== strpos( ( new TermRenderer() )->render( $md_alt_parent ), '](' . UrlConverter::convert_to_markdown_url( get_term_link( $md_alt_child ) ) . ') (' ), 'Parent archive lists its subcategories' );
	$md_alt_limit = static function () {
		return 1;
	};
	add_filter( 'markdown_alternate_term_posts_limit', $md_alt_limit );
	$md_alt_archive = ( new TermRenderer() )->render( $md_alt_child );
	remove_filter( 'markdown_alternate_term_posts_limit', $md_alt_limit );
	md_alt_woo_assert( 1 === substr_count( $md_alt_archive, '- [Archive visible' ) && false !== strpos( $md_alt_archive, '](' . get_term_link( $md_alt_child ) . ')' ), 'Product archive limit links to the HTML archive for the rest' );
	md_alt_woo_assert( false !== strpos( md_alt_woo_render( $md_alt_archive_ids[0] ), 'url: "' . rtrim( get_term_link( $md_alt_child ), '/' ) . '.md"' ), 'Product frontmatter links its category to the served Markdown archive' );

	require __DIR__ . '/term-integration.php';
	WP_CLI::success( 'WooCommerce integration tests passed.' );
} finally {
	foreach ( array_reverse( $md_alt_woo_ids ) as $md_alt_cleanup_id ) {
		wp_delete_post( $md_alt_cleanup_id, true );
		delete_transient( 'md_alt_cache_' . $md_alt_cleanup_id );
	}
	foreach ( $md_alt_woo_options as $md_alt_option => $md_alt_original_value ) {
		if ( false === $md_alt_original_value ) {
			delete_option( $md_alt_option );
		} else {
			update_option( $md_alt_option, $md_alt_original_value );
		}
	}
	foreach ( $md_alt_terms as list( $md_alt_taxonomy, $md_alt_term_id ) ) {
		wp_delete_term( $md_alt_term_id, $md_alt_taxonomy );
	}
	if ( $md_alt_tax_id ) {
		WC_Tax::_delete_tax_rate( $md_alt_tax_id );
		WC_Cache_Helper::invalidate_cache_group( 'taxes' );
	}
}
