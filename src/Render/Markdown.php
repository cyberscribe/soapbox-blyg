<?php
/**
 * HTML to Markdown.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Render;

use SoapboxBlyg\Vendor\League\HTMLToMarkdown\Converter\DivConverter;
use SoapboxBlyg\Vendor\League\HTMLToMarkdown\Converter\HeaderConverter;
use SoapboxBlyg\Vendor\League\HTMLToMarkdown\Converter\HorizontalRuleConverter;
use SoapboxBlyg\Vendor\League\HTMLToMarkdown\Converter\TableConverter;
use SoapboxBlyg\Vendor\League\HTMLToMarkdown\HtmlConverter;

defined( 'ABSPATH' ) || exit;

/**
 * Converts sanitised HTML to the Markdown whose bytes the content hash covers.
 *
 * The converter is league/html-to-markdown, namespace-prefixed by Strauss so that another plugin
 * bundling a different version cannot change what this one publishes. The library is loaded only
 * when something is rendered, so ordinary page views never pay for it.
 */
class Markdown {

	/**
	 * The configured converter, built on first use.
	 *
	 * @var HtmlConverter|null
	 */
	private static $converter = null;

	/**
	 * Whether the last thing written was a code span, with nothing after it yet.
	 *
	 * @var bool
	 */
	private static $after_span = false;

	/**
	 * How many code elements deep the conversion is. A code element inside another is converted
	 * by the library before the outer one, and its span becomes part of the outer one's text.
	 *
	 * @var int
	 */
	public static $code_depth = 0;

	/**
	 * Converts HTML that has already been through the sanitiser.
	 *
	 * @param string $html Sanitised HTML.
	 */
	public static function from_html( string $html ): string {
		if ( null === self::$converter ) {
			require_once dirname( SOAPBOX_BLYG_FILE ) . '/vendor-prefixed/autoload.php';
			self::$converter = new HtmlConverter(
				array(
					'header_style'    => 'atx',
					'strip_tags'      => true, // Tags with no Markdown form (abbr, cite, sup...) keep their text.
					'suppress_errors' => true,
					'list_item_style' => '-',
					'use_autolinks'   => true,
				)
			);
			$environment     = self::$converter->getEnvironment();
			foreach ( array( new TableConverter(), new MarkdownParagraph(), new HeaderConverter(), new DivConverter(), new HorizontalRuleConverter() ) as $block ) {
				$environment->addConverter( new MarkdownEndsRun( $block ) );
			}
			$environment->addConverter( new MarkdownHardBreak() );
			$environment->addConverter( new MarkdownText() );
			$environment->addConverter( new MarkdownBlock() );
			$environment->addConverter( new MarkdownImage() );
			$environment->addConverter( new MarkdownLink() );
			$environment->addConverter( new MarkdownCode() );
			$environment->addConverter( new MarkdownPre() );
			$environment->addConverter( new MarkdownListItem() );
			$environment->addConverter( new MarkdownList() );
			$environment->addConverter( new MarkdownBlockquote() );
			$environment->addConverter( new MarkdownEmphasis() );
		}
		self::$after_span = false;
		self::$code_depth = 0;
		return self::$converter->convert( $html );
	}

	/**
	 * A backtick that is text. Written as a character reference rather than with a backslash,
	 * because a backslash-escaped backtick next to a code span's delimiter still counts towards
	 * the length of that delimiter's run, and a reference never does.
	 */
	const BACKTICK = '&#96;';

	/**
	 * The length of the longest run of one character in a string.
	 *
	 * @param string $text      Text.
	 * @param string $character A single character.
	 */
	public static function longest_run( string $text, string $character ): int {
		$longest = 0;
		if ( preg_match_all( '/' . preg_quote( $character, '/' ) . '+/', $text, $runs ) ) {
			foreach ( $runs[0] as $run ) {
				$longest = max( $longest, strlen( $run ) );
			}
		}
		return $longest;
	}

