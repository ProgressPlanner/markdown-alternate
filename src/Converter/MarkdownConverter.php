<?php
/**
 * HTML to Markdown converter wrapper.
 *
 * @package MarkdownAlternate
 */

namespace MarkdownAlternate\Converter;

use League\HTMLToMarkdown\HtmlConverter;

/**
 * Wrapper class for converting HTML content to Markdown.
 *
 * Uses league/html-to-markdown library with security-focused defaults
 * to strip dangerous HTML tags and produce clean markdown output.
 */
class MarkdownConverter {

    /**
     * The underlying HTML to Markdown converter.
     *
     * @var HtmlConverter
     */
    private $converter;

    /**
     * Constructor.
     *
     * Initializes the converter with secure, consistent options.
     */
    public function __construct() {
        $this->converter = new HtmlConverter([
            'header_style'    => 'atx',
            'strip_tags'      => true,
            'remove_nodes'    => 'script style iframe',
            'hard_break'      => false,
            'list_item_style' => '-',
        ]);
    }

    /**
     * Convert HTML content to Markdown.
     *
     * @param string $html The HTML content to convert.
     * @return string The converted Markdown content.
     */
    public function convert(string $html): string {
        $html = trim($html);

        if ($html === '') {
            return '';
        }

        return $this->converter->convert($html);
    }
}
