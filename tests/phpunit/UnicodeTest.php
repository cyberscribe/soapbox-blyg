<?php
/**
 * Unicode on the wire (blygger-spec#6): the seams a ruling will change, and text that is never
 * split or made invalid. Every seam is today's behaviour; these tests pin that, so a ruling is a
 * change to one function and to the test beside it.
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

	/** Text that a #6 ruling might normalise, and that today is hashed exactly as given. */
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

	public function test_the_input_filter_is_off_and_rendering_is_as_before() {
		$this->assertFalse( ContentRenderer::FILTER_TEXT, 'switched on only by a #6 ruling' );
		$html = '<p>' . self::forbidden() . " \u{202E}rlo\u{202C} e\u{0301}</p>";
		$this->assertSame( ContentRenderer::render_html( $html, 'T', array( 'filter_text' => false ) ), ContentRenderer::render_html( $html, 'T' ) );
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

	/** What an origin is written as, for the homes the probe tried. */
	public function homes(): array {
		return array(
			'upper-case host' => array( 'https://Example.ORG', 'https://example.org' ),
			'IDN host, NFC'   => array( "https://b\u{00FC}cher.example", "https://b\u{00FC}cher.example" ),
			'IDN host, NFD'   => array( "https://bu\u{0308}cher.example", "https://bu\u{0308}cher.example" ),
			'IDN upper-case'  => array( "https://B\u{00DC}CHER.example", "https://b\u{00DC}cher.example" ),
			'punycode host'   => array( 'https://xn--bcher-kva.example', 'https://xn--bcher-kva.example' ),
		);
	}

	/**
	 * The write seam: an origin is home_url() and its mount, spelled exactly as the site's home is.
	 * The compare seam: the host is folded by ASCII case alone. Today neither does IDNA or NFC.
	 *
	 * @dataProvider homes
	 */
	public function test_the_origin_seams_write_home_as_given_and_compare_by_ascii_case_alone( string $home, string $compared ) {
		$set = static function () use ( $home ) {
			return $home;
		};
		add_filter( 'pre_option_home', $set );
		$origin = Plugin::instance()->registry->get( 'posts' );

		$this->assertSame( $home . '/blyg/', $origin->url() );
		$this->assertSame( $home . '/blyg/', Origin::spelling( $home . '/blyg/' ) );
		$this->assertSame( $compared, Locations::http_origin( $origin->url() ) );
		$this->assertSame( $compared . '/blyg/', Verifier::identity( $origin->url() ) );
		$this->assertSame( strtolower( (string) wp_parse_url( $home, PHP_URL_HOST ) ), Locations::host_key( (string) wp_parse_url( $home, PHP_URL_HOST ) ) );
		remove_filter( 'pre_option_home', $set );
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
