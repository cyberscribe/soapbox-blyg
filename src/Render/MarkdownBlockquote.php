<?php
/**
 * Blockquotes.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Render;

use SoapboxBlyg\Vendor\League\HTMLToMarkdown\Converter\ConverterInterface;
use SoapboxBlyg\Vendor\League\HTMLToMarkdown\ElementInterface;

defined( 'ABSPATH' ) || exit;

/**
 * A blockquote is only a blockquote at the start of a line. The library writes one straight after
 * whatever came before it, so a quote that follows an image inside a list item, say, begins in the
 * middle of a line; it is then not a quote, and a code fence inside it is not a fence, and the
 * code falls out as live text. Here a quote always begins on a line of its own.
 *
 * The lines are written as the library writes them. Runs of blank lines inside the quote are
 * collapsed first, so that the blocks inside, which also begin on lines of their own, do not add
 * empty quoted lines.
 */
class MarkdownBlockquote implements ConverterInterface {

	/**
	 * Converts one blockquote element.
	 *
	 * @param ElementInterface $element The element.
	 */
	public function convert( ElementInterface $element ): string {
		Markdown::end_span_run();
		$content  = trim( preg_replace( "/\n{3,}/", "\n\n", preg_replace( '/\r\n|\r/', "\n", $element->getValue() ) ) );
		$markdown = "\n\n";
		foreach ( explode( "\n", $content ) as $line ) {
			$markdown .= '> ' . $line . "\n";
		}
		return $markdown . "\n";
	}

	/**
	 * The tags this converter handles.
	 *
	 * @return string[]
	 */
	public function getSupportedTags(): array {
		return array( 'blockquote' );
	}
}
