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
        // Generate frontmatter
        $frontmatter = $this->generate_frontmatter($post);

        // Get title for H1 heading
        $title = get_the_title($post);

        // Get content and apply WordPress filters (renders shortcodes and blocks)
        $content = $post->post_content;
        $content = apply_filters('the_content', $content);

        // Convert HTML to markdown
        $body = $this->converter->convert($content);

        // Assemble output
        $output = $frontmatter . "\n";
        $output .= '# ' . $this->decode_entities($title) . "\n\n";
        $output .= $body;

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
        $categories = get_the_terms($post->ID, 'category');
        if ($categories && !is_wp_error($categories)) {
            $lines[] = 'categories:';
            foreach ($categories as $category) {
                $lines[] = '  - name: "' . $this->escape_yaml($category->name) . '"';
                $lines[] = '    url: "' . $this->get_term_markdown_url($category) . '"';
            }
        }

        // Tags (only if present and not WP_Error)
        $tags = get_the_terms($post->ID, 'post_tag');
        if ($tags && !is_wp_error($tags)) {
            $lines[] = 'tags:';
            foreach ($tags as $tag) {
                $lines[] = '  - name: "' . $this->escape_yaml($tag->name) . '"';
                $lines[] = '    url: "' . $this->get_term_markdown_url($tag) . '"';
            }
        }

        $lines[] = '---';

        return implode("\n", $lines);
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
        // Decode HTML entities first (WordPress often returns encoded strings)
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Escape backslashes first, then quotes
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace('"', '\\"', $value);

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
}
