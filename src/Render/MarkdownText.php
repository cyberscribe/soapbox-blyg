<?php
/**
 * Text nodes.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Render;

use SoapboxBlyg\Vendor\League\HTMLToMarkdown\Converter\TextConverter;
use SoapboxBlyg\Vendor\League\HTMLToMarkdown\ElementInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Corrections to how the library writes text.
 *
 * - It escapes every & as an entity. CommonMark needs that only where the text would otherwise
 *   read as one, and the Markdown is also meant to be read as plain text, so a bare ampersand
 *   stays bare.
 * - It does not escape backticks. A stray backtick in prose pairs with the delimiter of a code
 *   span later in the paragraph, which moves where that span ends and can leave its raw content
 *   outside any span. A backtick in text is written as a character reference, not with a
 *   backslash: an escaped backtick beside a span's delimiter still lengthens that delimiter's run.
 * - It leaves text directly inside a div unescaped altogether.
 * - It does not escape an exclamation mark that ends up straight before a link, or a run of
 *   tildes, both of which Markdown reads as syntax.
 */
class MarkdownText extends TextConverter {

	/**
	 * Whether the next thing written after a text node will be a link's opening bracket. Elements
	 * that write nothing before their content, and whitespace that will be dropped, are looked
	 * through. A following paragraph is not: it starts on a line of its own. Otherwise it errs
	 * towards yes: an escaped exclamation mark reads the same.
	 *
	 * @param ElementInterface $element A text node.
	 */
	private static function link_follows( ElementInterface $element ): bool {
		$stops = array( 'p', 'img', 'code', 'pre', 'br', 'hr', 'li', 'blockquote', 'table', 'tr', 'td', 'th', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' );
		$next  = $element->getNext();
		for ( $steps = 0; $next && $steps < 200; $steps++ ) {
			$tag = $next->getTagName();
			if ( 'a' === $tag ) {
				return true;
			}
			if ( in_array( $tag, $stops, true ) || ( '#text' === $tag && '' !== trim( $next->getValue() ) ) ) {
				return false;
			}
			$next = $next->getNext();
		}
		return false;
	}

	/**
	 * Converts one text node.
	 *
	 * @param ElementInterface $element The node.
	 */
	public function convert( ElementInterface $element ): string {
		$markdown = parent::convert( $element );
		$parent   = $element->getParent();
		if ( $parent && 'div' === $parent->getTagName() ) {
			$markdown = preg_replace( '~([*_\[\]\\\\])~u', '\\\\$1', $markdown );
		}
		$markdown = preg_replace( '/&amp;(?!#?[a-zA-Z0-9]+;)/', '&', $markdown );
		// "!" straight before a link would turn the link into an image.
		if ( '!' === substr( $markdown, -1 ) && self::link_follows( $element ) ) {
			$markdown = substr( $markdown, 0, -1 ) . '\\!';
		}
		if ( '' !== $markdown ) {
			Markdown::end_span_run();
		}
		// Three tildes at the start of a line open a code block, as three backticks do.
		$markdown = str_replace( '~~~', '\\~\\~\\~', $markdown );
		return str_replace( '`', Markdown::BACKTICK, $markdown );
	}
}
