<?php
/**
 * Structural verification of a mention.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Mentions;

use SoapboxBlyg\Ledger\Id;
use SoapboxBlyg\Ledger\Ledger;
use SoapboxBlyg\Origins\Registry;
use SoapboxBlyg\Render\Sanitizer;
use SoapboxBlyg\Surface\Documents;
use SoapboxBlyg\Surface\Locations;
use WP_HTML_Tag_Processor;
use WpOrg\Requests\Iri;
use WP_HTTP_Proxy;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether a mention is true (spec §15.4): the source's item document, fetched from the
 * origin it claims, must name the target in a machine-readable reference. A page that merely
 * links to the target proves nothing; that is the hole trackbacks had.
 *
 * This is the only code in the plugin that makes an outbound HTTP request, it only ever runs from
 * the queue, never during a page view, and it is switched off with the receiver. Every bound here
 * is a hard limit, because the URL was chosen by a stranger:
 *
 * - at most three fetches per verification: the source; the item document it points at, when
 *   the source is a page; and the manifest of the origin the document names, when the document
 *   is not at that origin's fixed address for it;
 * - at most three redirects per fetch and six requests per verification, redirects included;
 * - five seconds and one megabyte per request, uncompressed: a compressed body is refused rather
 *   than inflated, because inflating is where a small response becomes a large one;
 * - no private or reserved addresses. Redirects are followed here, one hop at a time, rather than
 *   by the HTTP transport, so that every hop passes the same address check as the first URL, and
 *   every hop is counted against the network it goes to;
 * - a claim is tried twice at most, and the try is recorded before it is made, so that a fetch
 *   which kills the process cannot be repeated for ever. It can also be put off three times
 *   when a destination has had its hour's requests, so the most one claim can cost, over days,
 *   is five runs of six requests.
 *
 * Nothing fetched is stored or shown. A verified mention records a pointer: which item, at which
 * origin and version, by whom, and how it refers to ours.
 */
class Verifier {

	const MAX_FETCHES   = 3;
	const MAX_REDIRECTS = 3;
	const MAX_REQUESTS  = 6;
	const MAX_ATTEMPTS  = 2;

	/**
	 * How long one verification may take in all, lookups included. The HTTP timeout bounds a
	 * request once it is connecting; it does not bound the resolving that comes before, and the
	 * sender controls how slowly its name resolves. So the whole job has a budget, checked before
	 * every step that can wait, and the time left is the most any request is given.
	 */
	const JOB_SECONDS = 20;

	/**
	 * A claim put off until the destination's next hour is run a little after the hour turns, by
	 * a random number of seconds between these, so that everything put off does not start at once.
	 */
	const DEFER_SPREAD_MIN = 5;
	const DEFER_SPREAD_MAX = 2 * MINUTE_IN_SECONDS;

	/**
	 * How long a worker holds a job. Well beyond what a job can take even if every lookup in it
	 * runs to the system resolver's own timeout, which the job's budget cannot cut short; so only
	 * a worker that died loses its lease.
	 */
	const LEASE_SECONDS = 10 * MINUTE_IN_SECONDS;

	/**
	 * How many times a claim is put off for a busy destination before it is failed for it.
	 */
	const MAX_DEFERRALS = 3;
	const TIMEOUT       = 5;
	const MAX_BYTES     = 1048576;

	/**
	 * One more try after a network failure or a 5xx, this long after the first.
	 */
	const RETRY_DELAY = 15 * MINUTE_IN_SECONDS;

	/**
	 * How long a claim may wait to be verified before it is given up on.
	 */
	const PENDING_LIFETIME = 7 * DAY_IN_SECONDS;

	/**
	 * Mentions verified per sweep. Each has JOB_SECONDS at most, and SWEEP_SECONDS ends the run.
	 */
	const SWEEP_BATCH = 20;

	/**
	 * How long one sweep goes on starting verifications.
	 */
	const SWEEP_SECONDS = 40;

	/**
	 * The most that is kept of anything the source's document says about itself. The document can
	 * be a megabyte, and a sender who really does reference us gets to have these fields stored.
	 */
	const MAX_STORED_URL    = 2000;
	const MAX_STORED_AUTHOR = 2000;

