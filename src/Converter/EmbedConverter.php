<?php // phpcs:ignore WordPress.Files.FileName -- PSR-4 class filename.
/**
 * Link fallback for iframe embeds.
 *
 * @package MarkdownAlternate
 */

namespace MarkdownAlternate\Converter;

use League\HTMLToMarkdown\Converter\ConverterInterface;
use League\HTMLToMarkdown\ElementInterface;

/**
 * Preserve an embed's destination without emitting executable iframe markup.
 */
class EmbedConverter implements ConverterInterface {

	/**
	 * Convert an iframe to a link using its source and optional title.
	 *
	 * @param ElementInterface $element Iframe element.
	 * @return string Markdown link or an empty string without a usable source.
	 */
	public function convert( ElementInterface $element ): string {
		$url = trim( html_entity_decode( $element->getAttribute( 'src' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( ! preg_match( '~^(?:https?://|/|\.\.?/)~i', $url ) || preg_match( '/[\x00-\x1f\x7f]/', $url ) ) {
			return '';
		}

		// Encode characters that would terminate a Markdown link destination.
		$url   = str_replace(
			array( ' ', '(', ')', '<', '>', '\\' ),
			array( '%20', '%28', '%29', '%3C', '%3E', '%5C' ),
			$url
		);
		$title = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( $element->getAttribute( 'title' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
		if ( '' === $title ) {
			$title = $url;
		}
		$title = str_replace(
			array( '\\', '[', ']', '*', '_', '`', '<', '>' ),
			array( '\\\\', '\\[', '\\]', '\\*', '\\_', '\\`', '\\<', '\\>' ),
			$title
		);
		return '[' . $title . '](' . $url . ")\n\n";
	}

	/**
	 * Tags converted by this fallback.
	 *
	 * @return string[] Supported tags.
	 */
	public function getSupportedTags(): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Library interface.
		return array( 'iframe' );
	}
}
