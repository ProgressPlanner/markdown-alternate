<?php
/**
 * Real WordPress/WooCommerce regression tests for an isolated test site.
 *
 * Run: wp eval-file /path/to/tests/woocommerce-integration.php
 *
 * @package MarkdownAlternate
 */

use MarkdownAlternate\Output\ContentRenderer;
use MarkdownAlternate\PostTypeSupport;

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
foreach ( array( 'woocommerce_currency', 'woocommerce_hide_out_of_stock_items', 'woocommerce_calc_taxes', 'woocommerce_prices_include_tax', 'woocommerce_tax_display_shop', 'woocommerce_default_country' ) as $md_alt_option ) {
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
	md_alt_woo_assert( false !== strpos( $md_alt_output, '[link](https://example.com)' ) && false !== strpos( $md_alt_output, '- One' ), 'Short description keeps links and lists' );
	md_alt_woo_assert( false === strpos( $md_alt_output, '## Attributes' ) && false === strpos( $md_alt_output, '## Variations' ), 'No empty product sections' );
	md_alt_woo_assert( false === get_transient( 'md_alt_cache_' . $md_alt_id ), 'Product output is not stored in the shared cache' );

	$md_alt_product->set_date_on_sale_from( time() + DAY_IN_SECONDS );
	$md_alt_product->set_price( '20' );
	$md_alt_product->save();
	$md_alt_output = md_alt_woo_render( $md_alt_id );
	md_alt_woo_assert( false === strpos( $md_alt_output, 'sale_price:' ) && false !== strpos( $md_alt_output, "price: 20\n" ), 'Future sale is not advertised as active' );

	$md_alt_visible = new WC_Product_Attribute();
	$md_alt_visible->set_name( 'Material' );
	$md_alt_visible->set_options( array( 'Cotton', 'Linen' ) );
	$md_alt_visible->set_visible( true );
	$md_alt_hidden = new WC_Product_Attribute();
	$md_alt_hidden->set_name( 'Internal note' );
	$md_alt_hidden->set_options( array( 'DO NOT EXPOSE' ) );
	$md_alt_hidden->set_visible( false );
	$md_alt_product->set_attributes( array( $md_alt_visible, $md_alt_hidden ) );
	$md_alt_product->save();
	$md_alt_output = md_alt_woo_render( $md_alt_id );
	md_alt_woo_assert( false !== strpos( $md_alt_output, 'Cotton, Linen' ) && false === strpos( $md_alt_output, 'DO NOT EXPOSE' ), 'Only visible attributes included' );

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
