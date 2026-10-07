<?php
/**
 * Which addresses the verifier may fetch.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Mentions;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether a URL names a public web address, and which address a request for it should be
 * sent to.
 *
 * Core's wp_http_validate_url() makes the same kind of decision, and the HTTP API applies it to
 * every request the verifier makes. It is not enough on its own, for two reasons. What it counts
 * as private has grown over time, and on older WordPress versions it lets link-local addresses
 * through, which is where cloud hosts keep their metadata service. And it looks at IPv4 only: a
 * name with a public A record and an AAAA record of ::1 passes, and an HTTP client that prefers
 * IPv6 then connects inward.
 *
 * So this class applies core's rules itself (http or https, no credentials in the URL, only the
 * ports core allows), resolves the host in both families, and refuses it if any address it has is
 * outside the globally routable space. A host must have an IPv4 address, as core also requires.
 * The caller then pins its request to that checked IPv4 address (see Verifier), so the address
 * that was judged is the address that is fetched. Without that, a hostile resolver could give one
 * answer to the check and another to the request.
 */
class Address {

	/**
	 * IPv4 ranges that are not globally routable unicast: the IANA IPv4 Special-Purpose Address
	 * Registry, plus multicast and the reserved class E. An address in any of these is refused.
	 */
	const SPECIAL_V4 = array(
		'0.0.0.0/8',          // "This network".
		'10.0.0.0/8',         // Private-use networks.
		'100.64.0.0/10',      // Shared address space (carrier-grade NAT).
		'127.0.0.0/8',        // Loopback.
		'169.254.0.0/16',     // Link-local, including cloud metadata services.
		'172.16.0.0/12',      // Private-use networks.
		'192.0.0.0/24',       // IETF protocol assignments.
		'192.0.2.0/24',       // Documentation (TEST-NET-1).
		'192.88.99.0/24',     // Formerly 6to4 relay anycast.
		'192.168.0.0/16',     // Private-use networks.
		'198.18.0.0/15',      // Benchmarking.
		'198.51.100.0/24',    // Documentation (TEST-NET-2).
		'203.0.113.0/24',     // Documentation (TEST-NET-3).
		'224.0.0.0/4',        // Multicast.
		'240.0.0.0/4',        // Reserved, and limited broadcast.
	);

	/**
	 * IPv6 ranges that are not globally routable unicast: the IANA IPv6 Special-Purpose Address
	 * Registry, plus multicast and the deprecated site-local range. Ranges that carry an IPv4
	 * address inside are handled separately, by judging the address they carry.
	 */
	const SPECIAL_V6 = array(
		'::/128',             // Unspecified.
		'::1/128',            // Loopback.
		'64:ff9b:1::/48',     // Local-use IPv4/IPv6 translation.
		'100::/64',           // Discard-only.
		'2001::/23',          // IETF protocol assignments, Teredo among them.
		'2001:db8::/32',      // Documentation.
		'3fff::/20',          // Documentation.
		'5f00::/16',          // Segment routing.
		'fc00::/7',           // Unique-local.
		'fe80::/10',          // Link-local.
		'fec0::/10',          // Site-local (deprecated).
		'ff00::/8',           // Multicast.
	);

	/**
	 * IPv6 ranges whose addresses carry an IPv4 address: prefix => the byte offset of the four
	 * bytes that hold it. A connection to one of these ends up at that IPv4 address.
	 */
	const EMBEDS_V4 = array(
		'::ffff:0:0/96' => 12, // IPv4-mapped.
		'::/96'         => 12, // IPv4-compatible (deprecated).
		'64:ff9b::/96'  => 12, // NAT64.
		'2002::/16'     => 2,  // 6to4.
	);

	/**
	 * Resolves a host name to its addresses: host => string[] of IPv4 and IPv6 addresses. Tests
	 * replace it, since DNS cannot be faked from inside WordPress. Null means the real resolver.
	 *
	 * @var callable|null
	 */
	public static $resolver = null;

	/**
	 * Whether a URL may be fetched.
	 *
	 * @param string $url An absolute URL.
	 */
	public static function is_public( string $url ): bool {
		return null !== self::addresses( $url );
	}

