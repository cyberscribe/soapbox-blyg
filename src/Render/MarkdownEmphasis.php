<?php
/**
 * Emphasis.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Render;

use SoapboxBlyg\Vendor\League\HTMLToMarkdown\Converter\EmphasisConverter;
use SoapboxBlyg\Vendor\League\HTMLToMarkdown\ElementInterface;

defined( 'ABSPATH' ) || exit;

/**
 * When two emphasised elements of the same kind touch, the library leaves out the markers between
 * them, so that the two read as one. It also trims what each holds. Together those can bring a
 * code span at the end of one right up against a code span at the start of the next, with the
 * space that kept them apart trimmed away. This puts the space back, and keeps the record of what
 * was last written true to what the element actually wrote.
 */
class MarkdownEmphasis extends EmphasisConverter {

	/**
	 * Converts one em, i, strong or b element.
	 *
	 * @param ElementInterface $element The element.
	 */
	public function convert( ElementInterface $element ): string {
		$markdown = parent::convert( $element );
		if ( '' === trim( $markdown ) ) {
			return $markdown;
		}
		// No opening marker was written, and the content starts with a span's delimiter.
		if ( '`' === $markdown[0] ) {
			$markdown = ' ' . $markdown;
		}
		Markdown::end_span_run( '`' === substr( $markdown, -1 ) );
		return $markdown;
	}
}