	/**
	 * The store.
	 *
	 * @var Store
	 */
	private $store;

	/**
	 * The registry.
	 *
	 * @var Registry
	 */
	private $registry;

	/**
	 * The ledger, which alone knows which of our versions are pinned.
	 *
	 * @var Ledger
	 */
	private $ledger;

	/**
	 * Fetches made in the current verification.
	 *
	 * @var int
	 */
	private $fetches = 0;

	/**
	 * Requests made in the current verification, redirect hops included.
	 *
	 * @var int
	 */
	private $requests = 0;

	/**
	 * When the current verification must be over: microtime.
	 *
	 * @var float
	 */
	private $deadline = 0.0;

	/**
	 * Who sent the claim being verified (Receiver::client()), or ''.
	 *
	 * @var string
	 */
	private $client = '';

	/**
	 * Returns the checked addresses of a URL's host, or null if it may not be fetched:
	 * Address::addresses().
	 *
	 * @var callable
	 */
	private $addresses_of;

	/**
	 * The request in flight: { url, host, port, address, pinned, overflow }, or null.
	 *
	 * @var array|null
	 */
	private $pin = null;

	/**
	 * Constructor.
	 *
	 * @param Store         $store         The store.
	 * @param Registry      $registry      Origin registry.
	 * @param Ledger        $ledger        The ledger.
	 * @param callable|null $addresses_of  Address check; tests replace it to run without a resolver.
	 */
	public function __construct( Store $store, Registry $registry, Ledger $ledger, $addresses_of = null ) {
		$this->store        = $store;
		$this->registry     = $registry;
		$this->ledger       = $ledger;
		$this->addresses_of = is_callable( $addresses_of ) ? $addresses_of : array( Address::class, 'addresses' );
		add_action( 'http_api_curl', array( $this, 'pin_connection' ), 10, 3 );
	}

	/**
	 * Makes curl connect to the address that was checked, rather than resolving the name again, and
	 * stop reading once the response is over the size limit.
	 *
	 * The address check and the request would otherwise each do their own DNS lookup, and a
	 * hostile resolver can answer the two differently (DNS rebinding). With the connection pinned,
	 * what was judged public is what is fetched. This acts only on the one request this verifier
	 * has in flight; every other request on the site is left alone. fetch() refuses to use a
	 * response this hook did not see.
	 *
	 * Core's own size limit stops keeping the body but goes on receiving it until the timeout. The
	 * progress callback ends the transfer instead.
	 *
	 * @param resource|\CurlHandle $handle      The curl handle.
	 * @param array                $parsed_args The request's arguments.
	 * @param string               $url         The request URL.
	 */
	public function pin_connection( $handle, $parsed_args, $url ): void {
		unset( $parsed_args );
		if ( null === $this->pin || $this->pin['url'] !== $url ) {
			return;
		}
		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt -- inside core's http_api_curl hook, which exists for setting options core's HTTP API does not expose.
		if ( '' !== $this->pin['address'] ) {
			$address = false !== strpos( $this->pin['address'], ':' ) ? '[' . $this->pin['address'] . ']' : $this->pin['address'];
			curl_setopt( $handle, CURLOPT_RESOLVE, array( $this->pin['host'] . ':' . $this->pin['port'] . ':' . $address ) );
		}
		curl_setopt( $handle, CURLOPT_NOPROGRESS, false );
		curl_setopt(
			$handle,
			CURLOPT_PROGRESSFUNCTION,
			function ( $handle, $expected, $received ) {
				unset( $handle );
				if ( null !== $this->pin && max( $expected, $received ) > self::MAX_BYTES ) {
					$this->pin['overflow'] = true;
					return 1; // Anything but zero aborts the transfer.
				}
				return 0;
			}
		);
		// phpcs:enable
		$this->pin['pinned'] = true;
	}

