<?php // phpcs:ignore WordPress.Files.FileName -- PSR-4 class filename, like the existing classes.
/**
 * Term archive renderer for markdown output.
 *
 * @package MarkdownAlternate
 */

namespace MarkdownAlternate\Output;

use WP_Post;
use WP_Term;
use MarkdownAlternate\Converter\MarkdownConverter;
use MarkdownAlternate\PostTypeSupport;
use MarkdownAlternate\TaxonomySupport;
use MarkdownAlternate\Router\UrlConverter;

/**
 * Renders a category, tag or other supported term archive as markdown.
 *
 * The frontmatter describes the term; the body has its description, its
 * child terms and its published posts, newest first, up to a limit, with a
 * link to the HTML archive for the rest.
 */
class TermRenderer {

	/**
	 * The HTML to Markdown converter.
	 *
	 * @var MarkdownConverter
	 */
	private $converter;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->converter = new MarkdownConverter();
	}

	/**
	 * Render a term archive as complete markdown output.
	 *
	 * @param WP_Term $term The term to render.
	 * @return string The rendered markdown content.
	 */
	public function render( WP_Term $term ): string {
		$url   = get_term_link( $term );
		$url   = is_wp_error( $url ) ? '' : $url;
		$query = $this->query_posts( $term );

		$sections = array(
			'title' => array(
				'priority' => 0,
				'markdown' => '# ' . MarkdownDocument::decode_entities( $term->name ),
			),
		);

		$description = trim( (string) term_description( $term->term_id ) );
		if ( '' !== $description ) {
			$sections['description'] = array(
				'priority' => 100,
				'markdown' => $this->to_markdown( $description ),
			);
		}

		$children = $this->build_children( $term );
		if ( '' !== $children ) {
			$sections['children'] = array(
				'priority' => 110,
				'markdown' => $children,
			);
		}

		$items = $this->build_items( $term, $query, $url );
		if ( '' !== $items ) {
			$sections['items'] = array(
				'priority' => 120,
				'markdown' => $items,
			);
		}

		/**
		 * Filters the keyed list of markdown body sections of a term archive.
		 *
		 * Each section has an integer priority and a markdown string. Lower
		 * priorities appear first; ties preserve insertion order. Defaults:
		 * title (0), description (100), children (110) and items (120).
		 *
		 * @since 1.3.0
		 *
		 * @param array   $sections Body sections.
		 * @param WP_Term $term     The term being rendered.
		 */
		$sections = (array) apply_filters( 'markdown_alternate_term_sections', $sections, $term );

		return $this->generate_frontmatter( $term, $url, $query->found_posts ) . "\n\n" . MarkdownDocument::render_sections( $sections );
	}

	/**
	 * Generate YAML frontmatter for a term archive.
	 *
	 * @param WP_Term $term  The term.
	 * @param string  $url   The term's HTML archive URL.
	 * @param int     $count Published posts in the archive.
	 * @return string The YAML frontmatter block.
	 */
	private function generate_frontmatter( WP_Term $term, string $url, int $count ): string {
		$content_lines = array(
			'title'    => array(
				'type'    => 'string',
				'content' => $term->name,
			),
			'url'      => array(
				'type'    => 'url',
				'content' => $url,
			),
			'taxonomy' => array(
				'type'    => 'string',
				'content' => $term->taxonomy,
			),
			'count'    => array(
				'type'    => 'number',
				'content' => $count,
			),
		);

		if ( $term->parent ) {
			$parent = get_term( $term->parent, $term->taxonomy );
			if ( $parent instanceof WP_Term ) {
				$content_lines['parent'] = array(
					'type'  => 'array',
					'items' => array(
						array(
							'name' => array(
								'type'    => 'string',
								'content' => $parent->name,
							),
							'url'  => array(
								'type'    => 'url',
								'content' => $this->term_url( $parent ),
							),
						),
					),
				);
			}
		}

		/**
		 * Filters typed frontmatter fields of a term archive before serialization.
		 *
		 * Same field format as markdown_alternate_frontmatter_content_lines.
		 *
		 * @since 1.3.0
		 *
		 * @param array   $content_lines Fields keyed by their YAML name.
		 * @param WP_Term $term          The term being rendered.
		 */
		$content_lines = (array) apply_filters( 'markdown_alternate_term_frontmatter_content_lines', $content_lines, $term );

		return implode( "\n", array_merge( array( '---' ), MarkdownDocument::frontmatter_lines( $content_lines ), array( '---' ) ) );
	}

	/**
	 * Query the archive's published posts of supported post types.
	 *
	 * @param WP_Term $term The term.
	 * @return \WP_Query The query.
	 */
	private function query_posts( WP_Term $term ): \WP_Query {
		$taxonomy   = get_taxonomy( $term->taxonomy );
		$post_types = array_values( array_intersect( $taxonomy ? (array) $taxonomy->object_type : array(), PostTypeSupport::get_supported_types() ) );

		$args = array(
			'post_type'           => $post_types ? $post_types : array( 'post' ),
			'post_status'         => 'publish',
			'posts_per_page'      => self::get_limit( $term ),
			'ignore_sticky_posts' => true,
			'no_found_rows'       => false,
			'has_password'        => false,
			'tax_query'           => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- The archive's own query.
				array(
					'taxonomy'         => $term->taxonomy,
					'terms'            => array( $term->term_id ),
					'include_children' => true,
				),
			),
		);

		/**
		 * Filters the query of the posts listed in a term archive.
		 *
		 * Integrations use it for their own order and visibility rules.
		 *
		 * @since 1.3.0
		 *
		 * @param array   $args WP_Query arguments.
		 * @param WP_Term $term The term being rendered.
		 */
		$args = (array) apply_filters( 'markdown_alternate_term_query_args', $args, $term );

		return new \WP_Query( $args );
	}

	/**
	 * Number of posts listed in a term archive.
	 *
	 * @param WP_Term $term The term.
	 * @return int The limit.
	 */
	public static function get_limit( WP_Term $term ): int {
		/**
		 * Filters how many posts a term archive lists. The rest are reached
		 * through the HTML archive, linked below the list.
		 *
		 * @since 1.3.0
		 *
		 * @param int     $limit Number of posts. Default 100.
		 * @param WP_Term $term  The term being rendered.
		 */
		return max( 1, (int) apply_filters( 'markdown_alternate_term_posts_limit', 100, $term ) );
	}

	/**
	 * List the term's child terms that have posts.
	 *
	 * @param WP_Term $term The term.
	 * @return string Markdown section or empty string.
	 */
	private function build_children( WP_Term $term ): string {
		if ( ! is_taxonomy_hierarchical( $term->taxonomy ) ) {
			return '';
		}
		$children = get_terms(
			array(
				'taxonomy'   => $term->taxonomy,
				'parent'     => $term->term_id,
				'hide_empty' => true,
			)
		);
		if ( ! is_array( $children ) || ! $children ) {
			return '';
		}
		$rows = array();
		foreach ( $children as $child ) {
			$rows[] = '- [' . $this->link_text( $child->name ) . '](' . $this->term_url( $child ) . ') (' . (int) $child->count . ')';
		}
		return '## ' . __( 'Subcategories', 'markdown-alternate' ) . "\n\n" . implode( "\n", $rows );
	}

	/**
	 * List the archive's posts, with a link to the HTML archive when there are more.
	 *
	 * @param WP_Term   $term  The term.
	 * @param \WP_Query $query The archive's query.
	 * @param string    $url   The term's HTML archive URL.
	 * @return string Markdown section or empty string.
	 */
	private function build_items( WP_Term $term, \WP_Query $query, string $url ): string {
		$rows = array();
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$default = '- [' . $this->link_text( get_the_title( $post ) ) . '](' . $this->post_url( $post ) . '): ' . get_the_date( 'Y-m-d', $post );

			/**
			 * Filters the markdown list item of a post in a term archive.
			 *
			 * @since 1.3.0
			 *
			 * @param string  $row  Markdown list item.
			 * @param WP_Post $post The post.
			 * @param WP_Term $term The term being rendered.
			 */
			$row = (string) apply_filters( 'markdown_alternate_term_item', $default, $post, $term );
			if ( '' !== trim( $row ) ) {
				$rows[] = $row;
			}
		}
		if ( ! $rows ) {
			return '';
		}

		$post_types = (array) $query->get( 'post_type' );
		$object     = 1 === count( $post_types ) ? get_post_type_object( $post_types[0] ) : null;
		$heading    = $object ? $object->labels->name : __( 'Posts', 'markdown-alternate' );
		$markdown   = '## ' . MarkdownDocument::decode_entities( $heading ) . "\n\n" . implode( "\n", $rows );

		if ( $query->found_posts > count( $rows ) && '' !== $url ) {
			$markdown .= "\n\n" . sprintf(
				/* translators: 1: number of posts listed, 2: total number of posts, 3: Markdown link to the HTML archive. */
				__( 'Showing %1$d of %2$d. All of them: %3$s', 'markdown-alternate' ),
				count( $rows ),
				(int) $query->found_posts,
				'[' . $this->link_text( $term->name ) . '](' . $url . ')'
			);
		}
		return $markdown;
	}

	/**
	 * The markdown URL of a supported term, or its HTML archive URL.
	 *
	 * @param WP_Term $term The term.
	 * @return string URL.
	 */
	private function term_url( WP_Term $term ): string {
		$url = get_term_link( $term );
		if ( is_wp_error( $url ) ) {
			return '';
		}
		return TaxonomySupport::is_supported( $term->taxonomy ) ? UrlConverter::convert_to_markdown_url( $url ) : $url;
	}

	/**
	 * The markdown URL of a post, or its HTML URL when it has no markdown version.
	 *
	 * @param WP_Post $post The post.
	 * @return string URL.
	 */
	private function post_url( WP_Post $post ): string {
		$url = (string) get_permalink( $post );
		if ( post_password_required( $post ) || PostTypeSupport::should_skip_noindexed( $post ) ) {
			return $url;
		}
		return UrlConverter::convert_to_markdown_url( $url );
	}

	/**
	 * Text for inside a Markdown link: entities decoded, brackets escaped.
	 *
	 * @param string $text Text.
	 * @return string Link text.
	 */
	private function link_text( string $text ): string {
		$text = trim( preg_replace( '/\s+/u', ' ', MarkdownDocument::decode_entities( wp_strip_all_tags( $text ) ) ) );
		return str_replace( array( '[', ']' ), array( '\\[', '\\]' ), $text );
	}

	/**
	 * Convert HTML to markdown, falling back to plain text.
	 *
	 * @param string $html HTML.
	 * @return string Markdown.
	 */
	private function to_markdown( string $html ): string {
		try {
			return $this->converter->convert( $html );
		} catch ( \Throwable $e ) {
			return MarkdownDocument::decode_entities( wp_strip_all_tags( $html ) );
		}
	}
}
