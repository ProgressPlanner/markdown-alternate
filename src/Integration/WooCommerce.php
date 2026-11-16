<?php
/**
 * WooCommerce integration.
 *
 * Adds the `product` post type to the supported list and enriches the
 * markdown output with product-specific data (price, stock, SKU, attributes,
 * variations) which lives outside `post_content`.
 *
 * @package MarkdownAlternate
 */

namespace MarkdownAlternate\Integration;

use WP_Post;

/**
 * Wires WooCommerce products into the markdown rendering pipeline.
 */
class WooCommerce {

    /**
     * Register hooks.
     *
     * @return void
     */
    public function register(): void {
        add_filter( 'markdown_alternate_supported_post_types', [ $this, 'add_product_post_type' ] );
        add_filter( 'markdown_alternate_frontmatter_content_lines', [ $this, 'add_frontmatter' ], 10, 2 );
        add_filter( 'markdown_alternate_content_sections', [ $this, 'add_sections' ], 10, 2 );

        // Invalidate cache when product data changes outside of post_modified.
        add_action( 'woocommerce_update_product', [ $this, 'invalidate_cache' ] );
        add_action( 'woocommerce_product_set_stock', [ $this, 'invalidate_cache_from_product' ] );
        add_action( 'woocommerce_variation_set_stock', [ $this, 'invalidate_cache_from_product' ] );
    }

    /**
     * Add `product` to the list of supported post types.
     *
     * @param array $types Existing supported post types.
     * @return array
     */
    public function add_product_post_type( $types ): array {
        if ( ! is_array( $types ) ) {
            $types = [];
        }
        if ( ! in_array( 'product', $types, true ) ) {
            $types[] = 'product';
        }
        return $types;
    }

    /**
     * Add product fields to the typed YAML frontmatter.
     *
     * Hooked to `markdown_alternate_frontmatter_content_lines`. Scalar fields are
     * `[ 'type' => ..., 'content' => ... ]`; list fields are
     * `[ 'type' => 'array', 'items' => [ [ key => scalar field, ... ], ... ] ]`.
     *
     * @param array   $lines Typed frontmatter fields keyed by YAML name.
     * @param WP_Post $post  The post being rendered.
     * @return array
     */
    public function add_frontmatter( $lines, $post ): array {
        if ( ! is_array( $lines ) ) {
            $lines = [];
        }
        if ( ! ( $post instanceof WP_Post ) || $post->post_type !== 'product' ) {
            return $lines;
        }

        $product = $this->get_product( $post->ID );
        if ( ! $product ) {
            return $lines;
        }

        $string = static function ( $value ): array {
            return [ 'type' => 'string', 'content' => (string) $value ];
        };

        $sku = $product->get_sku();
        if ( $sku !== '' ) {
            $lines['sku'] = $string( $sku );
        }

        $lines['product_type'] = $string( $product->get_type() );
        if ( function_exists( 'get_woocommerce_currency' ) ) {
            $lines['currency'] = $string( get_woocommerce_currency() );
        }

        $price      = $product->get_price();
        $regular    = $product->get_regular_price();
        $sale_price = $product->get_sale_price();
        if ( $price !== '' && $price !== null ) {
            $lines['price'] = $string( $price );
        }
        if ( $regular !== '' && $regular !== null ) {
            $lines['regular_price'] = $string( $regular );
        }
        if ( $sale_price !== '' && $sale_price !== null ) {
            $lines['sale_price'] = $string( $sale_price );
        }

        $lines['stock_status'] = $string( $product->get_stock_status() );
        if ( $product->managing_stock() ) {
            $stock_qty = $product->get_stock_quantity();
            if ( $stock_qty !== null ) {
                $lines['stock_quantity'] = [ 'type' => 'number', 'content' => (int) $stock_qty ];
            }
        }

        $product_categories = $this->collect_product_terms( $post->ID, 'product_cat' );
        if ( $product_categories ) {
            $lines['product_categories'] = [ 'type' => 'array', 'items' => $product_categories ];
        }

        $product_tags = $this->collect_product_terms( $post->ID, 'product_tag' );
        if ( $product_tags ) {
            $lines['product_tags'] = [ 'type' => 'array', 'items' => $product_tags ];
        }

        return $lines;
    }

