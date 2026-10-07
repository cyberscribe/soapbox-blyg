<?php
/**
 * Markdown for a paragraph.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Render;

use SoapboxBlyg\Vendor\League\HTMLToMarkdown\Converter\ConverterInterface;
use SoapboxBlyg\Vendor\League\HTMLToMarkdown\Converter\ParagraphConverter;
use SoapboxBlyg\Vendor\League\HTMLToMarkdown\ElementInterface;
use SoapboxBlyg\Vendor\League\HTMLToMarkdown\PreConverterInterface;

defined( 'ABSPATH' ) || exit;

/**
 * The library's paragraph converter, kept away from paragraphs that hold blocks.
 *
 * The library treats every line of a paragraph as text: it strips the line's indentation and
 * escapes a leading list or quote marker. That is right for prose and wrong for a list, a quote or
 * a code block that was already written inside the paragraph, whose markers and indentation are
 * its structure. A fenced code block inside a list item, once the item's marker is escaped, is no
 * longer a code block, and what it held is read as Markdown.
 *
 * HTML does not allow a block inside a paragraph, but the parser makes one anyway: it wraps a
 * document that begins with bare text in a paragraph, and whatever follows goes inside it.
 */
class MarkdownParagraph implements ConverterInterface, PreConverterInterface {

	const BLOCKS = array( 'ul', 'ol', 'li', 'blockquote', 'pre', 'table', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'div', 'figure', 'figcaption', 'dl', 'dt', 'dd', 'p' );

	/**
	 * The library's converter.
	 *
	 * @var ParagraphConverter
	 */
	private $inner;

	/**
	 * Whether each paragraph being converted held a block, innermost last. It has to be read
	 * before the children are converted, because converting replaces them with text.
	 *
	 * @var bool[]
	 */
	private $holds_blocks = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->inner = new ParagraphConverter();
	}

	/**
	 * Notes whether the paragraph holds a block, before its children are converted.
	 *
	 * @param ElementInterface $element The element.
	 */
	public function preConvert( ElementInterface $element ): void {
		$this->holds_blocks[] = self::has_block( $element );
	}

	/**
	 * Whether any descendant of an element is a block.
	 *
	 * @param ElementInterface $element The element.
	 */
	private static function has_block( ElementInterface $element ): bool {
		foreach ( $element->getChildren() as $child ) {
			if ( in_array( $child->getTagName(), self::BLOCKS, true ) || ( $child->hasChildren() && self::has_block( $child ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Converts one p element.
	 *
	 * @param ElementInterface $element The element.
	 */
	public function convert( ElementInterface $element ): string {
		if ( ! array_pop( $this->holds_blocks ) ) {
			return $this->inner->convert( $element );
		}
		$value = trim( $element->getValue(), "\n" );
		return '' !== trim( $value ) ? "\n\n" . $value . "\n\n" : '';
	}

	/**
	 * The tags this converter handles.
	 *
	 * @return string[]
	 */
	public function getSupportedTags(): array {
		return array( 'p' );
	}
}