	/**
	 * A host name as it is compared and pinned: lowercase, with no trailing dot.
	 *
	 * @param string $host Host name.
	 */
	public static function normalize_host( string $host ): string {
		return rtrim( strtolower( trim( $host, '[]' ) ), '.' );
	}

	/**
	 * The addresses a URL's host resolves to, if the URL may be fetched; null if it may not.
	 *
	 * An empty array means "allowed, and nothing to pin to": this site's own host on its own port,
	 * which is allowed whatever it resolves to. Another blyg on the same site is a legitimate
	 * source, and a development site lives on a private address.
	 *
	 * @param string $url    An absolute URL.
	 * @param bool   $lookup Whether a host name may be resolved. Without it only what can be told
	 *                       from the URL itself is checked (its form, its port, an address written
	 *                       as a literal), and an empty array means nothing is known against it.
	 * @return string[]|null IPv4 addresses first.
	 */
	public static function addresses( string $url, bool $lookup = true ) {
		$parts  = wp_parse_url( $url );
		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
		$host   = isset( $parts['host'] ) ? self::normalize_host( $parts['host'] ) : '';
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}
		if ( false !== strpbrk( $host, ':#?[]' ) ) {
			return null; // IPv6 literals and malformed hosts, which core refuses too.
		}

		$home      = wp_parse_url( home_url() );
		$same_host = isset( $home['host'] ) && self::normalize_host( $home['host'] ) === $host;

		$default_port = static function ( array $url_parts ): int {
			if ( isset( $url_parts['port'] ) ) {
				return (int) $url_parts['port'];
			}
			return isset( $url_parts['scheme'] ) && 'https' === strtolower( $url_parts['scheme'] ) ? 443 : 80;
		};

		// This site's own host is exempt from the address check below, so it is held to this site's
		// own port: the exemption is for another blyg on this site, not for whatever else listens
		// on the same machine.
		if ( $same_host ) {
			return $default_port( $parts ) === $default_port( $home ) ? array() : null;
		}

		if ( isset( $parts['port'] ) ) {
			/** This filter is documented in wp-includes/http.php */
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own list of ports a safe request may use.
			$allowed = (array) apply_filters( 'http_allowed_safe_ports', array( 80, 443, 8080 ), $host, $url );
			if ( ! in_array( (int) $parts['port'], array_map( 'intval', $allowed ), true ) ) {
				return null;
			}
		}

