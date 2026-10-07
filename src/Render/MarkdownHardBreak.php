<?php
/**
 * Hard breaks.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Render;

use SoapboxBlyg\Vendor\League\HTMLToMarkdown\Converter\HardBreakConverter;
use SoapboxBlyg\Vendor\League\HTMLToMarkdown\ElementInterface;

defined( 'ABSPATH' ) || exit;

/**
 * A line break becomes a backslash hard break ("\" and a newline), CommonMark's other spelling.
 * The library's default is two trailing spaces, which the no-trailing-whitespace rule would strip,
 * and every line break in every poem would silently disappear from the Markdown.
 */
class MarkdownHardBreak extends HardBreakConverter {

	/**
	 * Converts one br element.
	 *
	 * @param ElementInterface $element The element.
	 */
	public function convert( ElementInterface $element ): string {
		Markdown::end_span_run();
		return '\\' . ltrim( parent::convert( $element ), ' ' );
	}
}
