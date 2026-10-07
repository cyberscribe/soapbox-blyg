<?php
/**
 * B14: when a served document changes, the plugin says which addresses changed, so that a cache
 * in front of the site can drop its copies of them without waiting for them to expire.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Tests;

use SoapboxBlyg\Plugin;
use SoapboxBlyg\Settings;

class PurgeTest extends TestCase {

	/** @var array[] Each firing of soapbox_blyg_purge_urls: the list it was given. */
	private $fired = array();

	public function set_up() {
		parent::set_up();
		$this->fired = array();
		// Whatever earlier tests changed was never announced: no request ended. Start clean.
		if ( isset( Plugin::instance()->purge ) ) {
			Plugin::instance()->purge->flush();
		}
		add_action(
			'soapbox_blyg_purge_urls',
			function ( $urls ) {
				$this->fired[] = $urls;
			}
		);
	}

	/** What the end of the request does. */
	private function end_of_request(): array {
		$this->assertTrue( isset( Plugin::instance()->purge ), 'the plugin has a purge collector' );
		Plugin::instance()->purge->flush();
		$fired       = $this->fired;
		$this->fired = array();
		return $fired;
	}

	private function addresses( string $id ): array {
		$plugin = Plugin::instance();
		$notes  = $plugin->registry->get( 'notes' );
		$urls   = array( $plugin->locations->manifest( $notes ), $plugin->locations->feed( $notes ), $plugin->locations->index( $notes ), $plugin->locations->item( $notes, $id ), $notes->url() );
		sort( $urls );
		return $urls;
	}

	/**
	 * @dataProvider layouts
	 */
	public function test_b14_every_change_to_an_item_names_the_addresses_it_changed_once( string $layout ) {
		$this->register_notes( array( 'layout' => $layout ) );
		$a = soapbox_blyg_publish( 'notes', 'a', $this->fragment( 'One.' ), array( 'at' => '2026-03-01T10:00:00Z' ) );
		$this->assertSame( array(), $this->fired, 'nothing is said until the request is over' );
		$fired = $this->end_of_request();
		$this->assertCount( 1, $fired, 'created' );
		sort( $fired[0] );
		$this->assertSame( $this->addresses( $a['id'] ), $fired[0] );
		$this->assertContains( home_url( 'fixed' === $layout ? "/notes/items/{$a['id']}.json" : '/wp-json/soapbox-blyg/v1/origins/notes/items/' . $a['id'] ), $fired[0] );

		foreach ( array( 'a new version' => $this->fragment( 'Two.' ), 'a withdrawal' => null, 'a return' => $this->fragment( 'Three.' ) ) as $what => $snapshot ) {
			soapbox_blyg_publish( 'notes', 'a', $snapshot, array( 'at' => '2026-03-0' . ( 2 + count( $this->fired ) ) . 'T10:00:00Z' ) );
			$fired = $this->end_of_request();
			$this->assertCount( 1, $fired, $what );
			sort( $fired[0] );
			$this->assertSame( $this->addresses( $a['id'] ), $fired[0], $what );
		}

		// Published again with nothing changed: no event, nothing to purge.
		soapbox_blyg_publish( 'notes', 'a', $this->fragment( 'Three.' ), array( 'at' => '2026-03-09T10:00:00Z' ) );
		$this->assertSame( array(), $this->end_of_request() );
	}

	public function layouts(): array {
		return array( 'fixed' => array( 'fixed' ), 'templated' => array( 'templated' ) );
	}

	public function test_b14_several_changes_in_one_request_are_one_list_with_nothing_twice() {
		$this->register_notes();
		$a     = soapbox_blyg_publish( 'notes', 'a', $this->fragment( 'One.' ), array( 'at' => '2026-03-01T10:00:00Z' ) );
		$b     = soapbox_blyg_publish( 'notes', 'b', $this->fragment( 'Other.' ), array( 'at' => '2026-03-01T11:00:00Z' ) );
		soapbox_blyg_publish( 'notes', 'a', $this->fragment( 'Two.' ), array( 'at' => '2026-03-02T10:00:00Z' ) );
		$fired = $this->end_of_request();
		$this->assertCount( 1, $fired );
		$this->assertSame( array_values( array_unique( $fired[0] ) ), $fired[0] );
		$want = array_values( array_unique( array_merge( $this->addresses( $a['id'] ), $this->addresses( $b['id'] ) ) ) );
		sort( $want );
		sort( $fired[0] );
		$this->assertSame( $want, $fired[0] );
		$this->assertSame( array(), $this->end_of_request(), 'and once said, it is not said again' );
	}

	public function test_b14_a_pin_changes_nothing_that_is_served() {
		$this->register_notes();
		$a = soapbox_blyg_publish( 'notes', 'a', $this->fragment( 'One.' ), array( 'at' => '2026-03-01T10:00:00Z' ) );
		$this->end_of_request();
		soapbox_blyg_pin( $a['id'], 1 );
		$this->assertSame( array(), $this->end_of_request() );
	}

	public function test_b14_a_change_of_settings_names_what_carries_them() {
		$this->register_notes();
		update_option( Settings::OPTION, array( 'title' => 'A new name' ) + Settings::all() );
		$fired = $this->end_of_request();
		$this->assertCount( 1, $fired );
		$plugin = Plugin::instance();
		$notes  = $plugin->registry->get( 'notes' );
		foreach ( array( $plugin->locations->manifest( $notes ), $plugin->locations->feed( $notes ), $plugin->locations->index( $notes ), $notes->url() ) as $url ) {
			$this->assertContains( $url, $fired[0] );
		}
	}

	public function test_b14_a_listener_that_fails_does_not_take_the_request_with_it() {
		$this->register_notes();
		add_action(
			'soapbox_blyg_purge_urls',
			static function () {
				throw new \RuntimeException( 'a cache plugin changed its mind' );
			},
			5
		);
		soapbox_blyg_publish( 'notes', 'a', $this->fragment( 'One.' ), array( 'at' => '2026-03-01T10:00:00Z' ) );
		Plugin::instance()->purge->flush();
		$this->assertTrue( true, 'no exception left flush()' );
	}

	/*
	 * The cache plugins the list is passed to. Each is stood in for by the one function or action
	 * of its own that the listener calls.
	 */

	/** @var array[] What each stand-in was called with. */
	public static $calls = array();

	private function change_something(): array {
		$this->register_notes();
		$a = soapbox_blyg_publish( 'notes', 'a', $this->fragment( 'One ' . microtime() ), array( 'at' => gmdate( 'Y-m-d\TH:i:s\Z' ) ) );
		self::$calls = array();
		$urls        = $this->end_of_request();
		$this->assertCount( 1, $urls );
		return $urls[0];
	}

	public function test_b14_breeze_is_given_the_list_once_as_a_list() {
		// Breeze is not there: nothing listens, nothing is called, nothing breaks.
		$this->assertFalse( has_action( 'breeze_purge_varnish_urls' ) );
		$this->change_something();

		$spy = static function ( $urls ) {
			self::$calls['breeze'][] = $urls;
		};
		add_action( 'breeze_purge_varnish_urls', $spy );
		$urls = $this->change_something();
		$this->assertSame( array( $urls ), self::$calls['breeze'], 'one call, with every address, as an array: Breeze reads a string as something else' );
		remove_action( 'breeze_purge_varnish_urls', $spy );
	}

	public function test_b14_w3_total_cache_is_asked_for_each_address() {
		$this->assertFalse( function_exists( 'w3tc_flush_url' ), 'W3 Total Cache is not there' );
		$this->change_something();
		require_once __DIR__ . '/stubs/w3-total-cache.php';
		$urls = $this->change_something();
		$this->assertSame( $urls, array_column( self::$calls['w3tc'], 0 ) );
		$this->assertSame( array( 1 ), array_values( array_unique( array_map( 'count', self::$calls['w3tc'] ) ) ), 'one argument: the address' );
	}

	public function test_b14_siteground_is_asked_for_each_address_and_never_for_the_whole_site() {
		$this->assertFalse( function_exists( 'sg_cachepress_purge_cache' ), 'Speed Optimizer is not there' );
		$this->change_something();
		require_once __DIR__ . '/stubs/sg-cachepress.php';
		$urls = $this->change_something();
		$this->assertSame( $urls, array_column( self::$calls['sg'], 0 ) );

		// Given the site's own address, that function clears everything. It is never given it.
		self::$calls = array();
		do_action( 'soapbox_blyg_purge_urls', array( home_url(), home_url( '/' ), '', home_url( '/notes/blyg.json' ) ) );
		$this->assertSame( array( array( home_url( '/notes/blyg.json' ) ) ), self::$calls['sg'] );
	}

	public function test_b14_a_cache_plugin_that_fails_is_noted_and_the_others_are_still_told() {
		require_once __DIR__ . '/stubs/w3-total-cache.php';
		$breaks = static function () {
			throw new \ArgumentCountError( 'the signature changed' );
		};
		add_action( 'breeze_purge_varnish_urls', $breaks );
		$urls = $this->change_something();
		remove_action( 'breeze_purge_varnish_urls', $breaks );
		$this->assertSame( $urls, array_column( self::$calls['w3tc'], 0 ), 'W3 Total Cache was still told' );
	}
}