	/**
	 * Why this site cannot make a pinned request, or null if it can. Without curl there is no way
	 * to fix the address a request connects to, and through a proxy it is the proxy that resolves
	 * the name. In either case the address check would be a check of something else, so nothing is
	 * fetched.
	 *
	 * @param string $url The URL that would be fetched.
	 * @return string|null no-curl, proxy or null.
	 */
	public static function cannot_pin( string $url ) {
		if ( ! function_exists( 'curl_init' ) || ! function_exists( 'curl_exec' ) ) {
			return 'no-curl';
		}
		$proxy = new WP_HTTP_Proxy();
		if ( $proxy->is_enabled() && $proxy->send_through_proxy( $url ) ) {
			return 'proxy';
		}
		return null;
	}

	/**
	 * Verifies mentions the queue left pending for more than five minutes. Runs hourly, so a
	 * stalled WP-Cron loses nothing.
	 */
	public function sweep(): void {
		if ( ! Documents::receives_mentions() ) {
			return;
		}
		// A claim that has waited a week will not be verified now: the sender has long stopped
		// expecting an answer. Expiring it keeps the pending cap from silting up with old rows,
		// which matters most after the receiver has been off for a while.
		$this->store->expire_pending( gmdate( 'Y-m-d\TH:i:s\Z', time() - self::PENDING_LIFETIME ) );
		// Housekeeping first, and the follow-up booked before the work: a run that is killed
		// part-way has still done both.
		$this->store->prune();
		$ids = $this->store->pending_before( gmdate( 'Y-m-d\TH:i:s\Z', time() - 5 * MINUTE_IN_SECONDS ), self::SWEEP_BATCH );
		if ( count( $ids ) === self::SWEEP_BATCH && ! wp_next_scheduled( Receiver::SWEEP_HOOK . '_more' ) ) {
			wp_schedule_single_event( time() + 2 * MINUTE_IN_SECONDS, Receiver::SWEEP_HOOK . '_more' );
		}
		$started = microtime( true );
		foreach ( $ids as $id ) {
			$this->verify( $id );
			if ( microtime( true ) - $started > self::SWEEP_SECONDS ) {
				break; // The rest are still pending, and the next sweep has them.
			}
		}
	}

	/**
	 * Verifies one mention and records the outcome.
	 *
	 * @param int $id Mention id.
	 */
	public function verify( $id ): void {
		$id      = (int) $id;
		$mention = $this->store->get( $id );
		if ( ! $mention || Store::PENDING !== $mention['status'] || ! Documents::receives_mentions() ) {
			return;
		}
		if ( ! $this->registry->has( $mention['origin_key'] ) ) {
			return; // The origin is not registered on this request; the sweep will come back to it.
		}

		// The job is claimed before anything is fetched: one statement gives it to one worker and
		// counts the attempt. A second worker with the same row in hand gets nothing and stops. If
		// a fetch ends the process, the row still says it was tried, and once its lease has run
		// out a claim that has used its attempts is failed without another.
		$claim = $this->store->claim( $id, self::MAX_ATTEMPTS, self::LEASE_SECONDS );
		if ( null === $claim ) {
			$this->store->give_up( $id, self::MAX_ATTEMPTS );
			return;
		}
		$lease          = $claim['lease'];
		$attempts       = $claim['attempts'];
		$this->fetches  = 0;
		$this->requests = 0;
		$this->deadline = microtime( true ) + self::JOB_SECONDS;
		$this->client   = (string) $mention['client'];
		$outcome        = $this->examine( $mention );

		// The network the source is on has had its hour's requests from this site. That is no
		// fault of this claim, which may be one of many under names that share an address, some
		// of them honest. It is put off until the hour turns, and the attempt is not counted.
		// Three times at most. After that the destination is taken to be busy for good, and the
		// claim fails, as a claim to an unreachable host does.
		if ( 'defer' === $outcome['status'] && (int) $mention['deferrals'] >= self::MAX_DEFERRALS ) {
			$outcome = array(
				'status' => Store::FAILED,
				'reason' => 'destination-busy',
			);
		}
		if ( 'defer' === $outcome['status'] ) {
			$wait = HOUR_IN_SECONDS - ( time() % HOUR_IN_SECONDS ) + wp_rand( self::DEFER_SPREAD_MIN, self::DEFER_SPREAD_MAX );
			$kept = $this->store->finish(
				$id,
				$lease,
				array(
					'attempts'  => max( 0, $attempts - 1 ),
					'deferrals' => (int) $mention['deferrals'] + 1,
					'next_try'  => gmdate( 'Y-m-d\TH:i:s\Z', time() + $wait ),
					'reason'    => 'destination-busy',
				)
			);
			if ( $kept ) {
				Receiver::schedule( $id, $wait );
			}
			return;
		}
		if ( 'retry' === $outcome['status'] ) {
			if ( $attempts < self::MAX_ATTEMPTS ) {
				// Still pending, and nobody's: the retry claims it afresh, and not before its time.
				if ( $this->store->finish( $id, $lease, array( 'next_try' => gmdate( 'Y-m-d\TH:i:s\Z', time() + self::RETRY_DELAY ) ) ) ) {
					Receiver::schedule( $id, self::RETRY_DELAY );
				}
				return;
			}
			$outcome['status'] = Store::FAILED;
		}

		$fields = array(
			'status' => $outcome['status'],
			'reason' => isset( $outcome['reason'] ) ? $outcome['reason'] : null,
		);
		if ( Store::VERIFIED === $outcome['status'] ) {
			$fields += $outcome['record'] + array( 'verified_at' => Ledger::now() );
		} elseif ( Store::FAILED === $outcome['status'] && null !== $mention['verified_at'] ) {
			// It verified before and does not now: the reference was taken back. The row stays, so a
			// responder who withdraws and later republishes is recognised.
			$fields['status'] = Store::GONE;
		}
		// The outcome is this worker's to record only while the lease is. If the claim was sent
		// again meanwhile it has started afresh, and what was found here is about the old one.
		if ( ! $this->store->finish( $id, $lease, $fields ) ) {
			return;
		}

		/**
		 * Fires when a mention has been verified, or has stopped verifying.
		 *
		 * @param string $status  verified, failed or gone.
		 * @param array  $mention The mention row as it was before this verification.
		 * @param array  $fields  What was recorded.
		 */
		do_action( 'soapbox_blyg_mention', $fields['status'], $mention, $fields );
	}