		$literal = false !== filter_var( $host, FILTER_VALIDATE_IP );
		if ( ! $literal && ! $lookup ) {
			return array();
		}
		$addresses = $literal ? array( $host ) : self::resolve( $host );
		foreach ( $addresses as $address ) {
			if ( ! self::is_public_ip( $address ) ) {
				/** This filter is documented in wp-includes/http.php */
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own switch for sites that must reach hosts on their private network.
				if ( ! apply_filters( 'http_request_host_is_external', false, $host, $url ) ) {
					return null;
				}
			}
		}
		// IPv4 first, and at least one: the request is pinned to the first address.
		$ipv4 = array_values(
			array_filter(
				$addresses,
				static function ( $address ) {
					return false !== filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 );
				}
			)
		);
		if ( ! $ipv4 ) {
			return null; // Unresolvable, or IPv6 only.
		}
		return array_values( array_unique( array_merge( $ipv4, $addresses ) ) );
	}

	/**
	 * Every address a host name has, in both families, IPv4 first.
	 *
	 * IPv4 comes from the system resolver, so names in the hosts file are found as the HTTP client
	 * would find them. IPv6 comes from a DNS query for AAAA records.
	 *
	 * @param string $host Host name.
	 * @return string[]
	 */
	private static function resolve( string $host ): array {
		if ( is_callable( self::$resolver ) ) {
			return array_values( (array) call_user_func( self::$resolver, $host ) );
		}
		$addresses = gethostbynamel( $host );
		$addresses = is_array( $addresses ) ? $addresses : array();
		if ( function_exists( 'dns_get_record' ) ) {
			// A name with no AAAA record makes dns_get_record() raise a warning on some systems; the
			// handler turns that into "no records" without silencing anything else.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- scoped to this one lookup and restored below.
			set_error_handler( '__return_true', E_WARNING );
			$records = dns_get_record( $host, DNS_AAAA );
			restore_error_handler();
			foreach ( is_array( $records ) ? $records : array() as $record ) {
				if ( ! empty( $record['ipv6'] ) ) {
					$addresses[] = $record['ipv6'];
				}
			}
		}
		return array_values( array_unique( $addresses ) );
	}

	/**
	 * Whether a packed address falls inside a range written as "address/bits".
	 *
	 * @param string $packed An address from inet_pton().
	 * @param string $cidr   A range of the same family.
	 */
	private static function in_range( string $packed, string $cidr ): bool {
		list( $base, $bits ) = explode( '/', $cidr );
		$base                = inet_pton( $base );
		if ( false === $base || strlen( $base ) !== strlen( $packed ) ) {
			return false;
		}
		$bits  = (int) $bits;
		$whole = intdiv( $bits, 8 );
		if ( 0 !== strncmp( $packed, $base, $whole ) ) {
			return false;
		}
		$rest = $bits % 8;
		if ( 0 === $rest ) {
			return true;
		}
		$mask = 0xFF << ( 8 - $rest ) & 0xFF;
		return ( ord( $packed[ $whole ] ) & $mask ) === ( ord( $base[ $whole ] ) & $mask );
	}

	/**
	 * Whether an IP address is globally routable unicast: outside every special-purpose range, in
	 * its own right and, for an IPv6 address that carries an IPv4 address, in the address it
	 * carries. Anything that does not parse as an address is not public.
	 *
	 * @param string $ip IPv4 or IPv6 address, in any textual form.
	 */
	public static function is_public_ip( string $ip ): bool {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- inet_pton() warns on input that is not an address, which here is an answer, not an error.
		$packed = @inet_pton( $ip );
		if ( false === $packed ) {
			return false;
		}
		if ( 4 === strlen( $packed ) ) {
			foreach ( self::SPECIAL_V4 as $range ) {
				if ( self::in_range( $packed, $range ) ) {
					return false;
				}
			}
			return true;
		}
		foreach ( self::EMBEDS_V4 as $range => $offset ) {
			if ( self::in_range( $packed, $range ) ) {
				// The unspecified and loopback addresses also sit in ::/96; they are refused below.
				$inner = inet_ntop( substr( $packed, $offset, 4 ) );
				if ( '::/96' !== $range || ! in_array( $inner, array( '0.0.0.0', '0.0.0.1' ), true ) ) {
					return false !== $inner && self::is_public_ip( $inner );
				}
			}
		}
		foreach ( self::SPECIAL_V6 as $range ) {
			if ( self::in_range( $packed, $range ) ) {
				return false;
			}
		}
		// Global unicast is 2000::/3. Everything else in IPv6 is unassigned or special.
		return self::in_range( $packed, '2000::/3' );
	}

	/**
	 * The network an address belongs to, for rate limiting: its /24 in IPv4, its /48 in IPv6. A
	 * sender can have any number of host names; it cannot as easily have any number of networks.
	 *
	 * @param string $ip IPv4 or IPv6 address.
	 */
	public static function network_of( string $ip ): string {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.
		$packed = @inet_pton( $ip );
		if ( false === $packed ) {
			return 'unknown';
		}
		// An IPv4 address written as IPv6 (::ffff:a.b.c.d), which is how a dual-stack listener
		// reports an IPv4 client, is that IPv4 address. Left as it is, every one of them would
		// fall in the same /48.
		if ( 16 === strlen( $packed ) && self::in_range( $packed, '::ffff:0:0/96' ) ) {
			$packed = substr( $packed, 12 );
		}
		if ( 4 === strlen( $packed ) ) {
			return inet_ntop( substr( $packed, 0, 3 ) . "\0" ) . '/24';
		}
		return inet_ntop( substr( $packed, 0, 6 ) . str_repeat( "\0", 10 ) ) . '/48';
	}
}
