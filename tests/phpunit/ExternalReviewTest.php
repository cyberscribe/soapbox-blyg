<?php
/**
 * The external review of rc2 (2026-10-06), finding by finding, as reproductions.
 *
 * Each test drives the sequence the reviewer describes against real WordPress and MySQL. Where a
 * finding is an interleaving of two requests, the second request is run from a hook at the point
 * the reviewer names, so the order is fixed and nothing sleeps.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Tests;

use SoapboxBlyg\Admin\SettingsPage;
use SoapboxBlyg\Ledger\Schema;
use SoapboxBlyg\Mentions\Receiver;
use SoapboxBlyg\Mentions\Store;
use SoapboxBlyg\Mentions\Verifier;
use SoapboxBlyg\Origins\PostTypeSource;
use SoapboxBlyg\Plugin;
use SoapboxBlyg\Settings;
use SoapboxBlyg\Surface\Locations;
use WP_REST_Request;

class ExternalReviewTest extends TestCase {

	private function status( int $post_id ) {
		return soapbox_blyg_get_status( 'posts', 'post:' . $post_id );
	}

	/** The item document as served, decoded. */
	private function served_item( string $id ): array {
		$plugin = Plugin::instance();
		$doc    = $plugin->documents->item( $plugin->registry->get( 'posts' ), $id );
		return json_decode( $doc[0], true );
	}

	private function served_feed(): string {
		$plugin = Plugin::instance();
		return $plugin->documents->feed( $plugin->registry->get( 'posts' ) )[0];
	}

	private function served_index(): array {
		$plugin = Plugin::instance();
		return json_decode( $plugin->documents->index( $plugin->registry->get( 'posts' ) )[0], true );
	}

	private function admin_rest( string $method, string $route, array $params = array() ) {
		$request = new WP_REST_Request( $method, '/' . Locations::rest_namespace() . '/admin' . $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	/** Makes every write to the versions table fail, as a full disk or a lost connection would. */
	private function break_ledger_writes(): callable {
		global $wpdb;
		$table  = Schema::table( 'versions' );
		$filter = static function ( $query ) use ( $table ) {
			return preg_match( '/^\s*INSERT\b/i', $query ) && false !== strpos( $query, $table ) ? 'INSERT INTO soapbox_blyg_no_such_table VALUES (1)' : $query;
		};
		add_filter( 'query', $filter );
		$wpdb->suppress_errors( true );
		return $filter;
	}

	private function mend_ledger_writes( callable $filter ): void {
		global $wpdb;
		remove_filter( 'query', $filter );
		$wpdb->suppress_errors( false );
	}

	/*
	 * F1 (High): a failed withdrawal leaves content public, and pinning trusts the stale ledger.
	 */

	public function test_f1_a_withdrawal_that_could_not_be_written_does_not_leave_the_content_served() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$post = self::factory()->post->create( array( 'post_status' => 'publish', 'post_title' => 'Secret plans', 'post_content' => '<p>The body nobody should read now.</p>' ) );
		$id   = $this->status( $post )['id'];
		$this->assertStringContainsString( 'nobody should read', $this->served_item( $id )['content_md'] );

		// The post goes private, and the ledger cannot record it.
		$broken = $this->break_ledger_writes();
		wp_update_post( array( 'ID' => $post, 'post_status' => 'private' ) );
		$this->mend_ledger_writes( $broken );
		$this->assertSame( 'private', get_post_status( $post ) );

		// The database is well again. Nothing else is saved.
		$item = $this->served_item( $id );
		$this->assertSame( 'withdrawn', $item['kind'], 'the item document' );
		$this->assertSame( '', $item['content_md'] );
		$this->assertSame( '', $item['content_html'] );
		$this->assertStringNotContainsString( 'nobody should read', $this->served_feed(), 'the feed' );
		$this->assertStringNotContainsString( 'Secret plans', $this->served_feed() );
		foreach ( $this->served_index()['items'] as $entry ) {
			if ( $entry['id'] === $id ) {
				$this->assertSame( 'withdrawn', $entry['kind'], 'the index' );
			}
		}

		// And what was never pinned cannot be pinned now.
		$this->assertSame( 409, $this->admin_rest( 'POST', '/pin', array( 'post' => $post, 'version' => 1 ) )->get_status() );
		$this->assertSame( array(), $this->status( $post )['pinned'] );
	}

	public function test_f1_a_failed_withdrawal_is_kept_as_work_to_do_and_done_without_another_save() {
		$post = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<p>Body.</p>' ) );

		$broken = $this->break_ledger_writes();
		wp_update_post( array( 'ID' => $post, 'post_status' => 'private' ) );
		$this->mend_ledger_writes( $broken );
		$this->assertSame( 'thread', $this->status( $post )['kind'], 'the withdrawal did fail' );

		// The retry that cron, or the next request, makes.
		$this->assertTrue( method_exists( Plugin::instance()->posts, 'repair' ), 'there is a repair step' );
		$this->repairs_are_due();
		Plugin::instance()->posts->repair();
		$this->assertSame( 'withdrawn', $this->status( $post )['kind'] );
	}

	public function test_f1_a_post_that_is_private_in_the_database_cannot_be_pinned_whatever_the_ledger_says() {
		global $wpdb;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$post = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<p>Body.</p>' ) );
		$id   = $this->status( $post )['id'];

		// The no-fault race: WordPress has written the post's new status, and the handler that will
		// withdraw the item has not run yet. A pin request arrives in between.
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'private' ), array( 'ID' => $post ) );
		clean_post_cache( $post );

		$this->assertSame( 409, $this->admin_rest( 'POST', '/pin', array( 'post' => $post, 'version' => 1 ) )->get_status() );
		try {
			soapbox_blyg_pin( $id, 1 );
			$this->fail( 'pinned a version of a post that is private' );
		} catch ( \RuntimeException $e ) {
			$this->assertNotEmpty( $e->getMessage() );
		}
		$this->assertNull( Plugin::instance()->documents->pinned( Plugin::instance()->registry->get( 'posts' ), $id, 1 ) );

		// The same for a password, and for a post that has gone.
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'publish', 'post_password' => 'x' ), array( 'ID' => $post ) );
		clean_post_cache( $post );
		$this->assertSame( 409, $this->admin_rest( 'POST', '/pin', array( 'post' => $post, 'version' => 1 ) )->get_status() );
	}

	public function test_f1_pins_made_while_the_post_was_public_stay_served() {
		global $wpdb;
		$post = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<p>Pinned words.</p>' ) );
		$id   = $this->status( $post )['id'];
		soapbox_blyg_pin( $id, 1 );

		$wpdb->update( $wpdb->posts, array( 'post_status' => 'private' ), array( 'ID' => $post ) );
		clean_post_cache( $post );

		$origin = Plugin::instance()->registry->get( 'posts' );
		$this->assertSame( 'withdrawn', $this->served_item( $id )['kind'] );
		$pinned = Plugin::instance()->documents->pinned( $origin, $id, 1 );
		$this->assertNotNull( $pinned, 'a pin is a promise, and outlives the withdrawal' );
		$this->assertStringContainsString( 'Pinned words', $pinned[0] );
	}

	public function test_f1_while_a_withdrawal_cannot_be_written_the_content_is_not_served_in_either_layout() {
		$post = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<p>Must not be served.</p>' ) );
		$id   = $this->status( $post )['id'];

		$broken = $this->break_ledger_writes();
		wp_update_post( array( 'ID' => $post, 'post_status' => 'private' ) );

		// The database is still failing when a reader asks. The ledger's copy is the old content
		// and the withdrawal cannot be written. The answer is a document without the content,
		// marked not to be stored: never the content, and never an error a cache might answer
		// with the copy it has (QA's ruling R5 on the 503 this first returned).
		$plugin = Plugin::instance();
		$origin = $plugin->registry->get( 'posts' );
		$fixed         = clone $origin;
		$fixed->layout = 'fixed';
		$responses     = array();
		foreach ( array( "/origins/posts/items/{$id}", '/origins/posts/feed', '/origins/posts/items' ) as $route ) {
			$responses[ $route ] = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/' . Locations::rest_namespace() . $route ) )->get_data()[ \SoapboxBlyg\Surface\RestDocuments::MARKER ];
		}
		foreach ( array( "items/{$id}.json", 'feed.xml', 'items/index.json' ) as $path ) {
			$responses[ $path ] = $plugin->router->respond( $fixed, $path );
		}
		$this->mend_ledger_writes( $broken );
		foreach ( $responses as $where => $response ) {
			$this->assertSame( 200, $response['status'], $where );
			$this->assertFalse( $response['cache'], "{$where} is not to be stored" );
			$this->assertStringNotContainsString( 'Must not be served', $response['body'], $where );
		}
		$this->assertSame( 'withdrawn', json_decode( $responses[ "items/{$id}.json" ]['body'], true )['kind'] );
		wp_unschedule_hook( PostTypeSource::REPAIR_HOOK );
	}

	public function test_f1_a_post_published_again_while_a_withdrawal_waits_is_left_alone() {
		global $wpdb;
		$post = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<p>Body.</p>' ) );
		// A reconcile, or a reader's request, noticed this post while it was private; by the time
		// the ledger's lock is held it is public again. The decision is made under the lock.
		$result = Plugin::instance()->ledger->withdraw_if_stale( 'posts', 'post:' . $post );
		$this->assertSame( 'none', $result['event'] );
		$this->assertSame( 'thread', $this->status( $post )['kind'] );

		$wpdb->update( $wpdb->posts, array( 'post_password' => 'x' ), array( 'ID' => $post ) );
		$this->assertSame( 'withdrawn', Plugin::instance()->ledger->withdraw_if_stale( 'posts', 'post:' . $post )['event'] );
	}

	public function test_f1_a_post_changed_since_it_was_published_cannot_be_pinned_until_it_is_saved() {
		global $wpdb;
		$post = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<p>As published.</p>' ) );
		$id   = $this->status( $post )['id'];

		// The post was edited, and the publish that should have followed did not happen.
		$wpdb->update( $wpdb->posts, array( 'post_content' => '<p>Rewritten, to take something out.</p>' ), array( 'ID' => $post ) );
		try {
			soapbox_blyg_pin( $id, 1 );
			$this->fail( 'pinned what the author has since rewritten' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'changed since', $e->getMessage() );
		}

		// Saved: the ledger holds what the post holds, and either version can be pinned.
		clean_post_cache( $post );
		wp_update_post( array( 'ID' => $post, 'post_excerpt' => 'x' ) );
		soapbox_blyg_pin( $id, 2 );
		$this->assertSame( array( 2 ), $this->status( $post )['pinned'] );
	}

	public function test_f1_the_filter_that_withholds_a_post_is_honoured_when_its_item_is_served() {
		$post = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<p>Members only, as of now.</p>' ) );
		$id   = $this->status( $post )['id'];
		$hide = static function ( $public, $p ) use ( $post ) {
			return (int) $p->ID === $post ? false : $public;
		};
		add_filter( 'soapbox_blyg_is_public', $hide, 10, 2 );
		$this->assertSame( 'withdrawn', $this->served_item( $id )['kind'] );
		remove_filter( 'soapbox_blyg_is_public', $hide, 10 );
	}

	/*
	 * F2 (High): the REST backfill publishes a snapshot of a post that has since gone private.
	 */

	public function test_f2_a_post_made_private_while_the_backfill_renders_it_is_not_published() {
		global $wpdb;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$source = Plugin::instance()->posts;
		remove_action( 'wp_after_insert_post', array( $source, 'on_save' ), 10 );
		$post = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<p>Was public when the backfill began.</p>' ) );
		add_action( 'wp_after_insert_post', array( $source, 'on_save' ), 10, 2 );
		$this->assertNull( $this->status( $post ) );

		// While the backfill is rendering the post, another request makes it private. That request's
		// own handler rightly does nothing: the post has no item to withdraw.
		$interleave = static function ( $body ) use ( &$interleave, $post ) {
			remove_filter( 'soapbox_blyg_rendered_body', $interleave );
			wp_update_post( array( 'ID' => $post, 'post_status' => 'private' ) );
			return $body;
		};
		add_filter( 'soapbox_blyg_rendered_body', $interleave );
		$this->admin_rest( 'POST', '/backfill' );
		remove_filter( 'soapbox_blyg_rendered_body', $interleave );

		$this->assertSame( 'private', get_post_status( $post ) );
		$status = $this->status( $post );
		$this->assertTrue( null === $status || 'withdrawn' === $status['kind'], 'no live item was made from the stale snapshot' );
		$this->assertStringNotContainsString( 'Was public when', $this->served_feed() );
	}

	/*
	 * F3 (High): content is served before the reconcile after reactivation has finished.
	 */

	public function test_f3_an_unfinished_reconcile_does_not_leave_the_rest_served() {
		$plugin = Plugin::instance();
		// 201 items whose posts were deleted while the plugin was inactive: one more than a batch.
		// They were published when their posts existed; here the ledger is filled without asking.
		$origin               = $plugin->registry->get( 'posts' );
		$asks                 = array( $origin->source_state, $origin->stale_sources );
		$origin->source_state = null;
		$ids                  = array();
		for ( $i = 1; $i <= 201; $i++ ) {
			$ids[] = $plugin->ledger->publish(
				'posts',
				'post:' . ( 900000 + $i ),
				$this->fragment( "Body {$i}.", array( 'kind' => 'thread', 'transclusions' => array(), 'permalink' => home_url( "/gone-{$i}/" ) ) )
			)['id'];
		}
		sort( $ids );
		list( $origin->source_state, $origin->stale_sources ) = $asks;

		// Reactivation asks for a reconcile, and the request that runs it runs out of time.
		PostTypeSource::request_reconcile();
		$this->assertFalse( $plugin->posts->withdraw_missed( 0.0 ), 'the scan stopped after one batch' );

		// That same request goes on to serve the last item.
		$last = $this->served_item( $ids[200] );
		$this->assertSame( 'withdrawn', $last['kind'] );
		$this->assertSame( '', $last['content_md'] );
		$this->assertStringNotContainsString( 'Body 201.', $this->served_feed() );
	}

	public function test_f3_network_activation_asks_every_site_with_a_ledger_to_reconcile() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only: run with WP_MULTISITE=1.' );
		}
		delete_option( PostTypeSource::RECONCILE_OPTION );
		Plugin::activate( true );
		// Lazily, on this site's next request.
		do_action( 'wp_loaded' );
		$this->assertTrue(
			false !== get_option( PostTypeSource::RECONCILE_OPTION ) || did_action( 'soapbox_blyg_reconciled' ) > 0,
			'a site that already has a ledger reconciles after network activation'
		);
	}

	/*
	 * F4 (Medium): the Markdown hash is used as if it were equality of everything published.
	 */

	public function test_f4_a_change_that_leaves_the_markdown_alone_is_still_a_new_version() {
		$first  = soapbox_blyg_publish( 'posts', 'x:1', $this->fragment( 'Same words.', array( 'kind' => 'thread', 'transclusions' => array(), 'permalink' => home_url( '/old-address/' ), 'author' => array( 'name' => 'Old Name' ) ) ) );
		$second = soapbox_blyg_publish( 'posts', 'x:1', $this->fragment( 'Same words.', array( 'kind' => 'thread', 'transclusions' => array(), 'permalink' => home_url( '/new-address/' ), 'author' => array( 'name' => 'New Name' ) ) ) );
		$this->assertSame( 'bumped', $second['event'], 'a new address and a new byline are a change to what is published' );

		$item = $this->served_item( $first['id'] );
		$this->assertSame( home_url( '/new-address/' ), $item['page'] );
		$this->assertSame( 'New Name', $item['author']['name'] );
		$this->assertSame( 2, $item['version'] );
		$rows = Plugin::instance()->ledger->changelog_rows( $first['id'] );
		$this->assertCount( 2, $rows );
		$v1 = Plugin::instance()->ledger->version_row( $first['id'], 1 );
		$v2 = Plugin::instance()->ledger->version_row( $first['id'], 2 );
		$this->assertSame( $v1['content_hash'], $v2['content_hash'], 'the wire hash is still of the Markdown alone' );
		$this->assertSame( home_url( '/old-address/' ), $v1['permalink'], 'history is not rewritten' );
	}

	public function test_f4_html_that_differs_under_the_same_markdown_is_a_new_version() {
		$post = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<p>H<sub>2</sub>O</p>' ) );
		$id   = $this->status( $post )['id'];
		$this->assertStringContainsString( '<sub>2</sub>', $this->served_item( $id )['content_html'] );

		wp_update_post( array( 'ID' => $post, 'post_content' => '<p>H<sup>2</sup>O</p>' ) );
		$item = $this->served_item( $id );
		$this->assertStringContainsString( '<sup>2</sup>', $item['content_html'], 'what readers are shown follows the edit' );
		$this->assertSame( 2, $item['version'] );
	}

	/*
	 * F5 (Medium): two saves inside one second, finishing in reverse order.
	 */

	public function test_f5_an_older_snapshot_cannot_overwrite_a_newer_one_saved_in_the_same_second() {
		global $wpdb;
		$post = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<p>Original.</p>' ) );
		$id   = $this->status( $post )['id'];

		// Request A has the post as it was and starts rendering it.
		$a               = get_post( $post );
		$a->post_content = '<p>From request A, the older edit.</p>';
		$wpdb->update( $wpdb->posts, array( 'post_content' => $a->post_content ), array( 'ID' => $post ) );

		// While A renders, request B saves a newer body within the same second (the modified time
		// does not move) and publishes it.
		$source     = Plugin::instance()->posts;
		$interleave = static function ( $body ) use ( &$interleave, $wpdb, $post, $source ) {
			remove_filter( 'soapbox_blyg_rendered_body', $interleave );
			$wpdb->update( $wpdb->posts, array( 'post_content' => '<p>From request B, the newer edit.</p>' ), array( 'ID' => $post ) );
			clean_post_cache( $post );
			$source->publish( get_post( $post ) );
			return $body;
		};
		add_filter( 'soapbox_blyg_rendered_body', $interleave );
		$source->publish( $a );
		remove_filter( 'soapbox_blyg_rendered_body', $interleave );

		$this->assertStringContainsString( 'the newer edit', $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $post ) ) );
		$this->assertStringContainsString( 'the newer edit', $this->served_item( $id )['content_md'], 'the ledger ends on what the database holds' );
		$this->assertStringNotContainsString( 'the older edit', $this->served_item( $id )['content_md'] );
	}

	public function test_f5_a_change_of_featured_image_alone_is_seen() {
		$post  = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<p>Body.</p>' ) );
		$fresh = PostTypeSource::fresh( $post );
		$one   = PostTypeSource::fingerprint( $fresh );
		$this->assertSame( $one, PostTypeSource::fingerprint( PostTypeSource::fresh( $post ) ), 'the same state, the same fingerprint' );
		update_post_meta( $post, '_thumbnail_id', 12345 );
		$this->assertNotSame( $one, PostTypeSource::fingerprint( PostTypeSource::fresh( $post ) ) );
	}

	/*
	 * F6 (Medium): a date-only conditional request across a withdrawal in the same second.
	 */

	public function test_f6_an_item_document_gives_no_date_for_a_cache_to_trust() {
		$at = '2026-10-06T12:00:00Z';
		$r  = Plugin::instance()->ledger->publish( 'posts', 'x:2', $this->fragment( 'Live.', array( 'kind' => 'thread', 'transclusions' => array() ) ), array( 'at' => $at ) );
		$origin = Plugin::instance()->registry->get( 'posts' );
		$live   = Plugin::instance()->documents->item( $origin, $r['id'] );
		Plugin::instance()->ledger->publish( 'posts', 'x:2', null, array( 'at' => $at ) );
		$gone = Plugin::instance()->documents->item( $origin, $r['id'] );

		$this->assertNotSame( $live[0], $gone[0] );
		// Both events carry the same second. A date would tell a client that sends only
		// If-Modified-Since that nothing changed.
		$this->assertSame( 0, $live[1], 'no Last-Modified on a document that can change' );
		$this->assertSame( 0, $gone[1] );
	}

	/*
	 * F7 (Medium): rejected claims cost DNS work and count against nothing.
	 */

	public function test_f7_rejected_claims_from_one_client_are_limited_before_any_lookup() {
		$plugin  = Plugin::instance();
		update_option( Settings::OPTION, array( 'webmention' => true ) );
		$this->register_notes();
		soapbox_blyg_publish( 'notes', 'a', $this->fragment( 'One.', array( 'permalink' => home_url( '/notes/a/' ) ) ) );

		$lookups  = 0;
		$receiver = new Receiver(
			$plugin->mentions,
			$plugin->locations,
			static function () use ( &$lookups ) {
				++$lookups;
				return null; // Every source turns out to be private, or not to resolve.
			}
		);
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		$client                 = method_exists( Receiver::class, 'client' ) ? Receiver::client() : '';
		$refused                = 0;
		for ( $i = 0; $i < 400; $i++ ) {
			$status   = $receiver->receive( $plugin->registry->get( 'notes' ), "https://slow-{$i}.attacker.example/x", home_url( '/notes/a/' ), $client )['status'];
			$refused += 429 === $status ? 1 : 0;
		}
		unset( $_SERVER['REMOTE_ADDR'] );

		$this->assertSame( 0, $lookups, 'accepting a claim resolves nothing: that is the job\'s work' );
		$this->assertGreaterThanOrEqual( 100, $refused, 'and one client\'s attempts are counted, whatever becomes of them' );
	}

	/*
	 * F8 (Medium): the hourly limits count pairs, not claims.
	 */

	public function test_f8_a_pair_sent_again_counts_again() {
		global $wpdb;
		$plugin = Plugin::instance();
		update_option( Settings::OPTION, array( 'webmention' => true ) );
		$this->register_notes();
		soapbox_blyg_publish( 'notes', 'a', $this->fragment( 'One.', array( 'permalink' => home_url( '/notes/a/' ) ) ) );
		add_filter(
			'soapbox_blyg_webmention_limits',
			static function ( $limits ) {
				return array( 'host' => 3, 'resend_seconds' => 0 ) + $limits;
			}
		);
		$receiver = new Receiver(
			$plugin->mentions,
			$plugin->locations,
			static function () {
				return array( '93.184.216.34' );
			}
		);
		$send = static function () use ( $receiver, $plugin, $wpdb ) {
			$status = $receiver->receive( $plugin->registry->get( 'notes' ), 'https://reader.example/x', home_url( '/notes/a/' ) )['status'];
			// It is verified at once, so the next send is a new claim and not a duplicate of a pending one.
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET status = %s', Schema::table( 'mentions' ), Store::VERIFIED ) );
			return $status;
		};
		$this->assertSame( array( 202, 202, 202, 429 ), array( $send(), $send(), $send(), $send() ), 'three claims an hour from one host means three, however few pairs they name' );
	}

	/*
	 * F9 (Medium): two workers on one job, and two workers on the last permit.
	 */

	public function test_f9_a_job_already_being_worked_is_not_worked_twice() {
		$plugin = Plugin::instance();
		update_option( Settings::OPTION, array( 'webmention' => true ) );
		$this->register_notes();
		soapbox_blyg_publish( 'notes', 'a', $this->fragment( 'One.', array( 'permalink' => home_url( '/notes/a/' ) ) ) );
		$resolve = static function () {
			return array( '93.184.216.34' );
		};
		$receiver = new Receiver( $plugin->mentions, $plugin->locations, $resolve );
		$receiver->receive( $plugin->registry->get( 'notes' ), 'https://reader.example/x', home_url( '/notes/a/' ) );
		$id = (int) $plugin->mentions->query()[0]['mention_id'];

		// The scheduled job and the sweep pick the same row. The second starts while the first is
		// waiting for the source to answer.
		$fetches = 0;
		$second  = null;
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$fetches, &$second, $plugin, $resolve, $id ) {
				++$fetches;
				$handle = curl_init();
				do_action_ref_array( 'http_api_curl', array( &$handle, $args, $url ) );
				if ( null === $second ) {
					$second = new Verifier( $plugin->mentions, $plugin->registry, $plugin->ledger, $resolve );
					$second->verify( $id );
				}
				return new \WP_Error( 'http_request_failed', 'no answer' );
			},
			10,
			3
		);
		$first = new Verifier( $plugin->mentions, $plugin->registry, $plugin->ledger, $resolve );
		$first->verify( $id );
		remove_all_filters( 'pre_http_request' );

		$this->assertSame( 1, $fetches, 'one worker, one fetch' );
		$this->assertSame( '1', (string) $plugin->mentions->get( $id )['attempts'], 'and the attempts say what was done' );
	}

	public function test_f9_the_last_outbound_permit_is_given_once() {
		$plugin = Plugin::instance();
		add_filter(
			'soapbox_blyg_webmention_limits',
			static function ( $limits ) {
				return array( 'outbound' => 1 ) + $limits;
			}
		);
		$worker = static function () use ( $plugin ) {
			return new Verifier( $plugin->mentions, $plugin->registry, $plugin->ledger );
		};
		$ask    = static function ( Verifier $verifier ): bool {
			$method = new \ReflectionMethod( Verifier::class, 'may_request' );
			$method->setAccessible( true );
			return (bool) $method->invoke( $verifier, '198.51.100.0/24' );
		};

		// Worker A reads the count; before it writes, worker B reads the same count and writes.
		$granted = array();
		$b_ran   = false;
		$race    = static function ( $value ) use ( &$granted, &$b_ran, $worker, $ask ) {
			if ( ! $b_ran ) {
				$b_ran     = true;
				$granted[] = $ask( $worker() );
			}
			return $value;
		};
		add_filter( 'pre_set_transient_soapbox_blyg_out_' . md5( '198.51.100.0/24' ), $race );
		$granted[] = $ask( $worker() );
		remove_all_filters( 'pre_set_transient_soapbox_blyg_out_' . md5( '198.51.100.0/24' ) );
		if ( ! $b_ran ) {
			$granted[] = $ask( $worker() ); // No window to run B in: it simply comes second.
		}
		$this->assertSame( 1, count( array_filter( $granted ) ), 'a limit of one grants one' );
	}

	/*
	 * F10 (Medium): unticking every post type.
	 */

	public function test_f10_a_settings_screen_with_no_post_type_ticked_means_none() {
		update_option( Settings::OPTION, array( 'post_types' => array( 'post' ) ) );
		$clean = SettingsPage::sanitize( array( '_screen' => '1', 'enabled' => '1' ) );
		$this->assertSame( array(), $clean['post_types'] );

		// A script that sets one thing still changes only that thing.
		$this->assertSame( array( 'post' ), SettingsPage::sanitize( array( 'title' => 'T' ) )['post_types'] );
	}

	/*
	 * F11: nothing is published, and nothing can lock, before the owner has chosen.
	 */

	public function test_f11_a_fresh_site_publishes_nothing_until_it_is_set_up_and_switched_on() {
		delete_option( Settings::OPTION );
		$this->reset_registry();
		$this->assertFalse( Settings::get( 'enabled' ), 'activation leaves publishing off' );
		$this->assertFalse( Settings::get( 'setup' ) );
		$this->assertSame( 'fixed', Settings::get( 'layout' ), 'the layout every reader understands is the one offered first' );
		$this->assertFalse( Plugin::instance()->registry->has( 'posts' ) );

		// A post saved before setup goes nowhere, and nothing is locked.
		$post = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<p>Body.</p>' ) );
		$this->assertSame( 0, Plugin::instance()->ledger->count_items( 'posts' ) );
		$this->assertFalse( Plugin::instance()->registry->is_locked( 'posts' ) );

		// Publishing cannot be switched on with nothing chosen: from a script...
		update_option( Settings::OPTION, array( 'enabled' => true ) );
		$this->assertFalse( Settings::get( 'enabled' ) );
		// ...or from the screen in the same save as the choice.
		update_option( Settings::OPTION, array( '_screen' => '1', 'enabled' => '1', 'mount' => '/blyg/', 'layout' => 'templated' ) );
		$this->assertFalse( Settings::get( 'enabled' ), 'the choice is saved and seen first' );
		$this->assertTrue( Settings::get( 'setup' ) );
		$this->assertSame( 'templated', Settings::get( 'layout' ) );

		// Then it can.
		update_option( Settings::OPTION, array( '_screen' => '1', 'enabled' => '1', 'mount' => '/blyg/', 'layout' => 'templated', 'post_types' => array( 'post' ) ) );
		$this->assertTrue( Settings::get( 'enabled' ) );
		$this->reset_registry();
		$this->assertTrue( Plugin::instance()->registry->has( 'posts' ) );
		$this->assertSame( 0, Plugin::instance()->ledger->count_items( 'posts' ), 'switching on publishes nothing by itself' );
		wp_update_post( array( 'ID' => $post, 'post_content' => '<p>Saved after setup.</p>' ) );
		$this->assertSame( 1, Plugin::instance()->ledger->count_items( 'posts' ) );
	}

	public function test_f11_a_script_may_name_the_address_the_layout_and_the_switch_together() {
		delete_option( Settings::OPTION );
		update_option( Settings::OPTION, array( 'enabled' => true, 'mount' => '/blyg/', 'layout' => 'fixed' ) );
		$this->assertSame( array( true, true, 'fixed' ), array( Settings::get( 'enabled' ), Settings::get( 'setup' ), Settings::get( 'layout' ) ) );

		// But not with an address that is refused, and setup cannot be claimed directly.
		delete_option( Settings::OPTION );
		update_option( Settings::OPTION, array( 'enabled' => true, 'mount' => '/wp-admin/', 'layout' => 'fixed' ) );
		$this->assertFalse( Settings::get( 'enabled' ) );
		update_option( Settings::OPTION, array( 'enabled' => true, 'setup' => true ) );
		$this->assertSame( array( false, false ), array( Settings::get( 'enabled' ), Settings::get( 'setup' ) ) );
	}

	public function test_f11_both_layouts_declare_the_protocol_version_the_specification_gives() {
		// Spec 0.3 section 16.6e calls a templated surface a 0.4 construct and names no version
		// for its manifest to declare, so none is invented.
		$plugin = Plugin::instance();
		$origin = $plugin->registry->get( 'posts' );
		foreach ( array( 'templated', 'fixed' ) as $layout ) {
			$as         = clone $origin;
			$as->layout = $layout;
			$this->assertSame( '0.3', json_decode( $plugin->documents->manifest( $as )[0], true )['blyg'], $layout );
		}
	}

	public function test_a_dry_run_can_be_made_with_publishing_off_once_the_address_is_chosen() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		delete_option( Settings::OPTION );
		$this->reset_registry();
		$post = self::factory()->post->create( array( 'post_status' => 'publish', 'post_name' => 'looked-at-first', 'post_content' => '<p>Looked at before anything is published.</p>' ) );

		// Not before setup: a post's links are resolved against the blyg's address.
		$this->assertSame( 409, $this->admin_rest( 'POST', '/backfill', array( 'dry_run' => true ) )->get_status() );

		// The address and layout are chosen and saved. Publishing is still off.
		update_option( Settings::OPTION, array( '_screen' => '1', 'mount' => '/blyg/', 'layout' => 'templated' ) );
		$this->reset_registry();
		$this->assertFalse( Settings::get( 'enabled' ) );
		$this->assertFalse( Plugin::instance()->registry->has( 'posts' ) );

		$dry = $this->admin_rest( 'POST', '/backfill', array( 'dry_run' => true ) );
		$this->assertSame( 200, $dry->get_status() );
		$row = $dry->get_data()['rows'][0];
		$this->assertSame( array( $post, 'looked-at-first' ), array( $row['post'], $row['slug'] ) );
		$this->assertStringContainsString( 'Looked at before', $row['md'] );
		$this->assertStringContainsString( '<p>Looked at before', $row['html'], 'both forms, as the command line\'s dump has' );

		// And it is only the dry run: nothing can be published, and nothing was.
		$this->assertSame( 409, $this->admin_rest( 'POST', '/backfill' )->get_status() );
		$this->assertSame( 0, Plugin::instance()->ledger->count_items( 'posts' ) );
		$this->assertFalse( Plugin::instance()->registry->is_locked( 'posts' ) );
	}
}