    /**
     * Add product-specific body sections to the markdown output.
     *
     * @param array   $sections Existing sections.
     * @param WP_Post $post     The post being rendered.
     * @return array
     */
    public function add_sections( $sections, $post ): array {
        if ( ! is_array( $sections ) ) {
            $sections = [];
        }
        if ( ! ( $post instanceof WP_Post ) || $post->post_type !== 'product' ) {
            return $sections;
        }

        $product = $this->get_product( $post->ID );
        if ( ! $product ) {
            return $sections;
        }

        $summary = $this->build_summary_section( $product );
        if ( $summary !== '' ) {
            $sections['woo_summary'] = [ 'priority' => 10, 'markdown' => $summary ];
        }

        $short_description = trim( (string) $product->get_short_description() );
        if ( $short_description !== '' ) {
            $sections['woo_short_description'] = [
                'priority' => 50,
                'markdown' => "## Summary\n\n" . $this->html_to_text( $short_description ),
            ];
        }

        $attributes = $this->build_attributes_section( $product );
        if ( $attributes !== '' ) {
            $sections['woo_attributes'] = [ 'priority' => 150, 'markdown' => $attributes ];
        }

        $variations = $this->build_variations_section( $product );
        if ( $variations !== '' ) {
            $sections['woo_variations'] = [ 'priority' => 160, 'markdown' => $variations ];
        }

        return $sections;
    }

    /**
     * Build the at-a-glance summary block (price, stock, SKU).
     *
     * @param \WC_Product $product The product.
     * @return string
     */
    private function build_summary_section( $product ): string {
        $rows = [];

        $price_line = $this->format_price( $product );
        if ( $price_line !== '' ) {
            $rows[] = '**Price:** ' . $price_line;
        }

        $sku = $product->get_sku();
        if ( $sku !== '' ) {
            $rows[] = '**SKU:** ' . $sku;
        }

        $stock_status = $product->get_stock_status();
        $stock_label  = [
            'instock'     => 'In stock',
            'outofstock'  => 'Out of stock',
            'onbackorder' => 'On backorder',
        ];
        $rows[] = '**Availability:** ' . ( $stock_label[ $stock_status ] ?? $stock_status );

        if ( ! $rows ) {
            return '';
        }

        return implode( "\n", $rows );
    }

    /**
     * Format a product price as clean plain text.
     *
     * Avoids `get_price_html()` because it injects screen-reader spans
     * ("Original price was:", "Current price is:") and NBSP entities that
     * don't belong in markdown output.
     *
     * @param \WC_Product $product The product.
     * @return string
     */
    private function format_price( $product ): string {
        if ( ! function_exists( 'wc_price' ) ) {
            return '';
        }

        $regular = $product->get_regular_price();
        $sale    = $product->get_sale_price();
        $price   = $product->get_price();

        $clean = function ( $html ) {
            $text = wp_strip_all_tags( (string) $html );
            $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            // Normalize NBSP to a regular space.
            $text = str_replace( "\xc2\xa0", ' ', $text );
            return trim( $text );
        };

        if ( $product->is_on_sale() && $sale !== '' && $sale !== null && $regular !== '' && $regular !== null ) {
            return $clean( wc_price( wc_get_price_to_display( $product ) ) ) . ' (was ' . $clean( wc_price( wc_get_price_to_display( $product, [ 'price' => $regular ] ) ) ) . ')';
        }

        if ( $price !== '' && $price !== null ) {
            return $clean( wc_price( wc_get_price_to_display( $product ) ) );
        }

        return '';
    }

    /**
     * Build the attributes section.
     *
     * @param \WC_Product $product The product.
     * @return string
     */
    private function build_attributes_section( $product ): string {
        $attributes = $product->get_attributes();
        if ( ! $attributes ) {
            return '';
        }

        $lines = [ '## Attributes', '' ];
        $any   = false;
        foreach ( $attributes as $attribute ) {
            // Skip attributes hidden from product page.
            if ( method_exists( $attribute, 'get_visible' ) && ! $attribute->get_visible() ) {
                continue;
            }

            $label  = wc_attribute_label( $attribute->get_name() );
            $values = [];

            if ( $attribute->is_taxonomy() ) {
                $terms = wc_get_product_terms( $product->get_id(), $attribute->get_name(), [ 'fields' => 'names' ] );
                if ( $terms ) {
                    $values = $terms;
                }
            } else {
                $values = $attribute->get_options();
            }

            if ( ! $values ) {
                continue;
            }

            $lines[] = '- **' . $label . ':** ' . implode( ', ', array_map( 'strval', $values ) );
            $any     = true;
        }

        return $any ? implode( "\n", $lines ) : '';
    }

