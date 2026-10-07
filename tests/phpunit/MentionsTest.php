<?php
/**
 * The Webmention receiver and structural verification (G3).
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Tests;

use SoapboxBlyg\Mentions\Address;
use SoapboxBlyg\Mentions\Receiver;
use SoapboxBlyg\Mentions\Store;
use SoapboxBlyg\Mentions\Verifier;
use SoapboxBlyg\Plugin;
use SoapboxBlyg\Settings;

class MentionsTest extends TestCase {

	const THEIR_ORIGIN = 'https://reader.example/blyg/';
	const THEIR_ID     = '1vgtgz0gq5b2c9k7d3m8r4n6xy';
	const THEIR_PAGE   = 'https://reader.example/blyg/t/1vgtgz0gq5b2c9k7d3m8r4n6xy/';
	const THEIR_DOC    = 'https://reader.example/blyg/items/1vgtgz0gq5b2c9k7d3m8r4n6xy.json';

	/** @var array URL => [ status, body ] the mocked web answers with. */
	private $web = array();

	/** @var string[] URLs fetched. */
	private $fetched = array();

	/** @var string Our item's id. */
	private $ours;

	/** @var Verifier|null The verifier whose request is in flight. */
	private $verifier = null;

	/** @var array What each request was pinned to, by URL. */
	private $pins = array();

	/** @var array The arguments of each request, in order. */
	private $sent = array();

	/** @var bool Whether the stand-in transport fires curl's hook. */
	private $fire_curl_hook = true;

	public function set_up() {
		parent::set_up();
		update_option( Settings::OPTION, array( 'webmention' => true ) );
		$this->register_notes();
		$this->ours    = soapbox_blyg_publish( 'notes', 'a', $this->fragment( 'One.', array( 'permalink' => home_url( '/notes/a/' ) ) ) )['id'];
		$this->web     = array();
		$this->fetched = array();
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient%soapbox\_blyg\_out\_%'" );
		wp_cache_flush();

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				$this->fetched[] = $url;
				$this->sent[]    = $args;
				// The transport is replaced here, so its hook is fired by hand, as curl would: the
				// verifier refuses a response that did not pass through it.
				if ( $this->fire_curl_hook ) {
					$handle = curl_init();
					do_action_ref_array( 'http_api_curl', array( &$handle, $args, $url ) );
				}
				if ( $this->verifier ) {
					$pin = new \ReflectionProperty( Verifier::class, 'pin' );
					$pin->setAccessible( true );
					$this->pins[ $url ] = $pin->getValue( $this->verifier );
				}
				// The HTTP library tidies an address before it is sent: dot segments are resolved and
				// needless percent-encoding undone. So that is the address a server is asked for.
				$url = (string) ( new \WpOrg\Requests\Iri( $url ) )->uri;
				if ( ! isset( $this->web[ $url ] ) ) {
					return new \WP_Error( 'http_request_failed', 'no route to host' );
				}
				$this->assertSame( Verifier::TIMEOUT, $args['timeout'] );
				$this->assertSame( 0, $args['redirection'], 'redirects are followed by the verifier, one checked hop at a time' );
				$this->assertSame( Verifier::MAX_BYTES + 1, $args['limit_response_size'] );
				$this->assertTrue( $args['reject_unsafe_urls'], 'every fetch refuses private addresses, redirects included' );
				return array(
					'response' => array( 'code' => $this->web[ $url ][0], 'message' => '' ),
					'body'     => $this->web[ $url ][1],
					'headers'  => isset( $this->web[ $url ][2] ) ? ( is_array( $this->web[ $url ][2] ) ? $this->web[ $url ][2] : array( 'location' => $this->web[ $url ][2] ) ) : array(),
					'cookies'  => array(),
				);
			},
			10,
			3
		);
	}

	/**
	 * Sends a claim to a receiver whose address check treats *.example names as public. Those
	 * names never resolve, by design, and the tests must not depend on a resolver. Everything
	 * else, IP literals included, goes to core's real check.
	 */
	private function receive( string $source, string $target ): array {
		$plugin   = Plugin::instance();
		$receiver = new Receiver( $plugin->mentions, $plugin->locations, array( __CLASS__, 'addresses_of' ) );
		return $receiver->receive( $plugin->registry->get( 'notes' ), $source, $target );
	}

	/**
	 * *.example names resolve to a public address here; everything else goes to the real check.
	 * Every name under reader.example shares one address, as names under a wildcard DNS record
	 * do. Any other name gets a network of its own.
	 */
	public static function addresses_of( $url ) {
		$host = Address::normalize_host( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$port = wp_parse_url( $url, PHP_URL_PORT );
		if ( '.example' !== substr( $host, -8 ) || null !== $port ) {
			return Address::addresses( $url );
		}
		if ( in_array( $host, array( 'reader.example', 'short.example' ), true ) || '.reader.example' === substr( $host, -15 ) ) {
			return array( '93.184.216.34' );
		}
		return array( '93.185.' . ( crc32( $host ) % 250 ) . '.34' );
	}

	/** Time passes: every retry that was put off is due. */
	private function retries_are_due(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET next_try = NULL', \SoapboxBlyg\Ledger\Schema::table( 'mentions' ) ) ); // phpcs:ignore
	}

	/** Runs whatever the receiver queued, as though each job's time had come. */
	private function run_queue(): void {
		$this->retries_are_due();
		foreach ( (array) _get_cron_array() as $time => $hooks ) {
			foreach ( isset( $hooks[ Receiver::VERIFY_HOOK ] ) ? $hooks[ Receiver::VERIFY_HOOK ] : array() as $event ) {
				wp_unschedule_event( $time, Receiver::VERIFY_HOOK, $event['args'] );
				$plugin   = Plugin::instance();
				$verifier = new Verifier( $plugin->mentions, $plugin->registry, $plugin->ledger, array( __CLASS__, 'addresses_of' ) );
				$this->verifier = $verifier;
				$verifier->verify( $event['args'][0] );
			}
		}
	}

	private function mention(): array {
		return Plugin::instance()->mentions->query()[0];
	}

	/** Their item document, referring to ours in the given way. */
	private function their_document( array $extra ): string {
		return wp_json_encode(
			$extra + array(
				'blyg'    => '0.3',
				'id'      => self::THEIR_ID,
				'kind'    => 'thread',
				'origin'  => self::THEIR_ORIGIN,
				'page'    => 't/' . self::THEIR_ID . '/',
				'author'  => array( 'name' => 'A Reader', 'x-local' => 7 ),
				'version' => 4,
			)
		);
	}

	private function our_reference( array $extra = array() ): array {
		return $extra + array( 'origin' => home_url( '/notes/' ), 'id' => $this->ours, 'version' => 1 );
	}

	/**
	 * @dataProvider bad_claims
	 */
	public function test_bad_claims_are_400_and_nothing_is_queued( string $source, string $target ) {
		$target   = str_replace( array( '{home}', '{ours}' ), array( untrailingslashit( home_url() ), $this->ours ?? '' ), $target );
		$source   = str_replace( '{home}', untrailingslashit( home_url() ), $source );
		$response = $this->receive( $source, $target );
		$this->assertSame( 400, $response['status'], $response['body'] );
		$this->assertFalse( $response['cache'] );
		$this->assertSame( array(), Plugin::instance()->mentions->query() );
		$this->assertSame( array(), $this->fetched, 'receiving never fetches' );
	}

	public function bad_claims(): array {
		return array(
			'missing source'             => array( '', '{home}/notes/a/' ),
			'missing target'             => array( self::THEIR_PAGE, '' ),
			'relative source'            => array( '/blyg/t/x/', '{home}/notes/a/' ),
			'not http'                   => array( 'ftp://reader.example/x', '{home}/notes/a/' ),
			'source is the target'       => array( '{home}/notes/a/', '{home}/notes/a' ),
			'target on another site'     => array( self::THEIR_PAGE, 'https://elsewhere.example/notes/a/' ),
			'target on another port'     => array( self::THEIR_PAGE, 'http://example.org:8080/notes/a/' ),
			'target is not an item'      => array( self::THEIR_PAGE, '{home}/about/' ),
			'unknown item document'      => array( self::THEIR_PAGE, '{home}/notes/items/00000000000000000000000000.json' ),
			'over-long source'           => array( 'https://reader.example/' . str_repeat( 'a', 2100 ), '{home}/notes/a/' ),
		);
	}

	public function test_a_target_page_shared_by_several_items_is_400_and_the_item_url_still_works() {
		$shared = home_url( '/notes/' );
		$one    = soapbox_blyg_publish( 'notes', 'one', $this->fragment( 'First.', array( 'permalink' => $shared ) ) )['id'];
		soapbox_blyg_publish( 'notes', 'two', $this->fragment( 'Second.', array( 'permalink' => $shared ) ) );

		$by_page = $this->receive( self::THEIR_PAGE, $shared );
		$this->assertSame( 400, $by_page['status'], 'a page two items share does not say which is meant' );
		$this->assertSame( 202, $this->receive( self::THEIR_PAGE, home_url( "/notes/items/{$one}.json" ) )['status'] );
		$this->assertSame( $one, $this->mention()['target_id'] );
	}

	public function test_a_private_network_source_is_refused() {
		foreach ( array( 'http://127.0.0.1/x', 'http://10.0.0.5/x', 'http://192.168.1.10/x', 'http://169.254.169.254/latest/meta-data/', 'http://reader.example:22/x' ) as $source ) {
			$response = $this->receive( $source, home_url( '/notes/a/' ) );
			$this->assertSame( 400, $response['status'], $source );
		}
		$this->assertSame( array(), Plugin::instance()->mentions->query() );
	}

	public function test_private_and_reserved_ranges_are_recognised_on_every_php() {
		foreach ( array( '127.0.0.1', '10.1.2.3', '172.16.0.1', '192.168.0.1', '169.254.169.254', '0.0.0.0', '100.64.0.1', '100.127.255.254', '::1', 'fe80::1', 'febf::1', 'fd00::1', 'fc00::1', '::ffff:10.0.0.1', '::ffff:169.254.169.254' ) as $ip ) {
			$this->assertFalse( Address::is_public_ip( $ip ), $ip );
		}
		foreach ( array( '93.184.216.34', '100.63.255.255', '100.128.0.1', '2606:2800:220:1:248:1893:25c8:1946' ) as $ip ) {
			$this->assertTrue( Address::is_public_ip( $ip ), $ip );
		}
		$this->assertTrue( Address::is_public( home_url( '/other-blyg/' ) ), 'another blyg on this site is a legitimate source' );
		$this->assertFalse( Address::is_public( 'http://does-not-resolve.invalid/' ) );
		$this->assertFalse( Address::is_public( 'http://user:pass@93.184.216.34/' ) );
		$this->assertTrue( Address::is_public( 'http://93.184.216.34/' ) );
	}

	/**
	 * The IANA special-purpose registries (IPv4 and IPv6), one address from inside each block and,
	 * where a block has public neighbours, the addresses either side of it.
	 */
	public function test_every_special_purpose_range_is_refused_and_its_neighbours_are_not() {
		$refused = array(
			// IPv4 special-purpose address registry.
			'0.0.0.0', '0.255.255.255', '10.0.0.0', '10.255.255.255', '100.64.0.0', '100.127.255.255',
			'127.0.0.1', '127.255.255.255', '169.254.0.1', '169.254.169.254', '172.16.0.0', '172.31.255.255',
			'192.0.0.0', '192.0.0.8', '192.0.0.170', '192.0.0.255', '192.0.2.1',
			'192.88.99.1', '192.168.0.0', '192.168.255.255', '198.18.0.0', '198.19.255.255',
			'198.51.100.1', '203.0.113.1', '224.0.0.1', '239.255.255.255', '240.0.0.1', '255.255.255.255',
			// IPv6 special-purpose address registry.
			'::', '::1', '100::1', '64:ff9b:1::1', '2001::1', '2001:0:4136:e378:8000:63bf:3fff:fdd2', '2001:2::1',
			'2001:10::1', '2001:20::1', '2001:30::1', '2001:db8::1', '3fff::1', '5f00::1', 'fc00::1', 'fdff::1',
			'fe80::1', 'febf::1', 'fec0::1', 'ff00::1', 'ff02::1',
			// Outside global unicast altogether.
			'4000::1', 'e000::1',
			// IPv4 carried inside IPv6, in every form: the IPv4 address is what is judged.
			'::ffff:10.0.0.1', '::ffff:a00:1', '::ffff:7f00:1', '::ffff:169.254.169.254', '::ffff:a9fe:a9fe',
			'::10.0.0.1', '::7f00:1',
			'64:ff9b::10.0.0.1', '64:ff9b::a00:1', '64:ff9b::7f00:1', '64:ff9b::a9fe:a9fe', '64:ff9b::c0a8:101',
			'2002:a00:1::1', '2002:7f00:1::', '2002:a9fe:a9fe::1', '2002:c0a8:101:1::1', '2002:ac10:1::',
			// Not an address.
			'', 'example.com', '1.2.3', '1.2.3.4.5', 'gggg::1',
		);
		foreach ( $refused as $ip ) {
			$this->assertFalse( Address::is_public_ip( $ip ), "refused: {$ip}" );
		}
		$allowed = array(
			// One plain public address in each family.
			'93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946',
			// Either side of each IPv4 block.
			'1.0.0.0', '9.255.255.255', '11.0.0.0', '100.63.255.255', '100.128.0.0', '126.255.255.255', '128.0.0.0',
			'169.253.255.255', '169.255.0.0', '172.15.255.255', '172.32.0.0', '191.255.255.255', '192.0.1.0',
			'192.0.3.0', '192.88.98.255', '192.88.100.0', '192.167.255.255', '192.169.0.0', '198.17.255.255',
			'198.20.0.0', '198.51.99.255', '198.51.101.0', '203.0.112.255', '203.0.114.0', '223.255.255.255',
			// Public IPv4 carried inside IPv6 is public IPv4.
			'64:ff9b::5db8:d822', '2002:5db8:d822::1',
			// Blocks the registry lists as globally reachable (AS112, AMT): ordinary public services.
			'192.31.196.1', '192.52.193.1', '192.175.48.1',
			// IPv6 next to the special blocks.
			'2001:4860:4860::8888', '2003::1', '2a00:1450:4001::1',
		);
		foreach ( $allowed as $ip ) {
			$this->assertTrue( Address::is_public_ip( $ip ), "allowed: {$ip}" );
		}

		// Host names are compared in one form.
		$this->assertSame( 'example.com', Address::normalize_host( 'Example.COM.' ) );
		$this->assertSame( 'example.com', Address::normalize_host( 'example.com' ) );
	}

	public function tear_down() {
		Address::$resolver = null;
		parent::tear_down();
	}

	/**
	 * QA's finding on the step-7 review: the check has to see both address families.
	 */
	public function test_a_host_is_refused_if_any_of_its_addresses_in_either_family_is_private() {
		$dns               = array(
			'both-public.test'    => array( '93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946' ),
			'v6-loopback.test'    => array( '93.184.216.34', '::1' ),
			'v6-only-inward.test' => array( '::1' ),
			'v6-unique-local.test' => array( '93.184.216.34', 'fd12:3456:789a::1' ),
			'v6-link-local.test'  => array( '93.184.216.34', 'fe80::1' ),
			'v4-mapped.test'      => array( '::ffff:127.0.0.1' ),
			'v4-inward.test'      => array( '93.184.216.34', '10.0.0.8' ),
			'nowhere.test'        => array(),
		);
		Address::$resolver = static function ( $host ) use ( $dns ) {
			return isset( $dns[ $host ] ) ? $dns[ $host ] : array();
		};

		$this->assertSame( $dns['both-public.test'], Address::addresses( 'https://both-public.test/x' ) );
		$this->assertSame( $dns['both-public.test'], Address::addresses( 'http://both-public.test:8080/x' ) );
		$this->assertNull( Address::addresses( 'https://both-public.test:8443/x' ), 'only the ports core allows' );
		$this->assertNull( Address::addresses( 'https://user:pass@both-public.test/x' ) );
		$this->assertNull( Address::addresses( 'ftp://both-public.test/x' ) );
		$this->assertNull( Address::addresses( 'http://[::1]/x' ) );
		foreach ( array( 'v6-loopback.test', 'v6-only-inward.test', 'v6-unique-local.test', 'v6-link-local.test', 'v4-mapped.test', 'v4-inward.test', 'nowhere.test' ) as $host ) {
			$this->assertNull( Address::addresses( "https://{$host}/x" ), $host );
			$this->assertFalse( Address::is_public( "https://{$host}/x" ), $host );
		}

		// The endpoint looks nothing up, so a name is accepted; a public A record still does not get
		// a loopback AAAA through, because the job that verifies the claim resolves it and stops.
		$plugin   = Plugin::instance();
		$receiver = new Receiver( $plugin->mentions, $plugin->locations );
		$this->assertSame( 202, $receiver->receive( $plugin->registry->get( 'notes' ), 'https://v6-loopback.test/x', home_url( '/notes/a/' ) )['status'] );
		$verifier = new Verifier( $plugin->mentions, $plugin->registry, $plugin->ledger );
		$verifier->verify( (int) $this->mention()['mention_id'] );
		$this->assertSame( array( Store::FAILED, 'private-address' ), array( $this->mention()['status'], $this->mention()['reason'] ) );
		$this->assertSame( array(), $this->fetched, 'and nothing was fetched' );
	}

	public function test_what_the_url_itself_shows_is_refused_at_once_and_a_name_is_never_looked_up_there() {
		$looked_up         = array();
		Address::$resolver = static function ( $host ) use ( &$looked_up ) {
			$looked_up[] = $host;
			return array( '93.184.216.34' );
		};
		$plugin   = Plugin::instance();
		$receiver = new Receiver( $plugin->mentions, $plugin->locations );
		$origin   = $plugin->registry->get( 'notes' );
		foreach ( array( 'http://127.0.0.1/x', 'http://10.0.0.5/x', 'http://169.254.169.254/latest/', 'https://user:pass@named.test/x', 'https://named.test:22/x', 'http://[::1]/x' ) as $source ) {
			$this->assertSame( 400, $receiver->receive( $origin, $source, home_url( '/notes/a/' ) )['status'], $source );
		}
		$this->assertSame( 202, $receiver->receive( $origin, 'https://named.test/x', home_url( '/notes/a/' ) )['status'] );
		$this->assertSame( array(), $looked_up, 'the receiver resolved nothing' );
	}

	public function test_this_sites_own_host_is_exempt_only_on_its_own_port() {
		$this->assertSame( array(), Address::addresses( home_url( '/other-blyg/items/x.json' ) ) );
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		foreach ( array( 8080, 8443, 22, 3306, 9200 ) as $port ) {
			$this->assertNull( Address::addresses( "http://{$host}:{$port}/admin" ), "port {$port} on our own host is some other service" );
		}
	}

	public function test_what_a_source_says_about_itself_is_stored_only_up_to_a_size() {
		$huge = array( 'name' => 'A Reader', 'bio' => str_repeat( 'x', 50000 ) );
		$this->web[ self::THEIR_DOC ] = array( 200, $this->their_document( array( 'stub_of' => $this->our_reference(), 'author' => $huge, 'page' => 't/' . str_repeat( 'p', 5000 ) ) ) );
		$this->receive( self::THEIR_DOC, home_url( '/notes/a/' ) );
		$this->run_queue();
		$mention = $this->mention();
		$this->assertSame( Store::VERIFIED, $mention['status'] );
		$this->assertSame( '{"name":"A Reader"}', $mention['source_author'], 'an oversized author object is cut down to its name' );
		$this->assertSame( self::THEIR_DOC, $mention['source_page'], 'an oversized page falls back to the source URL' );
		$this->assertLessThan( 3000, strlen( wp_json_encode( $mention ) ) );

		// A page that is not http(s) is never stored as something an administrator might click.
		// (Sent from a page this time: a document is only believed at its own address, decision #61.)
		$this->web['https://reader.example/2'] = array( 200, '<html><head><link rel="alternate" type="application/json" href="' . self::THEIR_DOC . '"></head></html>' );
		$this->web[ self::THEIR_DOC ]          = array( 200, $this->their_document( array( 'stub_of' => $this->our_reference(), 'page' => 'javascript:alert(1)' ) ) );
		$this->receive( 'https://reader.example/2', home_url( '/notes/a/' ) );
		$this->run_queue();
		$this->assertSame( 'https://reader.example/2', Plugin::instance()->mentions->by_pair( 'https://reader.example/2', home_url( '/notes/a/' ) )['source_page'] );
	}

	public function test_each_request_is_pinned_to_an_address_that_was_checked() {
		$this->web = array(
			'https://short.example/a' => array( 302, '', self::THEIR_DOC ),
			self::THEIR_DOC           => array( 200, $this->their_document( array( 'stub_of' => $this->our_reference() ) ) ),
		);
		$this->receive( 'https://short.example/a', home_url( '/notes/a/' ) );
		$this->run_queue();
		$this->assertSame( Store::VERIFIED, $this->mention()['status'] );

		$this->assertSame(
			array( 'url' => 'https://short.example/a', 'host' => 'short.example', 'port' => 443, 'address' => '93.184.216.34', 'pinned' => true, 'overflow' => false ),
			$this->pins['https://short.example/a']
		);
		$this->assertSame( 'reader.example', $this->pins[ self::THEIR_DOC ]['host'], 'each hop is pinned afresh, to its own host' );

		// The pin does not outlive the request, and never touches anyone else's.
		$pin = new \ReflectionProperty( Verifier::class, 'pin' );
		$pin->setAccessible( true );
		$this->assertNull( $pin->getValue( $this->verifier ) );

		// This site's own host is allowed without a lookup, so there is nothing to pin to.
		$this->web[ home_url( '/other-blyg/items/x.json' ) ] = array( 200, '{}' );
		$this->receive( home_url( '/other-blyg/items/x.json' ), home_url( '/notes/a/' ) );
		$this->run_queue();
		$this->assertSame( '', $this->pins[ home_url( '/other-blyg/items/x.json' ) ]['address'] );
	}

	public function test_a_host_is_fetched_and_pinned_in_the_form_it_was_checked_in() {
		$document = $this->their_document( array( 'stub_of' => $this->our_reference() ) );
		$this->web = array(
			'https://short.example/a' => array( 302, '', 'https://READER.example./blyg/items/' . self::THEIR_ID . '.json' ),
			self::THEIR_DOC           => array( 200, $document ),
		);
		$this->receive( 'https://short.example/a', home_url( '/notes/a/' ) );
		$this->run_queue();
		$this->assertSame( Store::VERIFIED, $this->mention()['status'], (string) $this->mention()['reason'] );
		$this->assertSame( array( 'https://short.example/a', self::THEIR_DOC ), $this->fetched, 'case and a trailing dot are gone before the request is made' );
		$this->assertSame( 'reader.example', $this->pins[ self::THEIR_DOC ]['host'] );
	}

	public function test_a_request_that_could_not_be_pinned_is_not_used() {
		// A transport that is not curl never fires the hook, so the address that was checked is
		// not the address that was fetched. The response is thrown away.
		$this->fire_curl_hook         = false;
		$this->web[ self::THEIR_DOC ] = array( 200, $this->their_document( array( 'stub_of' => $this->our_reference() ) ) );
		$this->receive( self::THEIR_DOC, home_url( '/notes/a/' ) );
		$this->run_queue();
		$this->assertSame( array( Store::FAILED, 'cannot-pin' ), array( $this->mention()['status'], $this->mention()['reason'] ) );
		$this->fire_curl_hook = true;

		$this->assertNull( Verifier::cannot_pin( self::THEIR_DOC ), 'curl is here and no proxy is configured' );
	}

	public function test_a_compressed_response_is_refused_and_nothing_is_inflated() {
		$bomb = gzencode( str_repeat( "\0", 4 * 1024 * 1024 ) );
		$this->assertLessThan( 10000, strlen( $bomb ), 'four megabytes of zeros compress to a few kilobytes' );
		$this->web[ self::THEIR_DOC ] = array( 200, $bomb, array( 'content-encoding' => 'gzip' ) );
		$this->receive( self::THEIR_DOC, home_url( '/notes/a/' ) );
		$before = memory_get_peak_usage();
		$this->run_queue();
		$this->assertSame( array( Store::FAILED, 'encoded' ), array( $this->mention()['status'], $this->mention()['reason'] ) );
		$this->assertLessThan( 2 * 1024 * 1024, memory_get_peak_usage() - $before, 'the body was not inflated' );

		$this->assertFalse( $this->sent[0]['decompress'], 'the HTTP API is told not to inflate' );
		$this->assertSame( 'identity', $this->sent[0]['headers']['Accept-Encoding'], 'and the server is asked not to compress' );
	}

	public function test_an_attempt_is_recorded_before_the_fetch_so_a_fatal_one_cannot_repeat_for_ever() {
		global $wpdb;
		$this->receive( self::THEIR_DOC, home_url( '/notes/a/' ) );
		$id = (int) $this->mention()['mention_id'];

		// What the row says at the moment the request goes out.
		$seen = array();
		add_filter(
			'pre_http_request',
			function ( $pre ) use ( &$seen, $id ) {
				$seen[] = (int) Plugin::instance()->mentions->get( $id )['attempts'];
				return $pre;
			},
			5
		);
		$verifier = new Verifier( Plugin::instance()->mentions, Plugin::instance()->registry, Plugin::instance()->ledger, array( __CLASS__, 'addresses_of' ) );

		// Two runs die mid-fetch: the row is still pending, as a fatal error would leave it.
		foreach ( array( 1, 2 ) as $run ) {
			$verifier->verify( $id );
			$wpdb->update( \SoapboxBlyg\Ledger\Schema::table( 'mentions' ), array( 'status' => Store::PENDING, 'attempts' => $run, 'next_try' => null, 'lease' => null ), array( 'mention_id' => $id ) );
		}
		$this->assertSame( array( 1, 2 ), $seen, 'each attempt was on the row before its request was sent' );

		// The third run does not fetch.
		$this->fetched = array();
		$verifier->verify( $id );
		$this->assertSame( array(), $this->fetched );
		$this->assertSame( array( Store::FAILED, 'gave-up', '2' ), array( $this->mention()['status'], $this->mention()['reason'], (string) $this->mention()['attempts'] ) );
	}

	public function test_one_verification_makes_five_requests_at_most() {
		// A page behind three redirects, pointing at a document behind three more: eight requests
		// if each fetch had its own allowance.
		$this->web = array(
			'https://hop.example/1' => array( 302, '', 'https://hop.example/2' ),
			'https://hop.example/2' => array( 302, '', 'https://hop.example/3' ),
			'https://hop.example/3' => array( 302, '', 'https://hop.example/page' ),
			'https://hop.example/page' => array( 200, '<html><head><link rel="alternate" type="application/json" href="https://hop.example/d1"></head></html>' ),
			'https://hop.example/d1' => array( 302, '', 'https://hop.example/d2' ),
			'https://hop.example/d2' => array( 302, '', 'https://hop.example/d3' ),
			'https://hop.example/d3' => array( 200, '{}' ),
		);
		$this->receive( 'https://hop.example/1', home_url( '/notes/a/' ) );
		$this->run_queue();
		$this->assertSame( array( Store::FAILED, 'too-many-requests' ), array( $this->mention()['status'], $this->mention()['reason'] ) );
		$this->assertCount( Verifier::MAX_REQUESTS, $this->fetched );
	}

	public function test_requests_are_counted_against_the_network_they_go_to() {
		add_filter(
			'soapbox_blyg_webmention_limits',
			static function ( $limits ) {
				return array( 'outbound' => 3 ) + $limits;
			}
		);
		// Four senders on four networks, each redirecting to one third party.
		$target = home_url( '/notes/a/' );
		foreach ( array( 'one', 'two', 'three', 'four' ) as $name ) {
			$this->web[ "https://{$name}.example/x" ] = array( 302, '', 'https://victim.example/' . $name );
			$this->web[ 'https://victim.example/' . $name ] = array( 200, '{}' );
			$this->receive( "https://{$name}.example/x", $target );
		}
		$this->run_queue();
		$this->assertCount( 3, array_filter( $this->fetched, static function ( $url ) { return 0 === strpos( $url, 'https://victim.example/' ); } ), 'the fourth request to the third party was not made' );
		// The fourth is not failed for it. The busy network may be an honest sender's own, shared
		// with whoever used it up: the claim waits for the hour to turn, and has spent no attempt.
		$last = Plugin::instance()->mentions->by_pair( 'https://four.example/x', $target );
		$this->assertSame( array( Store::PENDING, 'destination-busy', '0' ), array( $last['status'], $last['reason'], (string) $last['attempts'] ) );
		$this->assertGreaterThan( \SoapboxBlyg\Ledger\Ledger::now(), $last['next_try'] );
		$this->assertNotFalse( wp_next_scheduled( Receiver::VERIFY_HOOK, array( (int) $last['mention_id'] ) ) );

		// When the hour has turned it is verified like any other.
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', \SoapboxBlyg\Ledger\Schema::table( 'counters' ) ) ); // phpcs:ignore
		$this->web['https://victim.example/four'] = array( 200, $this->their_document( array( 'stub_of' => $this->our_reference() ) ) );
		$this->run_queue();
		$last = Plugin::instance()->mentions->by_pair( 'https://four.example/x', $target );
		$this->assertNotSame( Store::PENDING, $last['status'] );
	}

	public function test_a_retry_that_was_put_off_is_not_run_early_by_the_sweep() {
		global $wpdb;
		$this->receive( self::THEIR_DOC, home_url( '/notes/a/' ) );
		$this->run_queue(); // Unreachable: to be tried again in a quarter of an hour.
		$mention = $this->mention();
		$this->assertSame( array( Store::PENDING, '1' ), array( $mention['status'], (string) $mention['attempts'] ) );
		$this->assertGreaterThan( gmdate( 'Y-m-d\\TH:i:s\\Z', time() + Verifier::RETRY_DELAY - 10 ), $mention['next_try'] );

		// The hourly sweep comes round, and the claim is more than five minutes old.
		$wpdb->update( \SoapboxBlyg\Ledger\Schema::table( 'mentions' ), array( 'received_at' => gmdate( 'Y-m-d\\TH:i:s\\Z', time() - 10 * MINUTE_IN_SECONDS ) ), array( 'mention_id' => (int) $mention['mention_id'] ) );
		$this->fetched = array();
		$verifier      = new Verifier( Plugin::instance()->mentions, Plugin::instance()->registry, Plugin::instance()->ledger, array( __CLASS__, 'addresses_of' ) );
		$verifier->sweep();
		$verifier->verify( (int) $mention['mention_id'] );
		$this->assertSame( array(), $this->fetched, 'not before its time, by the sweep or by an early job' );
		$this->assertSame( '1', (string) $this->mention()['attempts'] );
	}

	public function test_a_claim_there_is_no_room_for_does_not_spend_anybody_elses_allowance() {
		add_filter(
			'soapbox_blyg_webmention_limits',
			static function ( $limits ) {
				return array( 'host' => 2, 'pending' => 1 ) + $limits;
			}
		);
		$target = home_url( '/notes/a/' );
		$this->assertSame( 202, $this->receive( 'https://full.example/1', $target )['status'] );
		// The queue is full. These are refused, and must not use up reader.example's two an hour.
		$this->assertSame( 429, $this->receive( 'https://reader.example/1', $target )['status'] );
		$this->assertSame( 429, $this->receive( 'https://reader.example/2', $target )['status'] );
		$this->assertSame( 429, $this->receive( 'https://reader.example/3', $target )['status'] );

		// The queue drains, and reader.example still has both.
		$this->run_queue();
		$this->run_queue();
		$this->assertSame( 202, $this->receive( 'https://reader.example/1', $target )['status'] );
		$this->run_queue();
		$this->run_queue();
		$this->assertSame( 202, $this->receive( 'https://reader.example/2', $target )['status'] );
	}

	public function test_pending_mentions_expire_after_a_week_and_resume_when_the_receiver_returns() {
		global $wpdb;
		$table = \SoapboxBlyg\Ledger\Schema::table( 'mentions' );
		$this->web[ self::THEIR_DOC ] = array( 200, $this->their_document( array( 'stub_of' => $this->our_reference() ) ) );

		// Two claims arrive, then the receiver is switched off before either is verified.
		$this->receive( self::THEIR_DOC, home_url( '/notes/a/' ) );
		$this->receive( 'https://old.example/x', home_url( '/notes/a/' ) );
		update_option( Settings::OPTION, array( 'webmention' => false ) );
		$this->run_queue();
		$this->assertSame( array(), $this->fetched );

		// Eight days pass for one of them, ten minutes for the other, and the receiver comes back.
		$wpdb->update( $table, array( 'received_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() - 8 * DAY_IN_SECONDS ) ), array( 'source_url' => 'https://old.example/x' ) );
		$wpdb->update( $table, array( 'received_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() - 10 * MINUTE_IN_SECONDS ) ), array( 'source_url' => self::THEIR_DOC ) );
		update_option( Settings::OPTION, array( 'webmention' => true ) );

		$plugin   = Plugin::instance();
		$verifier = new Verifier( $plugin->mentions, $plugin->registry, $plugin->ledger, array( __CLASS__, 'addresses_of' ) );
		$verifier->sweep();

		$old = $plugin->mentions->by_pair( 'https://old.example/x', home_url( '/notes/a/' ) );
		$this->assertSame( array( Store::FAILED, 'expired' ), array( $old['status'], $old['reason'] ) );
		$this->assertSame( Store::VERIFIED, $plugin->mentions->by_pair( self::THEIR_DOC, home_url( '/notes/a/' ) )['status'], 'the one that waited through the gap is picked up' );
		$this->assertSame( array( self::THEIR_DOC ), $this->fetched, 'the expired one was never fetched' );

		// Switching the receiver on schedules a sweep at once, not an hour later.
		wp_clear_scheduled_hook( Receiver::SWEEP_HOOK );
		Receiver::sync_sweep( true );
		$this->assertLessThanOrEqual( time() + 5, wp_next_scheduled( Receiver::SWEEP_HOOK ) );
		Receiver::sync_sweep( false );
		$this->assertFalse( wp_next_scheduled( Receiver::SWEEP_HOOK ) );
	}

	public function test_redirects_are_followed_hop_by_hop_and_a_hop_to_a_private_address_ends_it() {
		$document = $this->their_document( array( 'stub_of' => $this->our_reference() ) );

		// Three redirects are allowed, and the final URL is who is speaking.
		$this->web = array(
			'https://short.example/a'    => array( 301, '', 'https://short.example/b' ),
			'https://short.example/b'    => array( 302, '', '/c' ),
			'https://short.example/c'    => array( 307, '', self::THEIR_DOC ),
			self::THEIR_DOC              => array( 200, $document ),
		);
		$this->receive( 'https://short.example/a', home_url( '/notes/a/' ) );
		$this->run_queue();
		$this->assertSame( Store::VERIFIED, $this->mention()['status'], (string) $this->mention()['reason'] );
		$this->assertSame( array( 'https://short.example/a', 'https://short.example/b', 'https://short.example/c', self::THEIR_DOC ), $this->fetched );

		// A fourth is one too many.
		$this->fetched = array();
		$this->web     = array(
			'https://loop.example/1' => array( 302, '', 'https://loop.example/2' ),
			'https://loop.example/2' => array( 302, '', 'https://loop.example/3' ),
			'https://loop.example/3' => array( 302, '', 'https://loop.example/4' ),
			'https://loop.example/4' => array( 302, '', 'https://loop.example/5' ),
			'https://loop.example/5' => array( 200, $document ),
		);
		$this->receive( 'https://loop.example/1', home_url( '/notes/a/' ) );
		$this->run_queue();
		$loop = Plugin::instance()->mentions->by_pair( 'https://loop.example/1', home_url( '/notes/a/' ) );
		$this->assertSame( array( Store::FAILED, 'too-many-redirects' ), array( $loop['status'], $loop['reason'] ) );
		$this->assertCount( 4, $this->fetched );

		// A public URL that redirects inward is stopped before the inward request is made.
		$this->fetched = array();
		$this->web     = array( 'https://bounce.example/' => array( 302, '', 'http://169.254.169.254/latest/meta-data/' ) );
		$this->receive( 'https://bounce.example/', home_url( '/notes/a/' ) );
		$this->run_queue();
		$bounce = Plugin::instance()->mentions->by_pair( 'https://bounce.example/', home_url( '/notes/a/' ) );
		$this->assertSame( array( Store::FAILED, 'private-address' ), array( $bounce['status'], $bounce['reason'] ) );
		$this->assertSame( array( 'https://bounce.example/' ), $this->fetched, 'the private address was never requested' );
	}

	public function test_a_valid_claim_is_202_pending_and_makes_no_request() {
		$response = $this->receive( self::THEIR_PAGE, home_url( '/notes/a/' ) );
		$this->assertSame( 202, $response['status'] );
		$this->assertSame( array(), $this->fetched );

		$mention = $this->mention();
		$this->assertSame( Store::PENDING, $mention['status'] );
		$this->assertSame( $this->ours, $mention['target_id'] );
		$this->assertSame( 'reader.example', $mention['source_host'] );
		$this->assertNotFalse( wp_next_scheduled( Receiver::VERIFY_HOOK, array( (int) $mention['mention_id'] ) ) );
	}

	public function test_the_target_may_be_named_by_its_item_document_and_may_be_withdrawn() {
		soapbox_blyg_publish( 'notes', 'a', null );
		$this->assertSame( 202, $this->receive( self::THEIR_PAGE, home_url( "/notes/items/{$this->ours}.json" ) )['status'] );
		$this->assertSame( 202, $this->receive( self::THEIR_DOC, home_url( '/notes/a/' ) )['status'], 'people may respond to a withdrawal' );
	}

	public function test_a_page_that_points_at_a_document_transcluding_us_verifies() {
		$this->web[ self::THEIR_PAGE ] = array( 200, '<html><head><link rel="stylesheet" href="x.css"><link rel="alternate" type="application/rss+xml" href="/feed"><link rel="ALTERNATE nofollow" type="application/json" href="../../items/' . self::THEIR_ID . '.json"></head><body>hi</body></html>' );
		$this->web[ self::THEIR_DOC ]  = array( 200, $this->their_document( array( 'transclusions' => array( array( 'id' => 'ffffffffffffffffffffffffff', 'version' => 1 ), $this->our_reference( array( 'cited' => array( 'retrieved' => '2026-10-01T00:00:00Z' ), 'selector' => array( 'exact' => 'One.' ) ) ) ) ) ) );

		$this->receive( self::THEIR_PAGE, home_url( '/notes/a/' ) );
		$this->run_queue();

		$mention = $this->mention();
		$this->assertSame( Store::VERIFIED, $mention['status'], (string) $mention['reason'] );
		$this->assertSame( 'transclusion', $mention['relation'] );
		$this->assertSame( self::THEIR_ORIGIN, $mention['source_origin'] );
		$this->assertSame( self::THEIR_ID, $mention['source_id'] );
		$this->assertSame( '4', (string) $mention['source_version'] );
		$this->assertSame( 'thread', $mention['source_kind'] );
		$this->assertSame( '{"name":"A Reader","x-local":7}', $mention['source_author'], 'the author passes through verbatim' );
		$this->assertSame( self::THEIR_PAGE, $mention['source_page'] );
		$this->assertNotEmpty( $mention['verified_at'] );
		$this->assertSame( array( self::THEIR_PAGE, self::THEIR_DOC ), $this->fetched, 'two fetches, no more' );
		$this->assertSame( 1, Plugin::instance()->mentions->verified_count( $this->ours ) );

		// Nothing of the source's content is kept.
		$this->assertStringNotContainsString( 'hi', wp_json_encode( $mention ) );
	}

	public function test_relations_in_the_protocols_order() {
		$doc = function ( array $extra ) {
			$this->web                     = array( self::THEIR_DOC => array( 200, $this->their_document( $extra ) ) );
			global $wpdb;
			$wpdb->query( 'DELETE FROM ' . \SoapboxBlyg\Ledger\Schema::table( 'mentions' ) ); // phpcs:ignore
			$this->receive( self::THEIR_DOC, home_url( '/notes/a/' ) );
			$this->run_queue();
			return $this->mention();
		};

		$stub = $doc( array( 'stub_of' => $this->our_reference(), 'transclusions' => array( $this->our_reference() ) ) );
		$this->assertSame( array( Store::VERIFIED, 'stub' ), array( $stub['status'], $stub['relation'] ), 'a stub that also quotes us is a stub' );

		$fork = $doc( array( 'forked_from' => $this->our_reference() ) );
		$this->assertSame( array( Store::FAILED, 'no-reference' ), array( $fork['status'], $fork['reason'] ), 'a fork of an unpinned version does not verify' );

		soapbox_blyg_pin( $this->ours, 1 );
		$fork = $doc( array( 'forked_from' => $this->our_reference() ) );
		$this->assertSame( array( Store::VERIFIED, 'fork' ), array( $fork['status'], $fork['relation'] ) );

		$url_stub = $doc( array( 'stub_of' => array( 'url' => home_url( '/notes/a/' ) ) ) );
		$this->assertSame( Store::FAILED, $url_stub['status'], 'a plain-web stub is not a structural reference' );
	}

	/**
	 * @dataProvider documents_that_do_not_verify
	 */
	public function test_documents_that_do_not_verify( string $reason, $document ) {
		$body                         = is_callable( $document ) ? $document( $this ) : $document;
		$this->web[ self::THEIR_DOC ] = array( 200, $body );
		$this->receive( self::THEIR_DOC, home_url( '/notes/a/' ) );
		$this->run_queue();
		$mention = $this->mention();
		$this->assertSame( Store::FAILED, $mention['status'] );
		$this->assertSame( $reason, $mention['reason'] );
	}

	public function documents_that_do_not_verify(): array {
		return array(
			'a page that merely links to us' => array( 'not-a-blyg-item', '<html><body><a href="http://example.org/notes/a/">nice post</a></body></html>' ),
			'json that is not an item'       => array( 'not-a-blyg-item', '{"hello":"world"}' ),
			'an item that does not cite us'  => array(
				'no-reference',
				static function ( $t ) {
					return $t->their_document_public( array( 'transclusions' => array( array( 'origin' => 'https://someone.example/blyg/', 'id' => 'ffffffffffffffffffffffffff', 'version' => 1 ) ) ) );
				},
			),
			'a transclusion with no origin (their own item)' => array(
				'no-reference',
				static function ( $t ) {
					return $t->their_document_public( array( 'transclusions' => array( array( 'id' => $t->ours_public(), 'version' => 1 ) ) ) );
				},
			),
			'a document claiming another origin' => array(
				'origin-mismatch',
				static function ( $t ) {
					return $t->their_document_public( array( 'origin' => 'https://famous.example/blyg/', 'stub_of' => $t->our_reference_public() ) );
				},
			),
		);
	}

	public function their_document_public( array $extra ): string {
		return $this->their_document( $extra );
	}

	public function our_reference_public(): array {
		return $this->our_reference();
	}

	public function ours_public(): string {
		return $this->ours;
	}

	public function test_a_page_on_one_host_pointing_at_a_document_on_another_does_not_verify() {
		$this->web['https://impostor.example/post/'] = array( 200, '<link rel="alternate" type="application/json" href="' . self::THEIR_DOC . '">' );
		$this->web[ self::THEIR_DOC ]                = array( 200, $this->their_document( array( 'stub_of' => $this->our_reference() ) ) );
		$this->assertSame( 202, $this->receive( 'https://impostor.example/post/', home_url( '/notes/a/' ) )['status'] );
		$this->run_queue();
		$this->assertSame( array( Store::FAILED, 'origin-mismatch' ), array( $this->mention()['status'], $this->mention()['reason'] ) );
	}

	public function test_a_resent_mention_for_a_withdrawn_source_becomes_gone_and_the_row_is_kept() {
		$this->web[ self::THEIR_DOC ] = array( 200, $this->their_document( array( 'stub_of' => $this->our_reference() ) ) );
		$this->receive( self::THEIR_DOC, home_url( '/notes/a/' ) );
		$this->run_queue();
		$first = $this->mention();
		$this->assertSame( Store::VERIFIED, $first['status'] );

		// The stubber withdraws and re-sends once (spec 15.2).
		add_filter(
			'soapbox_blyg_webmention_limits',
			static function ( $limits ) {
				$limits['resend_seconds'] = 0;
				return $limits;
			}
		);
		$this->web[ self::THEIR_DOC ] = array( 200, $this->their_document( array( 'kind' => 'withdrawn', 'transclusions' => array() ) ) );
		$this->assertSame( 202, $this->receive( self::THEIR_DOC, home_url( '/notes/a/' ) )['status'] );
		$this->run_queue();

		$rows = Plugin::instance()->mentions->query();
		$this->assertCount( 1, $rows, 'a re-sent pair re-verifies; it never duplicates' );
		$this->assertSame( Store::GONE, $rows[0]['status'] );
		$this->assertSame( $first['mention_id'], $rows[0]['mention_id'] );
		$this->assertSame( 0, Plugin::instance()->mentions->verified_count( $this->ours ) );

		// A reference that is simply removed, without withdrawal, is also gone, not merely failed.
		$this->web[ self::THEIR_DOC ] = array( 200, $this->their_document( array( 'transclusions' => array() ) ) );
		$this->receive( self::THEIR_DOC, home_url( '/notes/a/' ) );
		$this->run_queue();
		$this->assertSame( Store::GONE, $this->mention()['status'] );
	}

	public function test_an_unreachable_source_is_retried_once_then_fails() {
		$this->receive( self::THEIR_DOC, home_url( '/notes/a/' ) );
		$this->run_queue();
		$mention = $this->mention();
		$this->assertSame( Store::PENDING, $mention['status'] );
		$this->assertSame( '1', (string) $mention['attempts'] );
		$retry = wp_next_scheduled( Receiver::VERIFY_HOOK, array( (int) $mention['mention_id'] ) );
		$this->assertGreaterThanOrEqual( time() + Verifier::RETRY_DELAY - 5, $retry );

		$this->run_queue();
		$this->assertSame( array( Store::FAILED, 'unreachable' ), array( $this->mention()['status'], $this->mention()['reason'] ) );
		$this->assertFalse( wp_next_scheduled( Receiver::VERIFY_HOOK, array( (int) $mention['mention_id'] ) ) );
	}

	public function test_an_oversized_or_error_response_fails_without_a_second_fetch() {
		$this->web[ self::THEIR_DOC ] = array( 200, str_repeat( 'x', Verifier::MAX_BYTES + 1 ) );
		$this->receive( self::THEIR_DOC, home_url( '/notes/a/' ) );
		$this->run_queue();
		$this->assertSame( array( Store::FAILED, 'too-large' ), array( $this->mention()['status'], $this->mention()['reason'] ) );
		$this->assertCount( 1, $this->fetched );

		$this->web['https://reader.example/gone'] = array( 404, '' );
		$this->receive( 'https://reader.example/gone', home_url( '/notes/a/' ) );
		$this->run_queue();
		$this->assertSame( 'http-404', Plugin::instance()->mentions->by_pair( 'https://reader.example/gone', home_url( '/notes/a/' ) )['reason'] );
	}

	public function test_a_pending_pair_is_coalesced_and_a_recent_one_is_rate_limited() {
		$this->receive( self::THEIR_DOC, home_url( '/notes/a/' ) );
		$again = $this->receive( self::THEIR_DOC, home_url( '/notes/a/' ) );
		$this->assertSame( 202, $again['status'] );
		$this->assertCount( 1, Plugin::instance()->mentions->query() );

		$this->run_queue(); // First attempt: unreachable, still pending.
		$this->run_queue(); // Second: failed.
		$soon = $this->receive( self::THEIR_DOC, home_url( '/notes/a/' ) );
		$this->assertSame( 429, $soon['status'] );
		$this->assertArrayHasKey( 'retry_after', $soon );
	}

	public function test_caps_refuse_with_429_and_queue_nothing() {
		add_filter(
			'soapbox_blyg_webmention_limits',
			static function ( $limits ) {
				return array( 'host' => 2, 'origin' => 4, 'pending' => 1000 ) + $limits;
			}
		);
		$target = home_url( '/notes/a/' );
		$this->assertSame( 202, $this->receive( 'https://reader.example/1', $target )['status'] );
		$this->assertSame( 202, $this->receive( 'https://reader.example/2', $target )['status'] );
		$this->assertSame( 429, $this->receive( 'https://reader.example/3', $target )['status'], 'per host' );

		$this->assertSame( 202, $this->receive( 'https://one.example/1', $target )['status'] );
		$this->assertSame( 202, $this->receive( 'https://two.example/1', $target )['status'] );
		$this->assertSame( 429, $this->receive( 'https://three.example/1', $target )['status'], 'per origin, whatever the source' );

		$this->assertCount( 4, Plugin::instance()->mentions->query() );
		$this->assertSame( array(), $this->fetched );
	}

	public function test_sources_are_grouped_by_the_network_they_resolve_to() {
		$this->assertSame( '93.184.216.0/24', Address::network_of( '93.184.216.34' ) );
		$this->assertSame( Address::network_of( '93.184.216.1' ), Address::network_of( '93.184.216.254' ) );
		$this->assertNotSame( Address::network_of( '93.184.216.1' ), Address::network_of( '93.184.217.1' ) );
		$this->assertSame( Address::network_of( '2606:2800:220:1::1' ), Address::network_of( '2606:2800:220:ffff:1:2:3:4' ), 'a /48 in IPv6' );
		$this->assertNotSame( Address::network_of( '2606:2800:220::1' ), Address::network_of( '2606:2800:221::1' ) );
	}

	public function test_one_client_cannot_fill_the_queue_and_shut_out_the_rest() {
		add_filter(
			'soapbox_blyg_webmention_limits',
			static function ( $limits ) {
				return array( 'client_pending' => 3, 'pending' => 5 ) + $limits;
			}
		);
		$plugin   = Plugin::instance();
		$receiver = new Receiver( $plugin->mentions, $plugin->locations );
		$origin   = $plugin->registry->get( 'notes' );
		$target   = home_url( '/notes/a/' );

		// A flood from one client, under any number of names, holds three places and no more.
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		$flood                  = Receiver::client();
		$accepted               = 0;
		for ( $i = 0; $i < 10; $i++ ) {
			$accepted += 202 === $receiver->receive( $origin, "https://n{$i}.flood.example/x", $target, $flood )['status'] ? 1 : 0;
		}
		$this->assertSame( 3, $accepted );

		// So somebody else still gets in.
		$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
		$other                  = Receiver::client();
		$this->assertSame( 202, $receiver->receive( $origin, 'https://elsewhere.example/x', $target, $other )['status'] );
		$this->assertSame( 202, $receiver->receive( $origin, 'https://another.example/x', $target, $other )['status'] );
		$this->assertSame( 429, $receiver->receive( $origin, 'https://third.example/x', $target, $other )['status'], 'the site-wide cap still holds' );
		unset( $_SERVER['REMOTE_ADDR'] );
	}

	public function test_a_claim_sent_again_starts_afresh_and_the_old_worker_records_nothing() {
		$plugin = Plugin::instance();
		$store  = $plugin->mentions;
		$target = home_url( '/notes/a/' );
		$id     = $store->accept( 'notes', $this->ours, 'https://reader.example/x', $target );

		$claim = $store->claim( $id, Verifier::MAX_ATTEMPTS, Verifier::LEASE_SECONDS );
		$this->assertSame( 1, $claim['attempts'], 'the count the claim brought it to' );
		$lease = $claim['lease'];
		$this->assertNull( $store->claim( $id, Verifier::MAX_ATTEMPTS, Verifier::LEASE_SECONDS ), 'held: nobody else can take it' );
		$this->assertFalse( $store->give_up( $id, Verifier::MAX_ATTEMPTS ), 'and it is not given up on while it is held' );

		// The worker finishes; later the claim verifies, then is sent again while a second worker
		// is still out fetching on an older lease.
		$this->assertTrue( $store->finish( $id, $lease, array( 'status' => Store::VERIFIED, 'verified_at' => '2026-01-01T00:00:00Z' ) ) );
		$stale = '2026-01-01T00:02:00Z';
		$store->update( $id, array( 'status' => Store::PENDING, 'lease' => $stale ) );
		$store->accept( 'notes', $this->ours, 'https://reader.example/x', $target );
		$store->update( $id, array( 'status' => Store::FAILED ) );
		$store->accept( 'notes', $this->ours, 'https://reader.example/x', $target );
		$this->assertSame( array( Store::PENDING, '0', null ), array( $store->get( $id )['status'], (string) $store->get( $id )['attempts'], $store->get( $id )['lease'] ) );
		$this->assertFalse( $store->finish( $id, $stale, array( 'status' => Store::FAILED, 'reason' => 'from-the-old-worker' ) ) );
		$this->assertSame( Store::PENDING, $store->get( $id )['status'] );

		// A lease that ran out is a worker that died: the job can be taken again, once.
		$store->update( $id, array( 'lease' => '2026-01-01T00:00:00Z', 'attempts' => 1 ) );
		$this->assertNotNull( $store->claim( $id, Verifier::MAX_ATTEMPTS, Verifier::LEASE_SECONDS ) );
		$store->update( $id, array( 'lease' => '2026-01-01T00:00:00Z' ) );
		$this->assertNull( $store->claim( $id, Verifier::MAX_ATTEMPTS, Verifier::LEASE_SECONDS ), 'no attempts left' );
		$this->assertTrue( $store->give_up( $id, Verifier::MAX_ATTEMPTS ) );
		$this->assertSame( array( Store::FAILED, 'gave-up' ), array( $store->get( $id )['status'], $store->get( $id )['reason'] ) );
	}

	public function test_old_counts_and_old_failures_are_pruned_and_records_are_kept() {
		global $wpdb;
		$store  = Plugin::instance()->mentions;
		$target = home_url( '/notes/a/' );
		$failed = $store->accept( 'notes', $this->ours, 'https://reader.example/never', $target );
		$gone   = $store->accept( 'notes', $this->ours, 'https://reader.example/once', $target );
		$recent = $store->accept( 'notes', $this->ours, 'https://reader.example/recent', $target );
		$old    = gmdate( 'Y-m-d\\TH:i:s\\Z', time() - 31 * DAY_IN_SECONDS );
		$store->update( $failed, array( 'status' => Store::FAILED, 'received_at' => $old ) );
		$store->update( $gone, array( 'status' => Store::FAILED, 'received_at' => $old, 'verified_at' => $old ) );
		$store->update( $recent, array( 'status' => Store::FAILED ) );
		$this->assertTrue( $store->take( 'host', 'x', 5 ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET bucket = bucket - 5', \SoapboxBlyg\Ledger\Schema::table( 'counters' ) ) );

		$store->prune();
		$this->assertNull( $store->get( $failed ), 'a failure that never verified, a month old' );
		$this->assertNotNull( $store->get( $gone ), 'it verified once: that is a record' );
		$this->assertNotNull( $store->get( $recent ) );
		$this->assertSame( '0', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', \SoapboxBlyg\Ledger\Schema::table( 'counters' ) ) ) );
	}

	public function test_a_remote_document_must_have_an_id_a_version_and_a_kind_of_the_right_types() {
		$good = array( 'stub_of' => $this->our_reference() );
		foreach ( array( 'id' => array(), 'version' => '4', 'kind' => 7 ) as $member => $wrong ) {
			$this->web     = array( self::THEIR_DOC => array( 200, $this->their_document( array( $member => $wrong ) + $good ) ) );
			$this->receive( self::THEIR_DOC . '?' . $member, home_url( '/notes/a/' ) );
			$this->web[ self::THEIR_DOC . '?' . $member ] = $this->web[ self::THEIR_DOC ];
			$this->run_queue();
			$row = Plugin::instance()->mentions->by_pair( self::THEIR_DOC . '?' . $member, home_url( '/notes/a/' ) );
			$this->assertSame( array( Store::FAILED, 'not-a-blyg-item' ), array( $row['status'], $row['reason'] ), $member );
		}
	}

	public function test_one_sender_is_limited_whatever_sources_it_names() {
		add_filter(
			'soapbox_blyg_webmention_limits',
			static function ( $limits ) {
				return array( 'client' => 3 ) + $limits;
			}
		);
		$plugin   = Plugin::instance();
		$receiver = new Receiver( $plugin->mentions, $plugin->locations, array( __CLASS__, 'addresses_of' ) );
		$origin   = $plugin->registry->get( 'notes' );
		$target   = home_url( '/notes/a/' );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		$sender                 = Receiver::client();
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $sender );
		$this->assertStringNotContainsString( '203.0.113', $sender, 'the address is not what is stored' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.200';
		$this->assertSame( $sender, Receiver::client(), 'one network, one sender' );

		// Four publications on four networks, all sent from one machine.
		foreach ( array( 'one', 'two', 'three' ) as $name ) {
			$this->assertSame( 202, $receiver->receive( $origin, "https://{$name}.example/x", $target, $sender )['status'] );
		}
		$this->assertSame( 429, $receiver->receive( $origin, 'https://four.example/x', $target, $sender )['status'] );

		// Somebody else is not affected.
		$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
		$this->assertNotSame( $sender, Receiver::client() );
		$this->assertSame( 202, $receiver->receive( $origin, 'https://four.example/x', $target, Receiver::client() )['status'] );

		// Behind a proxy, a site says where the visitor's address is.
		$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
		$behind                 = Receiver::client();
		add_filter(
			'soapbox_blyg_webmention_client',
			static function () {
				return '203.0.113.50';
			}
		);
		$this->assertSame( $sender, Receiver::client() );
		$this->assertNotSame( $sender, $behind );
		remove_all_filters( 'soapbox_blyg_webmention_client' );

		$_SERVER['REMOTE_ADDR'] = 'not an address';
		$this->assertSame( '', Receiver::client() );
		unset( $_SERVER['REMOTE_ADDR'] );
	}

	public function test_one_pair_is_one_row_however_it_arrives() {
		global $wpdb;
		$store  = Plugin::instance()->mentions;
		$target = home_url( '/notes/a/' );
		$first  = $store->accept( 'notes', $this->ours, 'https://reader.example/x', $target );
		$second = $store->accept( 'notes', $this->ours, 'https://reader.example/x', $target );
		$this->assertGreaterThan( 0, $first );
		$this->assertSame( $first, $second );
		$this->assertCount( 1, $store->query() );

		// The table itself refuses a second row for the pair, whatever the code above it does.
		$table = \SoapboxBlyg\Ledger\Schema::table( 'mentions' );
		$wpdb->suppress_errors( true );
		$inserted = $wpdb->query( $wpdb->prepare( 'INSERT INTO %i ( origin_key, target_id, target_url, source_url, pair_hash, source_host, client, status, received_at ) SELECT origin_key, target_id, target_url, source_url, pair_hash, source_host, client, status, received_at FROM %i', $table, $table ) );
		$wpdb->suppress_errors( false );
		$this->assertFalse( $inserted );
		$this->assertCount( 1, $store->query() );
	}

	public function test_origin_identity_is_compared_normalised() {
		$this->assertSame( 'https://example.com/blyg/', Verifier::identity( 'HTTPS://Example.com:443/blyg' ) );
		$this->assertSame( 'https://example.com/', Verifier::identity( 'https://example.com' ) );
		$this->assertSame( 'http://localhost:8888/blyg/', Verifier::identity( 'http://localhost:8888/blyg/' ) );
	}

	public function test_the_endpoint_exists_only_while_the_receiver_is_on() {
		$plugin = Plugin::instance();
		$origin = $plugin->registry->get( 'notes' );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$get                       = $plugin->router->respond( $origin, 'webmention' );
		$this->assertSame( 405, $get['status'] );
		$this->assertSame( 'POST', $get['allow'] );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array();
		$this->assertSame( 400, $plugin->router->respond( $origin, 'webmention' )['status'] );
		// Another blyg on this same site: its host is ours, which core accepts without a lookup.
		$_POST = array( 'source' => home_url( '/other-blyg/t/x/' ), 'target' => home_url( '/notes/a/' ) );
		$this->assertSame( 202, $plugin->router->respond( $origin, 'webmention' )['status'] );

		$this->assertSame( 'webmention', json_decode( $plugin->documents->manifest( $origin )[0], true )['webmention'] );

		update_option( Settings::OPTION, array( 'webmention' => false ) );
		$this->assertSame( 405, $plugin->router->respond( $origin, 'webmention' )['status'], 'with the receiver off the path is like any other, and POST is not a method the surface takes' );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->assertSame( 404, $plugin->router->respond( $origin, 'webmention' )['status'] );
		$this->assertArrayNotHasKey( 'webmention', json_decode( $plugin->documents->manifest( $origin )[0], true ) );
		$_POST = array();
	}

	public function test_verification_does_nothing_once_the_receiver_is_off() {
		$this->web[ self::THEIR_DOC ] = array( 200, $this->their_document( array( 'stub_of' => $this->our_reference() ) ) );
		$this->receive( self::THEIR_DOC, home_url( '/notes/a/' ) );
		update_option( Settings::OPTION, array( 'webmention' => false ) );
		$this->run_queue();
		$this->assertSame( array(), $this->fetched, 'switching the receiver off stops every outbound request' );
		$this->assertSame( Store::PENDING, $this->mention()['status'] );
	}

	/**
	 * Spec 16.6e, and the protocol lead's note on blygger-spec#2: a mention's target is checked
	 * against this origin's own templates and permalinks, in whichever layout it has, and not
	 * against the protocol's default paths.
	 *
	 * @dataProvider layouts
	 */
	public function test_a_target_is_recognised_by_this_origins_own_addresses_in_its_own_layout( string $layout ) {
		$plugin = Plugin::instance();
		$post   = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<p>Body.</p>' ) );
		$id     = soapbox_blyg_get_status( 'posts', 'post:' . $post )['id'];
		soapbox_blyg_pin( $id, 1 );

		$origin         = clone $plugin->registry->get( 'posts' );
		$origin->layout = $layout;
		$other          = clone $origin;
		$other->layout  = 'fixed' === $layout ? 'templated' : 'fixed';
		$receiver       = new Receiver( $plugin->mentions, $plugin->locations );

		$ours = array(
			'the item document'  => $plugin->locations->item( $origin, $id ),
			'the pinned version' => $plugin->locations->pin( $origin, $id, 1 ),
			'the post permalink' => get_permalink( $post ),
		);
		$n = 0;
		foreach ( $ours as $what => $target ) {
			$this->assertSame( 202, $receiver->receive( $origin, 'https://reader.example/' . ( ++$n ), $target )['status'], "{$layout}: {$what} {$target}" );
		}

		// The other layout's addresses are not this origin's: they answer 404 here, and name nothing.
		foreach ( array( $plugin->locations->item( $other, $id ), $plugin->locations->pin( $other, $id, 1 ) ) as $target ) {
			$this->assertSame( 400, $receiver->receive( $origin, 'https://reader.example/other', $target )['status'], "{$layout}: {$target} is the other layout's" );
		}
		// A version that is not pinned has no address, so it names nothing either.
		wp_update_post( array( 'ID' => $post, 'post_content' => '<p>Two.</p>' ) );
		$this->assertSame( 400, $receiver->receive( $origin, 'https://reader.example/unpinned', $plugin->locations->pin( $origin, $id, 2 ) )['status'] );
	}

	public function layouts(): array {
		return array(
			'templated' => array( 'templated' ),
			'fixed'     => array( 'fixed' ),
		);
	}

	/*
	 * blygger-spec decision #61 (spec 15.4 step 2): a document speaks for the origin it names only
	 * when it was fetched from exactly that origin's address for that item. Scheme, host and port
	 * are not enough: two blygs can share a host.
	 */

	/** Sends a claim from $source, runs the job, and returns the mention's status and reason. */
	private function outcome_for( string $source ): array {
		$this->fetched = array();
		$this->receive( $source, home_url( '/notes/a/' ) );
		$this->run_queue();
		$row = Plugin::instance()->mentions->by_pair( $source, home_url( '/notes/a/' ) );
		return array( $row['status'], $row['reason'] );
	}

	public function test_61_a_blyg_on_the_same_host_cannot_speak_as_another() {
		// Bob and Alice both publish on reader.example. Bob's document says it is Alice's.
		$bob       = 'https://reader.example/bob/blyg/items/' . self::THEIR_ID . '.json';
		$this->web = array(
			$bob => array( 200, $this->their_document( array( 'origin' => 'https://reader.example/alice/blyg/', 'stub_of' => $this->our_reference() ) ) ),
			// Alice's manifest is an ordinary fixed one: it gives no template that could lead to Bob.
			'https://reader.example/alice/blyg/blyg.json' => array( 200, wp_json_encode( array( 'blyg' => '0.3', 'site' => 'https://reader.example/alice/blyg/' ) ) ),
		);
		$this->assertSame( array( Store::FAILED, 'origin-mismatch' ), $this->outcome_for( $bob ) );
	}

	public function test_61_a_fixed_sender_verifies_from_its_documents_exact_address_with_no_extra_fetch() {
		$this->web = array( self::THEIR_DOC => array( 200, $this->their_document( array( 'stub_of' => $this->our_reference() ) ) ) );
		$this->assertSame( array( Store::VERIFIED, null ), $this->outcome_for( self::THEIR_DOC ) );
		$this->assertSame( array( self::THEIR_DOC ), $this->fetched, 'the address is the proof: nothing else is asked' );

		// The host is compared as origins are, without regard to case, and nothing else is.
		$this->assertSame( Store::VERIFIED, $this->outcome_for( 'https://Reader.Example/blyg/items/' . self::THEIR_ID . '.json' )[0] );
	}

	/**
	 * @dataProvider wrong_addresses
	 */
	public function test_61_a_document_at_any_other_address_on_the_same_host_does_not_verify( string $address ) {
		$this->web = array(
			// Served wherever the address leads once the HTTP library has tidied it.
			(string) ( new \WpOrg\Requests\Iri( $address ) )->uri => array( 200, $this->their_document( array( 'stub_of' => $this->our_reference() ) ) ),
			self::THEIR_ORIGIN . 'blyg.json' => array( 200, wp_json_encode( array( 'blyg' => '0.3', 'site' => self::THEIR_ORIGIN ) ) ),
		);
		$this->assertSame( array( Store::FAILED, 'origin-mismatch' ), $this->outcome_for( $address ) );
	}

	public function wrong_addresses(): array {
		$id = self::THEIR_ID;
		return array(
			'another directory'          => array( "https://reader.example/elsewhere/items/{$id}.json" ),
			'a path with dot segments'   => array( "https://reader.example/blyg/items/../x/items/{$id}.json" ),
			'under the origin, deeper'   => array( "https://reader.example/blyg/uploads/items/{$id}.json" ),
			'the right path, upper case' => array( "https://reader.example/BLYG/items/{$id}.json" ),
			'percent-encoded'            => array( "https://reader.example/blyg/%69tems/{$id}.json" ),
			'with a query'               => array( "https://reader.example/blyg/items/{$id}.json?x=1" ),
			'another item\'s address'    => array( 'https://reader.example/blyg/items/2vgtgz0gq5b2c9k7d3m8r4n6xy.json' ),
		);
	}

	public function test_61_a_templated_sender_verifies_through_its_manifest_at_the_cost_of_one_fetch() {
		$id        = self::THEIR_ID;
		$document  = "https://reader.example/wp-json/soapbox-blyg/v1/origins/posts/items/{$id}";
		$manifest  = self::THEIR_ORIGIN . 'blyg.json';
		$this->web = array(
			$document => array( 200, $this->their_document( array( 'stub_of' => $this->our_reference() ) ) ),
			$manifest => array( 200, wp_json_encode( array( 'blyg' => '0.3', 'site' => self::THEIR_ORIGIN, 'item' => 'https://reader.example/wp-json/soapbox-blyg/v1/origins/posts/items/{id}' ) ) ),
		);
		$this->assertSame( Store::VERIFIED, $this->outcome_for( $document )[0] );
		$this->assertSame( array( $document, $manifest ), $this->fetched, 'the document, then the manifest of the origin it names' );

		// A template may carry a query string (decision #64); the manifest's own address may not.
		$query     = "https://reader.example/?rest_route=/blyg/items/{$id}";
		$this->web = array(
			$query    => array( 200, $this->their_document( array( 'stub_of' => $this->our_reference() ) ) ),
			$manifest => array( 200, wp_json_encode( array( 'blyg' => '0.3', 'item' => 'https://reader.example/?rest_route=/blyg/items/{id}' ) ) ),
		);
		$this->assertSame( Store::VERIFIED, $this->outcome_for( $query )[0] );
	}

	/**
	 * @dataProvider bad_manifests
	 */
	public function test_61_a_manifest_that_does_not_lead_to_the_document_does_not_verify( $manifest_answer ) {
		$id        = self::THEIR_ID;
		$document  = "https://reader.example/api/items/{$id}";
		$this->web = array(
			$document => array( 200, $this->their_document( array( 'stub_of' => $this->our_reference() ) ) ),
			self::THEIR_ORIGIN . 'blyg.json' => $manifest_answer,
			'https://reader.example/other/blyg.json' => array( 200, wp_json_encode( array( 'blyg' => '0.3', 'item' => 'https://reader.example/api/items/{id}' ) ) ),
		);
		$this->assertSame( array( Store::FAILED, 'origin-mismatch' ), $this->outcome_for( $document ) );
	}

	public function bad_manifests(): array {
		$manifest = static function ( $item ) {
			return array( 200, wp_json_encode( array( 'blyg' => '0.3' ) + ( null === $item ? array() : array( 'item' => $item ) ) ) );
		};
		return array(
			'its item template points elsewhere' => array( $manifest( 'https://reader.example/v2/items/{id}' ) ),
			'no item template'                   => array( $manifest( null ) ),
			'item is not a string'               => array( $manifest( array( 'https://reader.example/api/items/{id}' ) ) ),
			'item is relative'                   => array( $manifest( '/api/items/{id}' ) ),
			'item is not http'                   => array( $manifest( 'ftp://reader.example/api/items/{id}' ) ),
			'not a manifest'                     => array( array( 200, '<html></html>' ) ),
			'redirected to another address'      => array( array( 302, '', 'https://reader.example/other/blyg.json' ) ),
			'not found'                          => array( array( 404, '' ) ),
		);
	}

	public function test_61_an_origin_claimed_with_a_query_or_a_fragment_is_refused_without_asking_for_its_manifest() {
		foreach ( array( 'https://reader.example/blyg/?b=1', 'https://reader.example/blyg/#frag', 'https://reader.example/?blyg=1' ) as $claimed ) {
			$document  = 'https://reader.example/q/' . md5( $claimed ) . '/items/' . self::THEIR_ID . '.json';
			$this->web = array( $document => array( 200, $this->their_document( array( 'origin' => $claimed, 'stub_of' => $this->our_reference() ) ) ) );
			$this->assertSame( array( Store::FAILED, 'origin-mismatch' ), $this->outcome_for( $document ), $claimed );
			$this->assertSame( array( $document ), $this->fetched, $claimed );
		}
	}

	public function test_61_a_document_whose_own_id_is_not_the_one_in_its_address_does_not_verify() {
		// Served at the address of one item, saying it is another: both by fixed path and by template.
		$other     = '2vgtgz0gq5b2c9k7d3m8r4n6xy';
		$fixed     = self::THEIR_ORIGIN . "items/{$other}.json";
		$templated = "https://reader.example/api/items/{$other}";
		$this->web = array(
			$fixed     => array( 200, $this->their_document( array( 'stub_of' => $this->our_reference() ) ) ),
			$templated => array( 200, $this->their_document( array( 'stub_of' => $this->our_reference() ) ) ),
			self::THEIR_ORIGIN . 'blyg.json' => array( 200, wp_json_encode( array( 'blyg' => '0.3', 'item' => 'https://reader.example/api/items/{id}' ) ) ),
		);
		$this->assertSame( array( Store::FAILED, 'origin-mismatch' ), $this->outcome_for( $fixed ) );
		$this->assertSame( array( Store::FAILED, 'origin-mismatch' ), $this->outcome_for( $templated ) );
	}

	public function test_61_a_manifest_that_cannot_be_reached_is_tried_again_and_a_busy_destination_is_put_off() {
		$document  = 'https://reader.example/api/items/' . self::THEIR_ID;
		$this->web = array( $document => array( 200, $this->their_document( array( 'stub_of' => $this->our_reference() ) ) ) );
		// The manifest gives no answer.
		$this->receive( $document, home_url( '/notes/a/' ) );
		$this->run_queue();
		$row = Plugin::instance()->mentions->by_pair( $document, home_url( '/notes/a/' ) );
		$this->assertSame( array( Store::PENDING, '1' ), array( $row['status'], (string) $row['attempts'] ), 'unreachable: one attempt used, to be tried again' );

		// The manifest's fetch is a request like any other: it is counted against the destination.
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', \SoapboxBlyg\Ledger\Schema::table( 'counters' ) ) ); // phpcs:ignore
		add_filter(
			'soapbox_blyg_webmention_limits',
			static function ( $limits ) {
				return array( 'outbound' => 1 ) + $limits;
			}
		);
		$second = 'https://reader.example/api/items/' . self::THEIR_ID . '?again';
		$this->web[ $second ] = $this->web[ $document ];
		$this->web[ self::THEIR_ORIGIN . 'blyg.json' ] = array( 200, wp_json_encode( array( 'blyg' => '0.3', 'item' => 'https://reader.example/api/items/{id}?again' ) ) );
		$this->fetched = array();
		$this->receive( $second, home_url( '/notes/a/' ) );
		$this->run_queue();
		$row = Plugin::instance()->mentions->by_pair( $second, home_url( '/notes/a/' ) );
		$this->assertSame( array( Store::PENDING, 'destination-busy', '0' ), array( $row['status'], $row['reason'], (string) $row['attempts'] ), 'the one request allowed went on the document; the manifest waits for the next hour' );
		$this->assertNotContains( self::THEIR_ORIGIN . 'blyg.json', $this->fetched );
	}

	public function test_61_a_page_its_document_and_the_manifest_are_three_documents_and_that_is_the_most() {
		$id        = self::THEIR_ID;
		$page      = 'https://reader.example/2026/a-reply/';
		$document  = "https://reader.example/api/items/{$id}";
		$this->web = array(
			$page     => array( 200, '<html><head><link rel="alternate" type="application/json" href="' . $document . '"></head></html>' ),
			$document => array( 200, $this->their_document( array( 'transclusions' => array( $this->our_reference() ) ) ) ),
			self::THEIR_ORIGIN . 'blyg.json' => array( 200, wp_json_encode( array( 'blyg' => '0.3', 'item' => 'https://reader.example/api/items/{id}' ) ) ),
		);
		$this->assertSame( Store::VERIFIED, $this->outcome_for( $page )[0] );
		$this->assertSame( array( $page, $document, self::THEIR_ORIGIN . 'blyg.json' ), $this->fetched );
		$this->assertSame( 3, Verifier::MAX_FETCHES );
	}

	/*
	 * QA's pass on the decision-#61 change (review-rc4-61.md).
	 */

	/**
	 * V1. The HTTP library resolves dot segments and undoes needless percent-encoding before it
	 * asks, so an origin written with either is fetched from somewhere other than where it says.
	 *
	 * @dataProvider untidy_origins
	 */
	public function test_v1_an_origin_or_an_address_that_is_not_written_plainly_names_nothing( string $claimed, string $source, array $served ) {
		$this->web = array();
		foreach ( $served as $at => $what ) {
			$this->web[ $at ] = array( 200, 'manifest' === $what ? wp_json_encode( array( 'blyg' => '0.3', 'item' => 'https://reader.example/api/items/{id}' ) ) : $this->their_document( array( 'origin' => $claimed, 'stub_of' => $this->our_reference() ) ) );
		}
		$this->assertSame( array( Store::FAILED, 'origin-mismatch' ), $this->outcome_for( $source ) );
	}

	public function untidy_origins(): array {
		$id  = self::THEIR_ID;
		$doc = array( self::THEIR_DOC => 'document' );
		$tpl = array( "https://reader.example/api/items/{$id}" => 'document', self::THEIR_ORIGIN . 'blyg.json' => 'manifest' );
		return array(
			'dot segments in the origin'                 => array( 'https://reader.example/x/../blyg/', "https://reader.example/x/../blyg/items/{$id}.json", $doc ),
			'encoded dot segments'                       => array( 'https://reader.example/x/%2e%2e/blyg/', "https://reader.example/x/%2e%2e/blyg/items/{$id}.json", $doc ),
			'a letter percent-encoded in the origin'     => array( 'https://reader.example/bl%79g/', "https://reader.example/bl%79g/items/{$id}.json", $doc ),
			'a single dot'                               => array( 'https://reader.example/./blyg/', "https://reader.example/./blyg/items/{$id}.json", $doc ),
			'the origin untidy, the address plain'       => array( 'https://reader.example/x/../blyg/', self::THEIR_DOC, $doc ),
			'the origin plain, the address untidy'       => array( self::THEIR_ORIGIN, "https://reader.example/x/../blyg/items/{$id}.json", $doc ),
			'templated: dot segments in the origin'      => array( 'https://reader.example/x/../blyg/', "https://reader.example/api/items/{$id}", $tpl ),
			'templated: the document\'s address untidy'  => array( self::THEIR_ORIGIN, "https://reader.example/x/../api/items/{$id}", $tpl ),
		);
	}

	public function test_v2_a_page_is_recorded_only_when_it_is_under_the_origin_that_spoke() {
		global $wpdb;
		// Bob verifies honestly as himself, and says his page is one of Alice's.
		$bob       = 'https://reader.example/bob/blyg/';
		$document  = $bob . 'items/' . self::THEIR_ID . '.json';
		$this->web = array( $document => array( 200, $this->their_document( array( 'origin' => $bob, 'page' => 'https://reader.example/alice/blyg/t/hers/', 'stub_of' => $this->our_reference() ) ) ) );
		$this->assertSame( Store::VERIFIED, $this->outcome_for( $document )[0] );
		$row = Plugin::instance()->mentions->by_pair( $document, home_url( '/notes/a/' ) );
		$this->assertSame( $bob, $row['source_origin'] );
		$this->assertSame( $document, $row['source_page'], 'a page outside the origin is not kept: the source address stands in' );

		foreach ( array( 't/mine/' => $bob . 't/mine/', $bob . 't/mine/' => $bob . 't/mine/', '../../alice/blyg/t/hers/' => $document, 'https://elsewhere.example/bob/blyg/t/x/' => $document ) as $page => $kept ) {
			$this->web = array( $document => array( 200, $this->their_document( array( 'origin' => $bob, 'page' => $page, 'stub_of' => $this->our_reference() ) ) ) );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', \SoapboxBlyg\Ledger\Schema::table( 'mentions' ) ) ); // phpcs:ignore
			$this->outcome_for( $document );
			$this->assertSame( $kept, Plugin::instance()->mentions->by_pair( $document, home_url( '/notes/a/' ) )['source_page'], $page );
		}
	}

	/**
	 * V3. A manifest that could not be read says nothing about whose document it is.
	 *
	 * @dataProvider manifest_failures
	 */
	public function test_v3_a_manifest_that_fails_is_reported_as_what_went_wrong( array $answer, string $reason ) {
		$document  = 'https://reader.example/api/items/' . self::THEIR_ID;
		$this->web = array(
			$document                        => array( 200, $this->their_document( array( 'stub_of' => $this->our_reference() ) ) ),
			self::THEIR_ORIGIN . 'blyg.json' => $answer,
		);
		$this->assertSame( array( Store::FAILED, $reason ), $this->outcome_for( $document ) );
	}

	public function manifest_failures(): array {
		return array(
			'forbidden'             => array( array( 403, '' ), 'http-403' ),
			'rate limited'          => array( array( 429, '' ), 'http-429' ),
			'compressed'            => array( array( 200, 'x', array( 'content-encoding' => 'gzip' ) ), 'encoded' ),
			'too large'             => array( array( 200, str_repeat( ' ', Verifier::MAX_BYTES + 1 ) ), 'too-large' ),
			// There is no manifest, so no template leads to the document: that one is a mismatch.
			'not found'             => array( array( 404, '' ), 'origin-mismatch' ),
			'gone'                  => array( array( 410, '' ), 'origin-mismatch' ),
		);
	}

	public function test_v6_a_template_with_anything_but_id_in_braces_is_not_one() {
		$id = self::THEIR_ID;
		foreach ( array( 'https://reader.example/api/{+x}/items/{id}', 'https://reader.example/api/{x}/items/{id}', 'https://reader.example/api/}/items/{id}' ) as $template ) {
			$document  = str_replace( '{id}', $id, $template );
			$this->web = array(
				(string) ( new \WpOrg\Requests\Iri( $document ) )->uri => array( 200, $this->their_document( array( 'stub_of' => $this->our_reference() ) ) ),
				self::THEIR_ORIGIN . 'blyg.json' => array( 200, wp_json_encode( array( 'blyg' => '0.3', 'item' => $template ) ) ),
			);
			$this->assertSame( array( Store::FAILED, 'origin-mismatch' ), $this->outcome_for( $document ), $template );
		}
	}
}
