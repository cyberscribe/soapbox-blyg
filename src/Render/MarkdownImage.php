<?php
/**
 * Images.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Render;

use SoapboxBlyg\Vendor\League\HTMLToMarkdown\Converter\ConverterInterface;
use SoapboxBlyg\Vendor\League\HTMLToMarkdown\ElementInterface;

defined( 'ABSPATH' ) || exit;

/**
 * The library writes an image's alt text, address and title into the Markdown exactly as they
 * are. Each is somebody's free text, and Markdown has syntax of its own: alt text of
 * "x](javascript:alert(1))" closes the image early and leaves a second link behind it, an address
 * containing ")" ends the address early, and a tag in any of them becomes raw HTML in content_md.
 * So each is written in the form its position requires.
 */
class MarkdownImage implements ConverterInterface {

	/**
	 * Converts one img element.
	 *
	 * @param ElementInterface $element The element.
	 */
	public function convert( ElementInterface $element ): string {
		Markdown::end_span_run();
		$src   = Markdown::destination( $element->getAttribute( 'src' ) );
		$alt   = Markdown::escape_label( $element->getAttribute( 'alt' ) );
		$title = Markdown::escape_title( $element->getAttribute( 'title' ) );
		return '' !== $title ? '![' . $alt . '](' . $src . ' "' . $title . '")' : '![' . $alt . '](' . $src . ')';
	}

	/**
	 * The tags this converter handles.
	 *
	 * @return string[]
	 */
	public function getSupportedTags(): array {
		return array( 'img' );
	}
}
