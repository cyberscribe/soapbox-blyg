<?php
/**
 * Links.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Render;

use SoapboxBlyg\Vendor\League\HTMLToMarkdown\Converter\ConverterInterface;
use SoapboxBlyg\Vendor\League\HTMLToMarkdown\ElementInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Writes a link with its address and title in the form their positions require. See
 * MarkdownImage for why the library's own converter is not used.
 *
 * The shapes the library produces for ordinary links are kept, so that an ordinary link's
 * Markdown is the same as it always was: an address that is also the link's text becomes an
 * autolink, and so does an email address behind a mailto: link.
 */
class MarkdownLink implements ConverterInterface {

	/**
	 * Converts one a element.
	 *
	 * @param ElementInterface $element The element.
	 */
	public function convert( ElementInterface $element ): string {
		$href  = $element->getAttribute( 'href' );
		$title = $element->getAttribute( 'title' );
		$text  = trim( $element->getValue(), "\t\n\r\0\x0B" );
		if ( '' === $href ) {
			return $text;
		}
		Markdown::end_span_run();
		$destination = Markdown::destination( $href );

		if ( '' !== $title ) {
			return '[' . $text . '](' . $destination . ' "' . Markdown::escape_title( $title ) . '")';
		}
		// An autolink is written raw between angle brackets, so it is used only for an address that
		// needed no change to be written there.
		if ( $href === $text && $destination === $href && preg_match( '/^[A-Za-z][A-Za-z0-9.+-]{1,31}:[^<>\x00-\x20]*$/', $href ) ) {
			return '<' . $href . '>';
		}
		if ( 'mailto:' . $text === $href && false !== filter_var( $text, FILTER_VALIDATE_EMAIL ) && ! preg_match( '/[<>\s\\\\]/', $text ) ) {
			return '<' . $text . '>';
		}
		return '[' . $text . '](' . $destination . ')';
	}

	/**
	 * The tags this converter handles.
	 *
	 * @return string[]
	 */
	public function getSupportedTags(): array {
		return array( 'a' );
	}
}
