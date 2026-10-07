<?php
/**
 * Blocks with no Markdown form.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Render;

use SoapboxBlyg\Vendor\League\HTMLToMarkdown\Converter\ConverterInterface;
use SoapboxBlyg\Vendor\League\HTMLToMarkdown\ElementInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Block elements Markdown has no syntax for keep their content as a block of its own. Without
 * this, a figure's caption runs onto the same line as its image.
 */
class MarkdownBlock implements ConverterInterface {

	/**
	 * Converts one element.
	 *
	 * @param ElementInterface $element The element.
	 */
	public function convert( ElementInterface $element ): string {
		$value = trim( $element->getValue() );
		if ( '' === $value ) {
			return '';
		}
		Markdown::end_span_run();
		return "\n\n" . $value . "\n\n";
	}

	/**
	 * The tags this converter handles.
	 *
	 * @return string[]
	 */
	public function getSupportedTags(): array {
		return array( 'figure', 'figcaption', 'dl', 'dt', 'dd' );
	}
}
