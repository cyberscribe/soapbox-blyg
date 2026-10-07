<?php
/**
 * QA's adversarial pass on the rc3 candidate (124fcc1), item by item, as reproductions.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Tests;

use SoapboxBlyg\Ledger\Schema;
use SoapboxBlyg\Mentions\Receiver;
use SoapboxBlyg\Origins\PostTypeSource;
use SoapboxBlyg\Plugin;
use SoapboxBlyg\Surface\Locations;
use SoapboxBlyg\Surface\RestDocuments;
use WP_REST_Request;

class QaPassTest extends TestCase {

	private function status( int $post_id ) {
		return soapbox_blyg_get_status( 'posts', 'post:' . $post_id );
	}

	/** A surface response through the REST route, as a reader gets it. */
	private function read( string $route ): array {
		return rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/' . Locations::rest_namespace() . '/origins/posts' . $route ) )->get_data()[ RestDocuments::MARKER ];
	}

	/** A paywall of the usual kind: a flag in post meta, read by the plugin's filter. */
	private function paywall(): callable {
		$filter = static function ( $public, $post ) {
			return get_post_meta( $post->ID, '_members_only', true ) ? false : $public;
		};
		add_filter( 'soapbox_blyg_is_public', $filter, 10, 2 );
		return $filter;
	}

	private function break_writes_to( string ...$tables ): callable {
		global $wpdb;
		$names  = array_map( array( Schema::class, 'table' ), $tables );
		$filter = static function ( $query ) use ( $names ) {
			if ( preg_match( '/^\s*INSERT\b/i', $query ) ) {
				foreach ( $names as $name ) {
					if ( false !== strpos( $query, $name ) ) {
						return 'INSERT INTO soapbox_blyg_no_such_table VALUES (1)';
					}
				}
			}
			return $query;
		};
		add_filter( 'query', $filter );
		$wpdb->suppress_errors( true );
		return $filter;
	}

	private function mend( callable $filter ): void {
		global $wpdb;
		remove_filter( 'query', $filter );
		$wpdb->suppress_errors( false );
	}

	/*
	 * R1 (High): the filter is not asked for the feed or the index, and apply() publishes anyway.
	 */

	public function test_r1_a_post_the_filter_hides_leaves_the_feed_and_the_index_as_well_as_its_item() {
		$post = self::factory()->post->create( array( 'post_status' => 'publish', 'post_title' => 'For members', 'post_content' => '<p>Paid words.</p>' ) );
		$id   = $this->status( $post )['id'];
		$this->paywall();
		$this->assertStringContainsString( 'Paid words', $this->read( '/feed' )['body'] );

		// The flag is set with no save of the post: a membership plugin's own screen, say.
		update_post_meta( $post, '_members_only', 1 );

		$feed = $this->read( '/feed' );
		$this->assertSame( 200, $feed['status'] );
		$this->assertStringNotContainsString( 'Paid words', $feed['body'], 'the feed' );
		$this->assertStringNotContainsString( 'For members', $feed['body'] );
		foreach ( json_decode( $this->read( '/items' )['body'], true )['items'] as $entry ) {
			if ( $entry['id'] === $id ) {
				$this->assertSame( 'withdrawn', $entry['kind'], 'the index' );
			}
		}
		$this->assertSame( 'withdrawn', json_decode( $this->read( "/items/{$id}" )['body'], true )['kind'], 'the item' );
	}

	public function test_r1_the_ledger_does_not_publish_what_its_source_says_is_not_public() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$source = Plugin::instance()->posts;
		remove_action( 'wp_after_insert_post', array( $source, 'on_save' ), 10 );
		$post = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<p>Paid words.</p>' ) );
		add_action( 'wp_after_insert_post', array( $source, 'on_save' ), 10, 2 );
		$this->paywall();

		// While the backfill renders the post, the flag is set. No column of the post changes.
		$flip = static function ( $body ) use ( &$flip, $post ) {
			remove_filter( 'soapbox_blyg_rendered_body', $flip );
			update_post_meta( $post, '_members_only', 1 );
			return $body;
		};
		add_filter( 'soapbox_blyg_rendered_body', $flip );
		$request = new WP_REST_Request( 'POST', '/' . Locations::rest_namespace() . '/admin/backfill' );
		rest_get_server()->dispatch( $request );
		remove_filter( 'soapbox_blyg_rendered_body', $flip );

		$status = $this->status( $post );
		$this->assertTrue( null === $status || 'withdrawn' === $status['kind'], 'nothing live was made of it' );

		// And the same asked of the ledger directly, with a snapshot in hand.
		$other = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<p>Free.</p>' ) );
		update_post_meta( $other, '_members_only', 1 );
		$result = Plugin::instance()->ledger->publish( 'posts', 'post:' . $other, $this->fragment( 'Paid, again.', array( 'kind' => 'thread', 'transclusions' => array() ) ) );
		$this->assertSame( 'withdrawn', $result['event'], 'a live item whose source is not public is withdrawn, not given a new version' );
		$this->assertStringNotContainsString( 'Paid, again', $this->read( '/feed' )['body'] );
	}

	/*
	 * R2: a password of spaces compares equal to no password in MySQL.
	 */

	public function test_r2_a_password_of_whitespace_is_a_password() {
		global $wpdb;
		$post = self::factory()->post->create( array( 'post_status' => 'publish', 'post_title' => 'Spaced', 'post_content' => '<p>Behind a password.</p>' ) );
		$id   = $this->status( $post )['id'];
		$wpdb->update( $wpdb->posts, array( 'post_password' => ' ' ), array( 'ID' => $post ) );
		clean_post_cache( $post );

		$this->assertStringNotContainsString( 'Behind a password', $this->read( '/feed' )['body'] );
		$this->assertSame( 'withdrawn', json_decode( $this->read( "/items/{$id}" )['body'], true )['kind'] );
		$this->assertNotContains( $post, Plugin::instance()->posts->unpublished_ids(), 'nor is it offered to the backfill' );
	}

	/*
	 * R4: an anonymous request re-renders every queued post.
	 */

	public function test_r4_a_visitors_request_renders_nothing_and_repairs_nothing() {
		$breaks = static function () {
			throw new \RuntimeException( 'still broken' );
		};
		add_filter( 'soapbox_blyg_snapshot', $breaks );
		$post = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<p>Body.</p>' ) );
		$this->assertCount( 1, Plugin::instance()->posts->owed() );

		$renders = 0;
		$count   = static function ( $body ) use ( &$renders ) {
			++$renders;
			return $body;
		};
		add_filter( 'soapbox_blyg_rendered_body', $count );
		wp_set_current_user( 0 );
		set_current_screen( 'front' );
		for ( $i = 0; $i < 5; $i++ ) {
			do_action( 'wp_loaded' );
			$this->read( '/feed' );
		}
		$this->assertSame( 0, $renders, 'five page views, no renders' );
		$this->assertSame( 1, Plugin::instance()->posts->owed()[0]['tries'], 'and no retries' );
		remove_filter( 'soapbox_blyg_snapshot', $breaks );
		remove_filter( 'soapbox_blyg_rendered_body', $count );
		wp_unschedule_hook( PostTypeSource::REPAIR_HOOK );
		unset( $post );
	}

	public function test_r4_a_post_that_keeps_failing_is_retried_less_and_less_and_then_left_for_a_person() {
		$breaks = static function () {
			throw new \RuntimeException( 'still broken' );
		};
		add_filter( 'soapbox_blyg_snapshot', $breaks );
		self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<p>Body.</p>' ) );
		$posts = Plugin::instance()->posts;

		// Asked again at once, it is not due.
		$posts->repair();
		$posts->repair();
		$this->assertSame( 1, $posts->owed()[0]['tries'], 'not retried before its time' );
		remove_filter( 'soapbox_blyg_snapshot', $breaks );
		wp_unschedule_hook( PostTypeSource::REPAIR_HOOK );
	}

	/*
	 * R5: a 503 can be answered by a cache with its copy of the live document.
	 */

	public function test_r5_when_a_withdrawal_cannot_be_written_the_documents_are_served_without_the_content() {
		$post = self::factory()->post->create( array( 'post_status' => 'publish', 'post_title' => 'Taken down', 'post_content' => '<p>Must not be served.</p>' ) );
		$keep = self::factory()->post->create( array( 'post_status' => 'publish', 'post_title' => 'Still here', 'post_content' => '<p>Stays.</p>' ) );
		$id   = $this->status( $post )['id'];

		$broken = $this->break_writes_to( 'versions' );
		wp_update_post( array( 'ID' => $post, 'post_status' => 'private' ) );

		// The database is still failing when readers ask.
		$item = $this->read( "/items/{$id}" );
		$this->assertSame( 200, $item['status'], 'never a 503: a cache may answer one with what it has' );
		$this->assertFalse( $item['cache'], 'and never stored' );
		$doc = json_decode( $item['body'], true );
		$this->assertSame( array( 'withdrawn', '', '' ), array( $doc['kind'], $doc['content_md'], $doc['content_html'] ) );

		$feed = $this->read( '/feed' );
		$this->assertSame( 200, $feed['status'] );
		$this->assertFalse( $feed['cache'] );
		$this->assertStringNotContainsString( 'Must not be served', $feed['body'] );
		$this->assertStringNotContainsString( 'Taken down', $feed['body'] );
		$this->assertStringContainsString( 'Stays.', $feed['body'], 'everything else is served as usual' );

		$index = $this->read( '/items' );
		$this->assertSame( 200, $index['status'] );
		foreach ( json_decode( $index['body'], true )['items'] as $entry ) {
			$this->assertSame( $entry['id'] === $id ? 'withdrawn' : 'thread', $entry['kind'] );
		}
		$this->mend( $broken );

		// The failed write is owed, and once the database is well the documents are ordinary again.
		$this->assertContains( $post, array_column( Plugin::instance()->posts->owed(), 'post' ) );
		$item = $this->read( "/items/{$id}" );
		$this->assertArrayNotHasKey( 'cache', $item );
		$this->assertSame( 'withdrawn', $this->status( $post )['kind'] );
		wp_unschedule_hook( PostTypeSource::REPAIR_HOOK );
		unset( $keep );
	}

	/*
	 * R6: a post type that is not registered for a moment.
	 */

	public function test_r6_a_reader_cannot_withdraw_the_posts_of_a_type_that_is_missing_for_a_moment() {
		register_post_type( 'book', array( 'public' => true, 'supports' => array( 'title', 'editor', 'blyg' ) ) );
		$book = self::factory()->post->create( array( 'post_type' => 'book', 'post_status' => 'publish', 'post_content' => '<p>A book.</p>' ) );
		$id   = $this->status( $book )['id'];

		// The plugin that registers the type is switched off for an update.
		unregister_post_type( 'book' );
		$this->read( '/feed' );
		$this->read( '/items' );
		$this->read( "/items/{$id}" );
		$this->assertSame( 'thread', $this->status( $book )['kind'], 'still on the blyg when the type comes back' );
	}

	/*
	 * R7: the repair queue's own write is not checked.
	 */

	public function test_r7_a_failure_is_not_lost_when_the_queue_cannot_be_written_either() {
		$post = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<p>Body.</p>' ) );
		delete_option( PostTypeSource::RECONCILE_OPTION );

		$broken = $this->break_writes_to( 'versions', 'repairs' );
		wp_update_post( array( 'ID' => $post, 'post_status' => 'private' ) );
		$this->mend( $broken );

		$this->assertSame( array(), Plugin::instance()->posts->owed(), 'the queue could not take it' );
		$this->assertNotFalse( get_option( PostTypeSource::RECONCILE_OPTION ), 'so a full reconcile is asked for instead' );
		wp_unschedule_hook( PostTypeSource::REPAIR_HOOK );
	}

	/*
	 * R9: reading a withdrawn item takes the ledger's lock every time.
	 */

	public function test_r9_reading_an_item_that_is_already_withdrawn_takes_no_lock() {
		global $wpdb;
		$post = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<p>Body.</p>' ) );
		$id   = $this->status( $post )['id'];
		wp_update_post( array( 'ID' => $post, 'post_status' => 'draft' ) );
		$this->assertSame( 'withdrawn', $this->status( $post )['kind'] );

		$locks  = 0;
		$writes = 0;
		$watch  = static function ( $query ) use ( &$locks, &$writes ) {
			$locks  += false !== stripos( $query, 'GET_LOCK' ) ? 1 : 0;
			$writes += preg_match( '/^\s*(INSERT|UPDATE|DELETE|START TRANSACTION)\b/i', $query ) ? 1 : 0;
			return $query;
		};
		add_filter( 'query', $watch );
		for ( $i = 0; $i < 3; $i++ ) {
			$this->read( "/items/{$id}" );
		}
		remove_filter( 'query', $watch );
		$this->assertSame( array( 0, 0 ), array( $locks, $writes ) );
		unset( $wpdb );
	}

	/*
	 * W2: an IPv4 client seen through a dual-stack listener.
	 */

	public function test_w2_an_ipv4_client_written_as_ipv6_is_the_same_client_and_not_everybodys() {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		$plain                  = Receiver::client();
		$_SERVER['REMOTE_ADDR'] = '::ffff:203.0.113.7';
		$mapped                 = Receiver::client();
		$_SERVER['REMOTE_ADDR'] = '::ffff:198.51.100.9';
		$another                = Receiver::client();
		unset( $_SERVER['REMOTE_ADDR'] );
		$this->assertSame( $plain, $mapped );
		$this->assertNotSame( $mapped, $another, 'two IPv4 senders do not share one budget' );
	}

	/*
	 * The network activation test QA called weak: what matters is what is served.
	 */

	public function test_after_a_network_activation_a_post_made_private_meanwhile_is_not_served() {
		global $wpdb;
		$post = self::factory()->post->create( array( 'post_status' => 'publish', 'post_title' => 'Was public', 'post_content' => '<p>Unpublished while the plugin was off.</p>' ) );
		$id   = $this->status( $post )['id'];
		// The plugin was inactive: no hook saw this.
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'private' ), array( 'ID' => $post ) );
		clean_post_cache( $post );
		Plugin::activate( is_multisite() );

		$this->assertStringNotContainsString( 'Unpublished while', $this->read( '/feed' )['body'] );
		$this->assertSame( 'withdrawn', json_decode( $this->read( "/items/{$id}" )['body'], true )['kind'] );
	}

	/*
	 * R3: sites that published under the earlier defaults.
	 */

	public function test_r3_a_site_that_published_on_the_old_defaults_keeps_publishing_where_it_was() {
		$post = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<p>Body.</p>' ) );
		$this->assertTrue( Plugin::instance()->registry->is_locked( 'posts' ) );

		// As rc1 and rc2 left it: published on defaults, no option ever saved.
		delete_option( \SoapboxBlyg\Settings::OPTION );
		delete_option( \SoapboxBlyg\Settings::MEANING_OPTION );
		update_option( 'soapbox_blyg_last_error', array( 'post' => 1 ) );
		$this->assertFalse( \SoapboxBlyg\Settings::get( 'enabled' ), 'read with the new defaults it would have gone dark' );

		Plugin::upgrade();
		$this->assertSame(
			array( true, true, '/blyg/', 'templated' ),
			array( \SoapboxBlyg\Settings::get( 'enabled' ), \SoapboxBlyg\Settings::get( 'setup' ), \SoapboxBlyg\Settings::get( 'mount' ), \SoapboxBlyg\Settings::get( 'layout' ) )
		);
		$this->assertFalse( get_option( 'soapbox_blyg_last_error' ), 'the option the repair queue replaced is gone' );
		$this->reset_registry();
		$this->assertSame( 200, $this->read( '/feed' )['status'] );

		// Saving the settings screen afterwards does not switch it off.
		update_option( \SoapboxBlyg\Settings::OPTION, array( '_screen' => '1', 'enabled' => '1', 'mount' => '/blyg/', 'layout' => 'templated', 'post_types' => array( 'post' ) ) );
		$this->assertTrue( \SoapboxBlyg\Settings::get( 'enabled' ) );
		unset( $post );
	}

	public function test_r3_saved_settings_from_before_keep_what_their_missing_keys_meant() {
		// An rc2 site that had saved its settings and not yet published: enabled, layout unset.
		update_option( \SoapboxBlyg\Settings::OPTION, array( 'enabled' => true, 'setup' => true, 'mount' => '/journal/', 'layout' => 'fixed' ) );
		global $wpdb;
		$wpdb->update( $wpdb->options, array( 'option_value' => serialize( array( 'enabled' => true, 'title' => 'Mine', 'mount' => '/journal/' ) ) ), array( 'option_name' => \SoapboxBlyg\Settings::OPTION ) ); // phpcs:ignore
		wp_cache_flush();
		delete_option( \SoapboxBlyg\Settings::MEANING_OPTION );

		Plugin::upgrade();
		$this->assertSame(
			array( true, true, '/journal/', 'templated', 'Mine' ),
			array( \SoapboxBlyg\Settings::get( 'enabled' ), \SoapboxBlyg\Settings::get( 'setup' ), \SoapboxBlyg\Settings::get( 'mount' ), \SoapboxBlyg\Settings::get( 'layout' ), \SoapboxBlyg\Settings::get( 'title' ) ),
			'on, set up, and in the layout the old default gave it'
		);

		// A site that never did anything is a fresh site.
		delete_option( \SoapboxBlyg\Settings::OPTION );
		delete_option( \SoapboxBlyg\Settings::MEANING_OPTION );
		Plugin::upgrade();
		$this->assertSame( array( false, false, 'fixed' ), array( \SoapboxBlyg\Settings::get( 'enabled' ), \SoapboxBlyg\Settings::get( 'setup' ), \SoapboxBlyg\Settings::get( 'layout' ) ) );
	}

	/*
	 * R8 and R14: the schema is brought up to date wherever the next request lands, and a
	 * database that cannot hold it is reported and not hammered.
	 */

	public function test_r8_the_schema_is_upgraded_on_any_request_not_only_in_the_admin() {
		$this->assertNotFalse( has_action( 'init', array( Plugin::class, 'upgrade' ) ) );
		$this->assertFalse( has_action( 'admin_init', array( Schema::class, 'maybe_install' ) ) );
		update_option( Schema::VERSION_OPTION, '1' );
		set_current_screen( 'front' );
		Plugin::upgrade();
		$this->assertSame( Schema::VERSION, get_option( Schema::VERSION_OPTION ) );
	}

	public function test_r14_a_database_without_innodb_stops_publishing_says_so_and_is_not_retried() {
		update_option( Schema::PROBLEM_OPTION, Schema::VERSION . ':engine' );
		$this->assertSame( 'engine', Schema::blocked() );

		// Nothing is registered, so nothing is served or published.
		$this->reset_registry();
		$this->assertFalse( Plugin::instance()->registry->has( 'posts' ) );
		$this->assertSame( 'critical', \SoapboxBlyg\Admin\SiteHealth::test_ledger()['status'] );

		// Not tried again on every request...
		update_option( Schema::VERSION_OPTION, '1' );
		$queries = 0;
		$count   = static function ( $query ) use ( &$queries ) {
			$queries += false !== stripos( $query, 'CREATE TABLE' ) ? 1 : 0;
			return $query;
		};
		add_filter( 'query', $count );
		Plugin::upgrade();
		remove_filter( 'query', $count );
		$this->assertSame( 0, $queries );

		// ...and a problem recorded for an older schema version does not block this one.
		update_option( Schema::PROBLEM_OPTION, '1:engine' );
		$this->assertSame( '', Schema::blocked() );
		delete_option( Schema::PROBLEM_OPTION );
		update_option( Schema::VERSION_OPTION, Schema::VERSION );
	}

	/*
	 * G1: the setup notice is not a nag.
	 */

	public function test_g1_the_setup_notice_is_shown_only_where_plugins_are_managed() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		delete_option( \SoapboxBlyg\Settings::OPTION );
		$this->reset_registry();
		$shown = static function ( string $screen ): string {
			set_current_screen( $screen );
			ob_start();
			\SoapboxBlyg\Admin\ToolsPage::notices();
			return (string) ob_get_clean();
		};
		$this->assertStringContainsString( 'not publishing', $shown( 'plugins' ) );
		foreach ( array( 'dashboard', 'edit-post', 'options-general', 'upload' ) as $elsewhere ) {
			$this->assertSame( '', $shown( $elsewhere ), $elsewhere );
		}
		set_current_screen( 'front' );
	}

	/*
	 * R11: render drift sees what the ledger would be handed, and one bad post does not end it.
	 */

	public function test_r11_render_drift_applies_the_snapshot_filter_and_survives_a_post_that_will_not_render() {
		$stamp = static function ( $snapshot ) {
			$snapshot['content_html'] .= '<p>Added by a filter.</p>';
			return $snapshot;
		};
		add_filter( 'soapbox_blyg_snapshot', $stamp );
		$one = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<p>One.</p>' ) );
		$two = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<p>Two.</p>' ) );

		$verifier = new \SoapboxBlyg\Ledger\Verifier();
		$this->assertTrue( $verifier->run( null, Plugin::instance()->posts ) );
		$this->assertSame( array(), $verifier->warnings, 'what the filter adds is in the ledger, so it is not drift' );

		// One post now fails to render. It is reported; the other is still compared.
		remove_filter( 'soapbox_blyg_snapshot', $stamp );
		$breaks = static function ( $snapshot, $origin, $key ) use ( $one ) {
			if ( 'post:' . $one === $key ) {
				throw new \RuntimeException( 'this one is broken' );
			}
			return $snapshot;
		};
		add_filter( 'soapbox_blyg_snapshot', $breaks, 10, 3 );
		$verifier = new \SoapboxBlyg\Ledger\Verifier();
		$verifier->run( null, Plugin::instance()->posts );
		$text = implode( ' | ', $verifier->warnings );
		$this->assertStringContainsString( "post {$one}", $text );
		$this->assertStringContainsString( 'could not be rendered', $text );
		$this->assertStringContainsString( "post {$two}", $text, 'and the other post was still checked: without the filter it renders differently' );
		remove_filter( 'soapbox_blyg_snapshot', $breaks, 10 );
	}
}
