<?php
/**
 * Lists.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Render;

use SoapboxBlyg\Vendor\League\HTMLToMarkdown\Converter\ListBlockConverter;
use SoapboxBlyg\Vendor\League\HTMLToMarkdown\ElementInterface;

defined( 'ABSPATH' ) || exit;

/**
 * A list, like a blockquote, only exists at the start of a line, and a code fence inside one of
 * its items depends on it existing. A list that is not nested in another begins on a line of its
 * own. A nested list is left to the library, which already starts it on a new line.
 */
class MarkdownList extends ListBlockConverter {

	/**
	 * Converts one ul or ol element.
	 *
	 * @param ElementInterface $element The element.
	 */
	public function convert( ElementInterface $element ): string {
		Markdown::end_span_run();
		$markdown = parent::convert( $element );
		return $element->isDescendantOf( 'li' ) ? $markdown : "\n\n" . $markdown;
	}
}
