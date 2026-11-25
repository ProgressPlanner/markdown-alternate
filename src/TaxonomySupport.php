<?php // phpcs:ignore WordPress.Files.FileName -- PSR-4 class filename, like the existing classes.
/**
 * Taxonomy support utility for markdown output.
 *
 * @package MarkdownAlternate
 */

namespace MarkdownAlternate;

/**
 * Shared utility for taxonomy archive validation.
 *
 * Provides a single source of truth for the taxonomies whose term archives
 * are served as markdown, and whose frontmatter links point to .md URLs.
 */
class TaxonomySupport {

	/**
	 * Get the taxonomies whose term archives are served as markdown.
	 *
	 * Defaults to categories and tags, the taxonomies the frontmatter of posts
	 * links to. Integrations add their own, such as WooCommerce's product
	 * categories, tags and brands.
	 *
	 * @return string[] Taxonomy names.
	 */
	public static function get_supported_taxonomies(): array {
		/**
		 * Filters the taxonomies whose term archives are served as markdown.
		 *
		 * A taxonomy left out keeps HTML links in the frontmatter, so no link
		 * points to a .md URL that would not be served.
		 *
		 * @since 1.3.0
		 *
		 * @param string[] $taxonomies Taxonomy names. Default category and post_tag.
		 */
		$taxonomies = (array) apply_filters( 'markdown_alternate_supported_taxonomies', array( 'category', 'post_tag' ) );
		return array_values( array_filter( $taxonomies, 'is_string' ) );
	}

	/**
	 * Check if a taxonomy's term archives are served as markdown.
	 *
	 * @param string $taxonomy The taxonomy to check.
	 * @return bool True if supported, false otherwise.
	 */
	public static function is_supported( string $taxonomy ): bool {
		return in_array( $taxonomy, self::get_supported_taxonomies(), true );
	}

	/**
	 * Check whether the markdown archive should be withheld for a noindexed term.
	 *
	 * Off by default, like posts. A site opts in with the
	 * 'markdown_alternate_skip_noindexed_terms' filter, which runs first, so the
	 * noindex lookup only happens on sites that opted in.
	 *
	 * @param \WP_Term $term The term to check.
	 * @return bool True if the term is noindexed and the site opted in.
	 */
	public static function should_skip_noindexed( \WP_Term $term ): bool {
		/**
		 * Filters whether term archives marked noindex lose their markdown version.
		 *
		 * @since 1.3.0
		 *
		 * @param bool     $skip Whether to skip noindexed term archives. Default false.
		 * @param \WP_Term $term The term being checked.
		 */
		if ( ! apply_filters( 'markdown_alternate_skip_noindexed_terms', false, $term ) ) {
			return false;
		}

		return self::is_noindexed( $term );
	}

	/**
	 * Check whether Yoast SEO marks a term archive noindex.
	 *
	 * Prefers Yoast's effective robots value, which folds in the per-term
	 * setting and the taxonomy default, so the answer matches the robots meta
	 * on the HTML archive. Without Yoast active the answer is false.
	 *
	 * @param \WP_Term $term The term to check.
	 * @return bool True if Yoast marks the term archive noindex.
	 */
	public static function is_noindexed( \WP_Term $term ): bool {
		if ( ! function_exists( 'YoastSEO' ) ) {
			return false;
		}

		try {
			$meta = YoastSEO()->meta->for_term( $term->term_id );
			if ( $meta ) {
				$robots = $meta->robots;
				if ( is_array( $robots ) ) {
					return in_array( 'noindex', $robots, true );
				}
			}
		} catch ( \Throwable $e ) {
			// PHP 7.4 requires the catch variable.
			unset( $e );
		}

		return false;
	}
}
