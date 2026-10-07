<?php
/**
 * Snapshot validation.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Ledger;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Rejects a snapshot the protocol would not accept, before anything is written. A failure is the
 * calling source's bug to fix, not a condition to retry.
 */
class Validator {

	/**
	 * Fires for a SHOULD the snapshot misses. Nothing is refused.
	 *
	 * @var callable|null
	 */
	public static $on_warning = null;

	/**
	 * Throws when the snapshot is not publishable.
	 *
	 * @param array $s Snapshot in array form.
	 * @throws InvalidArgumentException With the reason.
	 */
	public static function check( array $s ): void {
		$kind = isset( $s['kind'] ) ? $s['kind'] : null;
		if ( ! in_array( $kind, array( 'fragment', 'thread' ), true ) ) {
			throw new InvalidArgumentException( 'soapbox-blyg: snapshot kind must be fragment or thread' );
		}
		foreach ( array( 'title', 'permalink', 'content_md', 'content_html' ) as $field ) {
			if ( ! isset( $s[ $field ] ) || ! is_string( $s[ $field ] ) ) {
				throw new InvalidArgumentException( sprintf( 'soapbox-blyg: snapshot %s must be a string', esc_html( $field ) ) );
			}
		}
		if ( ! mb_check_encoding( $s['content_md'], 'UTF-8' ) || ! mb_check_encoding( $s['content_html'], 'UTF-8' ) ) {
			throw new InvalidArgumentException( 'soapbox-blyg: snapshot content is not valid UTF-8' );
		}
		// The page is what a mention names and what the feed links to (spec §5.8, §7), so it has to be a URL.
		if ( ! preg_match( '#^https?://#i', $s['permalink'] ) ) {
			throw new InvalidArgumentException( 'soapbox-blyg: snapshot permalink must be an absolute http(s) URL' );
		}
		if ( ! isset( $s['media'] ) || ! is_array( $s['media'] ) ) {
			throw new InvalidArgumentException( 'soapbox-blyg: snapshot media must be an array' );
		}
		foreach ( $s['media'] as $m ) {
			if ( empty( $m['url'] ) || empty( $m['mime'] ) ) {
				throw new InvalidArgumentException( 'soapbox-blyg: every media entry needs url and mime (spec 5.4)' );
			}
		}
		if ( isset( $s['author'] ) && ! is_array( $s['author'] ) && ! is_object( $s['author'] ) ) {
			throw new InvalidArgumentException( 'soapbox-blyg: snapshot author must be an object (spec 5.5)' );
		}

		// Threads always carry transclusions and fragments never do (spec §10.3). The key's presence
		// is what matters, so an empty array on a fragment is still an error.
		if ( 'thread' === $kind ) {
			if ( ! isset( $s['transclusions'] ) || ! is_array( $s['transclusions'] ) ) {
				throw new InvalidArgumentException( 'soapbox-blyg: a thread snapshot must carry a transclusions array' );
			}
		} elseif ( array_key_exists( 'transclusions', $s ) ) {
			throw new InvalidArgumentException( 'soapbox-blyg: a fragment snapshot must not carry transclusions' );
		}

		if ( isset( $s['generated'] ) && ! is_array( $s['generated'] ) ) {
			throw new InvalidArgumentException( 'soapbox-blyg: snapshot generated must be an array when present' );
		}

		// Spec §10.1: the explicit-version directive is reserved, and publishers MUST reject it.
		if ( preg_match( '/^\s*!\[\[[0-9a-hjkmnp-tv-z]{26}@v\d+\]\]\s*$/m', $s['content_md'] ) ) {
			throw new InvalidArgumentException( 'soapbox-blyg: ![[id@vN]] directives are reserved by the protocol' );
		}

		// Spec §5.3: a SHOULD, reported and never refused.
		if ( 'fragment' === $kind && mb_strlen( $s['content_md'] ) > 2000 && is_callable( self::$on_warning ) ) {
			call_user_func( self::$on_warning, 'fragment content_md is over 2,000 characters (spec 5.3 SHOULD cap)' );
		}
	}
}
