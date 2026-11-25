<?php // phpcs:ignore WordPress.Files.FileName -- PSR-4 class filename matches the existing integration layout.
/**
 * WooCommerce integration, adapted from the product sections in PR #37.
 *
 * @package MarkdownAlternate
 */

namespace MarkdownAlternate\Integration;

use MarkdownAlternate\Converter\MarkdownConverter;
use MarkdownAlternate\PostTypeSupport;
use MarkdownAlternate\Router\UrlConverter;
use WP_Post;

/**
 * Adds product metadata and sections through the public rendering hooks.
 */
class WooCommerce {

	/**
	 * Converter reused across price, description and variation conversions.
	 *
	 * @var MarkdownConverter|null
	 */
	private $converter;

	/**
	 * Register hooks when WooCommerce is active.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'markdown_alternate_supported_post_types', array( $this, 'add_product_post_type' ) );
		add_filter( 'markdown_alternate_frontmatter_taxonomies', array( $this, 'add_taxonomies' ), 10, 2 );
		add_filter( 'markdown_alternate_frontmatter_content_lines', array( $this, 'add_frontmatter' ), 10, 2 );
		add_filter( 'markdown_alternate_content_sections', array( $this, 'add_sections' ), 10, 2 );
		add_filter( 'markdown_alternate_cache_enabled', array( $this, 'cache_enabled' ), 10, 2 );
		add_filter( 'markdown_alternate_supported_taxonomies', array( $this, 'add_supported_taxonomies' ) );
		add_filter( 'markdown_alternate_term_query_args', array( $this, 'term_query_args' ), 10, 2 );
		add_filter( 'markdown_alternate_term_item', array( $this, 'term_item' ), 10, 3 );
		add_filter( 'markdown_alternate_term_frontmatter_content_lines', array( $this, 'term_frontmatter' ), 10, 2 );
		add_filter( 'markdown_alternate_term_cache_enabled', array( $this, 'term_cache_enabled' ), 10, 2 );
	}

	/**
	 * Product taxonomies whose archives are served as markdown.
	 *
	 * @return string[] Taxonomy names.
	 */
	private function product_taxonomies(): array {
		$taxonomies = array( 'product_cat', 'product_tag' );
		if ( taxonomy_exists( 'product_brand' ) ) {
			$taxonomies[] = 'product_brand';
		}
		return $taxonomies;
	}

	/**
	 * Serve product category, tag and brand archives, which product frontmatter links to.
	 *
	 * @param mixed $taxonomies Supported taxonomies.
	 * @return string[] Supported taxonomies.
	 */
	public function add_supported_taxonomies( $taxonomies ): array {
		$taxonomies = is_array( $taxonomies ) ? $taxonomies : array();
		return array_values( array_unique( array_merge( $taxonomies, $this->product_taxonomies() ) ) );
	}