	/**
	 * Records what was last written: something other than a code span, which ends a run of spans,
	 * or, for an element that wrote nothing after a span inside it, a span still.
	 *
	 * @param bool $still_a_span Whether the last character written is a span's closing delimiter.
	 */
	public static function end_span_run( bool $still_a_span = false ): void {
		self::$after_span = $still_a_span;
	}

	/**
	 * Whether code can be written between backticks.
	 *
	 * Backticks are safe only in a reader that pairs them as CommonMark says, and readers differ:
	 * some do not know fenced blocks at all, and some let emphasis around a span decide where the
	 * span ends. Where a reader gets it wrong, the code is read as Markdown. That does no harm
	 * unless the code can then become a tag, a link or an image, and for those it needs an
	 * angle bracket or a square bracket. Code with neither is written the ordinary way; code with
	 * either is written by code_html(), which no reader can misread.
	 *
	 * @param string $code The code.
	 */
	public static function code_is_plain( string $code ): bool {
		return false === strpbrk( $code, '<[' );
	}

	/**
	 * Code as the text of an HTML code element, with every character that means anything to
	 * Markdown or to HTML written as a numeric character reference, line breaks included. What
	 * comes out is one line with no syntax in it, so it reads the same wherever it lands and
	 * however the reader parses what is around it.
	 *
	 * @param string $code The code.
	 */
	public static function code_html( string $code ): string {
		$code = preg_replace( '/[\x00-\x08\x0B-\x1F\x7F]/', '', str_replace( array( "\r\n", "\r" ), "\n", $code ) );
		return preg_replace_callback(
			'/[^A-Za-z0-9 \x80-\xFF]/',
			static function ( $m ) {
				return '&#' . ord( $m[0] ) . ';';
			},
			$code
		);
	}

	/**
	 * Writes text as a code span that nothing inside it can close.
	 *
	 * The delimiter is one backtick longer than the longest run in the text, with a space of
	 * padding where the text begins or ends with a backtick (CommonMark removes one such space
	 * from each side).
	 *
	 * Two spans must not touch: the closing delimiter of one and the opening delimiter of the
	 * next would be read as a single longer run that matches neither. Whether anything will be
	 * written between two spans cannot be told from the document's shape, because emphasis markers
	 * are sometimes merged away, empty elements write nothing and whitespace is sometimes dropped.
	 * So it is tracked as it happens: every converter that writes something else ends the run, and
	 * a span written while the run is still open gets a space in front of it.
	 *
	 * @param string $code The code, on one line.
	 */
	public static function code_span( string $code ): string {
		if ( '' === $code ) {
			return '';
		}
		if ( ! self::code_is_plain( $code ) ) {
			self::end_span_run();
			return '<code>' . self::code_html( $code ) . '</code>';
		}
		$delimiter = str_repeat( '`', self::longest_run( $code, '`' ) + 1 );
		$pad       = '`' === $code[0] || '`' === substr( $code, -1 ) || ( ' ' === $code[0] && ' ' === substr( $code, -1 ) && '' !== trim( $code ) ) ? ' ' : '';
		$apart     = self::$after_span ? ' ' : '';

		self::$after_span = true;
		return $apart . $delimiter . $pad . $code . $pad . $delimiter;
	}

	/**
	 * Writes entities for the characters that would otherwise start an HTML tag or an entity. A
	 * bare ampersand stays bare; one that would read as an entity is written as one.
	 *
	 * @param string $text Text.
	 */
	private static function entities( string $text ): string {
		$text = preg_replace( '/&(?=#?[a-zA-Z0-9]+;)/', '&amp;', $text );
		return str_replace( array( '<', '>', '`' ), array( '&lt;', '&gt;', self::BACKTICK ), $text );
	}

	/**
	 * Escapes free text for a heading: everything Markdown would read as syntax in running text.
	 *
	 * @param string $text Plain text, one line.
	 */
	public static function escape_text( string $text ): string {
		$text = preg_replace( '/([\\\\*_\[\]])/', '\\\\$1', $text );
		$text = str_replace( '~~~', '\\~\\~\\~', $text );
		// A closing run of # would be read as the heading's own closing sequence and dropped.
		$text = preg_replace( '/(#+)$/', '\\\\$1', $text );
		return self::entities( $text );
	}

