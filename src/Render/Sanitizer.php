<?php
/**
 * The HTML sanitiser.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Render;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

defined( 'ABSPATH' ) || exit;

/**
 * Rebuilds a fragment of HTML from an allowlist, as self-contained markup (spec §5.2, §7): no
 * scripts, no styles, no relative URLs, and no attribute that varies with where it was rendered.
 *
 * Two properties matter more than prettiness, because the output is hashed and kept forever:
 *
 * - Deterministic. The same input gives the same bytes on every run, in every request context, or
 *   a save that changed nothing would publish a new version. So the tree is serialised here, by
 *   hand, rather than by libxml, and every attribute WordPress adds depending on context (loading,
 *   srcset, sizes, decoding, class) is dropped.
 * - Structural. Markup written before the block editor uses line breaks as paragraphs and layout
 *   elements as blocks. Those are repaired here, because the Markdown converter needs real
 *   paragraphs, and this is why the walk uses the DOM rather than a tag-by-tag filter.
 *
 * One instance sanitises one fragment.
 */
class Sanitizer {

	/**
	 * Elements kept, with no attributes, besides `a` and `img`. span and div are not listed: they
	 * survive only as the generated-content wire token.
	 */
	const TEXT_TAGS = array(
		'p',
		'em',
		'strong',
		'b',
		'i',
		'u',
		's',
		'sub',
		'sup',
		'br',
		'hr',
		'h1',
		'h2',
		'h3',
		'h4',
		'h5',
		'h6',
		'ul',
		'ol',
		'li',
		'blockquote',
		'q',
		'cite',
		'pre',
		'code',
		'figure',
		'figcaption',
		'table',
		'thead',
		'tbody',
		'tr',
		'th',
		'td',
		'dl',
		'dt',
		'dd',
		'abbr',
	);

	/**
	 * Removed together with everything inside them.
	 */
	const DROP_TAGS = array(
		'script',
		'style',
		'link',
		'form',
		'noscript',
		'template',
		'head',
		'meta',
		'title',
		'svg',
		'math',
		'button',
		'input',
		'select',
		'textarea',
		'canvas',
		'map',
		'object',
		'embed',
		'param',
	);

	/**
	 * Replaced by a plain link to their source, so the reader still has somewhere to go.
	 */
	const EMBED_TAGS = array( 'iframe', 'video', 'audio' );

	const VOID_TAGS = array( 'br', 'hr', 'img' );

	/**
	 * Elements that start a new block.
	 */
	const BLOCK_TAGS = array(
		'p',
		'div',
		'figure',
		'figcaption',
		'table',
		'ul',
		'ol',
		'li',
		'dl',
		'dt',
		'dd',
		'blockquote',
		'pre',
		'h1',
		'h2',
		'h3',
		'h4',
		'h5',
		'h6',
		'hr',
		'td',
		'th',
		'center',
		'section',
		'article',
		'header',
		'footer',
		'aside',
		'nav',
		'main',
		'address',
		'details',
		'summary',
		'iframe',
		'video',
		'audio',
		'form',
	);

	/**
	 * The URL relative links resolve against.
	 *
	 * @var string
	 */
	private $base;

	/**
	 * Whether runs of line breaks are repaired into paragraphs.
	 *
	 * @var bool
	 */
	private $repair;

	/**
	 * Whether the protocol's wire tokens are kept: the blyg-transclusion blockquote and the
	 * blyg-tk-gen wrapper. They are claims the publisher makes (this is a quotation of that item,
	 * this was machine-generated), so they are kept only for a caller that makes them. HTML that
	 * came from a post's author is not allowed to make them on the publisher's behalf.
	 *
	 * @var bool
	 */
	private $wire_tokens;

	/**
	 * Tag => attributes kept.
	 *
	 * @var array
	 */
	private $allowed;

	/**
	 * Media found, keyed by URL so each appears once, in document order.
	 *
	 * @var array
	 */
	private $media = array();

