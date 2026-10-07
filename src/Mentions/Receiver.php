<?php
/**
 * The Webmention endpoint.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Mentions;

use SoapboxBlyg\Origins\Origin;
use SoapboxBlyg\Surface\Http;
use SoapboxBlyg\Surface\Locations;

defined( 'ABSPATH' ) || exit;

/**
 * Receives mentions at {origin}webmention (spec §15.3): a POST of `source` and `target` saying
 * that the item at one URL references the item at the other.
 *
 * This is the protocol's one dynamic surface, and the one a stranger can drive without
 * subscribing to anything: every accepted claim makes this site fetch a URL the sender chose. So
 * the request is answered without any outbound request, everything checkable is checked first,
 * the caps are applied before anything is queued, and the fetching happens later, in Verifier,
 * under hard bounds. The receiver is off unless the site owner switches it on.
 *
 * The endpoint is public by protocol: there is no login and no nonce to check, any more than
 * there is on a contact form's action URL.
 */
class Receiver {

	const VERIFY_HOOK = 'soapbox_blyg_verify_mention';
	const SWEEP_HOOK  = 'soapbox_blyg_sweep_mentions';

	const MAX_URL_LENGTH = 2000;

	/**
	 * The store.
	 *
	 * @var Store
	 */
	private $store;

	/**
	 * Where documents live.
	 *
	 * @var Locations
	 */
	private $locations;

	/**
	 * Constructor.
	 *
	 * @param Store     $store     The store.
	 * @param Locations $locations Where documents live.
	 */
	public function __construct( Store $store, Locations $locations ) {
		$this->store     = $store;
		$this->locations = $locations;
	}

	/**
	 * The limits, per clock hour unless noted. A claim over any of them is refused with 429 and
	 * never queued.
	 *
	 * @return array { client, client_pending, host, origin, pending, outbound, outbound_client,
	 *               resend_seconds }
	 */
	public static function limits(): array {
		/**
		 * Filters the Webmention receiver's limits.
		 *
		 * @param array $limits {
		 *     @type int $client         Requests from one client network per hour, whatever they name and whatever becomes of them.
		 *     @type int $client_pending Claims from one client network that may be waiting at once.
		 *     @type int $host           Claims from one source host per hour. The protocol's figure is 60.
		 *     @type int $origin         Claims accepted for one origin per hour, whatever their source.
		 *     @type int $pending        Claims waiting to be verified, across the site.
		 *     @type int $outbound        Verification requests sent to one network per hour.
		 *     @type int $outbound_client Of those, the most that claims from one client may use.
		 *     @type int $resend_seconds How soon the same source and target may be verified again.
		 * }
		 */
		return (array) apply_filters(
			'soapbox_blyg_webmention_limits',
			array(
				'host'            => 60,
				'client'          => 300,
				'client_pending'  => 50,
				'outbound'        => 300,
				// A quarter: four senders at their limit would otherwise be needed to shut the rest out of a destination; one cannot.
				'outbound_client' => 75,
				// Ten clients' worth: two senders at their own limit must not be able to shut the rest out.
				'origin'          => 3000,
				'pending'         => 1000,
				'resend_seconds'  => 10 * MINUTE_IN_SECONDS,
			)
		);
	}

	/**
	 * A plain-text response that no cache may hold.
	 *
	 * @param int    $status HTTP status.
	 * @param string $body   One line saying what happened.
	 * @param array  $more   Extra response fields.
	 */
	private static function reply( int $status, string $body, array $more = array() ): array {
		return array(
			'status' => $status,
			'type'   => Http::TEXT,
			'body'   => $body . "\n",
			'cache'  => false,
		) + $more;
	}