	/**
	 * Escapes free text for the square brackets of an image: the characters that would end the
	 * label or start a code span or a link inside it, and anything that would read as a tag.
	 *
	 * @param string $text Alt text.
	 */
	public static function escape_label( string $text ): string {
		$text = str_replace( array( "\r", "\n" ), ' ', $text );
		$text = preg_replace( '/([\\\\\[\]])/', '\\\\$1', $text );
		return self::entities( $text );
	}

	/**
	 * Escapes free text for the quoted title of a link or an image.
	 *
	 * @param string $text Title text.
	 */
	public static function escape_title( string $text ): string {
		$text = str_replace( array( "\r", "\n" ), ' ', $text );
		$text = preg_replace( '/([\\\\"\[\]])/', '\\\\$1', $text );
		return self::entities( $text );
	}

	/**
	 * Writes a URL as the destination of a link or an image.
	 *
	 * An address made only of characters that mean nothing to Markdown is written as it is, which
	 * is every ordinary URL. Anything else goes between angle brackets, where the only characters
	 * that matter are the brackets themselves, the backslash and line breaks, and those are
	 * percent-encoded. Square brackets are percent-encoded everywhere: "[[id]]" is the protocol's
	 * link syntax wherever it appears outside code (spec §10.1), an address included.
	 *
	 * @param string $url An absolute URL, a fragment, or a mailto: or tel: link.
	 */
	public static function destination( string $url ): string {
		$url = preg_replace_callback(
			'/[\x00-\x1F\x7F<>\\\\\[\]`]/',
			static function ( $m ) {
				return rawurlencode( $m[0] );
			},
			$url
		);
		return preg_match( '/[\s()"]/', $url ) ? '<' . $url . '>' : $url;
	}

	/**
	 * Normalises Markdown to the byte form that is promised and hashed: \n line endings, no
	 * trailing whitespace on any line, at most one blank line in a row, exactly one final \n.
	 *
	 * @param string $md Markdown.
	 */
	public static function normalize( string $md ): string {
		$md = str_replace( array( "\r\n", "\r" ), "\n", $md );
		$md = preg_replace( '/[ \t]+$/m', '', $md );
		$md = preg_replace( "/\n{3,}/", "\n\n", $md );
		return trim( $md, "\n" ) . "\n";
	}

	/**
	 * Makes a literal [[id]] or ![[id]] in authored prose plain text again.
	 *
	 * In the protocol both are grammar (spec §10.1): a link to, or a quotation of, another item,
	 * resolved at publish time, and an unresolvable one is a publish error. A WordPress post that
	 * happens to contain the sequence is not using that grammar, so the brackets are escaped.
	 * Inside code the sequence is already inert, and is left alone. Text is escaped by the converter
	 * as it is written, and addresses are percent-encoded, so this is a second line of defence
	 * rather than the first.
	 *
	 * @param string $md Markdown.
	 */
	public static function escape_item_references( string $md ): string {
		if ( false === strpos( $md, '[[' ) ) {
			return $md;
		}
		$fenced = false;
		$lines  = explode( "\n", $md );
		foreach ( $lines as $i => $line ) {
			// Fences are only ever written by MarkdownPre, at the start of a line or of a list item's
			// continuation, and are at least three backticks long.
			if ( preg_match( '/^\s*(?:>\s*)*(```+)\s*$/', $line ) ) {
				$fenced = ! $fenced;
				continue;
			}
			if ( $fenced || false === strpos( $line, '[[' ) ) {
				continue;
			}
			// Split on inline code spans and escape only what lies outside them.
			$parts = preg_split( '/(`+[^`]*`+)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE );
			foreach ( $parts as $j => $part ) {
				if ( '' !== $part && '`' !== $part[0] ) {
					$parts[ $j ] = preg_replace( '/\[\[([0-9a-hjkmnp-tv-z]{26}(?:@v\d+)?)\]\]/', '\\[\\[$1]]', $part );
				}
			}
			$lines[ $i ] = implode( '', $parts );
		}
		return implode( "\n", $lines );
	}
}
