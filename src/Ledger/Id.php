<?php
/**
 * Item ids.
 *
 * @package SoapboxBlyg
 */

namespace SoapboxBlyg\Ledger;

defined( 'ABSPATH' ) || exit;

/**
 * Item identity (spec §5.1): 128 random bits written as 26 characters of lowercase Crockford
 * base32. Ids are never reused and never derived from content.
 */
class Id {

	const PATTERN  = '/^[0-9a-hjkmnp-tv-z]{26}$/';
	const ALPHABET = '0123456789abcdefghjkmnpqrstvwxyz';

	/**
	 * A new id. The 128 bits are read as a big-endian integer and left-padded to 130 bits, which
	 * is why the first character is always 0-7.
	 */
	public static function mint(): string {
		$bits = '00';
		foreach ( str_split( random_bytes( 16 ) ) as $byte ) {
			$bits .= str_pad( decbin( ord( $byte ) ), 8, '0', STR_PAD_LEFT );
		}
		$id = '';
		foreach ( str_split( $bits, 5 ) as $group ) {
			$id .= self::ALPHABET[ bindec( $group ) ];
		}
		return $id;
	}

	/**
	 * Whether a string has the shape of an id. Says nothing about whether the id exists.
	 *
	 * @param mixed $id Candidate.
	 */
	public static function is_valid( $id ): bool {
		return is_string( $id ) && 1 === preg_match( self::PATTERN, $id );
	}
}
