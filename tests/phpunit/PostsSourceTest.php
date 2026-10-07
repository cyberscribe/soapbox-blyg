<?php
/**
 * The posts source: what is public, what a post looks like, and when it is an event.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Tests;

use SoapboxBlyg\Ledger\Verifier;
use SoapboxBlyg\Origins\PostTypeSource;
use SoapboxBlyg\Plugin;
use SoapboxBlyg\Settings;

class PostsSourceTest extends TestCase {

	private function status( int $post_id ) {
		return soapbox_blyg_get_status( 'posts', 'post:' . $post_id );
	}

	private function document( int $post_id ): array {
		$plugin = Plugin::instance();
		$status = $this->status( $post_id );
		$doc    = $plugin->documents->item( $plugin->registry->get( 'posts' ), $status['id'] );
		return json_decode( $doc[0], true );
	}

	public function test_a_draft_is_invisible_and_publishing_creates_a_thread() {
		$author = self::factory()->user->create( array( 'display_name' => 'Ada Writer' ) );
		$post   = self::factory()->post->create( array( 'post_status' => 'draft', 'post_title' => 'Hello', 'post_content' => '<p>First.</p>', 'post_author' => $author ) );
		$this->assertNull( $this->status( $post ) );

		wp_publish_post( $post );
		$status = $this->status( $post );
		$this->assertSame( 1, $status['version'] );
		$this->assertSame( 'thread', $status['kind'] );

		$doc = $this->document( $post );
		$this->assertSame( "# Hello\n\nFirst.\n", $doc['content_md'] );
		$this->assertSame( "<h1>Hello</h1>\n<p>First.</p>", $doc['content_html'] );
		$this->assertSame( get_permalink( $post ), $doc['page'] );
		$this->assertSame( array( 'name' => 'Ada Writer', 'url' => home_url( '/' ) ), $doc['author'] );
		$this->assertSame( array(), $doc['transclusions'] );
		$this->assertSame( PostTypeSource::gmt_iso( get_post( $post )->post_date_gmt, get_post( $post )->post_date ), $doc['created'] );
	}

	public function test_saves_that_change_nothing_visible_are_not_events() {
		$post = self::factory()->post->create( array( 'post_status' => 'publish', 'post_title' => 'Hello', 'post_content' => '<p>First.</p>' ) );
		wp_update_post( array( 'ID' => $post, 'post_title' => 'Hello' ) );
		update_post_meta( $post, 'some_meta', 'x' );
		wp_set_post_tags( $post, 'a-tag' );
		wp_update_post( array( 'ID' => $post, 'post_excerpt' => 'An excerpt.' ) );
		$this->assertSame( 1, $this->status( $post )['version'] );

		wp_update_post( array( 'ID' => $post, 'post_content' => '<p>Second.</p>' ) );
		$this->assertSame( 2, $this->status( $post )['version'] );
		wp_update_post( array( 'ID' => $post, 'post_title' => 'Hello again' ) );
		$this->assertSame( 3, $this->status( $post )['version'], 'the title is content: items are titleless' );
	}

	public function test_trash_password_and_delete_withdraw_and_republishing_returns() {
		$post = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<p>Body.</p>' ) );
		$id   = $this->status( $post )['id'];

		wp_trash_post( $post );
		$this->assertSame( array( 2, 'withdrawn' ), array( $this->status( $post )['version'], $this->status( $post )['kind'] ) );

		wp_untrash_post( $post );
		$this->assertSame( 2, $this->status( $post )['version'], 'untrashing to a draft is not an event' );

		wp_publish_post( $post );
		$this->assertSame( array( 3, 'thread', $id ), array( $this->status( $post )['version'], $this->status( $post )['kind'], $this->status( $post )['id'] ) );

		wp_update_post( array( 'ID' => $post, 'post_password' => 'secret' ) );
		$this->assertSame( 'withdrawn', $this->status( $post )['kind'] );
		wp_update_post( array( 'ID' => $post, 'post_password' => '' ) );
		$this->assertSame( array( 5, 'thread' ), array( $this->status( $post )['version'], $this->status( $post )['kind'] ) );

		wp_delete_post( $post, true );
		$status = $this->status( $post );
		$this->assertSame( array( 6, 'withdrawn', $id ), array( $status['version'], $status['kind'], $status['id'] ), 'the item outlives its post' );
	}

	public function test_only_post_types_with_blyg_support_publish() {
		$page = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
		$this->assertNull( $this->status( $page ) );

		register_post_type( 'book', array( 'public' => true, 'supports' => array( 'title', 'editor', 'blyg' ) ) );
		register_post_type( 'hidden', array( 'public' => false, 'supports' => array( 'title', 'editor', 'blyg' ) ) );
		$book   = self::factory()->post->create( array( 'post_type' => 'book', 'post_status' => 'publish' ) );
		$hidden = self::factory()->post->create( array( 'post_type' => 'hidden', 'post_status' => 'publish' ) );
		$this->assertSame( 'thread', $this->status( $book )['kind'], 'any plugin can opt a post type in' );
		$this->assertNull( $this->status( $hidden ), 'a post type with no pages of its own is not public' );
		unregister_post_type( 'book' );
		unregister_post_type( 'hidden' );
	}

	public function test_post_types_come_from_settings() {
		update_option( Settings::OPTION, array( 'post_types' => array( 'page' ) ) );
		remove_post_type_support( 'post', 'blyg' );
		Plugin::instance()->posts->add_support();
		$page = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
		$post = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->assertNotNull( $this->status( $page ) );
		$this->assertNull( $this->status( $post ) );
		remove_post_type_support( 'page', 'blyg' );
		add_post_type_support( 'post', 'blyg' );
	}

	public function test_a_post_whose_type_was_unticked_is_still_withdrawn_when_it_is_unpublished() {
		$trashed = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<p>One.</p>' ) );
		$private = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<p>Two.</p>' ) );
		$deleted = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<p>Three.</p>' ) );
		$kept    = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<p>Four.</p>' ) );

		// The site owner unticks Posts.
		update_option( Settings::OPTION, array( 'post_types' => array( 'page' ) ) );
		remove_post_type_support( 'post', 'blyg' );
		$this->assertFalse( PostTypeSource::supports( 'post' ) );

		// What is published stays, and an edit to it is no longer an event.
		wp_update_post( array( 'ID' => $kept, 'post_content' => '<p>Four, edited.</p>' ) );
		$this->assertSame( array( 1, 'thread' ), array( $this->status( $kept )['version'], $this->status( $kept )['kind'] ) );
		Plugin::instance()->posts->withdraw_missed();
		$this->assertSame( 'thread', $this->status( $kept )['kind'], 'a reconcile withdraws nothing that is still public' );

		// But taking a post down takes its item down, whatever the settings now say.
		wp_trash_post( $trashed );
		wp_update_post( array( 'ID' => $private, 'post_status' => 'private' ) );
		wp_delete_post( $deleted, true );
		foreach ( array( 'trashed' => $trashed, 'made private' => $private, 'deleted' => $deleted ) as $how => $post ) {
			$this->assertSame( array( 2, 'withdrawn' ), array( $this->status( $post )['version'], $this->status( $post )['kind'] ), $how );
		}

		// A post of that type that never was on the blyg is not put in the ledger by being trashed.
		$never = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		wp_trash_post( $never );
		$this->assertNull( $this->status( $never ) );

		add_post_type_support( 'post', 'blyg' );
	}

	public function test_what_was_unpublished_while_publishing_was_off_is_withdrawn_when_it_comes_back_on() {
		$private = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<p>One.</p>' ) );
		$trashed = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<p>Two.</p>' ) );
		$deleted = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<p>Three.</p>' ) );
		$kept    = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<p>Four.</p>' ) );
		delete_option( PostTypeSource::RECONCILE_OPTION );

		// Publishing is switched off: no origin is registered, so nothing reaches the ledger.
		update_option( Settings::OPTION, array( 'enabled' => false ) );
		$this->reset_registry();
		$this->assertFalse( Plugin::instance()->registry->has( 'posts' ) );
		wp_update_post( array( 'ID' => $private, 'post_status' => 'private' ) );
		wp_trash_post( $trashed );
		wp_delete_post( $deleted, true );
		$this->assertFalse( get_option( PostTypeSource::RECONCILE_OPTION ), 'switching off asks for nothing' );

		// Switched back on. The next request reconciles before it serves anything.
		update_option( Settings::OPTION, array( 'enabled' => true ) );
		$this->assertNotFalse( get_option( PostTypeSource::RECONCILE_OPTION ), 'switching on asks for a reconcile' );
		$this->reset_registry();
		foreach ( array( $private, $trashed, $deleted ) as $post ) {
			$this->assertSame( 'thread', $this->status( $post )['kind'], 'still live in the ledger until the reconcile runs' );
		}
		Plugin::instance()->posts->maybe_reconcile();

		foreach ( array( 'made private' => $private, 'trashed' => $trashed, 'deleted' => $deleted ) as $how => $post ) {
			$this->assertSame( array( 2, 'withdrawn' ), array( $this->status( $post )['version'], $this->status( $post )['kind'] ), $how );
		}
		$this->assertSame( array( 1, 'thread' ), array( $this->status( $kept )['version'], $this->status( $kept )['kind'] ) );
		$this->assertFalse( get_option( PostTypeSource::RECONCILE_OPTION ), 'and it is done' );
	}

	public function test_a_reconcile_is_asked_for_when_the_post_types_change_and_on_activation() {
		delete_option( PostTypeSource::RECONCILE_OPTION );
		update_option( Settings::OPTION, array( 'post_types' => array( 'post', 'page' ) ) );
		$this->assertNotFalse( get_option( PostTypeSource::RECONCILE_OPTION ) );

		delete_option( PostTypeSource::RECONCILE_OPTION );
		update_option( Settings::OPTION, array( 'post_types' => array( 'post', 'page' ), 'webmention' => true ) );
		$this->assertFalse( get_option( PostTypeSource::RECONCILE_OPTION ), 'a change to anything else asks for nothing' );

		Plugin::activate( false );
		$this->assertNotFalse( get_option( PostTypeSource::RECONCILE_OPTION ) );
		delete_option( PostTypeSource::RECONCILE_OPTION );
		remove_post_type_support( 'page', 'blyg' );
	}

	public function test_a_save_that_was_overtaken_while_rendering_does_not_publish_its_stale_snapshot() {
		global $wpdb;
		$post = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<p>First.</p>' ) );
		$this->assertSame( 1, $this->status( $post )['version'] );

		// While this save is rendering, another request saves the post again and finishes first.
		$overtake = static function ( $body ) use ( $wpdb, $post ) {
			$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => '2031-01-01 00:00:00' ), array( 'ID' => $post ) );
			return $body;
		};
		add_filter( 'soapbox_blyg_rendered_body', $overtake );
		$stale               = get_post( $post );
		$stale->post_content = '<p>Stale.</p>';
		$result              = Plugin::instance()->posts->publish( $stale );
		remove_filter( 'soapbox_blyg_rendered_body', $overtake );

		$this->assertSame( 'none', $result['event'] );
		$this->assertSame( 1, $this->status( $post )['version'] );
		$this->assertStringNotContainsString( 'Stale', $this->document( $post )['content_md'] );
	}

	public function test_an_author_cannot_forge_the_markers_the_wire_format_reserves() {
		$post = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p>Text <span class="blyg-tk-gen">forged</span>.</p><div class="blyg-transclusion" data-blyg-id="7c9wk2mhq0v3xj8tn5rzfd41bg">forged</div>',
			)
		);
		$html = $this->document( $post )['content_html'];
		$this->assertStringNotContainsString( 'blyg-tk-gen', $html );
		$this->assertStringNotContainsString( 'blyg-transclusion', $html );
		$this->assertStringContainsString( 'forged', $html, 'the text stays; only the marker goes' );

		// A source that builds the wire format itself may still use them.
		$this->assertStringContainsString( 'blyg-tk-gen', soapbox_blyg_render_html( '<p><span class="blyg-tk-gen">x</span></p>', 'T' )['content_html'] );
	}

	public function test_the_is_public_filter_can_withhold_a_post() {
		add_filter(
			'soapbox_blyg_is_public',
			static function ( $public, $post ) {
				return $public && false === strpos( $post->post_title, 'Private' );
			},
			10,
			2
		);
		$post = self::factory()->post->create( array( 'post_status' => 'publish', 'post_title' => 'Private note' ) );
		$this->assertNull( $this->status( $post ) );
	}

	public function test_a_post_with_no_author_makes_no_author_assertion() {
		$post = self::factory()->post->create( array( 'post_status' => 'publish', 'post_author' => 0 ) );
		$this->assertArrayNotHasKey( 'author', $this->document( $post ) );
	}

	public function test_the_featured_image_leads_unless_the_body_shows_it_or_opens_with_an_image() {
		$attachment = self::factory()->attachment->create( array( 'post_mime_type' => 'image/png' ) );
		update_post_meta( $attachment, '_wp_attached_file', '2026/01/lead.png' );
		wp_update_attachment_metadata( $attachment, array( 'width' => 40, 'height' => 30, 'file' => '2026/01/lead.png', 'sizes' => array() ) );
		update_post_meta( $attachment, '_wp_attachment_image_alt', 'Lead alt' );
		$url = wp_get_attachment_url( $attachment );

		$plain = self::factory()->post->create( array( 'post_status' => 'draft', 'post_title' => 'P', 'post_content' => '<p>Words.</p>' ) );
		set_post_thumbnail( $plain, $attachment );
		wp_publish_post( $plain );
		$doc = $this->document( $plain );
		$this->assertStringStartsWith( "<h1>P</h1>\n<figure><img src=\"{$url}\" alt=\"Lead alt\" width=\"40\" height=\"30\"></figure>\n<p>Words.</p>", $doc['content_html'] );
		$this->assertSame( $url, $doc['media'][0]['url'] );

		// Removing it is a visible change, so it is an event.
		delete_post_thumbnail( $plain );
		wp_update_post( array( 'ID' => $plain ) );
		$this->assertSame( 2, $this->status( $plain )['version'] );

		$sized = str_replace( 'lead.png', 'lead-300x200.png', $url );
		$shows = self::factory()->post->create( array( 'post_status' => 'draft', 'post_content' => "<p>Words.</p><p><img src=\"{$sized}\" alt=\"\"></p>" ) );
		set_post_thumbnail( $shows, $attachment );
		wp_publish_post( $shows );
		$this->assertStringNotContainsString( '<figure>', $this->document( $shows )['content_html'], 'another size of the same file is already there' );

		$opens = self::factory()->post->create( array( 'post_status' => 'draft', 'post_content' => '<p><a href="/x/"><img src="/other.jpg" alt=""></a> Words.</p>' ) );
		set_post_thumbnail( $opens, $attachment );
		wp_publish_post( $opens );
		$this->assertStringNotContainsString( 'lead.png', $this->document( $opens )['content_html'], 'the body already opens with an image' );

		update_option( Settings::OPTION, array( 'featured_image' => false ) );
		$off = self::factory()->post->create( array( 'post_status' => 'draft', 'post_content' => '<p>Words.</p>' ) );
		set_post_thumbnail( $off, $attachment );
		wp_publish_post( $off );
		$this->assertStringNotContainsString( '<figure>', $this->document( $off )['content_html'] );
	}

	public function test_embeds_are_links_and_nothing_is_fetched() {
		$fetched = 0;
		add_filter(
			'pre_http_request',
			static function ( $pre ) use ( &$fetched ) {
				++$fetched;
				return new \WP_Error( 'blocked', 'no network in tests' );
			}
		);
		$post = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => "Intro.\n\nhttps://www.youtube.com/watch?v=dQw4w9WgXcQ\n\n[embed]https://vimeo.com/1[/embed]",
			)
		);
		$doc = $this->document( $post );
		$this->assertStringContainsString( '<a href="https://www.youtube.com/watch?v=dQw4w9WgXcQ">https://www.youtube.com/watch?v=dQw4w9WgXcQ</a>', $doc['content_html'] );
		$this->assertStringContainsString( '<a href="https://vimeo.com/1">https://vimeo.com/1</a>', $doc['content_html'] );
		$this->assertSame( 0, $fetched );
	}

	public function test_rendering_does_not_depend_on_who_saves_or_where() {
		$post   = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => "<p>Body with a <a href=\"relative/\">link</a>.</p>\n\n[caption id=\"x\" width=\"100\"]<img src=\"/a.png\" alt=\"A\" /> Caption[/caption]" ) );
		$source = Plugin::instance()->posts;
		$first  = $source->snapshot( get_post( $post ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'edit-post' );
		$GLOBALS['post'] = get_post( self::factory()->post->create() );
		$second          = $source->snapshot( get_post( $post ) );

		$this->assertSame( $first, $second );
		$this->assertNotSame( $post, $GLOBALS['post']->ID, 'the global post is put back' );
		$this->assertNotSame( 0, get_current_user_id(), 'and so is the user' );
		set_current_screen( 'front' );
	}

	public function test_a_literal_item_reference_in_a_post_is_escaped() {
		$id   = '7c9wk2mhq0v3xj8tn5rzfd41bg';
		$post = self::factory()->post->create( array( 'post_status' => 'publish', 'post_title' => 'Syntax', 'post_content' => "<p>The syntax is [[{$id}]].</p>" ) );
		$doc  = $this->document( $post );
		$this->assertStringNotContainsString( "[[{$id}]]", $doc['content_md'], 'the sequence the protocol reads as a link is not in the Markdown' );
		$this->assertStringContainsString( $id, $doc['content_md'] );
		$this->assertStringContainsString( "[[{$id}]]", $doc['content_html'] );
	}

	public function test_backfill_gives_v1_to_posts_published_before_the_plugin_and_is_idempotent() {
		$source = Plugin::instance()->posts;
		remove_action( 'wp_after_insert_post', array( $source, 'on_save' ), 10 );
		$old = self::factory()->post->create( array( 'post_status' => 'publish', 'post_date' => '2015-06-01 12:00:00', 'post_date_gmt' => '2015-06-01 12:00:00' ) );
		self::factory()->post->create( array( 'post_status' => 'draft' ) );
		self::factory()->post->create( array( 'post_status' => 'publish', 'post_password' => 'x' ) );
		add_action( 'wp_after_insert_post', array( $source, 'on_save' ), 10, 2 );

		$this->assertSame( array( $old ), $source->unpublished_ids() );
		$result = $source->backfill_one( get_post( $old ) );
		$this->assertSame( 'created', $result['event'] );

		$doc = $this->document( $old );
		$this->assertSame( '2015-06-01T12:00:00Z', $doc['created'] );
		$this->assertSame( 'imported from WordPress', $doc['changelog'][0]['note'] );
		$this->assertSame( array(), $source->unpublished_ids() );
		$this->assertSame( 'none', $source->backfill_one( get_post( $old ) )['event'] );
	}

	public function test_a_failed_publish_on_the_web_is_queued_for_repair_not_thrown() {
		$breaks = static function ( $snapshot ) {
			$snapshot['kind'] = 'nonsense';
			return $snapshot;
		};
		add_filter( 'soapbox_blyg_snapshot', $breaks );
		$post = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->assertNull( $this->status( $post ) );
		$owed = Plugin::instance()->posts->owed();
		$this->assertCount( 1, $owed );
		$this->assertSame( array( $post, 1 ), array( $owed[0]['post'], $owed[0]['tries'] ) );
		$this->assertStringContainsString( 'kind', $owed[0]['message'] );
		$this->assertNotFalse( wp_next_scheduled( PostTypeSource::REPAIR_HOOK ), 'a retry is scheduled' );

		// Not due yet: asked at once, nothing is tried.
		Plugin::instance()->posts->repair();
		$this->assertSame( 1, Plugin::instance()->posts->owed()[0]['tries'] );

		// Still broken: the retry fails, is counted, and the next one is further off.
		$this->repairs_are_due();
		Plugin::instance()->posts->repair();
		$this->assertGreaterThan( gmdate( 'Y-m-d\\TH:i:s\\Z', time() + 90 ), Plugin::instance()->posts->owed()[0]['next_try'], 'two minutes, not one' );
		$this->assertSame( 2, Plugin::instance()->posts->owed()[0]['tries'] );

		// Mended: the retry publishes the post, with no save, and the queue is empty.
		remove_filter( 'soapbox_blyg_snapshot', $breaks );
		$this->repairs_are_due();
		Plugin::instance()->posts->repair();
		$this->assertSame( 'thread', $this->status( $post )['kind'] );
		$this->assertSame( array(), Plugin::instance()->posts->owed() );
		$this->assertFalse( get_option( PostTypeSource::REPAIRS_OPTION ) );
		wp_unschedule_hook( PostTypeSource::REPAIR_HOOK );
	}

	public function test_a_queued_failure_is_cleared_when_that_post_publishes() {
		$breaks = static function () {
			throw new \RuntimeException( 'it broke' );
		};
		$post  = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<p>One.</p>' ) );
		$other = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<p>Other.</p>' ) );
		add_filter( 'soapbox_blyg_snapshot', $breaks );
		wp_update_post( array( 'ID' => $post, 'post_content' => '<p>Two.</p>' ) );
		remove_filter( 'soapbox_blyg_snapshot', $breaks );
		// Keep the request-time repair out of it: this is about what a save does.
		remove_action( 'wp_loaded', array( Plugin::instance()->posts, 'maybe_reconcile' ), 5 );
		$this->assertCount( 1, Plugin::instance()->posts->owed() );

		wp_update_post( array( 'ID' => $other, 'post_content' => '<p>Other, edited.</p>' ) );
		$this->assertCount( 1, Plugin::instance()->posts->owed(), 'another post publishing says nothing about this one' );

		wp_update_post( array( 'ID' => $post, 'post_content' => '<p>Three.</p>' ) );
		$this->assertSame( array(), Plugin::instance()->posts->owed() );
		add_action( 'wp_loaded', array( Plugin::instance()->posts, 'maybe_reconcile' ), 5 );
		wp_unschedule_hook( PostTypeSource::REPAIR_HOOK );
	}

	public function test_verify_with_render_drift_finds_gaps_in_both_directions() {
		global $wpdb;
		$source = Plugin::instance()->posts;
		$post   = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<p>Body.</p>' ) );

		$verifier = new Verifier();
		$this->assertTrue( $verifier->run( null, $source ) );

		// A public post the ledger never heard about.
		remove_action( 'wp_after_insert_post', array( $source, 'on_save' ), 10 );
		$missed = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		// A live item whose post stopped being public behind the ledger's back.
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'draft' ), array( 'ID' => $post ) );
		clean_post_cache( $post );
		add_action( 'wp_after_insert_post', array( $source, 'on_save' ), 10, 2 );

		$verifier = new Verifier();
		$this->assertFalse( $verifier->run( null, $source ) );
		$problems = implode( "\n", $verifier->problems );
		$this->assertStringContainsString( "post {$missed} is public but has no blyg item", $problems );
		$this->assertStringContainsString( "(post {$post}) is live but the post is not public", $problems );
		$this->assertFalse( get_option( Verifier::RESULT_OPTION )['passed'] );
	}

	public function test_render_drift_warns_when_a_post_would_change_on_resave() {
		$source = Plugin::instance()->posts;
		self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<p>Body.</p>' ) );
		add_filter(
			'soapbox_blyg_rendered_body',
			static function ( $html ) {
				return $html . '<p>Added by a plugin update.</p>';
			}
		);
		$verifier = new Verifier();
		$this->assertTrue( $verifier->run( null, $source ), 'drift warns; it does not fail' );
		$this->assertCount( 1, $verifier->warnings );
		$this->assertStringContainsString( 'renders differently now', $verifier->warnings[0] );
	}
}
