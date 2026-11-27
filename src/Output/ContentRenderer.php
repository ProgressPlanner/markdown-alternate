<?php
/**
 * Content renderer for markdown output.
 *
 * @package MarkdownAlternate
 */

namespace MarkdownAlternate\Output;

use WP_Post;
use MarkdownAlternate\Converter\MarkdownConverter;
use MarkdownAlternate\TaxonomySupport;

/**
 * Renders WordPress posts as markdown with YAML frontmatter.
 *
 * Handles complete content rendering including:
 * - YAML frontmatter with metadata (title, date, author, categories, tags)
 * - H1 title heading
 * - HTML to markdown conversion
 */
class ContentRenderer {

	/**
	 * Cache schema; older entries may contain another visitor's output.
	 *
	 * @var int
	 */
	private const CACHE_SCHEMA = 2;

    /**
     * The HTML to Markdown converter.
     *
     * @var MarkdownConverter
     */
    private $converter;

    /**
     * Constructor.
     *
     * Initializes the markdown converter.
     */
    public function __construct() {
        $this->converter = new MarkdownConverter();
    }

	/**
	 * Render a post as complete markdown output.
	 *
	 * @param WP_Post $post The post to render.
	 * @return string The rendered markdown content.
	 */
	public function render( WP_Post $post ): string {
		$transient_key = 'md_alt_cache_' . $post->ID;
		$cache_enabled = CachePolicy::is_enabled( $post );
		$cached_data   = $cache_enabled ? get_transient( $transient_key ) : false;

		/**
		 * Filters the token used to validate cached markdown.
		 *
		 * Change the token when data outside post_content changes, or delete
		 * the md_alt_cache_{post_id} transient in the integration's update hook.
		 * Entries created before version tokens were introduced are regenerated.
		 *
		 * @since 1.2.0
		 *
		 * @param string  $version Cache version token. Default empty string.
		 * @param WP_Post $post    The post being rendered.
		 */
		$cache_version = (string) apply_filters( 'markdown_alternate_cache_version', '', $post );

		if ( is_array( $cached_data )
			&& isset( $cached_data['markdown'], $cached_data['modified'], $cached_data['version'], $cached_data['schema'] )
			&& self::CACHE_SCHEMA === $cached_data['schema']
			&& $post->post_modified === $cached_data['modified']
			&& $cache_version === $cached_data['version']
		) {
			return $cached_data['markdown'];
		}

		$frontmatter = $this->generate_frontmatter( $post );
		$title       = get_the_title( $post );

		// Get content and apply WordPress filters (renders shortcodes and blocks).
		$content = $post->post_content;
		$content = apply_filters( 'the_content', $content );

		$content = $this->strip_code_block_markup( $content );
		try {
			$body = $this->converter->convert( $content );
		} catch ( \Throwable $e ) {
			$default_fallback = html_entity_decode( wp_strip_all_tags( $content ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$body             = apply_filters(
				'markdown_alternate_conversion_error_fallback',
				$default_fallback,
				$content,
				$post,
				$e
			);
		}

		$default_sections = array(
			'title'   => array(
				'priority' => 0,
				'markdown' => '# ' . MarkdownDocument::decode_entities( $title ),
			),
			'content' => array(
				'priority' => 100,
				'markdown' => $body,
			),
		);

		/**
		 * Filters the keyed list of markdown body sections.
		 *
		 * Each section has an integer priority and a markdown string. Lower
		 * priorities appear first; ties preserve insertion order. Empty or
		 * malformed sections are skipped when the list is changed.
		 *
		 * @since 1.2.0
		 *
		 * @param array   $sections Default title (0) and content (100) sections.
		 * @param WP_Post $post     The post being rendered.
		 */
		$sections = apply_filters( 'markdown_alternate_content_sections', $default_sections, $post );

		// Preserve existing output exactly, including the empty-body separator.
		if ( ! is_array( $sections ) || $sections === $default_sections ) {
			$body_output = $default_sections['title']['markdown'] . "\n\n" . $body;
		} else {
			$body_output = $this->render_sections( $sections );
		}
		$output = $frontmatter . "\n\n" . $body_output;

		// Cache the result (default 24 hours).

		/**
		 * Filters the cache expiration time for rendered markdown output.
		 *
		 * @since 1.1.0
		 *
		 * @param int $expiration Cache expiration time in seconds. Default DAY_IN_SECONDS.
		 */
		$expiration = apply_filters( 'markdown_alternate_cache_expiration', DAY_IN_SECONDS );
		if ( $cache_enabled ) {
			set_transient(
				$transient_key,
				array(
					'schema'   => self::CACHE_SCHEMA,
					'markdown' => $output,
					'modified' => $post->post_modified,
					'version'  => $cache_version,
				),
				$expiration
			);
		}

		return $output;
	}

	/**
	 * Sort and join the valid sections supplied by an integration.
	 *
	 * @param array $sections Sections keyed by an integration-specific name.
	 * @return string Markdown body.
	 */
	private function render_sections( array $sections ): string {
		return MarkdownDocument::render_sections( $sections );
	}

	/**
	 * Generate YAML frontmatter for a post.
	 *
	 * @param WP_Post $post The post to generate frontmatter for.
	 * @return string The YAML frontmatter block.
	 */
	private function generate_frontmatter( WP_Post $post ): string {

		$lines         = array();
		$content_lines = array();

		// YAML starter.
		$lines[] = '---';

		// Title (always included).
		$title                  = get_the_title( $post );
		$content_lines['title'] = array(
			'type'    => 'string',
			'content' => $title,
		);

		// Date (always included).
		$date                  = get_the_date( 'Y-m-d', $post );
		$content_lines['date'] = array(
			'type'    => 'date',
			'content' => $date,
		);

		// Author (always included).
		$author                  = get_the_author_meta( 'display_name', $post->post_author );
		$content_lines['author'] = array(
			'type'    => 'string',
			'content' => $author,
		);

		// Featured image (only if set).
		$featured_image = get_the_post_thumbnail_url( $post->ID, 'full' );
		if ( $featured_image ) {
			$content_lines['featured_image'] = array(
				'type'    => 'url',
				'content' => $featured_image,
			);
		}

		// Taxonomies (categories and tags by default, but filterable).
		$default_taxonomies = array(
			'category' => 'categories',
			'post_tag' => 'tags',
		);
		/**
		 * Filters the taxonomy names and their frontmatter keys.
		 *
		 * @since 1.2.0
		 *
		 * @param array   $taxonomies Map of taxonomy name to YAML key.
		 * @param WP_Post $post       The post being rendered.
		 */
		$taxonomies = apply_filters( 'markdown_alternate_frontmatter_taxonomies', $default_taxonomies, $post );
		if ( ! is_array( $taxonomies ) ) {
			$taxonomies = $default_taxonomies;
		}

		// Loop through taxonomies and add to content lines (only if present and not WP_Error).
		foreach ( $taxonomies as $taxonomy => $key ) {
			$terms = get_the_terms( $post->ID, $taxonomy );
			if ( $terms && ! is_wp_error( $terms ) ) {
				/**
				 * Filters the terms included in a post's taxonomy frontmatter.
				 *
				 * Return an empty array to omit this taxonomy's field. This does
				 * not disable term archives or exclude posts from those archives.
				 * Runs only when the Markdown document is regenerated.
				 *
				 * @since 1.3.0
				 *
				 * @param mixed   $terms    Array of WP_Term objects; non-arrays keep the assigned terms.
				 * @param string  $taxonomy Taxonomy name.
				 * @param WP_Post $post     The post being rendered.
				 */
				$filtered_terms = apply_filters( 'markdown_alternate_frontmatter_terms', $terms, $taxonomy, $post );
				if ( is_array( $filtered_terms ) ) {
					$terms = array_filter(
						$filtered_terms,
						static function ( $term ) {
							return $term instanceof \WP_Term;
						}
					);
				}
				if ( empty( $terms ) ) {
					continue;
				}
				$content_lines[ $key ]['type']  = 'array';
				$content_lines[ $key ]['items'] = array();
				foreach ( $terms as $term ) {
					$content_lines[ $key ]['items'][] = array(
						'name' => array(
							'type'    => 'string',
							'content' => $term->name,
						),
						'url'  => array(
							'type'    => 'url',
							'content' => $this->get_term_markdown_url( $term, $taxonomy ),
						),
					);
				}
			}
		}

		/**
		 * Filters typed frontmatter fields before YAML serialization.
		 *
		 * Scalar fields contain type (string, url, date, number or boolean) and
		 * content. Array fields contain type 'array' and items, a list of maps
		 * whose values are scalar fields.
		 * Pass unescaped values; strings and URLs are escaped once below.
		 *
		 * @since 1.2.0
		 *
		 * @param array   $content_lines Fields keyed by their YAML name.
		 * @param WP_Post $post          The post being rendered.
		 */
		$filtered_lines = apply_filters( 'markdown_alternate_frontmatter_content_lines', $content_lines, $post );
		if ( is_array( $filtered_lines ) ) {
			$content_lines = $filtered_lines;
		}

		// Add content lines to lines.
		$lines = array_merge( $lines, MarkdownDocument::frontmatter_lines( $content_lines ) );

		/**
		 * Filters serialized YAML lines before the closing delimiter is added.
		 *
		 * The opening delimiter is included. Integrations using this low-level
		 * filter are responsible for producing valid YAML.
		 *
		 * @since 1.2.0
		 *
		 * @param string[] $lines Serialized lines, including the opening delimiter.
		 * @param WP_Post  $post  The post being rendered.
		 */
		$filtered_lines = apply_filters( 'markdown_alternate_frontmatter_lines', $lines, $post );
		if ( is_array( $filtered_lines ) ) {
			$lines = $filtered_lines;
		}

		// YAML ender.
		$lines[] = '---';

		return implode( "\n", $lines );
	}

	/**
	 * Get the frontmatter URL for a term (category or tag).
	 *
	 * Terms of taxonomies whose archives are served as markdown link to their
	 * .md URL; other terms link to their HTML archive, which exists. URLs are
	 * absolute, like the links in the body, so the frontmatter still resolves
	 * when it is read on its own.
	 *
	 * @param \WP_Term $term     The term object.
	 * @param string   $taxonomy The term's taxonomy.
	 * @return string The absolute URL for the term.
	 */
	private function get_term_markdown_url( \WP_Term $term, string $taxonomy ): string {
		$url = get_term_link( $term );
		if ( is_wp_error( $url ) ) {
			return '';
		}
		// Append .md when the archive is served as markdown.
		return TaxonomySupport::is_supported( $taxonomy ) && ! TaxonomySupport::should_skip_noindexed( $term )
			? rtrim( $url, '/' ) . '.md' : $url;
	}

    /**
     * Strip syntax highlighting markup from code blocks.
     *
     * Plugins like syntax highlighters wrap code content in span elements
     * with classes like "hljs-keyword". This strips all HTML from inside
     * pre/code blocks while preserving the outer tags.
     *
     * @param string $content The HTML content.
     * @return string The content with clean code blocks.
     */
    private function strip_code_block_markup(string $content): string {
        // Match <pre> blocks (with optional attributes) and their contents
        return preg_replace_callback(
            '/<pre([^>]*)>(.*?)<\/pre>/is',
            function ($matches) {
                $pre_attrs = $matches[1];
                $inner = $matches[2];

                // Check if there's a <code> tag inside and extract language if present
                if (preg_match('/<code[^>]*class="[^"]*language-(\w+)[^"]*"[^>]*>/i', $inner, $lang_match)) {
                    $lang = $lang_match[1];
                } elseif (preg_match('/<code[^>]*class="[^"]*hljs[^"]*language-(\w+)[^"]*"[^>]*>/i', $inner, $lang_match)) {
                    $lang = $lang_match[1];
                } else {
                    $lang = '';
                }

                // Strip all HTML tags from inside, keeping only text
                $clean = strip_tags($inner);

                // Decode entities that might have been in the code
                $clean = html_entity_decode($clean, ENT_QUOTES | ENT_HTML5, 'UTF-8');

                // Rebuild with clean code tag (include language class if found)
                $code_class = $lang ? ' class="language-' . $lang . '"' : '';
                return '<pre><code' . $code_class . '>' . htmlspecialchars($clean, ENT_NOQUOTES, 'UTF-8') . '</code></pre>';
            },
            $content
        );
    }
}
