<?php
/**
 * Unicode on the wire (spec 0.4 decision #103, blygger-spec#6): the hash over the bytes served,
 * text with nothing XML forbids, one ASCII spelling for an origin, and text that is never split
 * or made invalid.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Tests;

use SoapboxBlyg\Ledger\Snapshot;
use SoapboxBlyg\Mentions\Verifier;
use SoapboxBlyg\Origins\Origin;
use SoapboxBlyg\Origins\PostTypeSource;
use SoapboxBlyg\Plugin;
use SoapboxBlyg\Render\ContentRenderer;
use SoapboxBlyg\Surface\Locations;

class UnicodeTest extends TestCase {

	/** Every code point XML 1.0 forbids that UTF-8 can carry, each between two letters. */
	private static function forbidden(): string {
		$s = 'a';
		foreach ( array_merge( range( 0x00, 0x08 ), array( 0x0B, 0x0C ), range( 0x0E, 0x1F ) ) as $c ) {
			$s .= chr( $c ) . 'a';
		}
		return $s . "\u{FFFE}a\u{FFFF}a";
	}

	/** Text the plugin does not normalise: the hash covers the bytes as given (§5.1). */
	public function texts(): array {
		return array(
			'NFC'                 => array( "Caf\u{00E9}" ),
			'NFD'                 => array( "Cafe\u{0301}" ),
			'zero-width no-break' => array( "a\u{FEFF}b" ),
			'bidi override'       => array( "file \u{202E}exe.txt\u{202C}" ),
			'C0 controls'         => array( self::forbidden() ),
		);
	}

	/**
	 * @dataProvider texts
	 */
	public function test_the_canonical_text_seam_is_the_identity_and_the_hash_covers_the_bytes_as_given( string $text ) {
		$this->assertSame( $text, Snapshot::canonical( $text ) );
		$this->assertSame( 'sha256:' . hash( 'sha256', $text ), Snapshot::hash_of( $text ) );
	}

	public function test_the_input_filter_is_on_and_content_md_carries_nothing_xml_forbids() {
		$this->assertTrue( ContentRenderer::FILTER_TEXT, 'spec 0.4 §5.1, decision #103' );
		$html = '<p>' . self::forbidden() . " \u{202E}rlo\u{202C} e\u{0301}</p>";
		$this->assertSame( ContentRenderer::render_html( $html, 'T', array( 'filter_text' => true ) ), ContentRenderer::render_html( $html, 'T' ) );
		$post = self::factory()->post->create_and_get( array( 'post_content' => $html, 'post_status' => 'publish' ) );
		$this->assertSame( 0, preg_match( '/[\x{0}-\x{8}\x{B}\x{C}\x{E}-\x{1F}\x{FFFE}\x{FFFF}]/u', ContentRenderer::render_html( $post->post_content, $post->post_title )['content_md'] ) );
	}

	/**
	 * Switched on, the filter takes out what XML forbids before the parser sees it, so the result no
	 * longer depends on libxml: 2.14 and later keep some controls that earlier versions drop. NUL
	 * becomes a space, as earlier libxml makes it, and on a host with such a libxml the output is
	 * byte for byte what it is today. Bidi controls and combining marks are text, and stay.
	 */
	public function test_the_input_filter_switched_on_removes_what_xml_forbids_and_on_old_libxml_changes_nothing() {
		$html = '<p>' . self::forbidden() . " \u{202E}rlo\u{202C} e\u{0301}</p>";
		$on   = ContentRenderer::render_html( $html, 'T', array( 'filter_text' => true ) );

		foreach ( array( 'content_md', 'content_html' ) as $field ) {
			$this->assertSame( 0, preg_match( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', $on[ $field ] ), $field );
			$this->assertStringContainsString( "\u{202E}rlo\u{202C}", $on[ $field ], $field );
			$this->assertStringContainsString( "e\u{0301}", $on[ $field ], $field );
		}
		if ( LIBXML_VERSION < 21400 ) {
			$this->assertSame( ContentRenderer::render_html( $html, 'T', array( 'filter_text' => false ) ), $on, 'libxml ' . LIBXML_DOTTED_VERSION . ' already drops them' );
		}
		$this->assertSame( 'a b', ContentRenderer::filter_text( "a\0b" ), 'NUL becomes a space, as earlier libxml makes it' );
	}

	/** What an origin is written as (spec 0.4 §12.2): home as stored => the one ASCII spelling. */
	public function homes(): array {
		return array(
			'plain'           => array( 'https://example.org', 'https://example.org' ),
			'upper-case host' => array( 'https://Example.ORG', 'https://example.org' ),
			'upper-case http' => array( 'HTTP://example.org', 'http://example.org' ),
			'IDN host, NFC'   => array( "https://b\u{00FC}cher.example", 'https://xn--bcher-kva.example' ),
			'IDN upper-case'  => array( "https://B\u{00DC}CHER.example", 'https://xn--bcher-kva.example' ),
			'punycode host'   => array( 'https://XN--bcher-kva.example', 'https://xn--bcher-kva.example' ),
			'default port'    => array( 'https://example.org:443', 'https://example.org' ),
			'other port'      => array( 'https://example.org:8443', 'https://example.org:8443' ),
			'subdirectory'    => array( 'https://example.org/Wp', 'https://example.org/Wp' ),
		);
	}

	/**
	 * The origin written and the origin compared are one string, whatever the home URL's spelling.
	 *
	 * @dataProvider homes
	 */
	public function test_an_origin_is_written_in_one_ascii_spelling_and_compared_in_it( string $home, string $expected ) {
		$set = static function () use ( $home ) {
			return $home;
		};
		add_filter( 'pre_option_home', $set );
		$origin = Plugin::instance()->registry->get( 'posts' );
		$url    = $origin->url();
		remove_filter( 'pre_option_home', $set );

		$this->assertSame( $expected . '/blyg/', $url );
		$this->assertSame( 0, preg_match( '/[^\x21-\x7E]/', $url ), 'ASCII, nothing to escape' );
		$this->assertSame( $url, Origin::spelling( $url ), 'a canonical spelling is its own spelling' );
		$this->assertSame( Verifier::identity( $url ), Verifier::identity( $expected . '/blyg/' ) );
		$this->assertSame( (string) wp_parse_url( $expected, PHP_URL_HOST ), Locations::host_key( (string) wp_parse_url( $home, PHP_URL_HOST ) ) );
		$this->assertSame( Locations::http_origin( $home . '/blyg/' ), Locations::http_origin( $url ), 'a host from the network is folded the same way' );
	}

	public function test_an_origin_path_is_percent_encoded_with_upper_case_hex_and_never_case_folded() {
		$this->assertSame( 'https://example.org/Caf%C3%A9/a%2Fb/', Origin::spelling( "https://example.org/Caf\u{00E9}/a%2fb" ) );
		$this->assertSame( 'https://example.org/a%20b/', Origin::spelling( 'https://example.org/a b/' ) );
		$this->assertSame( 'https://example.org/blyg/', Origin::spelling( 'https://example.org/blyg/?x=1#y' ), 'no query or fragment' );
		$this->assertSame( 'https://example.org/', Origin::spelling( 'https://example.org' ), 'a final slash' );
	}

	/** NFD and NFC spell one host; UTS #46 folds them, which takes intl. */
	public function test_an_idn_host_in_nfd_is_folded_to_the_same_a_label_where_intl_is_present() {
		if ( ! function_exists( 'idn_to_ascii' ) ) {
			$this->markTestSkipped( 'needs ext-intl' );
		}
		$this->assertSame( 'xn--bcher-kva.example', Locations::host_key( "bu\u{0308}cher.example" ) );
	}

	/** A dry run's file name keeps to 80 bytes without cutting a character in two. */
	public function test_a_dump_file_name_is_cut_on_a_character_boundary() {
		$post = self::factory()->post->create_and_get( array( 'post_title' => str_repeat( 'a', 79 ) . "\u{043F}\u{0440}\u{0438}\u{0432}\u{0435}\u{0442}", 'post_status' => 'publish' ) );
		// WordPress caps a slug at 200 bytes percent-encoded, so ASCII first is what takes it past 80
		// decoded; byte 80 is then the middle of a Cyrillic letter.
		$this->assertGreaterThan( 80, strlen( urldecode( $post->post_name ) ), 'the set-up needs a slug longer than the cap' );
		$slug = PostTypeSource::dump_slug( $post );
		$this->assertLessThanOrEqual( 80, strlen( $slug ) );
		$this->assertTrue( mb_check_encoding( $slug, 'UTF-8' ), bin2hex( $slug ) );
	}
}
