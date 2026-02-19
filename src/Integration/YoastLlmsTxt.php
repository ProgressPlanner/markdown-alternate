<?php
/**
 * Yoast SEO llms.txt integration.
 *
 * Rewrites URLs in Yoast's llms.txt to point to .md versions
 * for post types supported by Markdown Alternate.
 *
 * @package MarkdownAlternate
 */

namespace MarkdownAlternate\Integration;

/**
 * Filters post URLs to .md during Yoast SEO's llms.txt generation.
 *
 * Wraps Yoast's generation triggers with permalink filters so URLs
 * are rewritten inline — no file post-processing needed.
 */
class YoastLlmsTxt {

    /**
     * Whether we're currently inside llms.txt generation.
     *
     * @var bool
     */
    private static bool $generating = false;

    /**
     * Register hooks to wrap Yoast's llms.txt generation.
     *
     * @return void
     */
    public function register(): void {
        // Wrap each trigger that causes Yoast to (re)generate the llms.txt file.
        // Priority 9 = before Yoast's priority 10, priority 11 = after.

        // 1. Feature toggle (enable/disable llms.txt in Yoast settings).
        add_action( 'update_option_wpseo', [ $this, 'start' ], 9 );
        add_action( 'update_option_wpseo', [ $this, 'stop' ], 11 );

        // 2. llms.txt page selection settings change.
        add_action( 'update_option_wpseo_llmstxt', [ $this, 'start' ], 9 );
        add_action( 'update_option_wpseo_llmstxt', [ $this, 'stop' ], 11 );

        // 3. Weekly cron regeneration.
        add_action( 'wpseo_llms_txt_population', [ $this, 'start' ], 9 );
        add_action( 'wpseo_llms_txt_population', [ $this, 'stop' ], 11 );
    }

    /**
     * Enable URL rewriting before Yoast generates the file.
     *
     * @return void
     */
    public function start(): void {
        if ( self::$generating ) {
            return;
        }
        self::$generating = true;

        // from_meta() path: $meta->canonical goes through this filter.
        add_filter( 'wpseo_canonical', [ $this, 'rewrite_canonical' ], 10, 2 );

        // from_post() fallback path: uses get_permalink() directly.
        add_filter( 'post_link', [ $this, 'rewrite_post_link' ], 10, 2 );
        add_filter( 'page_link', [ $this, 'rewrite_page_link' ], 10, 2 );
        add_filter( 'post_type_link', [ $this, 'rewrite_post_link' ], 10, 2 );
    }

    /**
     * Disable URL rewriting after Yoast finishes generating.
     *
     * @return void
     */
    public function stop(): void {
        if ( ! self::$generating ) {
            return;
        }
        self::$generating = false;

        remove_filter( 'wpseo_canonical', [ $this, 'rewrite_canonical' ], 10 );
        remove_filter( 'post_link', [ $this, 'rewrite_post_link' ], 10 );
        remove_filter( 'page_link', [ $this, 'rewrite_page_link' ], 10 );
        remove_filter( 'post_type_link', [ $this, 'rewrite_post_link' ], 10 );
    }

    /**
     * Rewrite Yoast canonical URL to .md for supported post types.
     *
     * @param string $canonical    The canonical URL.
     * @param object $presentation The Yoast indexable presentation.
     * @return string
     */
    public function rewrite_canonical( $canonical, $presentation ) {
        if ( empty( $canonical ) || ! is_object( $presentation ) || ! isset( $presentation->model ) ) {
            return $canonical;
        }

        if ( $presentation->model->object_type !== 'post' ) {
            return $canonical;
        }

        if ( ! $this->is_supported_post_type( $presentation->model->object_sub_type ) ) {
            return $canonical;
        }

        return rtrim( $canonical, '/' ) . '.md';
    }

    /**
     * Rewrite post/CPT permalink to .md.
     *
     * Works for both post_link and post_type_link filters.
     *
     * @param string  $url  The permalink.
     * @param WP_Post $post The post object.
     * @return string
     */
    public function rewrite_post_link( $url, $post ): string {
        if ( ! $this->is_supported_post_type( get_post_type( $post ) ) ) {
            return $url;
        }

        return rtrim( $url, '/' ) . '.md';
    }

    /**
     * Rewrite page permalink to .md.
     *
     * @param string $url     The page URL.
     * @param int    $post_id The page ID.
     * @return string
     */
    public function rewrite_page_link( $url, $post_id ): string {
        if ( ! $this->is_supported_post_type( get_post_type( $post_id ) ) ) {
            return $url;
        }

        return rtrim( $url, '/' ) . '.md';
    }

    /**
     * Check if a post type is supported for markdown output.
     *
     * @param string $post_type The post type to check.
     * @return bool
     */
    private function is_supported_post_type( string $post_type ): bool {
        $supported = apply_filters( 'markdown_alternate_supported_post_types', [ 'post', 'page' ] );
        return in_array( $post_type, $supported, true );
    }
}
