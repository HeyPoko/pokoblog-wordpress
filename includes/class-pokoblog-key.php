<?php
/**
 * The API key: minting it, storing it, and comparing it without leaking.
 *
 * This is the whole credential. There is no OAuth handshake, no signed
 * timestamp and no allowlist of source addresses -- a customer copies one
 * string out of this screen and pastes it into PokoBlog, which is the only
 * connection flow a non-technical WordPress owner completes without help. So
 * the string has to carry the security on its own.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PokoBlog_Key {

	/** Where the key lives. Prefixed like everything else, so uninstall finds it. */
	const OPTION = 'pokoblog_api_key';

	/**
	 * 32 bytes from the CSPRNG, hex-encoded to 64 characters.
	 *
	 * `random_bytes` rather than `wp_generate_password`: the latter is fine for
	 * passwords but is documented as "not cryptographically secure" and reads
	 * from `wp_rand`, whose seeding has historically depended on the host. This
	 * key is the only thing between a stranger and a customer's `wp_posts`, so
	 * it comes from the one source PHP guarantees is cryptographically strong
	 * and which throws rather than returning something weak.
	 */
	public static function generate() {
		return bin2hex( random_bytes( 32 ) );
	}

	/** The stored key, or an empty string when there is none. */
	public static function stored() {
		$key = get_option( self::OPTION );

		return is_string( $key ) ? $key : '';
	}

	/** Mint one if the site has none. Called on activation, idempotent. */
	public static function ensure() {
		if ( self::stored() === '' ) {
			update_option( self::OPTION, self::generate(), false );
		}

		return self::stored();
	}

	/** Replace the key, which revokes whatever PokoBlog currently holds. */
	public static function rotate() {
		$key = self::generate();
		update_option( self::OPTION, $key, false );

		return $key;
	}

	/**
	 * Does the presented key match the stored one?
	 *
	 * ## Why this is not `===`
	 *
	 * `===` on strings returns as soon as two bytes differ, so how long it takes
	 * is a function of how many leading bytes were right. That is an oracle: an
	 * attacker who can time the endpoint recovers the key one byte at a time,
	 * which turns 2^256 guesses into about 64 * 16 of them. Over a network the
	 * signal is small and noisy, but it is not zero, and the fix costs nothing.
	 *
	 * ## Why this is not a bare `hash_equals` either
	 *
	 * `hash_equals` is constant-time across the bytes it compares -- but its
	 * first line is a length check that returns immediately when the two strings
	 * are different lengths. So a bare `hash_equals( $stored, $presented )`
	 * still answers "your guess is the wrong length" faster than "your guess is
	 * the right length and wrong", which tells an attacker the key's length for
	 * free and lets them stop sending guesses of every other size.
	 *
	 * Hashing both sides first removes that. Both arguments are then always 64
	 * hex characters whatever the caller sent, so the length branch can never be
	 * the branch taken, and the comparison that decides the answer runs over a
	 * fixed width every time. The digest is not a secret and does not need to
	 * be: it is computed fresh on both sides of every request and never stored.
	 *
	 * ## The empty case
	 *
	 * Checked before hashing rather than left to the comparison. Without it a
	 * site whose key had somehow been deleted would have `hash( '' )` as its
	 * stored digest, and anyone sending an empty key would be let in -- the
	 * comparison would be perfectly constant-time and perfectly wrong.
	 */
	public static function matches( $stored, $presented ) {
		if ( ! is_string( $stored ) || ! is_string( $presented ) ) {
			return false;
		}

		if ( $stored === '' || $presented === '' ) {
			return false;
		}

		return hash_equals( hash( 'sha256', $stored ), hash( 'sha256', $presented ) );
	}

	/**
	 * The key a request presents, from either header we accept.
	 *
	 * `X-PokoBlog-Key` is the one PokoBlog sends. `Authorization: Bearer` is
	 * accepted as well because it is what somebody testing the endpoint by hand
	 * reaches for, and because a customer's proxy may strip headers it does not
	 * recognise while passing `Authorization` through.
	 *
	 * Returns a string always, never null, so every caller runs the same
	 * comparison. A branch that skips the comparison when no header was sent is
	 * a branch that answers faster than a wrong key, which is the thing this
	 * file exists to avoid.
	 */
	public static function presented( $request ) {
		$header = $request->get_header( 'x-pokoblog-key' );

		if ( ! is_string( $header ) || $header === '' ) {
			$authorization = $request->get_header( 'authorization' );

			if ( is_string( $authorization ) && stripos( $authorization, 'Bearer ' ) === 0 ) {
				$header = substr( $authorization, 7 );
			}
		}

		return is_string( $header ) ? trim( $header ) : '';
	}
}
