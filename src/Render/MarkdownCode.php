<?php
/**
 * Code.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Render;

use SoapboxBlyg\Vendor\League\HTMLToMarkdown\Converter\ConverterInterface;
use SoapboxBlyg\Vendor\League\HTMLToMarkdown\ElementInterface;
use SoapboxBlyg\Vendor\League\HTMLToMarkdown\PreConverterInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Code is the one place in Markdown where text is written raw: nothing inside a code span is
 * escaped, so everything depends on the span not ending before its content does. The library
 * always delimits a span with one backtick, which a backtick in the code closes, leaving the rest
 * of the code as live Markdown and HTML.
 *
 * Here the delimiter is always longer than the longest run of backticks inside (CommonMark's own
 * rule for writing such code), and only the element's text is used, never markup inside it.
 *
 * Code inside a pre is handed up unwrapped; MarkdownPre fences the whole block.
 */
class MarkdownCode implements ConverterInterface, PreConverterInterface {

	/**
	 * Called before the element's children are converted: one level deeper into code.
	 *
	 * @param ElementInterface $element The element.
	 */
	public function preConvert( ElementInterface $element ): void {
		unset( $element );
		++Markdown::$code_depth;
	}


	/**
	 * Converts one code element.
	 *
	 * @param ElementInterface $element The element.
	 */
	public function convert( ElementInterface $element ): string {
		--Markdown::$code_depth;
		$code   = $element->getValue();
		$parent = $element->getParent();
		if ( $parent && 'pre' === $parent->getTagName() ) {
			return $code . "\n";
		}
		$code = preg_replace( '/\r\n|\r|\n/', '', $code );
		if ( Markdown::$code_depth > 0 ) {
			// Inside another code element: this becomes part of that element's text, and that
			// element's delimiter is what holds it.
			return $code;
		}
		return Markdown::code_span( $code );
	}

	/**
	 * The tags this converter handles.
	 *
	 * @return string[]
	 */
	public function getSupportedTags(): array {
		return array( 'code' );
	}
}