	/**
	 * Fetches the source and reads its reference to us.
	 *
	 * @param array $mention The mention row.
	 * @return array { status: verified|failed|gone|retry, reason?: string, record?: array }
	 */
	private function examine( array $mention ): array {
		$failed = static function ( string $reason ): array {
			return array(
				'status' => Store::FAILED,
				'reason' => $reason,
			);
		};

		// The source's name is resolved here, in the job, and not while answering the sender.
		$addresses = call_user_func( $this->addresses_of, $mention['source_url'] );
		if ( microtime( true ) > $this->deadline ) {
			return $failed( 'too-slow' );
		}
		if ( null === $addresses ) {
			return $failed( 'private-address' );
		}

		$source = $this->fetch( $mention['source_url'] );
		if ( isset( $source['error'] ) && 'destination-busy' === $source['error'] ) {
			return array( 'status' => 'defer' );
		}
		if ( isset( $source['error'] ) ) {
			return 'unreachable' === $source['error'] ? array(
				'status' => 'retry',
				'reason' => 'unreachable',
			) : $failed( $source['error'] );
		}

		// If the source is the item document, that is it. If it is a page, the page says where its
		// document is (spec §5.8), and that is the second fetch.
		$document     = self::as_item_document( $source['body'] );
		$document_url = $source['url'];
		if ( null === $document ) {
			$alternate = self::alternate_json( $source['body'], $source['url'] );
			if ( '' === $alternate ) {
				return $failed( 'not-a-blyg-item' );
			}
			$second = $this->fetch( $alternate );
			if ( isset( $second['error'] ) && 'destination-busy' === $second['error'] ) {
				return array( 'status' => 'defer' );
			}
			if ( isset( $second['error'] ) ) {
				return 'unreachable' === $second['error'] ? array(
					'status' => 'retry',
					'reason' => 'unreachable',
				) : $failed( $second['error'] );
			}
			$document     = self::as_item_document( $second['body'] );
			$document_url = $second['url'];
			if ( null === $document ) {
				return $failed( 'not-a-blyg-item' );
			}
		}

		// A document is believed to speak for an origin only if it was fetched from that origin's
		// own address for that item (spec §15.4 step 2, blygger-spec decision #61). Scheme, host and
		// port are not enough: two blygs can share a host, and one could then speak as the other.
		$claimed = isset( $document['origin'] ) && is_string( $document['origin'] ) ? $document['origin'] : '';
		$id      = isset( $document['id'] ) && Id::is_valid( $document['id'] ) ? $document['id'] : '';
		$speaker = self::identity( $claimed );
		// An origin is a path (decision #64): one written with a query or a fragment names no blyg.
		if ( '' === $speaker || '' === $id || false !== strpbrk( $claimed, '?#' ) || Locations::http_origin( $source['url'] ) !== Locations::http_origin( $claimed ) ) {
			return $failed( 'origin-mismatch' );
		}
		// Nor does an origin, or a document's address, that the HTTP library would rewrite before
		// asking: it would be fetched from one place and recorded as another.
		if ( ! self::is_plain( $claimed ) || ! self::is_plain( $document_url ) ) {
			return $failed( 'origin-mismatch' );
		}
		$fetched_from = self::address( $document_url );
		if ( $speaker . 'items/' . $id . '.json' !== $fetched_from ) {
			// Not at the fixed address, so the origin may publish its items by template (§16.6e).
			// Its manifest says where, and is the third and last document fetched: the page, the
			// item document, and this. It is asked for at the claimed origin and nowhere else, so
			// a redirect away from that address is not an answer.
			$manifest = $this->fetch( $speaker . 'blyg.json' );
			if ( isset( $manifest['error'] ) && 'destination-busy' === $manifest['error'] ) {
				return array( 'status' => 'defer' );
			}
			if ( isset( $manifest['error'] ) && 'unreachable' === $manifest['error'] ) {
				return array(
					'status' => 'retry',
					'reason' => 'unreachable',
				);
			}
			// No manifest there means no template leads to the document. Any other failure is
			// about the fetch and says nothing of whose document it is, so it is reported as itself.
			if ( isset( $manifest['error'] ) && ! in_array( $manifest['error'], array( 'http-404', 'http-410' ), true ) ) {
				return $failed( $manifest['error'] );
			}
			$template = isset( $manifest['body'] ) && self::is_plain( $manifest['url'] ) && self::address( $manifest['url'] ) === $speaker . 'blyg.json' ? json_decode( $manifest['body'], true ) : null;
			$template = is_array( $template ) && isset( $template['item'] ) && is_string( $template['item'] ) ? $template['item'] : '';
			// {id} is the only expression a template may hold (URI Template level 1, spec §16.6e).
			$expanded = str_replace( '{id}', rawurlencode( $id ), $template );
			if ( false === strpos( $template, '{id}' ) || false !== strpbrk( $expanded, '{}' ) || self::address( $expanded ) !== $fetched_from ) {
				return $failed( 'origin-mismatch' );
			}
		}

		if ( isset( $document['kind'] ) && 'withdrawn' === $document['kind'] ) {
			return array(
				'status' => Store::GONE,
				'reason' => 'source-withdrawn',
			);
		}

		$relation = $this->relation( $document, $this->registry->get( $mention['origin_key'] )->url(), $mention['target_id'] );
		if ( null === $relation ) {
			return $failed( 'no-reference' ); // The document exists but does not reference us.
		}

		// The page is what an administrator is shown and may click. It is kept only when it lies
		// under the origin that has just been verified: a document can otherwise name anyone's page.
		$page = isset( $document['page'] ) && is_string( $document['page'] ) ? Sanitizer::clean_url( $document['page'], $claimed, false ) : '';
		if ( '' !== $page && ( ! self::is_plain( $page ) || 0 !== strpos( self::address( $page ), $speaker ) ) ) {
			$page = '';
		}
		if ( strlen( $claimed ) > self::MAX_STORED_URL ) {
			return $failed( 'origin-too-long' );
		}
		if ( strlen( $page ) > self::MAX_STORED_URL ) {
			$page = '';
		}

		// The author object passes through verbatim (spec §5.5) when it is of a sensible size. An
		// oversized one is cut down to its name, which is all that is ever shown.
		$author = null;
		if ( isset( $document['author'] ) && is_array( $document['author'] ) ) {
			$author = Ledger::json( (object) $document['author'] );
			if ( strlen( $author ) > self::MAX_STORED_AUTHOR ) {
				$name   = isset( $document['author']['name'] ) && is_string( $document['author']['name'] ) ? mb_substr( $document['author']['name'], 0, 200 ) : '';
				$author = '' !== $name ? Ledger::json( (object) array( 'name' => $name ) ) : null;
			}
		}
		return array(
			'status' => Store::VERIFIED,
			'record' => array(
				'relation'       => $relation,
				'source_origin'  => $claimed,
				'source_id'      => isset( $document['id'] ) && Id::is_valid( $document['id'] ) ? $document['id'] : null,
				'source_version' => isset( $document['version'] ) ? (int) $document['version'] : null,
				'source_kind'    => isset( $document['kind'] ) ? substr( (string) $document['kind'], 0, 16 ) : null,
				'source_author'  => $author,
				'source_page'    => '' !== $page ? $page : $mention['source_url'],
			),
		);
	}

