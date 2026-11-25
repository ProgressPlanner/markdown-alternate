<?php // phpcs:ignore WordPress.Files.FileName -- PSR-4 class filename.
/**
 * Image conversion for visible content and WordPress cover blocks.
 *
 * @package MarkdownAlternate
 */

namespace MarkdownAlternate\Converter;

use League\HTMLToMarkdown\Converter\ImageConverter as BaseImageConverter;
use League\HTMLToMarkdown\ElementInterface;

/**
 * Omit decorative image payloads and keep cover backgrounds block-separated.
 */
class ImageConverter extends BaseImageConverter {

	/**
	 * Convert a visible image while preserving the library's inline formatting.
	 *
	 * @param ElementInterface $element Image element.
	 * @return string Markdown image, or an empty string for an omitted image.
	 */
	public function convert( ElementInterface $element ): string {
		if ( 0 === stripos( ltrim( $element->getAttribute( 'src' ) ), 'data:' ) ) {
			return '';
		}
		for ( $ancestor = $element; null !== $ancestor; $ancestor = $ancestor->getParent() ) {
			if ( 'true' === strtolower( trim( $ancestor->getAttribute( 'aria-hidden' ) ) ) ) {
				return '';
			}
		}

		$markdown = parent::convert( $element );
		// Cover backgrounds precede a div of inner blocks without a wrapping paragraph.
		if ( preg_match( '/(?:^|\s)wp-block-cover__image-background(?:\s|$)/', $element->getAttribute( 'class' ) ) ) {
			$markdown .= "\n\n";
		}
		return $markdown;
	}
}