	/**
	 * Handles a request to {origin}webmention.
	 *
	 * @param Origin $origin The origin.
	 * @param string $method The request method.
	 * @return array A response for Http::send().
	 */
	public function handle( Origin $origin, string $method ): array {
		if ( 'POST' !== $method ) {
			return self::reply( 405, 'This is a Webmention endpoint. Send a POST with source and target.', array( 'allow' => 'POST' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- a public protocol endpoint; see the class comment.
		$source = isset( $_POST['source'] ) && is_string( $_POST['source'] ) ? esc_url_raw( sanitize_text_field( wp_unslash( $_POST['source'] ) ), array( 'http', 'https' ) ) : '';
		$target = isset( $_POST['target'] ) && is_string( $_POST['target'] ) ? esc_url_raw( sanitize_text_field( wp_unslash( $_POST['target'] ) ), array( 'http', 'https' ) ) : '';
		// phpcs:enable

		return $this->receive( $origin, $source, $target, self::client() );
	}

	/**
	 * Who is sending this request, as far as a rate limit needs to know: a keyed hash of the
	 * network the request came from (a /24, or a /48 in IPv6). The address itself is not kept.
	 *
	 * The limits by source bound what one publication can claim. This one bounds what one
	 * machine can send, whatever publications it names.
	 *
	 * @return string 32 hex characters, or '' when the address is not known.
	 */
	public static function client(): string {
		$address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		/**
		 * Filters the address a Webmention request is taken to come from.
		 *
		 * Behind a reverse proxy or a CDN, REMOTE_ADDR is the proxy's address, and every sender
		 * would share one limit. A site in that position returns the visitor's address here, from
		 * the header its proxy sets. Only do that for a header the proxy overwrites: one a sender
		 * can set for itself lets it choose its own limit.
		 *
		 * @param string $address REMOTE_ADDR.
		 */
		$address = (string) apply_filters( 'soapbox_blyg_webmention_client', $address );
		return false !== filter_var( $address, FILTER_VALIDATE_IP ) ? wp_hash( Address::network_of( $address ) ) : '';
	}

	/**
	 * Checks a claim and queues it. Makes no outbound request.
	 *
	 * @param Origin $origin The origin.
	 * @param string $source Source URL.
	 * @param string $target Target URL.
	 * @param string $client Who sent it: client(). '' applies no limit by sender.
	 * @return array A response for Http::send().
	 */
	public function receive( Origin $origin, string $source, string $target, string $client = '' ): array {
		// Every request a client makes is counted against it before anything else is done, and
		// whatever becomes of it: a claim that is refused has still cost this site the work of
		// refusing it.
		$limits  = self::limits();
		$refused = self::reply( 429, 'Too many mentions. Try again later.', array( 'retry_after' => HOUR_IN_SECONDS ) );
		if ( '' !== $client && ! $this->store->take( 'client', $client, (int) $limits['client'] ) ) {
			return $refused;
		}

		// Syntax (spec §15.3): both absolute http(s) URLs, and not the same URL.
		if ( '' === $source || '' === $target || strlen( $source ) > self::MAX_URL_LENGTH || strlen( $target ) > self::MAX_URL_LENGTH ) {
			return self::reply( 400, 'source and target must both be absolute http(s) URLs.' );
		}
		if ( '' === Locations::http_origin( $source ) || '' === Locations::http_origin( $target ) ) {
			return self::reply( 400, 'source and target must both be absolute http(s) URLs.' );
		}
		if ( untrailingslashit( strtok( $source, '#' ) ) === untrailingslashit( strtok( $target, '#' ) ) ) {
			return self::reply( 400, 'source and target are the same URL.' );
		}

		// The target must be on this site and name an item of this origin, by its page or by its
		// item document. An unknown item is 400, not 404: the endpoint exists, the claim is bad. A
		// withdrawn target is accepted, since people may respond to a withdrawal.
		if ( Locations::http_origin( $target ) !== Locations::http_origin( home_url( '/' ) ) ) {
			return self::reply( 400, 'target is not on this site.' );
		}
		$item = $this->locations->item_for_url( $origin, $target );
		if ( ! $item ) {
			return self::reply( 400, 'target does not name an item of this blyg.' );
		}

		$existing = $this->store->by_pair( $source, $target );
		if ( $existing ) {
			if ( Store::PENDING === $existing['status'] ) {
				return self::reply( 202, 'Already queued for verification.' ); // A re-sent pair re-verifies; it never duplicates.
			}
			if ( time() - (int) strtotime( $existing['received_at'] ) < (int) $limits['resend_seconds'] ) {
				return self::reply( 429, 'This mention was verified a moment ago. Try again later.', array( 'retry_after' => (int) $limits['resend_seconds'] ) );
			}
		}

		// What can be told from the URL alone is told now: its form, its port, an address written
		// out as a literal. A host name is not looked up here. Resolving is slow, the sender chooses
		// how slow, and this is an anonymous request; it is the job's work, where it is bounded.
		if ( null === Address::addresses( $source, false ) ) {
			return self::reply( 400, 'source is not a public web address.' );
		}

		// The queue's own size, and one client's share of it, are counted from the rows; that
		// check and the insert are one step, so two requests cannot both take the last place.
		// Only a claim there is room for is charged to its host and to the origin, each by an
		// atomic counter (a pair sent again is another claim): a claim that is turned away for
		// want of room must not also use up what somebody else could have had.
		$host = Address::normalize_host( (string) wp_parse_url( $source, PHP_URL_HOST ) );
		$id   = $this->store->with_lock(
			function () use ( $origin, $client, $limits, $item, $source, $target, $host ) {
				$waiting = $this->store->waiting( $client );
				if ( $waiting['pending'] >= (int) $limits['pending'] || ( '' !== $client && $waiting['client_pending'] >= (int) $limits['client_pending'] ) ) {
					return 0;
				}
				if ( ! $this->store->take( 'host', $host, (int) $limits['host'] ) || ! $this->store->take( 'origin', $origin->key, (int) $limits['origin'] ) ) {
					return 0;
				}
				return $this->store->accept( $origin->key, $item['blyg_id'], $source, $target, $client );
			}
		);
		if ( ! $id ) {
			return $refused;
		}
		self::schedule( $id );
		return self::reply( 202, 'Accepted. The mention will be verified.' );
	}

	/**
	 * Queues one mention for verification: Action Scheduler when another plugin has loaded it,
	 * because it does not depend on a visitor arriving; WP-Cron otherwise. The hourly sweep picks
	 * up whatever either leaves behind.
	 *
	 * @param int $id    Mention id.
	 * @param int $delay Seconds to wait.
	 */
	public static function schedule( int $id, int $delay = 0 ): void {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $delay, self::VERIFY_HOOK, array( $id ), SOAPBOX_BLYG_SLUG );
			return;
		}
		wp_schedule_single_event( time() + $delay, self::VERIFY_HOOK, array( $id ) );
	}

	/**
	 * Keeps the hourly sweep scheduled while the receiver is on, and unscheduled while it is off.
	 *
	 * @param bool $on Whether the receiver is on.
	 */
	public static function sync_sweep( bool $on ): void {
		$next = wp_next_scheduled( self::SWEEP_HOOK );
		if ( $on && ! $next ) {
			// The first sweep runs straight away: if the receiver was off for a while, claims queued
			// before then are still waiting, and this is what picks them up.
			wp_schedule_event( time(), 'hourly', self::SWEEP_HOOK );
		} elseif ( ! $on && $next ) {
			wp_clear_scheduled_hook( self::SWEEP_HOOK );
		}
	}
}
