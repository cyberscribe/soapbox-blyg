<?php
/**
 * A wrapper for the library's block converters.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Render;

use SoapboxBlyg\Vendor\League\HTMLToMarkdown\Configuration;
use SoapboxBlyg\Vendor\League\HTMLToMarkdown\ConfigurationAwareInterface;
use SoapboxBlyg\Vendor\League\HTMLToMarkdown\Converter\ConverterInterface;
use SoapboxBlyg\Vendor\League\HTMLToMarkdown\ElementInterface;
use SoapboxBlyg\Vendor\League\HTMLToMarkdown\PreConverterInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Runs one of the library's converters unchanged, and notes that what it wrote ends a run of code
 * spans. A paragraph, a heading, a table cell: each puts something of its own, a line break at
 * least, between the last span inside it and the first span of whatever comes next. See
 * Markdown::code_span() for why that matters.
 */
class MarkdownEndsRun implements ConverterInterface, ConfigurationAwareInterface, PreConverterInterface {

	/**
	 * The library's converter.
	 *
	 * @var ConverterInterface
	 */
	private $inner;

	/**
	 * Constructor.
	 *
	 * @param ConverterInterface $inner The library's converter.
	 */
	public function __construct( ConverterInterface $inner ) {
		$this->inner = $inner;
	}

	/**
	 * Passes the configuration on.
	 *
	 * @param Configuration $config The converter's configuration.
	 */
	public function setConfig( Configuration $config ): void {
		if ( $this->inner instanceof ConfigurationAwareInterface ) {
			$this->inner->setConfig( $config );
		}
	}

	/**
	 * Passes the pre-conversion hook on.
	 *
	 * @param ElementInterface $element The element.
	 */
	public function preConvert( ElementInterface $element ): void {
		if ( $this->inner instanceof PreConverterInterface ) {
			$this->inner->preConvert( $element );
		}
	}

	/**
	 * Converts one element.
	 *
	 * @param ElementInterface $element The element.
	 */
	public function convert( ElementInterface $element ): string {
		$markdown = $this->inner->convert( $element );
		// An element that wrote nothing, or only space a parent may trim away, ends nothing.
		if ( '' !== trim( $markdown ) ) {
			Markdown::end_span_run();
		}
		return $markdown;
	}

	/**
	 * The tags the library's converter handles.
	 *
	 * @return string[]
	 */
	public function getSupportedTags(): array {
		return $this->inner->getSupportedTags();
	}
}
