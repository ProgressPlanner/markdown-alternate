<?php
/**
 * Content renderer for markdown output.
 *
 * @package MarkdownAlternate
 */

namespace MarkdownAlternate\Output;

use WP_Post;
use MarkdownAlternate\Converter\MarkdownConverter;

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
    public function render(WP_Post $post): string {
        $transient_key = 'md_alt_cache_' . $post->ID;
        $cached_data   = get_transient( $transient_key );

        // Check if cache exists and post hasn't been modified since.
        if ( is_array( $cached_data ) && isset( $cached_data['markdown'], $cached_data['modified'] ) ) {
            if ( $post->post_modified === $cached_data['modified'] ) {
                return $cached_data['markdown'];
            }
        }

        $frontmatter = $this->generate_frontmatter($post);
        $title = get_the_title($post);

        // Get content and apply WordPress filters (renders shortcodes and blocks)
        $content = $post->post_content;
        $content = apply_filters('the_content', $content);

        $content = $this->strip_code_block_markup($content);
        try {
            $body = $this->converter->convert($content);
        } catch (\Throwable $e) {
            $default_fallback = html_entity_decode(wp_strip_all_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $body = apply_filters(
                'markdown_alternate_conversion_error_fallback',
                $default_fallback,
                $content,
                $post,
                $e
            );
        }

        $output = $frontmatter . "\n\n";
        $output .= '# ' . $this->decode_entities($title) . "\n\n";
        $output .= $body;

        // Cache the result (default 24 hours).

        /**
         * Filters the cache expiration time for rendered markdown output.
         *
         * @since 1.1.0
         *
         * @param int $expiration Cache expiration time in seconds. Default DAY_IN_SECONDS.
         */
        $expiration = apply_filters( 'markdown_alternate_cache_expiration', DAY_IN_SECONDS );
        set_transient( $transient_key, array(
            'markdown' => $output,
            'modified' => $post->post_modified,
        ), $expiration );

        return $output;
    }

    /**
     * Generate YAML frontmatter for a post.
     *
     * @param WP_Post $post The post to generate frontmatter for.
     * @return string The YAML frontmatter block.
     */
    private function generate_frontmatter(WP_Post $post): string {
        $lines = ['---'];

        // Title (always included)
        $title = get_the_title($post);
        $lines[] = 'title: "' . $this->escape_yaml($title) . '"';

        // Date (always included)
        $date = get_the_date('Y-m-d', $post);
        $lines[] = 'date: ' . $date;

        // Author (always included)
        $author = get_the_author_meta('display_name', $post->post_author);
        $lines[] = 'author: "' . $this->escape_yaml($author) . '"';

        // Featured image (only if set)
        $featured_image = get_the_post_thumbnail_url($post->ID, 'full');
        if ($featured_image) {
            $lines[] = 'featured_image: "' . $this->escape_yaml($featured_image) . '"';
        }

        // Categories (only if present and not WP_Error)
        $category_lines = $this->format_taxonomy_terms('category', $post->ID);
        if ($category_lines) {
            $lines[] = 'categories:';
            $lines = array_merge($lines, $category_lines);
        }

        // Tags (only if present and not WP_Error)
        $tag_lines = $this->format_taxonomy_terms('post_tag', $post->ID);
        if ($tag_lines) {
            $lines[] = 'tags:';
            $lines = array_merge($lines, $tag_lines);
        }

        $lines[] = '---';

        return implode("\n", $lines);
    }

    /**
     * Format taxonomy terms as YAML lines.
     *
     * @param string $taxonomy The taxonomy name (e.g., 'category', 'post_tag').
     * @param int $post_id The post ID.
     * @return array Array of formatted YAML lines, or empty array if no terms.
     */
    private function format_taxonomy_terms(string $taxonomy, int $post_id): array {
        $terms = get_the_terms($post_id, $taxonomy);
        if (!$terms || is_wp_error($terms)) {
            return [];
        }

        $lines = [];
        foreach ($terms as $term) {
            $lines[] = '  - name: "' . $this->escape_yaml($term->name) . '"';
            $lines[] = '    url: "' . $this->get_term_markdown_url($term) . '"';
        }

        return $lines;
    }

    /**
     * Get the markdown URL for a term (category or tag).
     *
     * @param \WP_Term $term The term object.
     * @return string The markdown URL for the term.
     */
    private function get_term_markdown_url(\WP_Term $term): string {
        $url = get_term_link($term);
        if (is_wp_error($url)) {
            return '';
        }
        // Convert to relative URL and append .md
        $path = wp_parse_url($url, PHP_URL_PATH);
        return rtrim($path, '/') . '.md';
    }

    /**
     * Escape a string for use in YAML.
     *
     * @param string $value The value to escape.
     * @return string The escaped value.
     */
    private function escape_yaml(string $value): string {
        $value = $this->decode_entities($value);
        $value = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
        return $value;
    }

    /**
     * Decode HTML entities from a string.
     *
     * WordPress functions often return HTML-entity-encoded strings.
     * This ensures clean markdown output.
     *
     * @param string $value The value to decode.
     * @return string The decoded value.
     */
    private function decode_entities(string $value): string {
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
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