	/**
	 * Diagnostics for the backfill dump: embeds replaced by links, and image or embed sources that
	 * were not absolute. Not part of any snapshot.
	 *
	 * @var array
	 */
	private $stats = array(
		'embeds'       => 0,
		'relative_src' => 0,
	);

	/**
	 * Depth inside code and pre, where a line break means a newline in the code itself.
	 *
	 * @var int
	 */
	private $code = 0;

	/**
	 * Depth inside pre.
	 *
	 * @var int
	 */
	private $pre = 0;

	/**
	 * Constructor.
	 *
	 * @param string $base        The URL relative links resolve against.
	 * @param bool   $repair      Whether to turn runs of two or more line breaks into paragraph breaks.
	 * @param bool   $wire_tokens Whether to keep the protocol's wire tokens.
	 */
	public function __construct( string $base, bool $repair = true, bool $wire_tokens = true ) {
		$this->base        = $base;
		$this->repair      = $repair;
		$this->wire_tokens = $wire_tokens;

		$allowed        = array_fill_keys( self::TEXT_TAGS, array() );
		$allowed['a']   = array( 'href', 'title' );
		$allowed['img'] = array( 'src', 'alt', 'width', 'height' );

		/**
		 * Filters the elements the sanitiser keeps, as tag => list of attribute names.
		 *
		 * Removing a tag unwraps it: its text is kept. Adding a tag keeps it with no attributes.
		 * Only `a` and `img` carry attributes, and scripts, styles, forms and embeds are removed
		 * before this list is consulted, whatever it says.
		 *
		 * @param array $allowed Tag => attribute names.
		 */
		$this->allowed = (array) apply_filters( 'soapbox_blyg_allowed_html', $allowed );
	}

	/**
	 * Media found while sanitising.
	 *
	 * @return array[] { url, mime, alt }
	 */
	public function media(): array {
		return array_values( $this->media );
	}

	/**
	 * Diagnostics from the last run.
	 */
	public function stats(): array {
		return $this->stats;
	}

