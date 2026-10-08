<?php
/**
 * B14: when a served document changes, the plugin says which addresses changed, so that a cache
 * in front of the site can drop its copies of them without waiting for them to expire.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Tests;

use SoapboxBlyg\Integrations\Purge;
use SoapboxBlyg\Plugin;
use SoapboxBlyg\Settings;
use SoapboxBlyg\Surface\Caching;

class PurgeTest extends TestCase {

	const PAGE = '/notes/a-page/';

	/** @var array[] Each firing of soapbox_blyg_purge_urls: [ the list, the origins' own documents ]. */
	private $fired = array();

	/** @var array[] What each stand-in for a cache plugin was called with. */
	public static $calls = array();

	/** @var bool Whether the stand-in for Action Scheduler refuses what it is given. */
	public static $queue_refuses = false;

	public function set_up() {
		parent::set_up();
		// Whatever earlier tests changed was never announced: no request ended. Start clean.
		Plugin::instance()->purge->flush();
		wp_unschedule_hook( Purge::AGAIN_HOOK );
		$this->fired         = array();
		self::$calls         = array();
		self::$queue_refuses = false;
		add_action(
			'soapbox_blyg_purge_urls',
			function ( $urls, $essential = null ) {
				$this->fired[] = array( $urls, $essential );
			},
			10,
			2
		);
	}

	/** What the end of the request does: the lists announced, each sorted. */
	private function end_of_request(): array {
		Plugin::instance()->purge->flush();
		$fired       = $this->fired;
		$this->fired = array();
		return array_map(
			static function ( $one ) {
				sort( $one[0] );
				return $one[0];
			},
			$fired
		);
	}

	/** The origin's own documents, and with $id the item's, sorted. */
	private function addresses( $id = null, array $more = array() ): array {
		$plugin = Plugin::instance();
		$notes  = $plugin->registry->get( 'notes' );
		$urls   = array_merge( array( $plugin->locations->manifest( $notes ), $plugin->locations->feed( $notes ), $plugin->locations->index( $notes ) ), null === $id ? array() : array( $plugin->locations->item( $notes, $id ) ), $more );
		sort( $urls );
		return $urls;
	}

	private function note( $body ): array {
		return null === $body ? array() : $this->fragment( $body, array( 'permalink' => home_url( self::PAGE ) ) );
	}

	/**
	 * @dataProvider layouts
	 */
	public function test_b14_every_change_names_the_addresses_it_changed_once( string $layout ) {
		$this->register_notes( array( 'layout' => $layout ) );
		$a = soapbox_blyg_publish( 'notes', 'a', $this->note( 'One.' ), array( 'at' => '2026-03-01T10:00:00Z' ) );
		$this->assertSame( array(), $this->fired, 'nothing is said until the request is over' );
		// F3: a new item's own address was never served, so there is no copy of it to drop. What
		// lists it changed. (A backfill of a thousand posts is then three addresses, not a thousand.)
		$this->assertSame( array( $this->addresses() ), $this->end_of_request(), 'created' );

		soapbox_blyg_publish( 'notes', 'a', $this->note( 'Two.' ), array( 'at' => '2026-03-02T10:00:00Z' ) );
		$fired = $this->end_of_request();
		$this->assertSame( array( $this->addresses( $a['id'] ) ), $fired, 'a new version: the item too' );
		$this->assertContains( home_url( 'fixed' === $layout ? "/notes/items/{$a['id']}.json" : '/wp-json/soapbox-blyg/v1/origins/notes/items/' . $a['id'] ), $fired[0] );
		// F4: not the bare mount. Some caches purge an address and everything under it.
		$this->assertNotContains( home_url( '/notes/' ), $fired[0] );

		// F5: a withdrawal and a return change the item's page as well, and one made by the plugin
		// itself (a reconcile, a reader's request) passes no post through WordPress for a cache
		// plugin to notice.
		soapbox_blyg_publish( 'notes', 'a', null, array( 'at' => '2026-03-03T10:00:00Z' ) );
		$this->assertSame( array( $this->addresses( $a['id'], array( home_url( self::PAGE ) ) ) ), $this->end_of_request(), 'a withdrawal' );
		soapbox_blyg_publish( 'notes', 'a', $this->note( 'Three.' ), array( 'at' => '2026-03-04T10:00:00Z' ) );
		$this->assertSame( array( $this->addresses( $a['id'], array( home_url( self::PAGE ) ) ) ), $this->end_of_request(), 'a return' );

		// Published again with nothing changed: no event, nothing to purge.
		soapbox_blyg_publish( 'notes', 'a', $this->note( 'Three.' ), array( 'at' => '2026-03-09T10:00:00Z' ) );
		$this->assertSame( array(), $this->end_of_request() );
	}

	public function layouts(): array {
		return array( 'fixed' => array( 'fixed' ), 'templated' => array( 'templated' ) );
	}

	public function test_b14_several_changes_in_one_request_are_one_list_with_nothing_twice() {
		$this->register_notes();
		$a = soapbox_blyg_publish( 'notes', 'a', $this->note( 'One.' ), array( 'at' => '2026-03-01T10:00:00Z' ) );
		$b = soapbox_blyg_publish( 'notes', 'b', $this->fragment( 'Other.' ), array( 'at' => '2026-03-01T11:00:00Z' ) );
		soapbox_blyg_publish( 'notes', 'a', $this->note( 'Two.' ), array( 'at' => '2026-03-02T10:00:00Z' ) );
		soapbox_blyg_publish( 'notes', 'b', $this->fragment( 'Another.' ), array( 'at' => '2026-03-02T11:00:00Z' ) );
		Plugin::instance()->purge->flush();
		$this->assertCount( 1, $this->fired );
		list( $urls, $essential ) = $this->fired[0];
		$this->assertSame( array_values( array_unique( $urls ) ), $urls );
		$want = array_values( array_unique( array_merge( $this->addresses( $a['id'] ), $this->addresses( $b['id'] ) ) ) );
		sort( $want );
		sort( $urls );
		sort( $essential );
		$this->assertSame( $want, $urls );
		$this->assertSame( $this->addresses(), $essential, 'and, apart, the documents every change touches' );
		$this->fired = array();
		$this->assertSame( array(), $this->end_of_request(), 'once said, it is not said again' );
	}

	public function test_f1_a_pin_changes_the_items_document_and_nothing_else() {
		$this->register_notes();
		$a = soapbox_blyg_publish( 'notes', 'a', $this->note( 'One.' ), array( 'at' => '2026-03-01T10:00:00Z' ) );
		$this->end_of_request();
		soapbox_blyg_pin( $a['id'], 1 );
		// Its changelog now says that version is pinned. The feed and the index do not say so.
		$notes = Plugin::instance()->registry->get( 'notes' );
		$this->assertSame( array( array( Plugin::instance()->locations->item( $notes, $a['id'] ) ) ), $this->end_of_request() );
	}

	public function test_b14_a_change_of_settings_names_what_carries_them_the_mount_included() {
		$this->register_notes();
		update_option( Settings::OPTION, array( 'title' => 'A new name ' . microtime() ) + Settings::all() );
		$fired = $this->end_of_request();
		$this->assertCount( 1, $fired );
		// (The site's own posts origin may be registered as well; its documents are then named too.)
		$this->assertSame( array(), array_diff( $this->addresses( null, array( home_url( '/notes/' ) ) ), $fired[0] ) );
	}

	/**
	 * F2: the documents' bytes also change with things no ledger event marks.
	 *
	 * @dataProvider things_the_documents_carry
	 */
	public function test_f2_a_change_to_what_the_documents_carry_names_them_too( callable $change ) {
		$this->register_notes();
		$change();
		$fired = $this->end_of_request();
		$this->assertCount( 1, $fired );
		$this->assertSame( array(), array_diff( $this->addresses(), $fired[0] ) );
	}

	public function things_the_documents_carry(): array {
		return array(
			'the site\'s name'       => array(
				static function () {
					update_option( 'blogname', 'Renamed ' . microtime() );
				},
			),
			'its tagline'            => array(
				static function () {
					update_option( 'blogdescription', 'Retold ' . microtime() );
				},
			),
			'its address'            => array(
				static function () {
					do_action( 'update_option_home', 'http://old.example', 'http://new.example', 'home' );
				},
			),
			'its permalinks'         => array(
				static function () {
					do_action( 'update_option_permalink_structure', '/%postname%/', '/%year%/%postname%/', 'permalink_structure' );
				},
			),
			// The generator in every document names the plugin's version. The first run of this
			// release on a site that ran an earlier one counts: nothing was recorded then.
			'the plugin\'s version' => array(
				static function () {
					delete_option( Plugin::VERSION_OPTION );
					Plugin::upgrade();
				},
			),
		);
	}

	public function test_f2_the_same_version_twice_is_not_a_change() {
		$this->register_notes();
		Plugin::upgrade();
		$this->end_of_request();
		Plugin::upgrade();
		$this->assertSame( array(), $this->end_of_request() );
		$this->assertSame( SOAPBOX_BLYG_VERSION, get_option( Plugin::VERSION_OPTION ) );
	}

	public function test_f8_switching_publishing_on_or_moving_the_mount_names_the_new_address() {
		// Nothing is registered yet: what is there now is a 404 that a cache may be holding.
		update_option( Settings::OPTION, array( 'enabled' => true, 'setup' => true, 'mount' => '/elsewhere/', 'layout' => 'fixed' ) + Settings::all() );
		$fired = $this->end_of_request();
		$this->assertCount( 1, $fired );
		foreach ( array( '/elsewhere/', '/elsewhere/blyg.json', '/elsewhere/feed.xml', '/elsewhere/items/index.json' ) as $path ) {
			$this->assertContains( home_url( $path ), $fired[0], $path );
		}
	}

	public function test_f7_the_list_is_announced_once_more_a_little_later_and_only_once_more() {
		$this->register_notes();
		$a = soapbox_blyg_publish( 'notes', 'a', $this->note( 'One.' ), array( 'at' => '2026-03-01T10:00:00Z' ) );
		soapbox_blyg_publish( 'notes', 'a', $this->note( 'Two.' ), array( 'at' => '2026-03-02T10:00:00Z' ) );
		$first = $this->end_of_request();
		// A request already under way when the purge landed can put the old copy back.
		$events = array();
		foreach ( _get_cron_array() as $at => $hooks ) {
			foreach ( isset( $hooks[ Purge::AGAIN_HOOK ] ) ? $hooks[ Purge::AGAIN_HOOK ] : array() as $event ) {
				$events[] = array( $at, $event['args'] );
			}
		}
		$this->assertCount( 1, $events );
		$wait = max( Purge::AGAIN_AT_LEAST, (int) ( Caching::lifetime() / Purge::AGAIN_FRACTION ) );
		$this->assertEqualsWithDelta( time() + $wait, $events[0][0], 3 );
		$this->assertSame( 30, Purge::AGAIN_AT_LEAST );

		do_action_ref_array( Purge::AGAIN_HOOK, $events[0][1] );
		$this->assertCount( 1, $this->fired );
		sort( $this->fired[0][0] );
		$this->assertSame( $first[0], $this->fired[0][0], 'the same list' );
		wp_unschedule_hook( Purge::AGAIN_HOOK );
		$this->fired = array();
		Plugin::instance()->purge->flush();
		$this->assertFalse( wp_next_scheduled( Purge::AGAIN_HOOK, $events[0][1] ), 'the second announcement schedules no third' );
	}

	public function test_b14_a_listener_that_fails_does_not_take_the_request_with_it() {
		$this->register_notes();
		add_action(
			'soapbox_blyg_purge_urls',
			static function () {
				throw new \RuntimeException( 'a cache plugin changed its mind' );
			},
			7
		);
		soapbox_blyg_publish( 'notes', 'a', $this->note( 'One.' ), array( 'at' => '2026-03-01T10:00:00Z' ) );
		Plugin::instance()->purge->flush();
		$this->assertTrue( true, 'no exception left flush()' );
	}

	/*
	 * The cache plugins the list is passed to. Each is stood in for by the one function or action
	 * of its own that the listener calls.
	 */

	/** Changes one item, or with $many that many, and returns [ the list announced, the origins' documents ]. */
	private function change_something( int $many = 1 ): array {
		$this->register_notes();
		for ( $i = 0; $i < $many; $i++ ) {
			soapbox_blyg_publish( 'notes', "n{$i}", $this->fragment( 'One.' ), array( 'at' => '2026-03-01T10:00:00Z' ) );
		}
		Plugin::instance()->purge->flush();
		for ( $i = 0; $i < $many; $i++ ) {
			soapbox_blyg_publish( 'notes', "n{$i}", $this->fragment( 'Two ' . microtime() ), array( 'at' => gmdate( 'Y-m-d\TH:i:s\Z' ) ) );
		}
		self::$calls = array();
		$this->fired = array();
		Plugin::instance()->purge->flush();
		$this->assertCount( 1, $this->fired );
		return $this->fired[0];
	}

	public function test_f8_the_plugins_own_listeners_run_before_anyone_elses() {
		foreach ( array( 'Breeze', 'W3TotalCache', 'SiteGround' ) as $listener ) {
			$this->assertSame( 5, has_action( 'soapbox_blyg_purge_urls', array( "SoapboxBlyg\\Integrations\\{$listener}", 'purge' ) ), $listener );
		}
	}

	public function test_b14_breeze_is_given_the_list_once_as_a_list() {
		// Breeze is not there: nothing listens, nothing is called, nothing breaks.
		$this->assertFalse( has_action( 'breeze_purge_varnish_urls' ) );
		$this->change_something();

		$spy = static function ( $urls ) {
			self::$calls['breeze'][] = $urls;
		};
		add_action( 'breeze_purge_varnish_urls', $spy );
		list( $urls ) = $this->change_something();
		$this->assertSame( array( $urls ), self::$calls['breeze'], 'one call, with every address, as an array: Breeze reads a string as something else' );

		// F3. Breeze purges at once when it is asked through its action, one request to Varnish
		// for each address. A long list is the origins' own documents and no more...
		$this->assertFalse( function_exists( 'as_enqueue_async_action' ) );
		list( $urls, $essential ) = $this->change_something( Purge::MANY );
		$this->assertGreaterThan( Purge::MANY, count( $urls ) );
		$this->assertSame( array( $essential ), self::$calls['breeze'] );

		// ...unless there is a queue to put it on, as Breeze does with its own long lists: the
		// name of a transient that holds the list, for its action to read later.
		require_once __DIR__ . '/stubs/action-scheduler.php';
		list( $urls ) = $this->change_something( Purge::MANY );
		$this->assertArrayNotHasKey( 'breeze', self::$calls, 'nothing is purged in this request' );
		$this->assertCount( 1, self::$calls['queued'] );
		list( $hook, $args, $group ) = self::$calls['queued'][0];
		$this->assertSame( array( 'breeze_purge_varnish_urls', 'breeze' ), array( $hook, $group ) );
		$this->assertSame( $urls, get_transient( $args[0] ) );
		remove_action( 'breeze_purge_varnish_urls', $spy );
	}

	/**
	 * P1 (production, 2026-10-07). Breeze works out which pages to drop from its own cache after
	 * the post has changed, from get_permalink(), and for a post that is now a draft that is the
	 * plain ?p= address: the page it cached stays, and goes on being served. The ledger still has
	 * the address the post was published at, and Breeze asks for more addresses through a filter.
	 */
	public function test_p1_breeze_is_given_the_page_a_post_was_published_at_when_it_asks() {
		$this->set_permalink_structure( '/archives/%post_id%-%postname%.html' );
		$post = self::factory()->post->create( array( 'post_status' => 'publish', 'post_title' => 'Taken down' ) );
		$page = get_permalink( $post );
		$this->assertStringContainsString( '/archives/' . $post . '-taken-down.html', $page );

		wp_update_post( array( 'ID' => $post, 'post_status' => 'draft' ) );
		$breeze_has = array( get_permalink( $post ) );
		$this->assertNotSame( $page, $breeze_has[0], 'after the change WordPress no longer says where the page was' );
		$this->assertSame( array( $breeze_has[0], $page ), apply_filters( 'breeze_collect_urls_for_cache_purge', $breeze_has, $post ) );

		// Nothing twice, nothing for a post the blyg never had, and a list that is not one is left alone.
		$this->assertSame( array( $page ), apply_filters( 'breeze_collect_urls_for_cache_purge', array( $page ), $post ) );
		$never = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$this->assertSame( array( 'x' ), apply_filters( 'breeze_collect_urls_for_cache_purge', array( 'x' ), $never ) );
		$this->assertSame( array( 'x' ), apply_filters( 'breeze_collect_urls_for_cache_purge', array( 'x' ), 'not-a-post' ) );
		$this->assertNull( apply_filters( 'breeze_collect_urls_for_cache_purge', null, $post ) );
	}

	public function test_b14_w3_total_cache_is_asked_for_each_address() {
		$this->assertFalse( function_exists( 'w3tc_flush_url' ), 'W3 Total Cache is not there' );
		$this->change_something();
		require_once __DIR__ . '/stubs/w3-total-cache.php';
		list( $urls ) = $this->change_something();
		$this->assertSame( $urls, array_column( self::$calls['w3tc'], 0 ) );
		$this->assertSame( array( 1 ), array_values( array_unique( array_map( 'count', self::$calls['w3tc'] ) ) ), 'one argument: the address' );

		list( , $essential ) = $this->change_something( Purge::MANY );
		$this->assertSame( $essential, array_column( self::$calls['w3tc'], 0 ), 'a long list is the origins\' own documents' );
	}

	public function test_b14_siteground_is_asked_for_each_address_and_never_for_the_whole_site() {
		$this->assertFalse( function_exists( 'sg_cachepress_purge_cache' ), 'Speed Optimizer is not there' );
		$this->change_something();
		require_once __DIR__ . '/stubs/sg-cachepress.php';
		list( $urls ) = $this->change_something();
		$this->assertSame( $urls, array_column( self::$calls['sg'], 0 ) );

		// Given the site's own address, that function clears everything. It is never given it.
		self::$calls = array();
		do_action( 'soapbox_blyg_purge_urls', array( home_url(), home_url( '/' ), '', home_url( '/notes/blyg.json' ) ), array() );
		$this->assertSame( array( array( home_url( '/notes/blyg.json' ) ) ), self::$calls['sg'] );

		list( , $essential ) = $this->change_something( Purge::MANY );
		$this->assertSame( $essential, array_column( self::$calls['sg'], 0 ) );
	}

	public function test_b14_a_cache_plugin_that_fails_is_noted_and_the_others_are_still_told() {
		require_once __DIR__ . '/stubs/w3-total-cache.php';
		$breaks = static function () {
			throw new \ArgumentCountError( 'the signature changed' );
		};
		add_action( 'breeze_purge_varnish_urls', $breaks );
		list( $urls ) = $this->change_something();
		remove_action( 'breeze_purge_varnish_urls', $breaks );
		$this->assertSame( $urls, array_column( self::$calls['w3tc'], 0 ), 'W3 Total Cache was still told' );
	}

	/**
	 * R1 (QA on ae06d2d). A long list is cut to the origins' own documents for a cache that is
	 * purged one address at a time. A withdrawal must never be among what is cut: its document
	 * and its page hold what was taken down, and a cache that is not told goes on serving them.
	 */
	public function test_r1_what_was_withdrawn_is_never_cut_from_a_long_list() {
		require_once __DIR__ . '/stubs/w3-total-cache.php';
		require_once __DIR__ . '/stubs/sg-cachepress.php';
		require_once __DIR__ . '/stubs/action-scheduler.php';
		$this->register_notes();
		$plugin = Plugin::instance();
		$notes  = $plugin->registry->get( 'notes' );
		$gone   = array();
		for ( $i = 0; $i < 30; $i++ ) {
			$page   = home_url( "/notes/page-{$i}/" );
			$made   = soapbox_blyg_publish( 'notes', "n{$i}", $this->fragment( 'One.', array( 'permalink' => $page ) ), array( 'at' => '2026-03-01T10:00:00Z' ) );
			$gone[] = $plugin->locations->item( $notes, $made['id'] );
			$gone[] = $page;
		}
		$plugin->purge->flush();
		wp_unschedule_hook( Purge::AGAIN_HOOK );

		for ( $i = 0; $i < 30; $i++ ) {
			soapbox_blyg_publish( 'notes', "n{$i}", null );
		}
		// Breeze with a queue that will not take the list: it falls back to purging in the request.
		self::$queue_refuses = true;
		$spy                 = static function ( $urls ) {
			self::$calls['breeze'][] = $urls;
		};
		add_action( 'breeze_purge_varnish_urls', $spy );
		self::$calls = array();
		$this->fired = array();
		$plugin->purge->flush();
		remove_action( 'breeze_purge_varnish_urls', $spy );

		$this->assertGreaterThan( Purge::MANY, count( $this->fired[0][0] ), 'the list is a long one' );
		$told = array(
			'W3 Total Cache' => array_column( self::$calls['w3tc'], 0 ),
			'SiteGround'     => array_column( self::$calls['sg'], 0 ),
			'Breeze'         => array_merge( ...self::$calls['breeze'] ),
		);
		foreach ( $told as $who => $urls ) {
			$this->assertSame( array(), array_values( array_diff( $gone, $urls ) ), "$who was not told of every withdrawn document and page" );
		}
		// And the second announcement, a little later, names them too.
		$again = array();
		foreach ( _get_cron_array() as $at ) {
			foreach ( isset( $at[ Purge::AGAIN_HOOK ] ) ? $at[ Purge::AGAIN_HOOK ] : array() as $event ) {
				$again = array_merge( $again, $event['args'][0] );
			}
		}
		$this->assertSame( array(), array_values( array_diff( $gone, $again ) ), 'the second announcement left a withdrawal out' );
	}

	/**
	 * R2 (QA on ae06d2d). The purge a new version causes happens once. Where the record of the
	 * version cannot be written (a stale object cache after a restore goes on answering the old
	 * one), it must not happen on every request instead.
	 */
	public function test_r2_a_version_that_cannot_be_recorded_does_not_purge_on_every_request() {
		$this->register_notes();
		update_option( Plugin::VERSION_OPTION, 'older' );
		$keeps = static function () {
			return 'older';
		};
		add_filter( 'pre_update_option_' . Plugin::VERSION_OPTION, $keeps );
		Plugin::upgrade();
		$this->assertSame( array(), $this->end_of_request(), 'nothing was recorded, so nothing is purged' );
		remove_filter( 'pre_update_option_' . Plugin::VERSION_OPTION, $keeps );

		Plugin::upgrade();
		$this->assertCount( 1, $this->end_of_request(), 'recorded: purged once' );
		Plugin::upgrade();
		$this->assertSame( array(), $this->end_of_request(), 'and not again' );
	}
}