	/**
	 * How a document refers to one of our items, if it does (spec §15.4), in the protocol's order:
	 * a stub of it, a transclusion of it, or a fork of a pinned version of it. Only the three
	 * identity members of a reference are read; `cited` and `selector` are never consulted.
	 *
	 * @param array  $document  The source's item document.
	 * @param string $our_url   This origin's URL: its identity.
	 * @param string $target_id Our item's id.
	 * @return string|null stub, transclusion, fork or null.
	 */
	private function relation( array $document, string $our_url, string $target_id ) {
		$ours  = self::identity( $our_url );
		$names = static function ( $reference ) use ( $ours, $target_id ): bool {
			return is_array( $reference )
				&& isset( $reference['origin'], $reference['id'] )
				&& is_string( $reference['origin'] )
				&& self::identity( $reference['origin'] ) === $ours
				&& $reference['id'] === $target_id;
		};

		if ( isset( $document['stub_of'] ) && $names( $document['stub_of'] ) ) {
			return 'stub';
		}
		if ( isset( $document['transclusions'] ) && is_array( $document['transclusions'] ) ) {
			foreach ( $document['transclusions'] as $entry ) {
				if ( $names( $entry ) ) {
					return 'transclusion';
				}
			}
		}
		if ( isset( $document['forked_from'] ) && $names( $document['forked_from'] ) ) {
			// Only we can say whether a version of ours is pinned, so a fork naming an unpinned
			// version fails here, at no extra fetch.
			$version = isset( $document['forked_from']['version'] ) ? (int) $document['forked_from']['version'] : 0;
			$row     = $version > 0 ? $this->ledger->version_row( $target_id, $version ) : null;
			if ( $row && (int) $row['pinned'] ) {
				return 'fork';
			}
		}
		return null;
	}

