<?php
/**
 * QA's second adversarial pass on the rc3 candidate (bbc5930), as reproductions.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Tests;

use SoapboxBlyg\Ledger\Schema;
use SoapboxBlyg\Mentions\Receiver;
use SoapboxBlyg\Mentions\Store;
use SoapboxBlyg\Mentions\Verifier;
use SoapboxBlyg\Origins\PostTypeSource;
use SoapboxBlyg\Plugin;
use SoapboxBlyg\Settings;
use SoapboxBlyg\Surface\Locations;
use SoapboxBlyg\Surface\RestDocuments;
use WP_REST_Request;

class QaPass2Test extends TestCase {

	private function status( int $post_id ) {
		return soapbox_blyg_get_status( 'posts', 'post:' . $post_id );
	}

	private function read( string $route ): array {
		return rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/' . Locations::rest_namespace() . '/origins/posts' . $route ) )->get_data()[ RestDocuments::MARKER ];
	}

	/** Runs what the posts source hooks to admin_init, and nothing of core's (which sends headers). */
	private function fire_our_admin_init(): void {
		global $wp_filter;
		$ran = 0;
		foreach ( $wp_filter['admin_init']->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof PostTypeSource ) {
					call_user_func( $callback['function'] );
					++$ran;
				}
			}
		}
		$this->assertGreaterThan( 0, $ran, 'the posts source does hook admin_init' );
	}

	/*
	 * S1 (High): the fingerprint hashes every row of post meta.
	 */

	public function test_s1_meta_that_core_adds_and_removes_does_not_stop_a_pin() {
		$post = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<p>Body.</p>' ) );
		$id   = $this->status( $post )['id'];

		// WordPress marks a published post for pingbacks and enclosures on save, and the do_pings
		// cron event removes the marks a few minutes later.
		add_post_meta( $post, '_pingme', '1' );
		add_post_meta( $post, '_encloseme', '1' );
		delete_post_meta( $post, '_pingme' );
		delete_post_meta( $post, '_encloseme' );
		update_post_meta( $post, '_edit_lock', time() . ':1' );
		update_post_meta( $post, 'views', 41 );

		soapbox_blyg_pin( $id, 1 );
		$this->assertSame( array( 1 ), $this->status( $post )['pinned'] );
	}

	public function test_s1_meta_written_while_a_post_renders_does_not_stop_the_edit_publishing() {
		$post = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<p>First.</p>' ) );

		// A view counter, a page builder's CSS cache, a related-posts cache: written by rendering.
		$writes = static function ( $body ) use ( $post ) {
			update_post_meta( $post, '_some_render_cache', microtime() );
			update_post_meta( $post, 'views', wp_rand() );
			return $body;
		};
		add_filter( 'soapbox_blyg_rendered_body', $writes );
		wp_update_post( array( 'ID' => $post, 'post_content' => '<p>Second.</p>' ) );
		remove_filter( 'soapbox_blyg_rendered_body', $writes );

		$this->assertSame( 2, $this->status( $post )['version'], 'the edit is on the blyg' );
	}

	public function test_s1_the_featured_image_and_keys_a_site_names_are_still_seen() {
		$post = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<p>Body.</p>' ) );
		$one  = PostTypeSource::fingerprint( PostTypeSource::fresh( $post ) );
		update_post_meta( $post, '_thumbnail_id', 4321 );
		$two = PostTypeSource::fingerprint( PostTypeSource::fresh( $post ) );
		$this->assertNotSame( $one, $two );

		update_post_meta( $post, '_members_only', 1 );
		$this->assertSame( $two, PostTypeSource::fingerprint( PostTypeSource::fresh( $post ) ), 'a key nobody named is not in it' );
		add_filter(
			'soapbox_blyg_fingerprint_meta',
			static function ( $keys ) {
				$keys[] = '_members_only';
				return $keys;
			}
		);
		$this->assertNotSame( $two, PostTypeSource::fingerprint( PostTypeSource::fresh( $post ) ), 'a key the site says its filter reads is' );
		remove_all_filters( 'soapbox_blyg_fingerprint_meta' );
	}

	/*
	 * S2: the stale check fails open.
	 */

	public function test_s2_when_the_stale_check_itself_fails_nothing_live_is_served_or_stored() {
		global $wpdb;
		$post = self::factory()->post->create( array( 'post_status' => 'publish', 'post_title' => 'Shown', 'post_content' => '<p>Served only when known to be public.</p>' ) );
		$id   = $this->status( $post )['id'];
		$this->assertSame( 'thread', json_decode( $this->read( "/items/{$id}" )['body'], true )['kind'] );

		// The query that asks the posts table fails.
		$breaks = static function ( $query ) use ( $wpdb ) {
			return false !== strpos( $query, 'LEFT JOIN ' . $wpdb->posts ) ? 'SELECT * FROM soapbox_blyg_no_such_table' : $query;
		};
		add_filter( 'query', $breaks );
		$wpdb->suppress_errors( true );
		$item = $this->read( "/items/{$id}" );
		$feed = $this->read( '/feed' );
		remove_filter( 'query', $breaks );
		$wpdb->suppress_errors( false );

		$this->assertFalse( $item['cache'] ?? null, 'not stored' );
		$this->assertSame( 'withdrawn', json_decode( $item['body'], true )['kind'], 'what cannot be checked is not shown' );
		$this->assertFalse( $feed['cache'] ?? null );
		$this->assertStringNotContainsString( 'Served only when known', $feed['body'] );

		// And it was a failure to check, not a withdrawal.
		$this->assertSame( 'thread', $this->status( $post )['kind'] );
		$this->assertSame( 'thread', json_decode( $this->read( "/items/{$id}" )['body'], true )['kind'] );
	}

	/*
	 * S3: the filter is run as whoever is asking.
	 */

	public function test_s3_whether_a_post_is_public_is_decided_as_a_logged_out_visitor_whoever_asks() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post  = self::factory()->post->create( array( 'post_status' => 'publish', 'post_title' => 'Members', 'post_content' => '<p>For members.</p>' ) );
		$id    = $this->status( $post )['id'];

		// A paywall that lets members read: public to them, not to a visitor.
		$seen = array();
		add_filter(
			'soapbox_blyg_is_public',
			static function ( $public ) use ( &$seen ) {
				$seen[] = get_current_user_id();
				return $public && is_user_logged_in();
			}
		);

		wp_set_current_user( $admin );
		$feed = $this->read( '/feed' );
		$this->assertSame( $admin, get_current_user_id(), 'the request goes on as who it was' );
		$this->assertSame( array( 0 ), array_values( array_unique( $seen ) ), 'the filter only ever ran as nobody' );
		$this->assertStringNotContainsString( 'For members', $feed['body'] );

		wp_set_current_user( 0 );
		$this->assertStringNotContainsString( 'For members', $this->read( '/feed' )['body'], 'and no answer worked out for a member is kept for a visitor' );
		$this->assertSame( 'withdrawn', json_decode( $this->read( "/items/{$id}" )['body'], true )['kind'] );
	}

	/*
	 * S4: a reconcile withdraws the posts of a type that is not registered just now.
	 */

	public function test_s4_a_reconcile_leaves_alone_the_posts_of_a_type_that_is_not_registered() {
		register_post_type( 'book', array( 'public' => true, 'supports' => array( 'title', 'editor', 'blyg' ) ) );
		$book = self::factory()->post->create( array( 'post_type' => 'book', 'post_status' => 'publish', 'post_content' => '<p>A book.</p>' ) );
		unregister_post_type( 'book' );

		PostTypeSource::request_reconcile();
		Plugin::instance()->posts->withdraw_missed();
		$this->assertSame( 'thread', $this->status( $book )['kind'], 'a type that is missing is unknown, not private' );

		// A type that is registered and has no pages of its own is another matter.
		register_post_type( 'book', array( 'public' => false, 'supports' => array( 'title', 'editor', 'blyg' ) ) );
		PostTypeSource::request_reconcile();
		Plugin::instance()->posts->withdraw_missed();
		$this->assertSame( 'withdrawn', $this->status( $book )['kind'] );
		unregister_post_type( 'book' );
	}

	/*
	 * S5: admin_init also fires for anonymous admin-ajax requests.
	 */

	public function test_s5_an_anonymous_admin_ajax_request_runs_no_repair_and_no_reconcile() {
		$breaks = static function () {
			throw new \RuntimeException( 'still broken' );
		};
		add_filter( 'soapbox_blyg_snapshot', $breaks );
		self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<p>Body.</p>' ) );
		$this->repairs_are_due();
		PostTypeSource::request_reconcile();

		wp_set_current_user( 0 );
		add_filter( 'wp_doing_ajax', '__return_true' );
		$this->fire_our_admin_init();
		remove_filter( 'wp_doing_ajax', '__return_true' );
		$this->assertSame( 1, Plugin::instance()->posts->owed()[0]['tries'], 'no repair' );
		$this->assertNotFalse( get_option( PostTypeSource::RECONCILE_OPTION ), 'no reconcile' );

		// Nor for a signed-in user who cannot manage the plugin.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->fire_our_admin_init();
		$this->assertSame( 1, Plugin::instance()->posts->owed()[0]['tries'] );

		remove_filter( 'soapbox_blyg_snapshot', $breaks );
		wp_unschedule_hook( PostTypeSource::REPAIR_HOOK );
		wp_unschedule_hook( PostTypeSource::RECONCILE_HOOK );
	}

	/*
	 * S7: a reconcile that cannot run reschedules itself for ever.
	 */

	public function test_s7_a_reconcile_asked_for_on_a_site_that_is_not_publishing_is_dropped_not_retried() {
		delete_option( Settings::OPTION );
		$this->reset_registry();
		$this->assertFalse( Plugin::instance()->registry->has( 'posts' ) );
		wp_unschedule_hook( PostTypeSource::RECONCILE_HOOK );

		PostTypeSource::request_reconcile(); // As activation does.
		wp_unschedule_hook( PostTypeSource::RECONCILE_HOOK );
		Plugin::instance()->posts->maybe_reconcile();
		$this->assertFalse( wp_next_scheduled( PostTypeSource::RECONCILE_HOOK ), 'nothing is booked again' );
		$this->assertFalse( get_option( PostTypeSource::RECONCILE_OPTION ), 'and the request is cleared: switching publishing on asks afresh' );
	}

	/*
	 * S8: a claim put off for a busy destination is put off for ever, and holds a place meanwhile.
	 */

	public function test_s8_a_claim_is_put_off_three_times_at_most_and_holds_no_place_in_the_queue_meanwhile() {
		global $wpdb;
		update_option( Settings::OPTION, array( 'webmention' => true ) );
		$this->register_notes();
		soapbox_blyg_publish( 'notes', 'a', $this->fragment( 'One.', array( 'permalink' => home_url( '/notes/a/' ) ) ) );
		add_filter(
			'soapbox_blyg_webmention_limits',
			static function ( $limits ) {
				return array( 'outbound' => 0, 'pending' => 1 ) + $limits;
			}
		);
		$plugin   = Plugin::instance();
		$resolve  = static function () {
			return array( '93.184.216.34' );
		};
		$receiver = new Receiver( $plugin->mentions, $plugin->locations );
		$origin   = $plugin->registry->get( 'notes' );
		$this->assertSame( 202, $receiver->receive( $origin, 'https://busy.example/x', home_url( '/notes/a/' ) )['status'] );
		$id       = (int) $plugin->mentions->query()[0]['mention_id'];
		$verifier = new Verifier( $plugin->mentions, $plugin->registry, $plugin->ledger, $resolve );
		$table    = Schema::table( 'mentions' );

		$verifier->verify( $id );
		$this->assertSame( array( Store::PENDING, 'destination-busy' ), array( $plugin->mentions->get( $id )['status'], $plugin->mentions->get( $id )['reason'] ) );

		// Put off, it is not waiting to be verified: somebody else's claim has room.
		$this->assertSame( 202, $receiver->receive( $origin, 'https://other.example/x', home_url( '/notes/a/' ) )['status'], 'a put-off claim does not fill the queue' );

		// The destination stays busy hour after hour.
		for ( $hour = 0; $hour < 5 && Store::PENDING === $plugin->mentions->get( $id )['status']; $hour++ ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET next_try = NULL WHERE mention_id = %d', $table, $id ) );
			$verifier->verify( $id );
		}
		$this->assertSame( array( Store::FAILED, 'destination-busy' ), array( $plugin->mentions->get( $id )['status'], $plugin->mentions->get( $id )['reason'] ) );
		$this->assertLessThanOrEqual( 3, $hour, 'three hours, not seven days' );
		wp_unschedule_hook( Receiver::VERIFY_HOOK );
	}

	/*
	 * L4: a reader's successful withdrawal settles what was owed.
	 */

	public function test_l4_a_withdrawal_made_while_serving_settles_what_was_owed_and_a_readers_failure_costs_no_try() {
		global $wpdb;
		$post = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<p>Body.</p>' ) );
		$id   = $this->status( $post )['id'];

		$table  = Schema::table( 'versions' );
		$breaks = static function ( $query ) use ( $table ) {
			return preg_match( '/^\s*INSERT\b/i', $query ) && false !== strpos( $query, $table ) ? 'INSERT INTO soapbox_blyg_no_such_table VALUES (1)' : $query;
		};
		add_filter( 'query', $breaks );
		$wpdb->suppress_errors( true );
		wp_update_post( array( 'ID' => $post, 'post_status' => 'private' ) );
		// Readers keep asking while it is broken.
		for ( $i = 0; $i < 4; $i++ ) {
			$this->read( "/items/{$id}" );
		}
		remove_filter( 'query', $breaks );
		$wpdb->suppress_errors( false );
		$this->assertSame( 1, Plugin::instance()->posts->owed()[0]['tries'], 'the save counted; the readers did not' );

		// Mended. The next reader's request writes the withdrawal, and nothing is owed.
		$this->read( "/items/{$id}" );
		$this->assertSame( 'withdrawn', $this->status( $post )['kind'] );
		$this->assertSame( array(), Plugin::instance()->posts->owed() );
		wp_unschedule_hook( PostTypeSource::REPAIR_HOOK );
	}

	/*
	 * S4, last part: when a type really has stopped having pages, something notices.
	 */

	public function test_s4_a_change_in_which_types_have_pages_asks_for_a_reconcile() {
		$posts = Plugin::instance()->posts;
		delete_option( PostTypeSource::TYPES_OPTION );
		delete_option( PostTypeSource::RECONCILE_OPTION );
		$posts->watch_post_types();
		$this->assertFalse( get_option( PostTypeSource::RECONCILE_OPTION ), 'the first look is only a note of how things are' );

		register_post_type( 'book', array( 'public' => true ) );
		$posts->watch_post_types();
		$this->assertNotFalse( get_option( PostTypeSource::RECONCILE_OPTION ) );

		delete_option( PostTypeSource::RECONCILE_OPTION );
		$posts->watch_post_types();
		$this->assertFalse( get_option( PostTypeSource::RECONCILE_OPTION ), 'nothing changed, nothing asked' );
		unregister_post_type( 'book' );
		wp_unschedule_hook( PostTypeSource::RECONCILE_HOOK );
	}

	/*
	 * L3: an upgrade that did not take is not recorded as done, and is not hammered.
	 */

	public function test_l3_a_schema_whose_columns_are_missing_is_not_recorded_as_installed() {
		global $wpdb;
		$table = Schema::table( 'repairs' );
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN next_try', $table ) ); // phpcs:ignore
		update_option( Schema::VERSION_OPTION, '1' );

		// dbDelta's ALTER fails silently.
		$breaks = static function ( $query ) {
			return preg_match( '/^\s*ALTER TABLE\b.*\bADD\b/i', $query ) ? 'SELECT 1' : $query;
		};
		add_filter( 'query', $breaks );
		$this->assertFalse( Schema::install() );
		$this->assertSame( '1', get_option( Schema::VERSION_OPTION ), 'the version is not recorded' );
		$this->assertSame( 'tables', Schema::blocked() );

		// A visitor's request does not try again for ten minutes.
		$creates = 0;
		$count   = static function ( $query ) use ( &$creates ) {
			$creates += preg_match( '/^\s*(CREATE TABLE|ALTER TABLE|DESCRIBE)\b/i', $query ) ? 1 : 0;
			return $query;
		};
		add_filter( 'query', $count );
		set_current_screen( 'front' );
		Schema::maybe_install();
		$this->assertSame( 0, $creates );
		remove_filter( 'query', $count );
		remove_filter( 'query', $breaks );

		// Nor does an administrator's page load: an admin screen is not a licence to run the
		// install on every click.
		$first = explode( ':', (string) get_option( Schema::PROBLEM_OPTION ) )[2];
		add_filter( 'query', $count );
		set_current_screen( 'dashboard' );
		Schema::maybe_install();
		set_current_screen( 'front' );
		remove_filter( 'query', $count );
		$this->assertSame( 0, $creates );

		// Ten minutes on it is tried again, and mends. The time it first failed was kept till then.
		$parts    = explode( ':', (string) get_option( Schema::PROBLEM_OPTION ) );
		$parts[3] = (string) ( time() - 11 * MINUTE_IN_SECONDS );
		update_option( Schema::PROBLEM_OPTION, implode( ':', $parts ) );
		$this->assertSame( $first, $parts[2] );
		Schema::maybe_install();
		$this->assertSame( Schema::VERSION, get_option( Schema::VERSION_OPTION ) );
		$this->assertSame( '', Schema::blocked() );
		$this->assertSame( '', get_option( Schema::PROBLEM_OPTION, 'gone' ), 'kept as an empty option, so asking costs nothing' );
	}

	/*
	 * L6: a site that has gone offline for its tables says so everywhere, once per person.
	 */

	public function test_l6_a_blocked_schema_is_said_on_every_admin_screen_until_dismissed() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		update_option( Schema::PROBLEM_OPTION, Schema::VERSION . ':engine:' . time() );
		$shown = static function ( string $screen ): string {
			set_current_screen( $screen );
			ob_start();
			\SoapboxBlyg\Admin\ToolsPage::notices();
			return (string) ob_get_clean();
		};
		$this->assertStringContainsString( 'InnoDB', $shown( 'dashboard' ) );
		$this->assertStringContainsString( 'soapbox_blyg_dismiss', $shown( 'edit-post' ) );

		update_user_meta( $admin, 'soapbox_blyg_dismissed_problem', Schema::problem_key() );
		$this->assertSame( '', $shown( 'dashboard' ), 'dismissed by this person' );
		// Tried again and failed again: the same problem, so it stays dismissed.
		update_option( Schema::PROBLEM_OPTION, Schema::VERSION . ':engine:' . ( time() - 500 ) . ':' . time() );
		$this->assertSame( '', $shown( 'dashboard' ), 'and it does not come back with each retry' );
		$this->assertStringContainsString( 'InnoDB', $shown( 'plugins' ), 'still said where the plugin is managed' );

		update_option( Schema::PROBLEM_OPTION, '' );
		set_current_screen( 'front' );
	}

	/*
	 * From QA's verification of bd139de: seven small things before the tag.
	 */

	public function test_settings_can_be_saved_where_there_is_no_admin_screen_to_report_to() {
		self::factory()->post->create( array( 'post_status' => 'publish' ) ); // The blyg is locked.
		$this->assertTrue( Plugin::instance()->registry->is_locked( 'posts' ) );
		// As WP-CLI, the REST API or a front-end request would: a refused change, nobody to tell.
		$clean = \SoapboxBlyg\Admin\SettingsPage::sanitize( array( 'mount' => '/elsewhere/', 'layout' => 'fixed', 'enabled' => true ) );
		$this->assertSame( array( '/blyg/', 'templated' ), array( $clean['mount'], $clean['layout'] ) );
		$source = (string) file_get_contents( dirname( SOAPBOX_BLYG_FILE ) . '/src/Admin/SettingsPage.php' );
		$this->assertSame( 1, substr_count( $source, 'add_settings_error(' ), 'every report goes through the one guarded call' );
		$this->assertStringContainsString( "function_exists( 'add_settings_error' )", $source );
	}

	public function test_an_upgrade_to_schema_5_forgets_fingerprints_made_the_old_way() {
		global $wpdb;
		$post = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<p>Body.</p>' ) );
		$id   = $this->status( $post )['id'];
		// As version 4 left it: a fingerprint of all the post's meta, which will never match now.
		$wpdb->update( Schema::table( 'items' ), array( 'source_fp' => str_repeat( 'a', 64 ) ), array( 'blyg_id' => $id ) );
		update_option( Schema::VERSION_OPTION, '4' );
		Schema::install();
		$this->assertNull( Plugin::instance()->ledger->item_by_id( $id )['source_fp'] );
		soapbox_blyg_pin( $id, 1 );
		$this->assertSame( array( 1 ), $this->status( $post )['pinned'], 'an item from before the upgrade can be pinned without a save' );

		// And only on that upgrade: a later install leaves fingerprints alone.
		wp_update_post( array( 'ID' => $post, 'post_content' => '<p>Two.</p>' ) );
		$this->assertNotNull( Plugin::instance()->ledger->item_by_id( $id )['source_fp'] );
		Schema::install();
		$this->assertNotNull( Plugin::instance()->ledger->item_by_id( $id )['source_fp'] );
	}

	public function test_cleaning_a_posts_cache_marks_the_kept_answer_old() {
		add_filter( 'soapbox_blyg_is_public', '__return_true' );
		$post = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->read( '/feed' );
		$kept = get_transient( PostTypeSource::STALE_TRANSIENT );
		$this->assertGreaterThan( 0, $kept['at'] );
		clean_post_cache( $post );
		$this->assertSame( 0, get_transient( PostTypeSource::STALE_TRANSIENT )['at'] );
	}
}
