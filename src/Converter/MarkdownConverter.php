<?php
/**
 * HTML to Markdown converter wrapper.
 *
 * @package MarkdownAlternate
 */

namespace MarkdownAlternate\Converter;

use League\HTMLToMarkdown\Converter\TableConverter;
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

        // GFM tables are an opt-in extension, so createDefaultEnvironment()
        // registers every converter the library ships EXCEPT TableConverter.
        // Without this line <table> falls through to DefaultConverter, which
        // concatenates cell text with no separators: a 4x3 pricing table
        // arrives as "PlanPriceStorageSupportFree$05 GBCommunity...", and no
        // consumer can recover the grid. That is the opposite of this plugin's
        // purpose, since tables are the densest content an LLM can ingest.
        // The library already ships table_pipe_escape and table_caption_side
        // defaults for this converter — only the registration is missing.
        $this->converter->getEnvironment()->addConverter(new TableConverter());
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