	/**
	 * Parses a fragment and returns it rebuilt from the allowlist.
	 *
	 * @param string $html HTML fragment.
	 */
	public function sanitize( string $html ): string {
		if ( '' === trim( $html ) ) {
			return '';
		}

		// libxml's HTML parser assumes ISO-8859-1 unless told otherwise; the meta tag tells it. The
		// wrapper div gives one known root to walk, whatever fragment the source handed over.
		$doc      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$doc->loadHTML(
			'<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head>'
			. '<body><div id="soapbox-blyg-root">' . $html . '</div></body></html>',
			LIBXML_NONET | LIBXML_COMPACT
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$root = $doc->getElementById( 'soapbox-blyg-root' );
		if ( ! $root ) {
			return '';
		}

		$this->code = 0;
		$this->pre  = 0;
		return $this->block_content( $root );
	}

	/**
	 * The children of a block-level container, as a sequence of blocks. This is where paragraph
	 * boundaries are decided, for the body root, p, blockquote and unwrapped layout containers:
	 *
	 * - Runs of text and inline elements become paragraphs. wpautop leaves them loose when a post
	 *   mixes hand-written HTML with plain lines, and loose they would run into the next block.
	 * - Two or more line breaks in a row end the paragraph. Older posts used a doubled break as
	 *   their paragraph break; kept as breaks, each became a line holding only "\" in the Markdown.
	 * - A block child (a figure inside a paragraph, which wpautop produces around a caption) ends
	 *   the paragraph and is emitted as a block of its own, since a paragraph cannot contain one.
	 *
	 * @param DOMNode $container The container.
	 */
	private function block_content( DOMNode $container ): string {
		$out    = '';
		$inline = '';
		$breaks = 0;
		$flush  = function () use ( &$out, &$inline ) {
			$run = self::trim_breaks( trim( $inline ) );
			// A paragraph of nothing but non-breaking spaces is a classic-editor spacer, not content.
			if ( '' !== trim( str_replace( "\u{00A0}", '', $run ) ) ) {
				$out .= "<p>{$run}</p>\n";
			}
			$inline = '';
		};

		foreach ( $container->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName -- DOM property.
			if ( $child instanceof DOMElement && 'br' === strtolower( $child->nodeName ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName
				++$breaks;
				continue;
			}
			if ( $breaks && $child instanceof DOMText && '' === trim( $child->nodeValue ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName
				continue; // The newline wpautop leaves after each break.
			}
			// Nothing that renders to nothing may split a run: a "more" comment between two breaks is
			// the commonest paragraph break in old posts, and a script between two breaks is no different.
			$renders_nothing = $child instanceof DOMElement
				? in_array( strtolower( $child->nodeName ), self::DROP_TAGS, true ) // phpcs:ignore WordPress.NamingConventions.ValidVariableName
				: ! $child instanceof DOMText; // Comments and processing instructions.
			if ( $renders_nothing ) {
				continue;
			}
			if ( $this->is_block( $child ) ) {
				$breaks = 0;
				$flush();
				$block = $this->node( $child, false );
				$out  .= '' === $block ? '' : $block . "\n";
				continue;
			}
			if ( $breaks >= 2 && $this->repair ) {
				$flush();
			} elseif ( $breaks >= 1 ) {
				$inline .= str_repeat( '<br>', $this->repair ? 1 : $breaks );
			}
			$breaks  = 0;
			$inline .= $this->node( $child, true );
		}
		$flush();
		return trim( $out );
	}

	/**
	 * One node, sanitised.
	 *
	 * @param DOMNode $node The node.
	 * @param bool    $in_p Whether we are inside a paragraph, where an embed's replacement must stay inline.
	 */
	private function node( DOMNode $node, bool $in_p ): string {
		if ( $node instanceof DOMText ) { // Includes CDATA sections, which extend DOMText.
			$text = $node->nodeValue; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			if ( $this->code > 0 ) {
				// Code is indented with non-breaking spaces in old posts. As a character it would survive
				// into the Markdown and break copy and paste, so it becomes a space.
				$text = str_replace( "\u{00A0}", ' ', $text );
				if ( 0 === $this->pre ) {
					// Outside pre, a source newline is only whitespace; the break before it is the line
					// break, so the newline straight after one is dropped and any other is a space.
					$text = preg_replace( '/\n/', ' ', preg_replace( '/^\r?\n/', '', $text ) );
				}
			}
			return self::text( $text );
		}
		if ( ! $node instanceof DOMElement ) {
			return ''; // Comments, processing instructions.
		}

		$tag = strtolower( $node->nodeName ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName

		if ( in_array( $tag, self::DROP_TAGS, true ) ) {
			return '';
		}

		if ( in_array( $tag, self::EMBED_TAGS, true ) ) {
			$src = $this->embed_src( $node );
			if ( '' === $src ) {
				return '';
			}
			++$this->stats['embeds'];
			$link = '<a href="' . self::attr( $src ) . '">' . self::text( $src ) . '</a>';
			return $in_p ? $link : "<p>{$link}</p>";
		}

		// span and div exist on the wire only as the generated-content token (spec §5.7). Any other
		// span or div is layout, so it is unwrapped and its content kept.
		if ( $this->wire_tokens && ( 'span' === $tag || 'div' === $tag ) && self::has_class( $node, 'blyg-tk-gen' ) ) {
			return "<{$tag} class=\"blyg-tk-gen\">" . $this->children( $node, $in_p ) . "</{$tag}>";
		}

		// Inside code or pre, a break is a line break in the code itself.
		if ( 'br' === $tag && $this->code > 0 ) {
			return "\n";
		}

		if ( 'div' === $tag || ! isset( $this->allowed[ $tag ] ) ) {
			// Layout containers (div, center, section, details...) lose the tag and keep the words, as
			// paragraphs when they stand at block level. Older posts used them as paragraphs.
			if ( ! $in_p && in_array( $tag, self::BLOCK_TAGS, true ) ) {
				return $this->block_content( $node );
			}
			return $this->children( $node, $in_p );
		}

		if ( 'p' === $tag ) {
			return $this->block_content( $node );
		}

		if ( 'pre' === $tag || 'code' === $tag ) {
			++$this->code;
			$this->pre += 'pre' === $tag ? 1 : 0;
			$inner      = $this->children( $node, $in_p );
			--$this->code;
			$this->pre -= 'pre' === $tag ? 1 : 0;
			if ( '' === trim( $inner ) ) {
				return '';
			}
			// Code with line breaks, outside a pre, is a code block in all but markup: fence it, or the
			// Markdown converter writes the breaks out as literal tags inside inline code.
			if ( 'code' === $tag && 0 === $this->pre && false !== strpos( $inner, "\n" ) ) {
				return '<pre><code>' . trim( $inner, "\n" ) . '</code></pre>';
			}
			return "<{$tag}>{$inner}</{$tag}>";
		}

		$attrs = '';

		if ( 'a' === $tag ) {
			$href = self::clean_url( $node->getAttribute( 'href' ), $this->base, true );
			if ( '' === $href ) {
				return $this->children( $node, $in_p ); // Named anchors, javascript: and unknown schemes: text only.
			}
			$attrs .= ' href="' . self::attr( $href ) . '"';
			if ( '' !== $node->getAttribute( 'title' ) ) {
				$attrs .= ' title="' . self::attr( $node->getAttribute( 'title' ) ) . '"';
			}
		} elseif ( 'img' === $tag ) {
			$src = self::clean_url( $node->getAttribute( 'src' ), $this->base, false );
			if ( '' === $src ) {
				return '';
			}
			$this->count_relative( $node->getAttribute( 'src' ) );
			$alt    = $node->getAttribute( 'alt' );
			$attrs .= ' src="' . self::attr( $src ) . '" alt="' . self::attr( $alt ) . '"';
			foreach ( array( 'width', 'height' ) as $dimension ) {
				if ( ctype_digit( $node->getAttribute( $dimension ) ) ) {
					$attrs .= " {$dimension}=\"" . $node->getAttribute( $dimension ) . '"';
				}
			}
			if ( ! isset( $this->media[ $src ] ) ) {
				$this->media[ $src ] = array(
					'url'  => $src,
					'mime' => self::mime_for( $src ),
					'alt'  => $alt,
				);
			}
		} elseif ( $this->wire_tokens && 'blockquote' === $tag && self::has_class( $node, 'blyg-transclusion' ) ) {
			// The transclusion wrapper (spec §10.2): a permanent wire token, kept with its data attributes.
			$id      = $node->getAttribute( 'data-blyg-id' );
			$version = $node->getAttribute( 'data-blyg-version' );
			if ( preg_match( '/^[0-9a-hjkmnp-tv-z]{26}$/', $id ) && preg_match( '/^[1-9][0-9]*$/', $version ) ) {
				$attrs = " class=\"blyg-transclusion\" data-blyg-id=\"{$id}\" data-blyg-version=\"{$version}\"";
			}
		}

		if ( in_array( $tag, self::VOID_TAGS, true ) ) {
			return "<{$tag}{$attrs}>";
		}

		if ( 'blockquote' === $tag ) {
			$inner = $this->block_content( $node );
		} else {
			$inner = $this->children( $node, $in_p );
			if ( in_array( $tag, self::BLOCK_TAGS, true ) ) {
				$inner = self::trim_breaks( $inner );
			}
		}

		// An element the unwrapping emptied would render as nothing but still convert to stray
		// Markdown (an empty list item, a bare ">"). Table cells keep their shape.
		if ( '' === trim( $inner ) && ! in_array( $tag, array( 'td', 'th', 'tr' ), true ) ) {
			return '';
		}

		return "<{$tag}{$attrs}>{$inner}</{$tag}>";
	}

	/**
	 * An element's children, sanitised and joined.
	 *
	 * @param DOMNode $node The element.
	 * @param bool    $in_p Whether we are inside a paragraph.
	 */
	private function children( DOMNode $node, bool $in_p ): string {
		$html = '';
		foreach ( $node->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			$html .= $this->node( $child, $in_p );
		}
		return $html;
	}

	/**
	 * Whether a node renders as a block. Includes an inline wrapper around a block, and code with
	 * line breaks in it outside a pre, which becomes a fenced block.
	 *
	 * @param DOMNode $node The node.
	 */
	private function is_block( DOMNode $node ): bool {
		if ( ! $node instanceof DOMElement ) {
			return false;
		}
		$tag = strtolower( $node->nodeName ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		if ( in_array( $tag, self::BLOCK_TAGS, true ) ) {
			return true;
		}
		if ( in_array( $tag, self::DROP_TAGS, true ) ) {
			return false;
		}
		if ( 'code' === $tag && $node->getElementsByTagName( 'br' )->length > 0 ) {
			for ( $up = $node->parentNode; $up instanceof DOMElement; $up = $up->parentNode ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName
				if ( 'pre' === strtolower( $up->nodeName ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName
					return false;
				}
			}
			return true;
		}
		foreach ( $node->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			if ( $this->is_block( $child ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Removes line breaks at the very start or end of a block. They come from wpautop meeting a
	 * removed comment or shortcode, render as nothing useful, and would become a stray "\" line in
	 * the Markdown. A non-breaking space beside such a break goes with it: that pair is how the
	 * classic editor wrote an empty line. A non-breaking space in front of text is indentation,
	 * and stays.
	 *
	 * @param string $inner Block content.
	 */
	private static function trim_breaks( string $inner ): string {
		return preg_replace( '#^(?:[\s\x{00A0}]*<br>)+\s*|\s*(?:<br>[\s\x{00A0}]*)+$#u', '', $inner );
	}

	/**
	 * Whether an element carries a class.
	 *
	 * @param DOMElement $element The element.
	 * @param string     $name    Class name.
	 */
	private static function has_class( DOMElement $element, string $name ): bool {
		return in_array( $name, preg_split( '/\s+/', trim( $element->getAttribute( 'class' ) ) ), true );
	}

	/**
	 * The URL an iframe, video or audio element points at. WordPress's video and audio shortcodes
	 * put the file on a source child, with a "_" query argument that counts players on the page.
	 * That count depends on what else was rendered earlier in the same request, so it is removed:
	 * left in, a backfill and a single save of the same post would hash differently.
	 *
	 * @param DOMElement $element The embed element.
	 */
	private function embed_src( DOMElement $element ): string {
		$src = $element->getAttribute( 'src' );
		if ( '' === $src ) {
			foreach ( $element->getElementsByTagName( 'source' ) as $source ) {
				$src = $source->getAttribute( 'src' );
				if ( '' !== $src ) {
					break;
				}
			}
		}
		if ( '' === $src ) {
			return '';
		}
		$src = preg_replace( '/([?&])_=\d+(&|$)/', '$1', $src );
		$src = rtrim( $src, '?&' );
		$this->count_relative( $src );
		return self::clean_url( $src, $this->base, false );
	}

	/**
	 * Counts a source written without scheme and host, for the dump's relative-src flag.
	 *
	 * @param string $src The source as written.
	 */
	private function count_relative( string $src ): void {
		if ( ! preg_match( '#^([a-z][a-z0-9+.\-]*:|//)#i', trim( $src ) ) ) {
			++$this->stats['relative_src'];
		}
	}

	/**
	 * Makes a URL absolute against a base, or returns '' if it cannot be used. Links may also be
	 * in-page fragments, mailto: or tel:, which stay as they are.
	 *
	 * @param string $url     The URL as written.
	 * @param string $base    The base URL.
	 * @param bool   $is_link Whether this is a link rather than a source.
	 */
	public static function clean_url( string $url, string $base, bool $is_link ): string {
		// Read the URL as a browser would before judging its scheme: tabs and line breaks are removed
		// wherever they are, and control characters and spaces at either end. Otherwise
		// "java&#9;script:" or a scheme behind a stray control byte would not look like a scheme here
		// and would look like one there.
		$url = trim( str_replace( array( "\t", "\n", "\r" ), '', $url ), "\x00..\x20\x7F" );
		if ( '' === $url ) {
			return '';
		}
		if ( $is_link && ( '#' === $url[0] || preg_match( '/^(mailto|tel):/i', $url ) ) ) {
			// These are passed on as written, so they are held to the characters such a link can
			// properly contain. Anything else is not a fragment, an address or a number.
			$fine = '#' === $url[0]
				? preg_match( '/^#[A-Za-z0-9._~%:@\/?!$&\'*+,;=-]*$/', $url )
				: ( preg_match( '/^mailto:[A-Za-z0-9._%+@,?&=;:~!$\'*\/-]+$/i', $url ) || preg_match( '/^tel:[0-9A-Za-z+.;=-]+$/i', $url ) );
			return $fine ? $url : '';
		}
		if ( preg_match( '/^([a-z][a-z0-9+.\-]*):/i', $url, $m ) ) {
			return in_array( strtolower( $m[1] ), array( 'http', 'https' ), true ) ? $url : '';
		}
		return self::absolute_url( $url, $base );
	}

	/**
	 * RFC 3986 §5.2 reference resolution, for the relative forms that turn up in post HTML.
	 *
	 * @param string $url  A relative reference.
	 * @param string $base The base URL.
	 */
	public static function absolute_url( string $url, string $base ): string {
		$b      = wp_parse_url( $base );
		$scheme = isset( $b['scheme'] ) ? $b['scheme'] : 'https';
		if ( 0 === strpos( $url, '//' ) ) {
			return $scheme . ':' . $url;
		}
		$origin = $scheme . '://' . ( isset( $b['host'] ) ? $b['host'] : '' ) . ( isset( $b['port'] ) ? ':' . $b['port'] : '' );
		$path   = isset( $b['path'] ) ? $b['path'] : '/';

		if ( '/' === $url[0] ) {
			$target = $url;
		} elseif ( '?' === $url[0] || '#' === $url[0] ) {
			$target = $path . $url;
		} else {
			$target = preg_replace( '#/[^/]*$#', '/', $path ) . $url;
		}

		// Remove dot segments from the path part only.
		$split    = strcspn( $target, '?#' );
		$segments = array();
		foreach ( explode( '/', substr( $target, 0, $split ) ) as $segment ) {
			if ( '..' === $segment ) {
				if ( count( $segments ) > 1 ) {
					array_pop( $segments );
				}
			} elseif ( '.' !== $segment ) {
				$segments[] = $segment;
			}
		}
		$clean = implode( '/', $segments );
		if ( preg_match( '#/\.{1,2}$#', substr( $target, 0, $split ) ) ) {
			$clean .= '/'; // "a/.." names a directory.
		}
		return $origin . ( '' === $clean || '/' !== $clean[0] ? '/' . $clean : $clean ) . substr( $target, $split );
	}

	/**
	 * MIME type from the file extension. The protocol requires a mime on every media entry, so an
	 * image with no recognisable extension (an avatar, a CDN URL) gets the honest generic type.
	 *
	 * @param string $url Media URL.
	 */
	public static function mime_for( string $url ): string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$type = wp_check_filetype( wp_basename( $path ) );
		return $type['type'] ? $type['type'] : 'application/octet-stream';
	}

	/**
	 * Escapes text content. Quotes are left alone so prose reads the same in the stored HTML.
	 *
	 * @param string $s Text.
	 */
	public static function text( string $s ): string {
		return htmlspecialchars( $s, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}

	/**
	 * Escapes an attribute value.
	 *
	 * @param string $s Value.
	 */
	public static function attr( string $s ): string {
		return htmlspecialchars( $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
