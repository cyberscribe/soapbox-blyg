<?php
/**
 * The renderer: what gets through, what is repaired, and what the Markdown looks like.
 *
 * E1 holds the renderer to 853 real posts byte for byte, but only on the machine that made the
 * reference dump. These cases are the ones that must hold on every PHP and libxml the plugin
 * supports.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Tests;

use SoapboxBlyg\Render\Markdown;

class RenderTest extends TestCase {

	private function render( string $html, array $opts = array() ): array {
		return soapbox_blyg_render_html( $html, 'T', $opts + array( 'base' => 'https://example.org/2020/01/post/' ) );
	}

	public function test_title_leads_both_forms_and_markdown_is_normalised() {
		$r = soapbox_blyg_render_html( "<p>Body   \r\ntext.</p>\n\n\n", "A &amp; B\n<em>title</em>" );
		$this->assertSame( "<h1>A &amp; B title</h1>\n<p>Body   \r\ntext.</p>", $r['content_html'] );
		$this->assertSame( "# A & B title\n\nBody text.\n", $r['content_md'] );
		$this->assertSame( array(), $r['media'] );
	}

	public function test_hostile_and_contextual_markup_is_removed() {
		$r    = $this->render(
			'<p class="x" style="color:red" onclick="alert(1)" id="p1">Text <span class="wp-x">kept</span></p>'
			. '<script>alert(1)</script><style>p{}</style><form><input name="q"></form><noscript>n</noscript>'
			. '<p><a href="javascript:alert(1)">js</a> <a name="anchor">named</a> <a href="#frag">frag</a> <a href="mailto:a@example.org">mail</a></p>'
			. '<p><img src="x.png" srcset="x.png 2x" loading="lazy" decoding="async" class="wp-image-1" width="10" height="20" alt="alt"></p>'
		);
		$html = $r['content_html'];
		foreach ( array( '<script', '<style', '<form', '<input', '<noscript', 'onclick', 'style=', 'class=', 'id=', 'srcset', 'loading', 'decoding', 'javascript:', 'alert(1)' ) as $gone ) {
			$this->assertStringNotContainsString( $gone, $html, $gone );
		}
		$this->assertStringContainsString( '<p>Text kept</p>', $html );
		$this->assertStringContainsString( '<p>js named <a href="#frag">frag</a> <a href="mailto:a@example.org">mail</a></p>', $html );
		$this->assertStringContainsString( '<img src="https://example.org/2020/01/post/x.png" alt="alt" width="10" height="20">', $html );
	}

	public function test_every_url_is_made_absolute_against_the_base() {
		$r = $this->render(
			'<p><a href="/root">a</a> <a href="sibling">b</a> <a href="../up/">c</a> <a href="//cdn.example.net/x">d</a> <a href="?q=1">e</a></p>'
			. '<p><img src="/files/a.jpg" alt=""><img src="/files/a.jpg" alt="again"><img src="https://other.example/b" alt="b"></p>'
		);
		foreach ( array( 'href="https://example.org/root"', 'href="https://example.org/2020/01/post/sibling"', 'href="https://example.org/2020/01/up/"', 'href="https://cdn.example.net/x"', 'href="https://example.org/2020/01/post/?q=1"' ) as $link ) {
			$this->assertStringContainsString( $link, $r['content_html'] );
		}
		$this->assertSame(
			array(
				array( 'url' => 'https://example.org/files/a.jpg', 'mime' => 'image/jpeg', 'alt' => '' ),
				array( 'url' => 'https://other.example/b', 'mime' => 'application/octet-stream', 'alt' => 'b' ),
			),
			$r['media'],
			'each image once, in order, and a mime on every entry'
		);
		$this->assertSame( 2, $r['stats']['relative_src'] );
	}

	public function test_embeds_become_links_and_the_player_counter_is_dropped() {
		$r = $this->render( '<iframe src="https://video.example/embed/1"></iframe><p>See <video><source src="/v/clip.mp4?_=3"></video></p>' );
		$this->assertStringContainsString( '<p><a href="https://video.example/embed/1">https://video.example/embed/1</a></p>', $r['content_html'] );
		$this->assertStringContainsString( "<p>See</p>\n<p><a href=\"https://example.org/v/clip.mp4\">https://example.org/v/clip.mp4</a></p>", $r['content_html'], 'a player is a block of its own' );
		$this->assertSame( 2, $r['stats']['embeds'] );
	}

	public function test_wire_tokens_survive_and_other_spans_do_not() {
		$id = '7c9wk2mhq0v3xj8tn5rzfd41bg';
		$r  = $this->render(
			'<blockquote class="blyg-transclusion extra" data-blyg-id="' . $id . '" data-blyg-version="3" data-other="x"><p>quoted</p></blockquote>'
			. '<blockquote class="blyg-transclusion" data-blyg-id="NOT-AN-ID" data-blyg-version="1"><p>bad</p></blockquote>'
			. '<p><span class="blyg-tk-gen">made</span> <span class="other">plain</span></p><div class="blyg-tk-gen"><p>block</p></div>'
		);
		$this->assertStringContainsString( '<blockquote class="blyg-transclusion" data-blyg-id="' . $id . '" data-blyg-version="3"><p>quoted</p></blockquote>', $r['content_html'] );
		$this->assertStringContainsString( '<blockquote><p>bad</p></blockquote>', $r['content_html'] );
		$this->assertStringContainsString( '<p><span class="blyg-tk-gen">made</span> plain</p>', $r['content_html'] );
		$this->assertStringContainsString( '<div class="blyg-tk-gen"><p>block</p></div>', $r['content_html'] );
	}

	public function test_break_runs_become_paragraphs_and_single_breaks_stay() {
		$r = $this->render( "<p>One<br /><br />Two<br />\n<br />\nThree<br />\n<!--more--><br />\nFour<br>Five</p><p>&nbsp;</p><p>&nbsp;<br />\n<strong>Six</strong></p><p>&nbsp;&nbsp;indented</p>" );
		$this->assertSame(
			"<h1>T</h1>\n<p>One</p>\n<p>Two</p>\n<p>Three</p>\n<p>Four<br>Five</p>\n<p><strong>Six</strong></p>\n<p>\u{00A0}\u{00A0}indented</p>",
			$r['content_html']
		);
		// The converter collapses the non-breaking indentation to one space; content_html keeps it.
		$this->assertSame( "# T\n\nOne\n\nTwo\n\nThree\n\nFour\\\nFive\n\n**Six**\n\n indented\n", str_replace( "\u{00A0}", ' ', $r['content_md'] ) );
		$this->assertSame( 1, $r['stats']['breaks'] );
	}

	public function test_break_repair_can_be_switched_off() {
		$r = $this->render( '<p>One<br><br>Two</p>', array( 'repair' => false ) );
		$this->assertStringContainsString( '<p>One<br><br>Two</p>', $r['content_html'] );
	}

	public function test_code_with_breaks_is_fenced_with_its_indentation() {
		$r = $this->render( '<p><code><font color="#000000">&lt;a&gt;<br />&nbsp;&nbsp;&lt;b&gt;<br /></font></code></p><p>Inline <code>x &lt; y</code>.</p>' );
		$this->assertStringContainsString( "<pre><code>&lt;a&gt;\n  &lt;b&gt;</code></pre>", $r['content_html'] );
		// Code that holds an angle or a square bracket is written as an HTML code element, every
		// character a reference, on one line: a reader that does not honour a fence or pairs
		// backticks its own way still cannot read it as a tag or a link.
		$this->assertStringContainsString( "<pre><code>&#60;a&#62;&#10;  &#60;b&#62;</code></pre>", $r['content_md'] );
		$this->assertStringContainsString( 'Inline <code>x &#60; y</code>.', $r['content_md'] );

		// Code with neither is fenced, with its indentation.
		$r = $this->render( '<p><code>if x:<br />&nbsp;&nbsp;return 1<br /></code></p><p>Inline <code>x == y</code>.</p>' );
		$this->assertStringContainsString( "```\nif x:\n  return 1\n```", $r['content_md'] );
		$this->assertStringContainsString( 'Inline `x == y`.', $r['content_md'] );
	}

	public function test_bracketed_code_reads_back_as_the_same_code() {
		$code = "<?php\n\tif ( \$a[0] < 1 && \$b ) { echo '*x*', \"`y`\"; }\n\n# not a heading\n[[ref]] \\ &amp; &#60;";
		$r    = $this->render( '<pre><code>' . htmlspecialchars( $code ) . '</code></pre><p>And <code>' . htmlspecialchars( 'a[i] <b> `c` *d*' ) . '</code>.</p>' );
		$this->assertStringNotContainsString( '<?php', $r['content_md'] );
		$this->assertStringNotContainsString( '[[', $r['content_md'] );
		$this->assertSame( 1, substr_count( $r['content_md'], '<pre><code>' ), 'the block is one line of the Markdown' );

		$converter = new \League\CommonMark\CommonMarkConverter( array( 'html_input' => 'allow' ) );
		$doc       = new \DOMDocument();
		$doc->loadHTML( '<meta charset="utf-8">' . $converter->convert( $r['content_md'] )->getContent() );
		$this->assertSame( $code, $doc->getElementsByTagName( 'pre' )->item( 0 )->textContent );
		$this->assertSame( 'a[i] <b> `c` *d*', $doc->getElementsByTagName( 'code' )->item( 1 )->textContent );
	}

	public function test_loose_inline_content_and_layout_containers_become_paragraphs() {
		$r = $this->render( 'Loose text <em>here</em><div>In a div</div><center>Centred</center><p><figure><img src="/a.png" alt="A"><figcaption>Cap</figcaption></figure>After</p><ul><li></li><li>Item</li></ul>' );
		$this->assertSame(
			"<h1>T</h1>\n<p>Loose text <em>here</em></p>\n<p>In a div</p>\n<p>Centred</p>\n<figure><img src=\"https://example.org/a.png\" alt=\"A\"><figcaption>Cap</figcaption></figure>\n<p>After</p>\n<ul><li>Item</li></ul>",
			$r['content_html']
		);
		$this->assertStringContainsString( "![A](https://example.org/a.png)\n\nCap\n\nAfter\n\n- Item\n", $r['content_md'] );
	}

	public function test_markdown_keeps_bare_ampersands_and_tables() {
		$r = $this->render( '<p>Salt &amp; pepper, &amp;copy; stays escaped.</p><table><thead><tr><th>A</th><th>B</th></tr></thead><tbody><tr><td>1</td><td>2</td></tr></tbody></table>' );
		$this->assertStringContainsString( 'Salt & pepper, &amp;copy; stays escaped.', $r['content_md'] );
		$this->assertStringContainsString( "| A | B |\n|---|---|\n| 1 | 2 |", $r['content_md'] );
	}

	public function test_the_same_input_renders_the_same_bytes_twice() {
		$html = '<p>Poem line one<br>line two</p><p><img src="/a.png" alt=""></p>';
		$this->assertSame( $this->render( $html ), $this->render( $html ) );
	}

	public function test_the_allowlist_filter_can_remove_a_tag() {
		add_filter(
			'soapbox_blyg_allowed_html',
			static function ( $allowed ) {
				unset( $allowed['u'] );
				return $allowed;
			}
		);
		$this->assertStringContainsString( '<p>under</p>', $this->render( '<p><u>under</u></p>' )['content_html'] );
	}

	public function test_literal_item_references_are_escaped_outside_code_only() {
		$id = '7c9wk2mhq0v3xj8tn5rzfd41bg';
		$md = "See [[{$id}]] here.\n\n![[{$id}]]\n\nInline `[[{$id}]]` code.\n\n```\n[[{$id}]]\n```\n\nNot one: [[short]] [[UPPERCASE00000000000000000]].\n";
		$this->assertSame(
			"See \\[\\[{$id}]] here.\n\n!\\[\\[{$id}]]\n\nInline `[[{$id}]]` code.\n\n```\n[[{$id}]]\n```\n\nNot one: [[short]] [[UPPERCASE00000000000000000]].\n",
			Markdown::escape_item_references( $md )
		);
	}

	public function test_the_bundled_library_is_only_ever_loaded_under_our_prefix() {
		$this->render( '<p>x</p>' );
		foreach ( get_declared_classes() as $class ) {
			$this->assertStringStartsNotWith( 'League\\HTMLToMarkdown', $class );
		}
		$this->assertTrue( class_exists( 'SoapboxBlyg\\Vendor\\League\\HTMLToMarkdown\\HtmlConverter', false ) );
	}
}
