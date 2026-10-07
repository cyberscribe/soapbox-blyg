<?php
/**
 * B12: the documents must be safe behind a host that adds caching headers of its own.
 *
 * Seen on a stock Cloudways server with Breeze's "Browser Cache" on: every response gets
 * `Cache-Control: s-maxage=2592000` appended, and mod_expires adds a `max-age` by content type to
 * any response that has no `Expires`. The plugin cannot stop a server adding headers. It can make
 * sure its own come first and leave mod_expires nothing to add.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Tests;

use SoapboxBlyg\Admin\SettingsPage;
use SoapboxBlyg\Admin\SiteHealth;
use SoapboxBlyg\Plugin;
use SoapboxBlyg\Settings;
use SoapboxBlyg\Surface\Caching;
use SoapboxBlyg\Surface\Http;

class CachingTest extends TestCase {

	private function caching( array $response ): array {
		$this->assertTrue( method_exists( Http::class, 'caching' ), 'Http::caching() gives the caching headers of a response' );
		return Http::caching( $response, (int) $response['status'] );
	}

	public function test_b12_a_live_document_names_its_own_shared_cache_lifetime_first() {
		$headers = $this->caching( Http::document( Http::JSON, '{}', 0 ) );
		$this->assertSame( 'public, max-age=300, s-maxage=300', $headers['Cache-Control'] );

		// With the host's header appended, as Apache's `Header append` leaves it: ours is first.
		$as_sent = $headers['Cache-Control'] . ', s-maxage=2592000';
		$this->assertSame( 300, SiteHealth::shared_lifetime( $as_sent )['seconds'] );
	}

	public function test_b12_a_pinned_document_and_a_not_found_do_the_same() {
		$pin = $this->caching( Http::document( Http::JSON, '{}', 1, array( 'immutable' => true ) ) );
		$this->assertSame( 'public, max-age=31536000, s-maxage=31536000, immutable', $pin['Cache-Control'] );
		$this->assertSame( 'public, max-age=300, s-maxage=300', $this->caching( Http::not_found() )['Cache-Control'] );
	}

	public function test_b12_every_response_carries_an_expires_of_its_own_so_that_the_server_adds_none() {
		$now  = time();
		$live = strtotime( $this->caching( Http::document( Http::JSON, '{}', 0 ) )['Expires'] );
		$this->assertGreaterThanOrEqual( $now + 299, $live );
		$this->assertLessThanOrEqual( $now + 302, $live );

		$pin = strtotime( $this->caching( Http::document( Http::JSON, '{}', 1, array( 'immutable' => true ) ) )['Expires'] );
		$this->assertGreaterThanOrEqual( $now + 31535999, $pin );

		// Not to be stored at all: no-store, and a date that has already passed.
		$held = $this->caching( Http::document( Http::JSON, '{}', 0, array( 'cache' => false ) ) );
		$this->assertMatchesRegularExpression( '/(^|, )no-store(,|$)/', $held['Cache-Control'] );
		$this->assertStringNotContainsString( 'public', $held['Cache-Control'] );
		// Core's set is filterable; whatever it is made into, the response still says no-store.
		add_filter( 'nocache_headers', '__return_empty_array' );
		$this->assertSame( 'no-store', $this->caching( Http::document( Http::JSON, '{}', 0, array( 'cache' => false ) ) )['Cache-Control'] );
		remove_filter( 'nocache_headers', '__return_empty_array' );
		$this->assertLessThan( $now, strtotime( $held['Expires'] ) );
		$this->assertMatchesRegularExpression( '/^[A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} GMT$/', $held['Expires'] );
	}

	public function test_b12_a_filtered_value_sets_the_expires_to_match() {
		add_filter(
			'soapbox_blyg_cache_control',
			static function () {
				return 'public, max-age=60, s-maxage=60';
			}
		);
		$headers = $this->caching( Http::document( Http::JSON, '{}', 0 ) );
		$this->assertSame( 'public, max-age=60, s-maxage=60', $headers['Cache-Control'] );
		$this->assertLessThanOrEqual( time() + 62, strtotime( $headers['Expires'] ) );
		remove_all_filters( 'soapbox_blyg_cache_control' );
	}

	/**
	 * RFC 9111 4.2.1: when a directive is repeated, a cache uses the first or treats the response
	 * as stale. So the first is what counts, not the longest.
	 *
	 * @dataProvider cache_controls
	 */
	public function test_b12_the_first_lifetime_in_a_cache_control_is_the_one_a_cache_uses( $header, int $seconds, bool $within_the_lifetime ) {
		$read = SiteHealth::shared_lifetime( $header );
		// The second column is what proves "first": where two lifetimes are given, it is the first of them.
		$this->assertSame( $seconds, $read['seconds'], wp_json_encode( $header ) );
		$this->assertSame( $within_the_lifetime, $read['safe'] ?? null, wp_json_encode( $header ) );
	}

	public function cache_controls(): array {
		return array(
			'ours alone'                                   => array( 'public, max-age=300, s-maxage=300', 300, true ),
			'ours, then Breeze appended (rc4 on Breeze)'   => array( 'public, max-age=300, s-maxage=300, s-maxage=2592000', 300, true ),
			'the same as two header lines'                 => array( array( 'public, max-age=300, s-maxage=300', 's-maxage=2592000' ), 300, true ),
			'a pin, then Breeze'                           => array( 'public, max-age=31536000, s-maxage=31536000, immutable, s-maxage=2592000', 31536000, false ),
			'rc3 on Breeze: the host\'s is the only one'   => array( 'public, max-age=300, max-age=0, s-maxage=2592000', 2592000, false ),
			'rc3 feed on Breeze'                           => array( array( 'public, max-age=300', 'max-age=3600, s-maxage=2592000' ), 2592000, false ),
			'a filter dropped s-maxage; the host added one' => array( 'public, max-age=60, s-maxage=2592000', 2592000, false ),
			'the host\'s came first'                       => array( array( 's-maxage=2592000', 'public, max-age=300, s-maxage=300' ), 2592000, false ),
			'no s-maxage anywhere: max-age governs'        => array( 'public, max-age=300', 300, true ),
			'no s-maxage, and a long max-age first'        => array( 'max-age=3600, max-age=300', 3600, false ),
			'in any case, with spaces'                     => array( 'S-MaxAge = 900', 900, false ),
			's-maxage of nothing, and a long max-age'      => array( 'max-age=86400, s-maxage=0', 0, true ),
			// C10: what is inside a quoted string is not a directive.
			'a lifetime inside a quoted string'            => array( 'public, ext="x, s-maxage=1", s-maxage=99999', 99999, false ),
			'a quoted number is still a number'            => array( 'public, max-age="300", s-maxage="300"', 300, true ),
			'a quote that is never closed'                 => array( 'public, max-age=300, ext="x, s-maxage=1', 300, true ),
			'no-store'                                     => array( 'no-store', 0, true ),
			'nothing'                                      => array( '', 0, true ),
		);
	}

	/*
	 * B13: how long a live document may be cached is a setting, and every number in caching
	 * comes from one place.
	 */

	private function lifetime( $stored ): void {
		update_option( Settings::OPTION, array( 'cache_lifetime' => $stored ) + Settings::all() );
	}

	public function test_b13_the_lifetimes_offered_and_the_default() {
		$this->assertSame( array( MINUTE_IN_SECONDS, 5 * MINUTE_IN_SECONDS, 15 * MINUTE_IN_SECONDS, HOUR_IN_SECONDS ), Caching::LIFETIMES );
		$this->assertSame( 5 * MINUTE_IN_SECONDS, Caching::DEFAULT_LIFETIME );
		$this->assertSame( YEAR_IN_SECONDS, Caching::PIN_LIFETIME );
		$this->assertSame( Caching::DEFAULT_LIFETIME, Caching::lifetime(), 'a site that has saved nothing' );
		$this->assertSame( Caching::DEFAULT_LIFETIME, Settings::defaults()['cache_lifetime'] );
	}

	public function test_b13_every_live_document_and_a_not_found_carry_the_lifetime_chosen() {
		$this->register_notes();
		$a      = soapbox_blyg_publish( 'notes', 'a', $this->fragment( 'One.' ), array( 'at' => '2026-03-01T10:00:00Z' ) );
		$plugin = Plugin::instance();
		$notes  = $plugin->registry->get( 'notes' );
		foreach ( Caching::LIFETIMES as $seconds ) {
			$this->lifetime( $seconds );
			foreach ( array( 'blyg.json', 'feed.xml', 'items/index.json', "items/{$a['id']}.json", 'items/0000000000000000000000000a.json' ) as $path ) {
				$response = $plugin->router->respond( $notes, $path );
				$headers  = $this->caching( $response );
				$this->assertSame( "public, max-age={$seconds}, s-maxage={$seconds}", $headers['Cache-Control'], "{$path} at {$seconds}" );
				$this->assertEqualsWithDelta( time() + $seconds, strtotime( $headers['Expires'] ), 2, "{$path} at {$seconds}" );
			}
			$this->assertSame( 404, $response['status'], 'the last of those is a document that does not exist' );
		}
	}

	public function test_b13_pins_and_responses_that_must_not_be_stored_do_not_follow_the_setting() {
		$this->lifetime( HOUR_IN_SECONDS );
		$year = YEAR_IN_SECONDS;
		$this->assertSame( "public, max-age={$year}, s-maxage={$year}, immutable", $this->caching( Http::document( Http::JSON, '{}', 1, array( 'immutable' => true ) ) )['Cache-Control'] );
		$held = $this->caching( Http::document( Http::JSON, '{}', 0, array( 'cache' => false ) ) );
		$this->assertMatchesRegularExpression( '/(^|, )no-store(,|$)/', $held['Cache-Control'] );
		$this->assertStringNotContainsString( (string) HOUR_IN_SECONDS, $held['Cache-Control'] );
		$this->assertLessThan( time(), strtotime( $held['Expires'] ) );
	}

	public function test_b13_a_stored_value_that_is_not_one_of_those_offered_means_the_default() {
		foreach ( array( 0, -60, 1, 301, 30 * DAY_IN_SECONDS, 'forever', '', null, array( 60 ), 60.5 ) as $stored ) {
			$this->lifetime( $stored );
			$this->assertSame( Caching::DEFAULT_LIFETIME, Caching::lifetime(), wp_json_encode( $stored ) );
		}
		// As a form sends it.
		$this->lifetime( (string) HOUR_IN_SECONDS );
		$this->assertSame( HOUR_IN_SECONDS, Caching::lifetime() );
	}

	public function test_b13_saving_the_setting_accepts_only_what_is_offered() {
		$this->assertSame( 15 * MINUTE_IN_SECONDS, SettingsPage::sanitize( array( 'cache_lifetime' => (string) ( 15 * MINUTE_IN_SECONDS ) ) )['cache_lifetime'] );
		// Anything else leaves the setting as it was, and says so (N7).
		$this->lifetime( HOUR_IN_SECONDS );
		foreach ( array( '0', '2592000', 'abc', '61' ) as $sent ) {
			global $wp_settings_errors;
			$wp_settings_errors = array();
			$this->assertSame( HOUR_IN_SECONDS, SettingsPage::sanitize( array( 'cache_lifetime' => $sent ) )['cache_lifetime'], $sent );
			$this->assertContains( 'cache_lifetime', wp_list_pluck( get_settings_errors( Settings::OPTION ), 'code' ), $sent );
		}
		// Left out by a script that sets something else: unchanged.
		$this->assertSame( HOUR_IN_SECONDS, SettingsPage::sanitize( array( 'title' => 'T' ) )['cache_lifetime'] );
	}

	public function test_b13_the_lifetime_can_be_filtered_and_is_never_less_than_a_second() {
		$given = 120;
		$set   = static function () use ( &$given ) {
			return $given;
		};
		add_filter( 'soapbox_blyg_cache_lifetime', $set );
		$this->assertSame( 120, Caching::lifetime() );
		$this->assertSame( 'public, max-age=120, s-maxage=120', $this->caching( Http::not_found() )['Cache-Control'] );
		foreach ( array( 0, -5, 'never', null ) as $given ) {
			$this->assertSame( 1, Caching::lifetime(), wp_json_encode( $given ) );
		}
		remove_filter( 'soapbox_blyg_cache_lifetime', $set );
	}

	/** Answers Site Health's requests for the manifest and the feed, in turn, with these headers. */
	private function site_health_sees( array $first, ?array $second = null ): array {
		$this->register_notes();
		$calls  = 0;
		$answer = static function () use ( &$calls, $first, $second ) {
			// Four requests: each of the two documents is asked for twice.
			$headers = ( 1 === ++$calls % 2 || null === $second ) ? $first : $second;
			return array( 'response' => array( 'code' => 200, 'message' => '' ), 'body' => '', 'headers' => $headers, 'cookies' => array() );
		};
		add_filter( 'pre_http_request', $answer );
		$result = SiteHealth::test_edge_cache();
		remove_filter( 'pre_http_request', $answer );
		return $result;
	}

	public function test_b13_site_health_measures_against_the_lifetime_chosen() {
		$hour = HOUR_IN_SECONDS;

		// An hour was chosen: an hour at the edge, and a copy forty minutes old, are the plan.
		$this->lifetime( $hour );
		$ours = array( 'cache-control' => "public, max-age={$hour}, s-maxage={$hour}, s-maxage=2592000" );
		$this->assertSame( 'good', $this->site_health_sees( $ours, $ours + array( 'age' => (string) ( 40 * MINUTE_IN_SECONDS ) ) )['status'] );
		$this->assertSame( 'recommended', $this->site_health_sees( $ours, $ours + array( 'age' => (string) ( $hour + 1 ) ) )['status'] );

		// A minute was chosen: five minutes at the edge is too long, and so is a copy two minutes old.
		$this->lifetime( MINUTE_IN_SECONDS );
		$this->assertSame( 'recommended', $this->site_health_sees( array( 'cache-control' => 'public, max-age=300, s-maxage=300' ) )['status'] );
		$minute = array( 'cache-control' => 'public, max-age=60, s-maxage=60' );
		$this->assertSame( 'recommended', $this->site_health_sees( $minute, $minute + array( 'age' => '120' ) )['status'] );
		$this->assertSame( 'good', $this->site_health_sees( $minute, $minute + array( 'age' => '59' ) )['status'] );
	}

	public function test_b13_site_health_says_how_long_in_words_and_the_words_follow_the_setting() {
		foreach ( array( MINUTE_IN_SECONDS, 15 * MINUTE_IN_SECONDS, HOUR_IN_SECONDS ) as $seconds ) {
			$this->lifetime( $seconds );
			$words = human_time_diff( 0, $seconds );
			$ours  = array( 'cache-control' => "public, max-age={$seconds}, s-maxage={$seconds}" );
			$this->assertStringContainsString( $words, $this->site_health_sees( $ours )['description'], 'good' );
			$this->assertStringContainsString( $words, $this->site_health_sees( array( 'cache-control' => 'public, max-age=2592000' ) )['description'], 'held' );
			$this->assertStringContainsString( $words, $this->site_health_sees( array( 'cache-control' => 's-maxage=2592000, ' . $ours['cache-control'] ) )['description'], 'the host comes first' );
			add_filter( 'pre_http_request', array( $this, 'no_answer' ) );
			$this->assertStringContainsString( (string) $seconds, SiteHealth::test_edge_cache()['description'], 'could not check: the number to look for' );
			remove_filter( 'pre_http_request', array( $this, 'no_answer' ) );
		}
	}

	public function no_answer() {
		return new \WP_Error( 'http_request_failed', 'no loopback' );
	}

	public function test_n2_a_lifetime_filtered_to_something_absurd_is_held_to_the_longest_offered() {
		foreach ( array( PHP_INT_MAX, 1e30, '99999999999999999999', 30 * DAY_IN_SECONDS ) as $given ) {
			$set = static function () use ( $given ) {
				return $given;
			};
			add_filter( 'soapbox_blyg_cache_lifetime', $set );
			$this->assertSame( max( Caching::LIFETIMES ), Caching::lifetime(), wp_json_encode( $given ) );
			$headers = $this->caching( Http::not_found() );
			$this->assertEqualsWithDelta( time() + max( Caching::LIFETIMES ), strtotime( $headers['Expires'] ), 2 );
			remove_filter( 'soapbox_blyg_cache_lifetime', $set );
		}
		// And a value in Cache-Control itself, from the other filter, cannot break the date either.
		$huge = static function () {
			return 'public, max-age=' . PHP_INT_MAX . ', s-maxage=300';
		};
		add_filter( 'soapbox_blyg_cache_control', $huge );
		$this->assertNotFalse( strtotime( $this->caching( Http::not_found() )['Expires'] ) );
		remove_filter( 'soapbox_blyg_cache_control', $huge );
	}

	public function test_n3_the_settings_field_shows_the_lifetime_in_force_when_what_is_stored_is_not_one() {
		// Stored behind the sanitiser's back, as an old or hand-edited option could be.
		add_filter( 'pre_option_' . Settings::OPTION, array( $this, 'odd_setting' ) );
		ob_start();
		SettingsPage::field( array( 'key' => 'cache_lifetime' ) );
		$html = (string) ob_get_clean();
		remove_filter( 'pre_option_' . Settings::OPTION, array( $this, 'odd_setting' ) );
		$this->assertSame( 1, preg_match_all( '/<option value="(\d+)"\s+selected/', $html, $m ), 'exactly one option is selected' );
		$this->assertSame( (string) Caching::DEFAULT_LIFETIME, $m[1][0] );
	}

	public function odd_setting() {
		return array( 'cache_lifetime' => 7777 );
	}

	/**
	 * The acceptance scripts check the headers the plugin sends, so they ask the plugin what it
	 * means to send. A lifetime written into a script is wrong the day the setting is changed.
	 */
	public function test_b13_no_script_has_a_lifetime_of_its_own() {
		$found = array();
		foreach ( glob( dirname( __DIR__, 2 ) . '/bin/*' ) as $script ) {
			foreach ( file( $script ) as $n => $line ) {
				// 2592000 is not here: it is what a host adds, which the Breeze script names as such.
				if ( 0 !== strpos( ltrim( $line ), '#' ) && preg_match( '/(?<![\w.])(300|900|3600|86400|31536000)(?![\w.])/', $line ) ) {
					$found[] = basename( $script ) . ':' . ( $n + 1 );
				}
			}
		}
		$this->assertSame( array(), $found );
	}

	/**
	 * A number of seconds written out in the code is a second place to change, and the one that
	 * gets forgotten. Durations are WordPress's time constants or named constants of the plugin's.
	 */
	public function test_b13_no_duration_is_written_as_a_bare_number() {
		$found = array();
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__, 2 ) . '/src', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() || false !== strpos( $file->getPathname(), '/Vendor/' ) ) {
				continue;
			}
			foreach ( token_get_all( (string) file_get_contents( $file->getPathname() ) ) as $token ) {
				if ( is_array( $token ) && T_LNUMBER === $token[0] && in_array( (int) $token[1], array( 60, 300, 600, 900, 1800, 3600, 86400, 604800, 2592000, 31536000 ), true ) ) {
					$found[] = basename( $file->getPathname() ) . ':' . $token[2] . ' ' . $token[1];
				}
			}
		}
		// Not durations: the HTTP status at which redirects begin, counts of requests an hour, and a hook's priority.
		$allowed = array( 'Verifier.php' => array( '300' ), 'Receiver.php' => array( '60', '300' ), 'Plugin.php' => array( '900' ) );
		$found   = array_values(
			array_filter(
				$found,
				static function ( $hit ) use ( $allowed ) {
					list( $file, $number ) = array( strtok( $hit, ':' ), substr( strrchr( $hit, ' ' ), 1 ) );
					return ! in_array( $number, $allowed[ $file ] ?? array(), true );
				}
			)
		);
		$this->assertSame( array(), $found );
	}

	/*
	 * QA's pass on the B12 change (review-rc4-b12.md).
	 */

	/*
	 * B14 (which replaces QA's C1): whether a cache is holding the documents matters less than
	 * whether the copy it hands out is the current one. The ETag says which copy it is.
	 */

	/** Site Health asks for each origin's documents; each is answered with its real ETag, or $change's. */
	private function site_health_served( callable $change, array $more = array() ): array {
		$plugin  = Plugin::instance();
		$notes   = $plugin->registry->get( 'notes' );
		$life    = Caching::lifetime();
		$asked   = array();
		$answer  = static function ( $pre, $args, $url ) use ( $plugin, $notes, $change, $more, $life, &$asked ) {
			// The test environment sends a loopback to the web server beside it; it is the same request.
			$url     = (string) preg_replace( '#^http://127\.0\.0\.1(?=/)#', home_url(), $url );
			$asked[] = $url;
			// An address of another origin (the site's own posts may be registered too) is answered
			// with no ETag, which says nothing either way.
			$mine    = 0 === strpos( $url, $notes->url() );
			$this_is = $mine ? $plugin->router->respond( $notes, substr( $url, strlen( $notes->url() ) ) ) : null;
			$headers = array( 'cache-control' => "public, max-age={$life}, s-maxage={$life}", 'etag' => $mine ? $change( Http::etag( $this_is['body'] ), $url ) : null ) + ( $mine ? $more : array() );
			return array( 'response' => array( 'code' => 200, 'message' => '' ), 'body' => '', 'headers' => array_filter( $headers, 'is_string' ), 'cookies' => array() );
		};
		add_filter( 'pre_http_request', $answer, 10, 3 );
		$result = SiteHealth::test_edge_cache();
		remove_filter( 'pre_http_request', $answer, 10 );
		return $result + array( 'asked' => $asked );
	}

	public function test_b14_site_health_is_content_when_the_copy_served_is_the_current_one() {
		$this->register_notes();
		$a    = soapbox_blyg_publish( 'notes', 'a', $this->fragment( 'One.' ), array( 'at' => '2026-03-01T10:00:00Z' ) );
		$same = static function ( $etag ) {
			return $etag;
		};
		$good = $this->site_health_served( $same );
		$this->assertSame( 'good', $good['status'], $good['label'] . ' / ' . $good['description'] );
		// In the fixed layout the item documents are on the paths hosts cache: one is looked at.
		$this->assertContains( home_url( "/notes/items/{$a['id']}.json" ), $good['asked'] );

		// A cache that keeps the documents for a day, and hands out the current one: good, and said.
		$held = $this->site_health_served( $same, array( 'age' => (string) DAY_IN_SECONDS ) );
		$this->assertSame( 'good', $held['status'] );
		$this->assertNotSame( $good['description'], $held['description'], 'it says that a cache is holding them' );

		// What servers do to an ETag when they compress a response is not a different document.
		foreach ( array( 'W/%s', '"%s-gzip"', '"%s;br"', 'W/"%s-gzip"' ) as $form ) {
			$dressed = static function ( $etag ) use ( $form ) {
				return false === strpos( $form, '"' ) ? sprintf( $form, $etag ) : sprintf( $form, trim( $etag, '"' ) );
			};
			$this->assertSame( 'good', $this->site_health_served( $dressed, array( 'age' => (string) DAY_IN_SECONDS ) )['status'], $form );
		}
	}

	public function test_b14_site_health_warns_when_a_cache_serves_an_old_copy() {
		$this->register_notes();
		$a = soapbox_blyg_publish( 'notes', 'a', $this->fragment( 'One.' ), array( 'at' => '2026-03-01T10:00:00Z' ) );
		foreach ( array( 'blyg.json', 'feed.xml', "items/{$a['id']}.json" ) as $stale ) {
			$old = static function ( $etag, $url ) use ( $stale ) {
				return substr( $url, -strlen( $stale ) ) === $stale ? '"00000000000000000000000000000000"' : $etag;
			};
			$seen = $this->site_health_served( $old );
			$this->assertSame( 'recommended', $seen['status'], $stale );
			$this->assertStringContainsString( 'old copy', $seen['label'], $stale );
			$this->assertStringContainsString( $stale, $seen['description'], 'it names the document' );
		}
		// No ETag at all: nothing can be compared, and an Age past the lifetime is then all there is to go on.
		$none = static function () {
			return null;
		};
		$this->assertSame( 'good', $this->site_health_served( $none )['status'] );
		$this->assertSame( 'recommended', $this->site_health_served( $none, array( 'age' => (string) DAY_IN_SECONDS ) )['status'] );
	}

	public function test_b14_a_templated_origin_is_checked_by_its_manifest_and_feed() {
		$this->register_notes( array( 'layout' => 'templated' ) );
		soapbox_blyg_publish( 'notes', 'a', $this->fragment( 'One.' ), array( 'at' => '2026-03-01T10:00:00Z' ) );
		$plugin = Plugin::instance();
		$notes  = $plugin->registry->get( 'notes' );
		$asked  = array();
		$answer = static function ( $pre, $args, $url ) use ( &$asked ) {
			$asked[] = (string) preg_replace( '#^http://127\.0\.0\.1(?=/)#', home_url(), $url );
			return array( 'response' => array( 'code' => 200, 'message' => '' ), 'body' => '', 'headers' => array( 'cache-control' => 'public, max-age=300, s-maxage=300', 'etag' => '"00000000000000000000000000000000"' ), 'cookies' => array() );
		};
		add_filter( 'pre_http_request', $answer, 10, 3 );
		$seen = SiteHealth::test_edge_cache();
		remove_filter( 'pre_http_request', $answer, 10 );
		$this->assertSame( 'recommended', $seen['status'] );
		$this->assertStringContainsString( 'old copy', $seen['label'] );
		$this->assertSame( array( $plugin->locations->manifest( $notes ) ), array_values( array_unique( $asked ) ), 'the first stale document is enough' );
	}

	public function test_c2_the_message_says_what_is_wrong_a_header_or_a_cache() {
		$header = 'Your host adds its own caching instructions to the blyg';
		$cache  = 'A cache in front of this site is holding the blyg';
		// As rc4 leaves a Breeze host when a filter has dropped the plugin's s-maxage: one
		// s-maxage, the host's, and nothing repeated.
		$seen = $this->site_health_sees( array( 'cache-control' => array( 'public, max-age=300', 's-maxage=2592000' ) ) );
		$this->assertSame( array( 'recommended', $header ), array( $seen['status'], $seen['label'] ) );
		$this->assertStringContainsString( 'soapbox_blyg_cache_control', $seen['description'] );
		// The host's first, the plugin's after.
		$this->assertSame( $header, $this->site_health_sees( array( 'cache-control' => 's-maxage=2592000, public, max-age=300, s-maxage=300' ) )['label'] );
		// The headers are right and a cache holds a copy anyway.
		$ours = array( 'cache-control' => 'public, max-age=300, s-maxage=300, s-maxage=2592000' );
		$this->assertSame( $cache, $this->site_health_sees( $ours, $ours + array( 'age' => '86400' ) )['label'] );
	}

	public function test_c7_nothing_fetched_is_not_good_news() {
		add_filter( 'pre_http_request', array( $this, 'no_answer' ) );
		$this->register_notes();
		$result = SiteHealth::test_edge_cache();
		remove_filter( 'pre_http_request', array( $this, 'no_answer' ) );
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'could not be checked', $result['label'] );
	}

	public function test_c10_a_lifetime_inside_a_quoted_string_does_not_set_expires() {
		$set = static function () {
			return 'public, ext="max-age=9999", max-age=60, s-maxage=60';
		};
		add_filter( 'soapbox_blyg_cache_control', $set );
		$this->assertEqualsWithDelta( time() + 60, strtotime( $this->caching( Http::document( Http::JSON, '{}', 0 ) )['Expires'] ), 2 );
		remove_filter( 'soapbox_blyg_cache_control', $set );
	}
}