	/**
	 * An origin string in the form two publishers who resolved the same blyg would both write:
	 * scheme and host lowercased, default port elided, one trailing slash on the path.
	 *
	 * It also folds leading slashes into one, which address() does not. An origin written
	 * `https://host//blyg/` therefore never matches the address its documents were fetched from,
	 * and fails: stricter than it need be, and on the safe side.
	 *
	 * @param string $url Origin URL.
	 */
	public static function identity( string $url ): string {
		$base = Locations::http_origin( $url );
		if ( '' === $base ) {
			return '';
		}
		return $base . trailingslashit( '/' . ltrim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' ) );
	}

	/**
	 * A URL in the form two addresses are compared in: scheme and host lowercased and a default
	 * port elided, as identity() does, and everything after them left exactly as written. No case
	 * folding, no percent-decoding and no dot segments resolved: a path that merely resolves to
	 * the right place is a different address, and a server may answer it differently.
	 *
	 * @param string $url An absolute http(s) URL.
	 * @return string The address, or '' when it is not one.
	 */
	public static function address( string $url ): string {
		$base = Locations::http_origin( $url );
		if ( '' === $base || ! preg_match( '~^[a-z][a-z0-9+.-]*://[^/?#]*([^#]*)~i', $url, $m ) ) {
			return '';
		}
		return $base . ( '/' === ( $m[1][0] ?? '' ) ? '' : '/' ) . $m[1];
	}

	/**
	 * Whether a URL is written the way it will be asked for. The HTTP library resolves dot
	 * segments and undoes percent-encoding that was not needed before it sends a request, so
	 * `/alice/../bob/` is fetched from `/bob/`. An address compared exactly has to be the one
	 * that was fetched, so one the library would rewrite is refused, not tidied.
	 *
	 * @param string $url An absolute http(s) URL.
	 */
	public static function is_plain( string $url ): bool {
		$address = self::address( $url );
		return '' !== $address && self::address( (string) ( new Iri( $url ) )->uri ) === $address;
	}

	/**
	 * A body as a blyg item document, or null. Content-Type is not trusted; parsing is the test.
	 *
	 * @param string $body Response body.
	 * @return array|null
	 */
	private static function as_item_document( string $body ) {
		$first = ltrim( $body )[0] ?? '';
		if ( '{' !== $first ) {
			return null;
		}
		$document = json_decode( $body, true );
		if ( ! is_array( $document ) || ! isset( $document['blyg'], $document['id'] ) ) {
			return null;
		}
		// The members a mention is recorded from have to be what the protocol says they are. A
		// document may carry anything else: unknown members are ignored, not refused.
		$version = isset( $document['version'] ) ? $document['version'] : null;
		$kind    = isset( $document['kind'] ) ? $document['kind'] : null;
		if ( ! is_string( $document['blyg'] ) || ! Id::is_valid( $document['id'] ) || ! is_int( $version ) || $version < 1 || ! is_string( $kind ) || '' === $kind || strlen( $kind ) > 16 ) {
			return null;
		}
		return $document;
	}

	/**
	 * The item document a page declares with rel="alternate" type="application/json", absolute.
	 *
	 * @param string $html     The page.
	 * @param string $page_url Its final URL.
	 */
	private static function alternate_json( string $html, string $page_url ): string {
		$tags = new WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag( 'link' ) ) {
			$rel  = strtolower( (string) $tags->get_attribute( 'rel' ) );
			$type = strtolower( trim( (string) $tags->get_attribute( 'type' ) ) );
			$href = $tags->get_attribute( 'href' );
			if ( is_string( $href ) && 'application/json' === $type && in_array( 'alternate', preg_split( '/\s+/', trim( $rel ) ), true ) ) {
				return Sanitizer::clean_url( $href, $page_url, false );
			}
		}
		return '';
	}

