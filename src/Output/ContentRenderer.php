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
 *
 * Integrations can extend the output through two filters:
 * - `markdown_alternate_frontmatter`       — add/modify YAML frontmatter keys.
 * - `markdown_alternate_content_sections`  — add/reorder body sections.
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

        /**
         * Filters the cache version token mixed into the cache key.
         *
         * Integrations that derive markdown output from data outside post_content
         * (e.g. postmeta) should return a token that changes when their data changes,
         * so cached output is invalidated correctly. Alternatively, integrations may
         * call delete_transient( 'md_alt_cache_' . $post_id ) on their own update hooks.
         *
         * @since 1.2.0
         *
         * @param string  $version The cache version token. Default empty string.
         * @param WP_Post $post    The post being rendered.
         */
        $cache_version = (string) apply_filters( 'markdown_alternate_cache_version', '', $post );

        // Check if cache exists and post hasn't been modified since.
        if ( is_array( $cached_data )
            && isset( $cached_data['markdown'], $cached_data['modified'], $cached_data['version'] )
            && $post->post_modified === $cached_data['modified']
            && $cache_version === $cached_data['version']
        ) {
            return $cached_data['markdown'];
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

        // Build ordered body sections. Integrations can add their own via the filter.
        $default_sections = [
            'title' => [
                'priority' => 0,
                'markdown' => '# ' . $this->decode_entities($title),
            ],
            'content' => [
                'priority' => 100,
                'markdown' => $body,
            ],
        ];

        /**
         * Filters the ordered list of body sections rendered after the frontmatter.
         *
         * Each section is an array with:
         * - 'priority' (int)    — sort key, lower runs first. Title is 0, post_content is 100.
         * - 'markdown' (string) — the markdown to emit. Empty strings are skipped.
         *
         * Section keys are arbitrary; use a unique prefix (e.g. "woo_price") to avoid
         * collisions with other integrations and to allow targeted removal/replacement.
         *
         * @since 1.2.0
         *
         * @param array   $sections Default sections keyed by name.
         * @param WP_Post $post     The post being rendered.
         */
        $sections = apply_filters( 'markdown_alternate_content_sections', $default_sections, $post );

        $output = $frontmatter . "\n\n" . $this->render_sections( $sections );

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
            'version'  => $cache_version,
        ), $expiration );

        return $output;
    }

    /**
     * Sort sections by priority and concatenate their markdown.
     *
     * @param array $sections Sections from the markdown_alternate_content_sections filter.
     * @return string
     */
    private function render_sections(array $sections): string {
        // Tolerate malformed entries from third-party filters.
        $sections = array_filter( $sections, static function ( $section ) {
            return is_array( $section )
                && isset( $section['markdown'] )
                && is_string( $section['markdown'] )
                && trim( $section['markdown'] ) !== '';
        } );

        uasort( $sections, static function ( $a, $b ) {
            $pa = isset( $a['priority'] ) ? (int) $a['priority'] : 100;
            $pb = isset( $b['priority'] ) ? (int) $b['priority'] : 100;
            return $pa <=> $pb;
        } );

        return implode( "\n\n", array_map(
            static fn( $section ) => rtrim( $section['markdown'] ),
            $sections
        ) );
    }

    /**
     * Generate YAML frontmatter for a post.
     *
     * Builds an associative data array first, runs it through the
     * `markdown_alternate_frontmatter` filter so integrations can add keys,
     * and then serializes the result to YAML.
     *
     * @param WP_Post $post The post to generate frontmatter for.
     * @return string The YAML frontmatter block.
     */
    private function generate_frontmatter(WP_Post $post): string {
        $data = [
            'title'  => get_the_title( $post ),
            'date'   => get_the_date( 'Y-m-d', $post ),
            'author' => get_the_author_meta( 'display_name', $post->post_author ),
        ];

        $featured_image = get_the_post_thumbnail_url( $post->ID, 'full' );
        if ( $featured_image ) {
            $data['featured_image'] = $featured_image;
        }

        $categories = $this->collect_taxonomy_terms( 'category', $post->ID );
        if ( $categories ) {
            $data['categories'] = $categories;
        }

        $tags = $this->collect_taxonomy_terms( 'post_tag', $post->ID );
        if ( $tags ) {
            $data['tags'] = $tags;
        }

        /**
         * Filters the frontmatter data array before it is serialized to YAML.
         *
         * Integrations can add scalar values, lists, or lists of associative arrays
         * (mirroring the shape of `categories` / `tags`). Keys with `null` or empty
         * values will be skipped.
         *
         * @since 1.2.0
         *
         * @param array   $data Associative array of frontmatter keys and values.
         * @param WP_Post $post The post being rendered.
         */
        $data = apply_filters( 'markdown_alternate_frontmatter', $data, $post );

        return $this->serialize_frontmatter( $data );
    }

    /**
     * Collect taxonomy terms as an array of name + markdown URL pairs.
     *
     * @param string $taxonomy The taxonomy name (e.g., 'category', 'post_tag').
     * @param int    $post_id  The post ID.
     * @return array List of ['name' => ..., 'url' => ...] entries.
     */
    private function collect_taxonomy_terms(string $taxonomy, int $post_id): array {
        $terms = get_the_terms( $post_id, $taxonomy );
        if ( ! $terms || is_wp_error( $terms ) ) {
            return [];
        }

        $out = [];
        foreach ( $terms as $term ) {
            $out[] = [
                'name' => $term->name,
                'url'  => $this->get_term_markdown_url( $term ),
            ];
        }
        return $out;
    }

    /**
     * Serialize a frontmatter data array to a YAML block.
     *
     * Supports scalars, plain lists of scalars, and lists of associative arrays.
     *
     * @param array $data The frontmatter data.
     * @return string The serialized YAML block (including delimiters).
     */
    private function serialize_frontmatter(array $data): string {
        $lines = ['---'];

        foreach ( $data as $key => $value ) {
            if ( $value === null || $value === '' || $value === [] ) {
                continue;
            }

            if ( is_array( $value ) ) {
                // List of associative arrays (e.g. categories/tags).
                if ( isset( $value[0] ) && is_array( $value[0] ) ) {
                    $lines[] = $key . ':';
                    foreach ( $value as $entry ) {
                        $first = true;
                        foreach ( $entry as $sub_key => $sub_value ) {
                            $prefix  = $first ? '  - ' : '    ';
                            $lines[] = $prefix . $sub_key . ': "' . $this->escape_yaml( (string) $sub_value ) . '"';
                            $first   = false;
                        }
                    }
                    continue;
                }

                // Plain list of scalars.
                $lines[] = $key . ':';
                foreach ( $value as $scalar ) {
                    $lines[] = '  - "' . $this->escape_yaml( (string) $scalar ) . '"';
                }
                continue;
            }

            // Scalars: quote strings, leave plain dates/numbers unquoted when safe.
            if ( is_string( $value ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
                $lines[] = $key . ': ' . $value;
            } elseif ( is_int( $value ) || is_float( $value ) ) {
                $lines[] = $key . ': ' . $value;
            } elseif ( is_bool( $value ) ) {
                $lines[] = $key . ': ' . ( $value ? 'true' : 'false' );
            } else {
                $lines[] = $key . ': "' . $this->escape_yaml( (string) $value ) . '"';
            }
        }

        $lines[] = '---';

        return implode( "\n", $lines );
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
