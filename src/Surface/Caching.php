<?php
/**
 * How long the surface's documents may be cached.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Surface;

use SoapboxBlyg\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Every lifetime the plugin tells a cache, in one place. The headers that carry them
 * (Http::caching()), the setting that chooses one, and the Site Health test that checks whether
 * the caches in front of the site keep to it all read from here, so they cannot disagree.
 *
 * The lifetime of a live document is how long a withdrawn or edited item can go on being served
 * from a cache after the site has stopped serving it. That is the whole trade: shorter, and a
 * withdrawal is seen sooner; longer, and fewer requests reach WordPress. It is also how long a
 * "not found" is remembered, so an address asked for just before its item is published answers
 * 404 for up to that long.
 */
class Caching {

	/**
	 * The lifetimes a site can choose between. A list and not a number field: nothing here can
	 * be set to no caching at all, or to the month that makes a withdrawal meaningless.
	 */
	const LIFETIMES = array( MINUTE_IN_SECONDS, 5 * MINUTE_IN_SECONDS, 15 * MINUTE_IN_SECONDS, HOUR_IN_SECONDS );

	const DEFAULT_LIFETIME = 5 * MINUTE_IN_SECONDS;

	/**
	 * A pinned version never changes (spec §9), so it is not part of the choice.
	 */
	const PIN_LIFETIME = YEAR_IN_SECONDS;

	/**
	 * How long a browser may remember the answer to a CORS preflight. The answer is the same for
	 * every caller and every document.
	 */
	const PREFLIGHT_LIFETIME = DAY_IN_SECONDS;

	/**
	 * A value as one of the lifetimes offered, or the default when it is not one of them. Whole
	 * numbers only, as an integer or as a form sends them: nothing is rounded into the list.
	 *
	 * @param mixed $value A stored or submitted value.
	 */
	public static function offered( $value ): int {
		$whole = is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) );
		return $whole && in_array( (int) $value, self::LIFETIMES, true ) ? (int) $value : self::DEFAULT_LIFETIME;
	}

	/**
	 * The lifetime of a live document, in seconds.
	 */
	public static function lifetime(): int {
		$seconds = self::offered( Settings::get( 'cache_lifetime' ) );
		/**
		 * Filters how long a live document (anything but a pinned version) may be cached.
		 *
		 * A withdrawn item can go on being served from caches for this long, so keep it short.
		 * It is never less than a second, nor more than the longest lifetime the setting offers.
		 *
		 * @param int $seconds The lifetime chosen in the plugin's settings.
		 */
		$filtered = apply_filters( 'soapbox_blyg_cache_lifetime', $seconds );
		// Held between a second and the longest lifetime offered, as a float first: a number too
		// large to be an integer must not become one, and one that is not a number at all is none.
		$filtered = is_numeric( $filtered ) && ! is_nan( (float) $filtered ) ? (float) $filtered : 0.0;
		return (int) min( (float) max( self::LIFETIMES ), max( 1.0, $filtered ) );
	}

	/**
	 * The first value a Cache-Control header gives for a directive, or null when it gives none.
	 *
	 * The first, because that is the one a cache uses when a directive is repeated (RFC 9111
	 * §4.2.1), and hosts do repeat them. What sits inside a quoted string is some other
	 * directive's argument and is not read, except a quoted number, which is a legal way to
	 * write a lifetime.
	 *
	 * @param string $header    The header's value; several headers joined with commas.
	 * @param string $directive A directive that takes seconds: max-age or s-maxage.
	 * @return int|null
	 */
	public static function first( string $header, string $directive ) {
		return preg_match( '/(?<![a-z-])' . preg_quote( $directive, '/' ) . '\s*=\s*"?(\d+)/', self::unquoted( $header ), $m ) ? (int) $m[1] : null;
	}

	/**
	 * Whether a Cache-Control header carries a directive that takes no value, such as no-store.
	 *
	 * @param string $header    The header's value.
	 * @param string $directive The directive.
	 */
	public static function has( string $header, string $directive ): bool {
		return 1 === preg_match( '/(?<![a-z-])' . preg_quote( $directive, '/' ) . '(?![a-z-])/', self::unquoted( $header ) );
	}

	/**
	 * A header's value in lower case, with every quoted string but a quoted number emptied.
	 *
	 * @param string $header The header's value.
	 */
	private static function unquoted( string $header ): string {
		// A quote that is never closed takes the rest of the header with it.
		return (string) preg_replace( '/"(?!\d+")(?:[^"\\\\]|\\\\.)*("|$)/', '""', strtolower( $header ) );
	}

	/**
	 * A lifetime in words, in the site's language, as WordPress itself writes durations
	 * ("5 mins", "1 hour").
	 *
	 * @param int $seconds Seconds.
	 */
	public static function in_words( int $seconds ): string {
		return human_time_diff( 0, $seconds );
	}
}