	/**
	 * Counts one outbound request against the network it goes to, and says whether it may be made.
	 *
	 * The caps on receiving are by where a claim comes from. This one is by where a request goes,
	 * which a redirect can make somewhere else entirely: without it, many senders, or one sender's
	 * many redirects, could point this site's verifier at a single third party.
	 *
	 * @param string $network Address::network_of() of the address being connected to.
	 */
	private function may_request( string $network ): bool {
		$limits = Receiver::limits();
		// One client's claims may use only a share of what a destination is sent in an hour, so
		// that one sender naming a destination again and again cannot keep everybody else's
		// claims about it waiting.
		if ( '' !== $this->client && ! $this->store->take( 'out-client', $this->client . '|' . $network, (int) $limits['outbound_client'] ) ) {
			return false;
		}
		// One statement that raises the count only while it is under the limit: two workers cannot
		// both be given the last request.
		return $this->store->take( 'outbound', $network, (int) $limits['outbound'] );
	}

	/**
	 * One bounded GET, following redirects itself so that each hop is address-checked.
	 *
	 * @param string $url URL.
	 * @return array { url: the final URL, body } or
	 *               { error: unreachable|too-many-fetches|too-many-redirects|too-many-requests|
	 *                 private-address|destination-busy|cannot-pin|encoded|too-large|http-NNN }
	 */
	private function fetch( string $url ): array {
		if ( $this->fetches >= self::MAX_FETCHES ) {
			return array( 'error' => 'too-many-fetches' );
		}
		++$this->fetches;

		for ( $hop = 0; $hop <= self::MAX_REDIRECTS; $hop++ ) {
			if ( $this->requests >= self::MAX_REQUESTS ) {
				return array( 'error' => 'too-many-requests' );
			}
			++$this->requests;
			$left = $this->deadline - microtime( true );
			if ( $left < 1 ) {
				return array( 'error' => 'too-slow' );
			}

			// The name is fetched in the form it was checked in: case and a trailing dot would
			// otherwise make the pin below miss.
			$host   = (string) wp_parse_url( $url, PHP_URL_HOST );
			$normal = Address::normalize_host( $host );
			if ( $host !== $normal ) {
				$url = (string) preg_replace( '#^(https?://)' . preg_quote( $host, '#' ) . '#i', '$1' . $normal, $url, 1 );
			}

			$addresses = call_user_func( $this->addresses_of, $url );
			if ( null === $addresses ) {
				return array( 'error' => 'private-address' );
			}
			// The lookup has no timeout of its own. If it used up the job's time, the job is over.
			$left = $this->deadline - microtime( true );
			if ( $left < 1 ) {
				return array( 'error' => 'too-slow' );
			}
			if ( null !== self::cannot_pin( $url ) ) {
				return array( 'error' => 'cannot-pin' );
			}
			if ( ! $this->may_request( $addresses ? Address::network_of( $addresses[0] ) : 'this-site' ) ) {
				return array( 'error' => 'destination-busy' );
			}

			$scheme    = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
			$port      = wp_parse_url( $url, PHP_URL_PORT );
			$this->pin = array(
				'url'      => $url,
				'host'     => trim( $normal, '[]' ),
				'port'     => $port ? (int) $port : ( 'https' === $scheme ? 443 : 80 ),
				// No addresses means a host the site has allowed by name (its own, say): nothing to pin.
				'address'  => $addresses ? $addresses[0] : '',
				'pinned'   => false,
				'overflow' => false,
			);
			$response  = wp_safe_remote_get(
				$url,
				array(
					'timeout'             => (int) max( 1, min( self::TIMEOUT, floor( $left ) ) ),
					'redirection'         => 0,
					// One byte over the limit is read, so a body at the limit can be told from one beyond it.
					'limit_response_size' => self::MAX_BYTES + 1,
					// Nothing is inflated. A megabyte on the wire is then a megabyte in memory.
					'decompress'          => false,
					'user-agent'          => Documents::generator() . ' (Webmention verification; +' . home_url( '/' ) . ')',
					'headers'             => array(
						'Accept'          => 'application/json, text/html;q=0.9',
						'Accept-Encoding' => 'identity',
					),
					'cookies'             => array(),
				)
			);
			$pin       = $this->pin;
			$this->pin = null;
			if ( ! $pin['pinned'] ) {
				// The request did not go through the hook, so it was not held to the checked address.
				return array( 'error' => 'cannot-pin' );
			}
			if ( $pin['overflow'] ) {
				return array( 'error' => 'too-large' );
			}
			if ( is_wp_error( $response ) ) {
				return array( 'error' => 'unreachable' );
			}
			$status = (int) wp_remote_retrieve_response_code( $response );
			if ( $status >= 300 && $status < 400 ) {
				$location = (string) wp_remote_retrieve_header( $response, 'location' );
				$next     = '' === $location ? '' : Sanitizer::clean_url( $location, $url, false );
				if ( '' === $next ) {
					return array( 'error' => 'http-' . $status );
				}
				$url = $next;
				continue;
			}
			if ( $status >= 500 ) {
				return array( 'error' => 'unreachable' );
			}
			if ( 200 !== $status ) {
				return array( 'error' => 'http-' . $status );
			}
			// A server that compresses when asked not to is not answered by inflating what it sent.
			$encoding = wp_remote_retrieve_header( $response, 'content-encoding' );
			$encoding = strtolower( trim( is_array( $encoding ) ? implode( ',', $encoding ) : (string) $encoding ) );
			if ( '' !== $encoding && 'identity' !== $encoding ) {
				return array( 'error' => 'encoded' );
			}
			$body = (string) wp_remote_retrieve_body( $response );
			if ( strlen( $body ) > self::MAX_BYTES ) {
				return array( 'error' => 'too-large' );
			}
			// $url is where the body actually came from, after redirects: that is who is speaking.
			return array(
				'url'  => $url,
				'body' => $body,
			);
		}
		return array( 'error' => 'too-many-redirects' );
	}
}
