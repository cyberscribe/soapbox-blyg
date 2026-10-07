<?php
/**
 * Preformatted blocks.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Render;

use SoapboxBlyg\Vendor\League\HTMLToMarkdown\Converter\ConverterInterface;
use SoapboxBlyg\Vendor\League\HTMLToMarkdown\ElementInterface;

defined( 'ABSPATH' ) || exit;

/**
 * A pre becomes a fenced code block whose fence is longer than any run of backticks inside it, so
 * nothing in the block can close it.
 *
 * The library fences with three backticks whatever the content, and passes a block through
 * untouched when its text happens to begin and end with a backtick, on the assumption that a
 * nested code element was already converted. Both let a block's own text end the block and
 * continue as live Markdown and HTML. Neither is done here: the block is always fenced, and only
 * its text is used.
 *
 * A fence is only a fence at the start of a line. A pre can turn up where Markdown has no room for
 * a block (inside a heading, a link, a table cell or emphasis), and there the opening fence would
 * land in the middle of a line and the code after it would be read as ordinary text. In those
 * places the code is written as a span instead. Everywhere else the block begins on a line of its
 * own; extra blank lines are collapsed when the Markdown is normalised.
 */
class MarkdownPre implements ConverterInterface {

	/**
	 * Elements whose content Markdown writes on one line, or wraps in markers of its own. A fence
	 * inside one of these would not start a line, so it would not be a fence.
	 */
	const NO_ROOM_FOR_A_BLOCK = array( 'a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'th', 'td', 'em', 'i', 'strong', 'b', 'code' );

	/**
	 * Converts one pre element.
	 *
	 * @param ElementInterface $element The element.
	 */
	public function convert( ElementInterface $element ): string {
		$content = preg_replace( '/\r\n|\r|\n/', "\n", $element->getValue() );

		// Where there is no room for a block, the code is written as a span on one line instead.
		if ( $element->isDescendantOf( self::NO_ROOM_FOR_A_BLOCK ) ) {
			return Markdown::code_span( trim( preg_replace( '/\s*\n\s*/', ' ', $content ) ) );
		}

		// Code that could become a tag or a link if a reader did not honour the fence is written
		// as an HTML block on one line instead. See Markdown::code_is_plain().
		if ( ! Markdown::code_is_plain( $content ) ) {
			Markdown::end_span_run();
			return "\n\n<pre><code>" . Markdown::code_html( "\n" === substr( $content, -1 ) ? substr( $content, 0, -1 ) : $content ) . "</code></pre>\n\n";
		}

		if ( '' !== $content && "\n" !== substr( $content, -1 ) ) {
			$content .= "\n";
		}
		$fence = str_repeat( '`', max( 3, Markdown::longest_run( $content, '`' ) + 1 ) );
		Markdown::end_span_run();
		return "\n\n" . $fence . "\n" . $content . $fence . "\n\n";
	}

	/**
	 * The tags this converter handles.
	 *
	 * @return string[]
	 */
	public function getSupportedTags(): array {
		return array( 'pre' );
	}
}
