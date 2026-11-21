<?php // phpcs:ignore WordPress.Files.FileName -- PSR-4 class filename, like the existing classes.
/**
 * Shared YAML frontmatter and body section helpers.
 *
 * @package MarkdownAlternate
 */

namespace MarkdownAlternate\Output;

/**
 * Serializes typed frontmatter fields and orders body sections for every renderer.
 */
final class MarkdownDocument {

	/**
	 * Serialize typed frontmatter fields to YAML lines, without delimiters.
	 *
	 * Scalar fields contain type (string, url, date, number or boolean) and
	 * content. Array fields contain type 'array' and items, a list of maps
	 * whose values are scalar fields. Malformed fields are skipped.
	 *
	 * @param array<string, mixed> $content_lines Fields keyed by their YAML name.
	 * @return string[] YAML lines.
	 */
	public static function frontmatter_lines( array $content_lines ): array {
		$lines = array();
		foreach ( $content_lines as $key => $value ) {
			if ( ! is_array( $value ) || empty( $value['type'] ) ) {
				// Skip empty type values.
				continue;
			}
			if ( 'array' === $value['type'] && isset( $value['items'] ) && is_array( $value['items'] ) ) {
				// Multiple items (like categories and tags).
				$lines[] = $key . ':';
				foreach ( $value['items'] as $item ) {
					if ( ! is_array( $item ) ) {
						continue;
					}
					$i = 0;
					foreach ( $item as $item_key => $item_value ) {
						if ( ! is_array( $item_value ) || empty( $item_value['type'] ) || ! isset( $item_value['content'] ) || ! is_scalar( $item_value['content'] ) ) {
							// Skip empty type values or if content is not set.
							continue;
						}
						$item_value = self::format_yaml_value( $item_value['content'], $item_value['type'] );
						if ( 0 === $i ) {
							$lines[] = '  - ' . $item_key . ': ' . $item_value;
						} else {
							$lines[] = '    ' . $item_key . ': ' . $item_value;
						}
						++$i;
					}
				}
			} elseif ( isset( $value['content'] ) && is_scalar( $value['content'] ) ) {
				// Single content line (like title, date, author, featured_image).
				$lines[] = $key . ': ' . self::format_yaml_value( $value['content'], $value['type'] );
			}
		}
		return $lines;
	}

	/**
	 * Sort and join the valid sections supplied by an integration.
	 *
	 * Lower priorities appear first; ties preserve insertion order. Empty or
	 * malformed sections are skipped.
	 *
	 * @param array<string, mixed> $sections Sections keyed by an integration-specific name.
	 * @return string Markdown body.
	 */
	public static function render_sections( array $sections ): string {
		$ordered = array();
		foreach ( $sections as $section ) {
			if ( ! is_array( $section ) || ! isset( $section['markdown'] )
				|| ! is_string( $section['markdown'] ) || trim( $section['markdown'] ) === ''
			) {
				continue;
			}
			$ordered[] = array(
				'priority' => isset( $section['priority'] ) ? (int) $section['priority'] : 100,
				'position' => count( $ordered ),
				'markdown' => $section['markdown'],
			);
		}
		usort(
			$ordered,
			static function ( $a, $b ) {
				$priority_order = $a['priority'] <=> $b['priority'];
				return 0 !== $priority_order ? $priority_order : ( $a['position'] <=> $b['position'] );
			}
		);
		return implode( "\n\n", array_column( $ordered, 'markdown' ) );
	}

	/**
	 * Format a value for YAML output based on its type.
	 *
	 * @param string|bool $value The value to format.
	 * @param string      $type  The type of the value (e.g. 'string', 'date', 'url', 'number', 'boolean').
	 * @return string The formatted value for YAML.
	 */
	public static function format_yaml_value( $value, $type ) {
		switch ( $type ) {
			case 'boolean':
				return $value ? 'true' : 'false';
			case 'date':
			case 'number':
				return $value;
			default:
				return '"' . self::escape_yaml( $value ) . '"';
		}
	}

	/**
	 * Escape a string for use in YAML.
	 *
	 * @param string $value The value to escape.
	 * @return string The escaped value.
	 */
	public static function escape_yaml( string $value ): string {
		$value = self::decode_entities( $value );
		return str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $value );
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
	public static function decode_entities( string $value ): string {
		return html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
