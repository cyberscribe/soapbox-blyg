<?php
/**
 * List items.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Render;

use SoapboxBlyg\Vendor\League\HTMLToMarkdown\Converter\ListItemConverter;
use SoapboxBlyg\Vendor\League\HTMLToMarkdown\ElementInterface;

defined( 'ABSPATH' ) || exit;

/**
 * One correction to the library's list items. It indents an item's later lines by four spaces,
 * which is right when the item begins with text. When the item begins with another list, the
 * inner item's marker lands on the outer marker's line ("- - text"), the inner item's content
 * then starts two columns further right than the library assumes, and its later lines are indented
 * too far for CommonMark to accept a closing code fence among them. A fenced block there never
 * closes where it should.
 *
 * So an item that begins with a list marker is written with its own marker on a line by itself,
 * and everything it holds indented beneath: the indentation the library assumed is then true.
 */
class MarkdownListItem extends ListItemConverter {

	/**
	 * Converts one li element.
	 *
	 * @param ElementInterface $element The element.
	 */
	public function convert( ElementInterface $element ): string {
		Markdown::end_span_run();
		$markdown = parent::convert( $element );
		$parent   = $element->getParent();
		if ( ! $parent || ! in_array( $parent->getTagName(), array( 'ul', 'ol' ), true ) ) {
			$markdown = "\n\n" . $markdown; // An item with no list around it still has to start a line.
		}
		if ( preg_match( '/^(\n*)(-|\*|\+|\d+\.) ((?:-|\*|\+|\d+[.)]) .*)$/s', $markdown, $m ) ) {
			return $m[1] . $m[2] . "\n    " . $m[3];
		}
		return $markdown;
	}
}
