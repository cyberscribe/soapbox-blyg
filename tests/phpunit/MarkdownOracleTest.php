<?php
/**
 * content_md, judged by rendering it.
 *
 * content_md is the form the content hash covers, and the form a Blygger reader renders. A check
 * on its text cannot tell whether a "<" in it is live: that depends on every backtick, bracket
 * and backslash before it. So this renders it, with a CommonMark renderer set to let raw HTML and
 * unsafe links through, and inspects the HTML that comes out. Anything a reader would execute or
 * load shows up there as an element or an attribute.
 *
 * The renderer is league/commonmark, a development dependency. It is not shipped and the plugin
 * never renders Markdown.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Tests;

use League\CommonMark\CommonMarkConverter;
use SoapboxBlyg\Render\Sanitizer;

class MarkdownOracleTest extends TestCase {

	const BASE = 'https://example.org/post/';

	/** @var CommonMarkConverter|null */
	private static $commonmark = null;

	/**
	 * Renders Markdown as permissively as a reader could.
	 */
	private static function to_html( string $md ): string {
		if ( null === self::$commonmark ) {
			self::$commonmark = new CommonMarkConverter(
				array(
					'html_input'         => 'allow',
					'allow_unsafe_links' => true,
				)
			);
		}
		return (string) self::$commonmark->convert( $md );
	}

	/**
	 * Everything in rendered Markdown that the sanitiser would not have let into content_html.
	 *
	 * @return string[]
	 */
	private static function problems( string $rendered ): array {
		$allowed = array_merge( Sanitizer::TEXT_TAGS, array( 'a', 'img' ) );
		$doc     = new \DOMDocument();
		$before  = libxml_use_internal_errors( true );
		$doc->loadHTML( '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body><div id="root">' . $rendered . '</div></body></html>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $before );

		$problems = array();
		$root     = $doc->getElementById( 'root' );
		if ( ! $root ) {
			return array( 'the rendered HTML has no root: something closed it' );
		}
		foreach ( $root->getElementsByTagName( '*' ) as $element ) {
			$tag = strtolower( $element->nodeName );
			if ( ! in_array( $tag, $allowed, true ) ) {
				$problems[] = "<{$tag}>";
			}
			foreach ( iterator_to_array( $element->attributes ) as $attribute ) {
				$name  = strtolower( $attribute->nodeName );
				$value = $attribute->nodeValue;
				if ( 0 === strpos( $name, 'on' ) ) {
					$problems[] = "{$name} on <{$tag}>";
				}
				if ( ! in_array( $name, array( 'href', 'src', 'alt', 'title', 'start' ), true ) ) {
					$problems[] = "attribute {$name} on <{$tag}>";
				}
				if ( 'href' === $name || 'src' === $name ) {
					$scheme = strtolower( preg_replace( '/[\x00-\x20]+/', '', $value ) );
					$fine   = 0 === strpos( $scheme, 'http://' ) || 0 === strpos( $scheme, 'https://' )
						|| ( 'href' === $name && ( 0 === strpos( $scheme, '#' ) || 0 === strpos( $scheme, 'mailto:' ) || 0 === strpos( $scheme, 'tel:' ) ) );
					if ( ! $fine ) {
						$problems[] = "{$name}=\"{$value}\" on <{$tag}>";
					}
				}
			}
		}
		// Anything that escaped the wrapper div ended up outside it.
		foreach ( $doc->getElementsByTagName( 'body' )->item( 0 )->childNodes as $node ) {
			if ( $node instanceof \DOMElement && 'root' !== $node->getAttribute( 'id' ) ) {
				$problems[] = 'content outside the wrapper: <' . strtolower( $node->nodeName ) . '>';
			}
		}
		return array_values( array_unique( $problems ) );
	}

	/**
	 * Renders HTML and a title through the plugin, then the Markdown through CommonMark, and
	 * asserts nothing dangerous came out.
	 */
	private function assert_markdown_is_inert( string $html, string $title = 'Title', string $label = '' ): string {
		$result   = soapbox_blyg_render_html( $html, $title, array( 'base' => self::BASE ) );
		$md       = $result['content_md'];
		$problems = self::problems( self::to_html( $md ) );
		$this->assertSame( array(), $problems, "{$label}\n--- input\n{$html}\n--- title\n{$title}\n--- content_html\n{$result['content_html']}\n--- content_md\n{$md}\n--- rendered\n" . self::to_html( $md ) );
		// The protocol's own link syntax must never appear outside code by accident (spec 10.1).
		return $md;
	}

	/**
	 * Every input of the sanitiser's hostile suite, judged by rendering its Markdown.
	 */
	public function test_every_hostile_sanitiser_input_renders_inert() {
		$suite = new SanitizerHostileTest();
		$count = 0;
		foreach ( $suite->hostile_inputs() as $name => $case ) {
			$this->assert_markdown_is_inert( $case[0], 'Title', $name );
			// And with the same input as the title, which takes a different path.
			$this->assert_markdown_is_inert( '<p>body</p>', $case[0], $name . ' (as title)' );
			++$count;
		}
		$this->assertGreaterThan( 80, $count );
	}

	/**
	 * @dataProvider review_repros
	 */
	public function test_the_adversarial_reviews_cases_render_inert( string $html, string $title = 'Title' ) {
		$this->assert_markdown_is_inert( $html, $title );
	}

	public function review_repros(): array {
		$img = '<img src=x onerror=alert(1)>';
		$enc = htmlspecialchars( $img );
		return array(
			// A1: the title.
			'A1 entity-encoded tag in the title'       => array( '<p>x</p>', "Hello {$enc}" ),
			'A1 double-encoded tag in the title'       => array( '<p>x</p>', 'Hello ' . htmlspecialchars( $enc ) ),
			'A1 markdown link in the title'            => array( '<p>x</p>', '[click](javascript:alert(1))' ),
			'A1 image in the title'                    => array( '<p>x</p>', '![x](javascript:alert(1))' ),
			'A1 autolink in the title'                 => array( '<p>x</p>', '&lt;javascript:alert(1)&gt;' ),
			'A1 code span in the title'                => array( "<p>`{$enc}`</p>", '`' ),
			'A1 backslash before a bracket'            => array( '<p>x</p>', '\\[x](javascript:alert(1))' ),
			'A1 heading closed by hashes'              => array( '<p>x</p>', 'Title ## ' . $enc ),
			'A1 numeric entities in the title'         => array( '<p>x</p>', '&#60;img src=x onerror=alert(1)&#62;' ),
			'A1 setext underline cannot follow'        => array( '<p>===</p>', 'T' ),

			// A2: destinations.
			'A2 image src that ends the destination'   => array( '<img src="https://e.example/a.png)' . $enc . '" alt="a">' ),
			'A2 link href that ends the destination'   => array( '<a href="https://e.example/a)' . $enc . '">x</a>' ),
			'A2 href with a space and a tag'           => array( '<a href="https://e.example/a b>' . $enc . '">x</a>' ),
			'A2 href with angle brackets'              => array( '<a href="https://e.example/a><script>alert(1)</script>">x</a>' ),
			'A2 href that is its own text'             => array( '<a href="https://e.example/a>' . $enc . '">https://e.example/a&gt;' . $enc . '</a>' ),
			'A2 fragment href with a tag'              => array( '<a href="#x)' . $enc . '">x</a>' ),
			'A2 mailto with a tag'                     => array( '<a href="mailto:a@example.org)' . $enc . '">x</a>' ),
			'A2 mailto autolink with a tag'            => array( '<a href="mailto:a@example.org&gt;' . $enc . '">a@example.org&gt;' . $enc . '</a>' ),
			'A2 tel with a tag'                        => array( '<a href="tel:+1)' . $enc . '">x</a>' ),
			'A2 href with a backslash'                 => array( '<a href="https://e.example/a\\)' . $enc . '">x</a>' ),
			'A2 href with a newline'                   => array( "<a href=\"https://e.example/a\n\n{$enc}\">x</a>" ),
			'A2 href with a quote and a title'         => array( '<a href="https://e.example/a &quot;t&quot;)' . $enc . '" title="t">x</a>' ),
			'A2 src with brackets'                     => array( '<img src="https://e.example/[[7c9wk2mhq0v3xj8tn5rzfd41bg]].png" alt="">' ),

			// A3: code.
			'A3 pre whose text starts and ends with a backtick' => array( "<pre>`\n{$enc}\n`</pre>" ),
			'A3 pre that closes its own fence'         => array( "<pre>x\n```\n{$enc}\n```\ny</pre>" ),
			'A3 pre with a longer fence inside'        => array( "<pre>x\n`````\n{$enc}\n</pre>" ),
			'A3 pre code that closes its own fence'    => array( "<pre><code>x\n```\n{$enc}\n</code></pre>" ),
			'A3 pre with tilde fences'                 => array( "<pre>~~~\n{$enc}\n~~~</pre>" ),
			'A3 code span closed from inside'          => array( "<p><code>x`{$enc}`y</code></p>" ),
			'A3 code span with a double backtick'      => array( "<p><code>x``{$enc}``y</code></p>" ),
			'A3 code span that is only backticks'      => array( "<p><code>```</code> {$enc}</p>" ),
			'A3 code span starting with a backtick'    => array( "<p><code>`{$enc}</code></p>" ),
			'A3 code span ending with a backtick'      => array( "<p><code>{$enc}`</code></p>" ),
			'A3 stray backtick before a code span'     => array( "<p>a ` b <code>{$enc}</code> c ` d</p>" ),
			'A3 backticks around a code span'          => array( "<p>``<code>{$enc}</code>``</p>" ),
			'A3 code with markup inside'               => array( "<p><code><strong>{$enc}</strong></code></p>" ),
			'A3 pre with markup inside'                => array( "<pre><a href=\"https://e.example/\">{$enc}</a></pre>" ),
			'A3 multi-line code outside pre'           => array( "<p><code>a<br>```<br>{$enc}<br>```</code></p>" ),
			'A3 pre in a list item'                    => array( "<ul><li>item<pre>```\n{$enc}\n```</pre></li></ul>" ),
			'A3 pre in a blockquote'                   => array( "<blockquote><pre>```\n{$enc}\n```</pre></blockquote>" ),

			// A5: the protocol's own link syntax.
			'A5 item reference in a nested list'       => array( '<ul><li>a<ul><li>b<p>[[7c9wk2mhq0v3xj8tn5rzfd41bg]]</p></li></ul></li></ul>' ),

			// Text straight inside the one div the sanitiser keeps.
			'text directly inside a generated-content div' => array( '<div class="blyg-tk-gen">[x](javascript:alert(1)) ' . $enc . '</div>' ),
		);
	}

	public function test_item_references_never_appear_live_outside_code() {
		$id    = '7c9wk2mhq0v3xj8tn5rzfd41bg';
		$cases = array(
			"<p>[[{$id}]]</p>",
			"<ul><li>a<ul><li>b<ul><li>[[{$id}]]</li></ul></li></ul></li></ul>",
			"<blockquote><ul><li><p>x</p><p>![[{$id}]]</p></li></ul></blockquote>",
			"<a href=\"https://e.example/[[{$id}]]\">x</a>",
			"<img src=\"https://e.example/[[{$id}]].png\" alt=\"[[{$id}]]\" title=\"[[{$id}]]\">",
			"<a href=\"https://e.example/\" title=\"[[{$id}]]\">[[{$id}]]</a>",
		);
		foreach ( $cases as $html ) {
			foreach ( array( $html, "<p>before</p>{$html}" ) as $input ) {
				$md = soapbox_blyg_render_html( $input, "T [[{$id}]]", array( 'base' => self::BASE ) )['content_md'];
				$this->assertStringNotContainsString( "[[{$id}]]", $md, $md );
			}
		}
		// Inside code it would be inert by the protocol's own rule, in a reader that finds the code
		// where CommonMark does. Not every reader does, so it is not written as brackets at all.
		$md = soapbox_blyg_render_html( "<p><code>[[{$id}]]</code></p><pre>![[{$id}]]</pre>", 'T' )['content_md'];
		$this->assertStringNotContainsString( '[[', $md );
		$this->assertStringContainsString( "<code>&#91;&#91;{$id}&#93;&#93;</code>", $md );
		$this->assertStringContainsString( "<pre><code>&#33;&#91;&#91;{$id}&#93;&#93;</code></pre>", $md );
		$this->assertStringContainsString( "<code>[[{$id}]]</code>", self::to_html( $md ), 'and it reads back as what was written' );
	}

	/**
	 * Ordinary content keeps the Markdown it always had: these are the shapes E1's posts use.
	 */
	public function test_ordinary_content_is_written_as_before() {
		$r = soapbox_blyg_render_html(
			'<p>A <a href="https://e.example/page">link</a>, an autolink <a href="https://e.example/">https://e.example/</a>, mail <a href="mailto:a@example.org">a@example.org</a>, <a href="#top">top</a>.</p>'
			. '<p><img src="/files/a.jpg" alt="A photo" title="T"> and <code>inline()</code>.</p>'
			. "<pre><code>line one\nline two</code></pre><pre>plain pre</pre>"
			. '<p><a href="https://en.wikipedia.org/wiki/Poem_(disambiguation)">parens</a> <a href="https://e.example/a b">space</a></p>',
			'Salt & Pepper: a poem',
			array( 'base' => self::BASE )
		);
		$this->assertSame(
			"# Salt & Pepper: a poem\n\n"
			. "A [link](https://e.example/page), an autolink <https://e.example/>, mail <a@example.org>, [top](#top).\n\n"
			. "![A photo](https://example.org/files/a.jpg) and `inline()`.\n\n"
			. "```\nline one\nline two\n```\n\n```\nplain pre\n```\n\n"
			. "[parens](<https://en.wikipedia.org/wiki/Poem_(disambiguation)>) [space](<https://e.example/a b>)\n",
			$r['content_md']
		);
	}

	public function test_an_exclamation_mark_is_escaped_only_where_a_link_would_make_it_an_image() {
		$md = soapbox_blyg_render_html( '<p>Enjoy!</p><p><a href="https://e.example/a">link</a></p><p>Wow!<a href="https://e.example/b">b</a> and so!<em><a href="https://e.example/c">c</a></em></p>', 'T' )['content_md'];
		$this->assertStringContainsString( "Enjoy!\n\n[link](https://e.example/a)", $md, 'a link that starts the next paragraph is not next to it' );
		$this->assertStringContainsString( 'Wow\\![b](https://e.example/b)', $md );
		$this->assertStringNotContainsString( '<img', self::to_html( $md ) );
	}

	public function test_code_delimiters_are_always_longer_than_what_they_hold() {
		$md = soapbox_blyg_render_html( '<p><code>a`b</code> <code>a``b</code> <code>`a</code> <code>a`</code> <code> a </code></p>' . "<pre>```\nx\n````\n</pre>", 'T' )['content_md'];
		$this->assertStringContainsString( '``a`b`` ```a``b``` `` `a `` `` a` `` `  a  `', $md );
		$this->assertStringContainsString( "`````\n```\nx\n````\n`````", $md );
		// And they read back as the same code.
		$html = self::to_html( $md );
		foreach ( array( '<code>a`b</code>', '<code>a``b</code>', '<code>`a</code>', '<code>a`</code>', '<code> a </code>' ) as $span ) {
			$this->assertStringContainsString( $span, $html );
		}
		$this->assertStringContainsString( "<pre><code>```\nx\n````\n</code></pre>", $html );
	}

	/**
	 * Random nestings of pre, code, links, images and the rest, filled with the characters that
	 * mean something to Markdown or HTML. Fixed seed, so a failure reproduces.
	 */
	public function test_generated_hostile_documents_render_inert() {
		// SOAPBOX_BLYG_SEED and SOAPBOX_BLYG_FUZZ change the seed and the count, for hunting.
		mt_srand( (int) ( getenv( 'SOAPBOX_BLYG_SEED' ) ? getenv( 'SOAPBOX_BLYG_SEED' ) : 20261006 ) );
		$iterations = (int) ( getenv( 'SOAPBOX_BLYG_FUZZ' ) ? getenv( 'SOAPBOX_BLYG_FUZZ' ) : 10000 );
		for ( $i = 0; $i < $iterations; $i++ ) {
			$html  = '';
			$count = mt_rand( 1, 4 );
			for ( $n = 0; $n < $count; $n++ ) {
				$html .= self::random_node( 0 );
			}
			$title = mt_rand( 0, 3 ) ? self::random_text() : 'T';
			$this->assert_markdown_is_inert( $html, $title, "iteration {$i}" );
		}
		$this->assertTrue( true );
	}

	/** The raw material: every character with a meaning in Markdown or HTML, and some ready-made attacks. */
	const SHARDS = array(
		'`', '``', '```', '````', '~~~', '\\', '\\\\', '[', ']', '(', ')', '<', '>', '"', "'", '*', '_', '#', '&', '!', '|', '-', '+', '=', ' ', '  ', "\n", "\n\n", "\t", '    ',
		'x', 'word', 'two words', '1. ', '- ', '> ', '# ', '=== ', '--- ',
		'<img src=x onerror=alert(1)>', '<script>alert(1)</script>', '</code>', '</pre>', '-->', '<!--', '<svg onload=alert(1)>',
		'](javascript:alert(1))', '[x](javascript:alert(1))', '![x](javascript:alert(1))', '<javascript:alert(1)>', '[x]: javascript:alert(1)', '[x][x]',
		'&lt;', '&gt;', '&amp;', '&amp;lt;', '&#60;', '&#x3C;', '&quot;', '&#96;', '&#x5D;', '&nbsp;',
		'[[7c9wk2mhq0v3xj8tn5rzfd41bg]]', 'javascript:alert(1)', 'data:text/html,<script>alert(1)</script>', ' onerror=alert(1) ',
	);

	private static function random_text(): string {
		$text  = '';
		$count = mt_rand( 1, 6 );
		for ( $i = 0; $i < $count; $i++ ) {
			$text .= self::SHARDS[ mt_rand( 0, count( self::SHARDS ) - 1 ) ];
		}
		return $text;
	}

	/** Text as it would sit in post HTML: sometimes escaped once, sometimes twice, sometimes raw. */
	private static function random_text_html(): string {
		$text = self::random_text();
		switch ( mt_rand( 0, 5 ) ) {
			case 0:
				return $text; // Raw: real tags and entities, as an author with unfiltered_html could write.
			case 1:
				return htmlspecialchars( htmlspecialchars( $text ) );
			default:
				return htmlspecialchars( $text );
		}
	}

	private static function random_url(): string {
		$starts = array( 'https://e.example/', 'http://e.example/a', '/relative/', 'relative', '#frag', 'mailto:a@example.org', 'tel:+15551234', '//e.example/', '?q=' );
		return $starts[ mt_rand( 0, count( $starts ) - 1 ) ] . ( mt_rand( 0, 2 ) ? self::random_text() : '' );
	}

	private static function random_node( int $depth ): string {
		if ( $depth > 3 || 0 === mt_rand( 0, 3 ) ) {
			return self::random_text_html();
		}
		$children = '';
		$count    = mt_rand( 1, 3 );
		for ( $i = 0; $i < $count; $i++ ) {
			$children .= self::random_node( $depth + 1 );
		}
		$attr = static function ( string $value ): string {
			return htmlspecialchars( $value, ENT_QUOTES );
		};
		switch ( mt_rand( 0, 13 ) ) {
			case 0:
			case 1:
				return "<pre>{$children}</pre>";
			case 2:
			case 3:
				return "<code>{$children}</code>";
			case 4:
				return "<pre><code>{$children}</code></pre>";
			case 5:
			case 6:
				$title = mt_rand( 0, 2 ) ? '' : ' title="' . $attr( self::random_text() ) . '"';
				$url   = self::random_url();
				// Sometimes the link's text is its own address, which is the autolink path.
				$text = mt_rand( 0, 3 ) ? $children : htmlspecialchars( $url );
				return '<a href="' . $attr( $url ) . "\"{$title}>{$text}</a>";
			case 7:
				$title = mt_rand( 0, 2 ) ? '' : ' title="' . $attr( self::random_text() ) . '"';
				return '<img src="' . $attr( self::random_url() ) . '" alt="' . $attr( self::random_text() ) . "\"{$title}>";
			case 8:
				return "<p>{$children}</p>";
			case 9:
				return "<blockquote>{$children}</blockquote>";
			case 10:
				return "<ul><li>{$children}</li><li>" . self::random_node( $depth + 1 ) . '</li></ul>';
			case 11:
				return mt_rand( 0, 1 ) ? "<em>{$children}</em>" : "<strong>{$children}</strong>";
			case 12:
				return mt_rand( 0, 1 ) ? "<div class=\"blyg-tk-gen\">{$children}</div>" : "<span class=\"blyg-tk-gen\">{$children}</span>";
			default:
				return mt_rand( 0, 1 ) ? "<h2>{$children}</h2>" : "<table><tr><th>{$children}</th><td>" . self::random_node( $depth + 1 ) . '</td></tr></table>';
		}
	}
}