    /**
     * Build the variations section for variable products.
     *
     * @param \WC_Product $product The product.
     * @return string
     */
    private function build_variations_section( $product ): string {
        if ( ! $product->is_type( 'variable' ) || ! method_exists( $product, 'get_available_variations' ) ) {
            return '';
        }

        $variations = $product->get_available_variations();
        if ( ! $variations ) {
            return '';
        }

        $lines = [ '## Variations', '' ];
        foreach ( $variations as $variation ) {
            $attrs = [];
            if ( ! empty( $variation['attributes'] ) && is_array( $variation['attributes'] ) ) {
                foreach ( $variation['attributes'] as $key => $value ) {
                    if ( $value === '' ) {
                        continue;
                    }
                    $clean_key = ucfirst( str_replace( [ 'attribute_pa_', 'attribute_' ], '', (string) $key ) );
                    $attrs[]   = $clean_key . ': ' . $value;
                }
            }
            $label  = $attrs ? implode( ', ', $attrs ) : ( '#' . ( $variation['variation_id'] ?? '' ) );
            $price  = isset( $variation['display_price'] ) ? (string) $variation['display_price'] : '';
            $sku    = $variation['sku'] ?? '';
            $detail = [];
            if ( $price !== '' ) {
                $detail[] = 'price: ' . $price;
            }
            if ( $sku !== '' ) {
                $detail[] = 'SKU: ' . $sku;
            }
            $lines[] = '- ' . $label . ( $detail ? ' — ' . implode( ', ', $detail ) : '' );
        }

        return implode( "\n", $lines );
    }

    /**
     * Collect product taxonomy terms (categories/tags) as name/url pairs.
     *
     * Mirrors the structure used by core categories/tags so the YAML serializer
     * emits them the same way.
     *
     * @param int    $post_id  The product ID.
     * @param string $taxonomy The taxonomy name.
     * @return array
     */
    private function collect_product_terms( int $post_id, string $taxonomy ): array {
        $terms = get_the_terms( $post_id, $taxonomy );
        if ( ! $terms || is_wp_error( $terms ) ) {
            return [];
        }

        $out = [];
        foreach ( $terms as $term ) {
            $url  = get_term_link( $term );
            $path = is_wp_error( $url ) ? '' : rtrim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' ) . '.md';
            $out[] = [
                'name' => [ 'type' => 'string', 'content' => $term->name ],
                'url'  => [ 'type' => 'url', 'content' => $path ],
            ];
        }
        return $out;
    }

    /**
     * Resolve a `WC_Product` instance for a post id.
     *
     * @param int $post_id The product ID.
     * @return \WC_Product|null
     */
    private function get_product( int $post_id ) {
        if ( ! function_exists( 'wc_get_product' ) ) {
            return null;
        }
        $product = wc_get_product( $post_id );
        return $product ? $product : null;
    }

    /**
     * Convert a small chunk of HTML to plain text suitable for embedding in markdown.
     *
     * @param string $html The HTML.
     * @return string
     */
    private function html_to_text( string $html ): string {
        $text = wp_strip_all_tags( $html );
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        return trim( $text );
    }

    /**
     * Invalidate the cached markdown for a product.
     *
     * @param int $product_id The product ID.
     * @return void
     */
    public function invalidate_cache( $product_id ): void {
        $product_id = (int) $product_id;
        if ( $product_id > 0 ) {
            delete_transient( 'md_alt_cache_' . $product_id );
        }
    }

    /**
     * Invalidate cache when given a product object (variation stock hooks pass the product).
     *
     * @param \WC_Product $product The product.
     * @return void
     */
    public function invalidate_cache_from_product( $product ): void {
        if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
            $parent_id = method_exists( $product, 'get_parent_id' ) ? (int) $product->get_parent_id() : 0;
            $this->invalidate_cache( $parent_id ?: (int) $product->get_id() );
        }
    }
}