	/**
	 * List a product archive's products as the shop does: catalog order, catalog visibility.
	 *
	 * @param mixed    $args WP_Query arguments.
	 * @param \WP_Term $term The term being rendered.
	 * @return array<string, mixed> WP_Query arguments.
	 */
	public function term_query_args( $args, $term ): array {
		$args = is_array( $args ) ? $args : array();
		if ( ! in_array( $term->taxonomy, $this->product_taxonomies(), true ) ) {
			return $args;
		}
		$args['post_type'] = array( 'product' );
		$args['orderby']   = array(
			'menu_order' => 'ASC',
			'title'      => 'ASC',
		);
		// Hidden products, and out of stock ones when the shop hides them, are not in the catalog.
		$visibility = wc_get_product_visibility_term_ids();
		$hidden     = array( $visibility['exclude-from-catalog'] ?? 0 );
		if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) ) {
			$hidden[] = $visibility['outofstock'] ?? 0;
		}
		$args['tax_query'][] = array(
			'taxonomy' => 'product_visibility',
			'field'    => 'term_taxonomy_id',
			'terms'    => array_values( array_filter( $hidden ) ),
			'operator' => 'NOT IN',
		);
		return $args;
	}

	/**
	 * A product in an archive: name linked to its markdown, displayed price and availability.
	 *
	 * @param string   $row  Default list item.
	 * @param mixed    $post The post.
	 * @param \WP_Term $term The term being rendered.
	 * @return string List item.
	 */
	public function term_item( $row, $post, $term ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Filter signature.
		$product = $post instanceof WP_Post ? $this->get_product( $post ) : null;
		if ( ! $product ) {
			return (string) $row;
		}
		$details      = array();
		$price        = $this->format_price( $product );
		$stock_labels = wc_get_product_stock_status_options();
		$status       = $product->get_stock_status();
		if ( '' !== $price ) {
			$details[] = $price;
		}
		$details[] = $this->plain_markdown( $stock_labels[ $status ] ?? $status );
		$url       = (string) get_permalink( $product->get_id() );
		if ( ! PostTypeSupport::should_skip_noindexed( $post ) ) {
			$url = UrlConverter::convert_to_markdown_url( $url );
		}
		return '- [' . str_replace( array( '[', ']' ), array( '\\[', '\\]' ), $this->plain_text( $product->get_name() ) ) . '](' . $url . '): ' . implode( ', ', $details );
	}

	/**
	 * State the currency of the prices a product archive lists.
	 *
	 * @param mixed    $fields Frontmatter fields.
	 * @param \WP_Term $term   The term being rendered.
	 * @return array<string, mixed> Frontmatter fields.
	 */
	public function term_frontmatter( $fields, $term ): array {
		$fields = is_array( $fields ) ? $fields : array();
		if ( in_array( $term->taxonomy, $this->product_taxonomies(), true ) ) {
			$fields['currency'] = array(
				'type'    => 'string',
				'content' => get_woocommerce_currency(),
			);
		}
		return $fields;
	}

	/**
	 * Product archives list prices, which may depend on the customer, like product pages.
	 *
	 * @param bool     $enabled Whether the shared cache is enabled.
	 * @param \WP_Term $term    The term being served.
	 * @return bool Whether the shared cache is enabled.
	 */
	public function term_cache_enabled( $enabled, $term ): bool {
		return in_array( $term->taxonomy, $this->product_taxonomies(), true ) ? false : (bool) $enabled;
	}

	/**
	 * Support product permalinks without exposing standalone variations.
	 *
	 * @param array $types Supported post types.
	 * @return array Supported post types.
	 */
	public function add_product_post_type( $types ): array {
		$types = is_array( $types ) ? $types : array();
		if ( ! in_array( 'product', $types, true ) ) {
			$types[] = 'product';
		}
		return $types;
	}

	/**
	 * Product prices may depend on customer location, currency or pricing plugins.
	 *
	 * @param bool    $enabled Whether the shared cache is enabled.
	 * @param WP_Post $post    The post being rendered.
	 * @return bool Whether the shared cache is enabled.
	 */
	public function cache_enabled( $enabled, $post ): bool {
		return 'product' === $post->post_type ? false : (bool) $enabled;
	}

	/**
	 * Use product taxonomies with the same field names as posts and extensions.
	 *
	 * @param array   $taxonomies Taxonomy to frontmatter key mapping.
	 * @param WP_Post $post       The post being rendered.
	 * @return array Taxonomy mapping.
	 */
	public function add_taxonomies( $taxonomies, $post ): array {
		$taxonomies = is_array( $taxonomies ) ? $taxonomies : array();
		if ( 'product' !== $post->post_type ) {
			return $taxonomies;
		}
		unset( $taxonomies['category'], $taxonomies['post_tag'] );
		$taxonomies['product_cat'] = 'categories';
		$taxonomies['product_tag'] = 'tags';
		if ( taxonomy_exists( 'product_brand' ) ) {
			$taxonomies['product_brand'] = 'brands';
		}
		return $taxonomies;
	}

	/**
	 * Add typed product metadata using the API shared with existing extensions.
	 *
	 * Prices are numeric catalog values; the body uses WooCommerce display HTML.
	 *
	 * @param array   $fields Frontmatter fields.
	 * @param WP_Post $post   The post being rendered.
	 * @return array Frontmatter fields.
	 */
	public function add_frontmatter( $fields, $post ): array {
		$fields  = is_array( $fields ) ? $fields : array();
		$product = $this->get_product( $post );
		if ( ! $product ) {
			return $fields;
		}

		// The post author is the shop user who created the product, not product information.
		// Products are registered without author support, and WooCommerce never displays it.
		unset( $fields['author'] );

		$values = array(
			'url'          => array( 'url', get_permalink( $product->get_id() ) ),
			'modified'     => array( 'date', $this->format_date( $product->get_date_modified() ) ),
			'product_type' => array( 'string', $product->get_type() ),
			'currency'     => array( 'string', get_woocommerce_currency() ),
			'sku'          => array( 'string', $product->get_sku() ),
			// GTIN, UPC, EAN or ISBN: WooCommerce stores one global identifier without its kind (9.2+).
			'gtin'         => array( 'string', method_exists( $product, 'get_global_unique_id' ) ? $product->get_global_unique_id() : '' ),
			'stock_status' => array( 'string', $product->get_stock_status() ),
		);
		if ( $product->is_type( 'variable' ) ) {
			$values['price']     = array( 'number', $product->get_variation_price( 'min' ) );
			$values['price_max'] = array( 'number', $product->get_variation_price( 'max' ) );
		} elseif ( ! $product->is_type( 'grouped' ) ) {
			$values['price']         = array( 'number', $product->get_price() );
			$values['regular_price'] = array( 'number', $product->get_regular_price() );
			if ( $product->is_on_sale() ) {
				$values['sale_price'] = array( 'number', $product->get_sale_price() );
				$values['sale_ends']  = array( 'date', $this->format_date( $product->get_date_on_sale_to() ) );
			}
		}
		if ( $product->managing_stock() ) {
			$values['stock_quantity'] = array( 'number', $product->get_stock_quantity() );
		}
		if ( wc_review_ratings_enabled() && $product->get_rating_count() > 0 ) {
			$values['rating']       = array( 'number', $product->get_average_rating() );
			$values['review_count'] = array( 'number', $product->get_review_count() );
		}
		foreach ( $values as $key => list( $type, $value ) ) {
			if ( '' !== $value && null !== $value ) {
				$fields[ $key ] = array(
					'type'    => $type,
					'content' => $value,
				);
			}
		}
		if ( isset( $values['price'] ) && '' !== $values['price'][1] && null !== $values['price'][1] ) {
			// Catalog prices do not depend on the requester; state whether they include tax.
			$fields['prices_include_tax'] = array(
				'type'    => 'boolean',
				'content' => wc_prices_include_tax(),
			);
		}
		return $fields;
	}

	/**
	 * Format a WooCommerce date like the core frontmatter date field.
	 *
	 * @param \WC_DateTime|null $date Date or null when not set.
	 * @return string Y-m-d in the site timezone, or an empty string.
	 */
	private function format_date( $date ): string {
		return $date ? $date->date_i18n( 'Y-m-d' ) : '';
	}

	/**
	 * Add product details and label the long description.
	 *
	 * @param array   $sections Existing sections.
	 * @param WP_Post $post     The post being rendered.
	 * @return array Ordered section descriptors.
	 */
	public function add_sections( $sections, $post ): array {
		$sections = is_array( $sections ) ? $sections : array();
		$product  = $this->get_product( $post );
		if ( ! $product ) {
			return $sections;
		}
		$rows  = array();
		$price = $this->format_price( $product );
		if ( '' !== $price ) {
			if ( 'incl' === get_option( 'woocommerce_tax_display_shop' ) && $product->is_taxable() && ( ! WC()->customer || ! WC()->customer->get_is_vat_exempt() ) ) {
				$price .= ' ' . __( '(incl. tax)', 'markdown-alternate' );
			}
			$rows[] = '**' . __( 'Price:', 'markdown-alternate' ) . '** ' . $price;
		}
		if ( '' !== $product->get_sku() ) {
			$rows[] = '**' . __( 'SKU:', 'markdown-alternate' ) . '** ' . $this->code_span( $product->get_sku() );
		}
		$stock_labels            = wc_get_product_stock_status_options();
		$status                  = $product->get_stock_status();
		$rows[]                  = '**' . __( 'Availability:', 'markdown-alternate' ) . '** ' . $this->plain_markdown( $stock_labels[ $status ] ?? $status );
		$sections['woo_summary'] = array(
			'priority' => 10,
			'markdown' => implode( "\n\n", $rows ),
		);

		$description = $this->to_markdown( apply_filters( 'woocommerce_short_description', $product->get_short_description() ) );
		if ( '' !== $description ) {
			$sections['woo_short_description'] = array(
				'priority' => 50,
				'markdown' => '## ' . __( 'Summary', 'markdown-alternate' ) . "\n\n" . $description,
			);
		}
		if ( isset( $sections['content']['markdown'] ) && is_string( $sections['content']['markdown'] ) && '' !== trim( $sections['content']['markdown'] ) ) {
			$sections['content']['markdown'] = '## ' . __( 'Description', 'markdown-alternate' ) . "\n\n" . $sections['content']['markdown'];
		}
		$attributes = $this->build_attributes( $product );
		if ( '' !== $attributes ) {
			$sections['woo_attributes'] = array(
				'priority' => 150,
				'markdown' => $attributes,
			);
		}
		$variations = $this->build_variations( $product );
		if ( '' !== $variations ) {
			$sections['woo_variations'] = array(
				'priority' => 160,
				'markdown' => $variations,
			);
		}
		return $sections;
	}

	/**
	 * Render attributes that are visible on the product page.
	 *
	 * @param \WC_Product $product Product object.
	 * @return string Markdown section or empty string.
	 */
	private function build_attributes( $product ): string {
		$rows = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute->get_visible() ) {
				continue;
			}
			$values = $attribute->is_taxonomy()
				? wc_get_product_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'names' ) )
				: $attribute->get_options();
			if ( ! is_array( $values ) || ! $values ) {
				continue;
			}
			$rows[] = '- **' . $this->plain_markdown( wc_attribute_label( $attribute->get_name(), $product ) ) . ':** ' . $this->plain_markdown( implode( ', ', $values ) );
		}
		return $rows ? '## ' . __( 'Attributes', 'markdown-alternate' ) . "\n\n" . implode( "\n", $rows ) : '';
	}

	/**
	 * List available variations, respecting WooCommerce visibility and stock rules.
	 *
	 * @param \WC_Product $product Product object.
	 * @return string Markdown section or empty string.
	 */
	private function build_variations( $product ): string {
		if ( ! $product->is_type( 'variable' ) ) {
			return '';
		}
		$rows = array();
		foreach ( $product->get_available_variations( 'objects' ) as $variation ) {
			$label = wc_get_formatted_variation( $variation, true, true, false );
			foreach ( $variation->get_attributes() as $name => $value ) {
				if ( '' === $value ) {
					/* translators: %s: product attribute label, such as Size. */
					$any    = sprintf( __( 'Any %s', 'markdown-alternate' ), wc_attribute_label( $name, $product ) );
					$label .= ( '' !== $label ? ', ' : '' ) . $any;
				}
			}
			if ( '' === $label ) {
				$label = '#' . $variation->get_id();
			}
			$details = array();
			$price   = $this->format_price( $variation );
			if ( '' !== $price ) {
				$details[] = $price;
			}
			if ( '' !== $variation->get_sku() ) {
				$details[] = __( 'SKU:', 'markdown-alternate' ) . ' ' . $this->code_span( $variation->get_sku() );
			}
			$rows[] = '- ' . $this->plain_markdown( $label ) . ( $details ? ': ' . implode( ', ', $details ) : '' );
		}
		return $rows ? '## ' . __( 'Variations', 'markdown-alternate' ) . "\n\n" . implode( "\n", $rows ) : '';
	}

	/**
	 * Resolve products without affecting other post types.
	 *
	 * @param WP_Post $post Current post.
	 * @return \WC_Product|null Product object.
	 */
	private function get_product( WP_Post $post ) {
		$product = 'product' === $post->post_type ? wc_get_product( $post->ID ) : null;
		return $product instanceof \WC_Product ? $product : null;
	}

	/**
	 * Retain WooCommerce pricing filters, ranges, taxes and sale semantics.
	 *
	 * Remove duplicated screen-reader labels and preserve deleted prices as
	 * Markdown strikethrough, which the general converter does not support.
	 *
	 * @param \WC_Product $product Product object.
	 * @return string Display price in Markdown.
	 */
	private function format_price( $product ): string {
		$html = trim( (string) $product->get_price_html() );
		if ( '' === $html ) {
			return '';
		}
		$document = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		try {
			$document->loadHTML( '<?xml encoding="UTF-8" ?>' . $html, LIBXML_NONET );
			$xpath = new \DOMXPath( $document );
			foreach ( $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " screen-reader-text ")]' ) as $node ) {
				$node->parentNode->removeChild( $node ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native DOM API.
			}
			foreach ( $xpath->query( '//del' ) as $node ) {
				$node->parentNode->replaceChild( $document->createTextNode( '~~' . $node->textContent . '~~ ' ), $node ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native DOM API.
			}
			$body = $document->getElementsByTagName( 'body' )->item( 0 );
			$html = $body ? $document->saveHTML( $body ) : '';
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
		return str_replace( "\xc2\xa0", ' ', $this->to_markdown( $html ) );
	}

	/**
	 * Convert WooCommerce display HTML, preserving links and formatted prices.
	 *
	 * @param string $html WooCommerce display HTML.
	 * @return string Markdown.
	 */
	private function to_markdown( $html ): string {
		try {
			if ( null === $this->converter ) {
				$this->converter = new MarkdownConverter();
			}
			return $this->converter->convert( (string) $html );
		} catch ( \Throwable $error ) {
			return $this->plain_markdown( $html );
		}
	}

	/**
	 * Flatten a plain value to one line of text.
	 *
	 * @param string $value Plain text or HTML.
	 * @return string Decoded single-line text.
	 */
	private function plain_text( $value ): string {
		$value = html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( preg_replace( '/\s+/u', ' ', $value ) );
	}

	/**
	 * Escape a plain value so product text cannot create Markdown structure.
	 *
	 * Only characters that act inline are escaped everywhere, plus a leading
	 * block marker (">", or "#", "+", "-", "1.", "1)" followed by a space),
	 * because variation labels start a list item. Values such as
	 * "1.5 kg (approx.)" therefore stay readable.
	 *
	 * @param string $value Plain text or a formatted variation label.
	 * @return string Escaped single-line Markdown.
	 */
	private function plain_markdown( $value ): string {
		$value = preg_replace( '/([\\\\`*_\[\]<>~])/u', '\\\\$1', $this->plain_text( $value ) );
		$value = preg_replace( '/^(>|[#+\-](?=\s|$))/u', '\\\\$1', $value );
		return preg_replace( '/^(\d+)([.)])(?=\s|$)/u', '$1\\\\$2', $value );
	}

	/**
	 * Wrap an identifier such as a SKU in a code span so it is copied verbatim.
	 *
	 * @param string $value Identifier.
	 * @return string Markdown code span.
	 */
	private function code_span( $value ): string {
		$value = $this->plain_text( $value );
		preg_match_all( '/`+/', $value, $runs );
		$fence = str_repeat( '`', $runs[0] ? max( array_map( 'strlen', $runs[0] ) ) + 1 : 1 );
		$pad   = ( '`' === substr( $value, 0, 1 ) || '`' === substr( $value, -1 ) ) ? ' ' : '';
		return $fence . $pad . $value . $pad . $fence;
	}
}
