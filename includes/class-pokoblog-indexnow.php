<?php
/**
 * IndexNow: proving to Bing and Yandex that whoever submits a URL for this site
 * is allowed to.
 *
 * The protocol is one idea. You pick a key, you publish it at
 * `https://example.com/<key>.txt` so that fetching that URL returns the key
 * itself, and then any submission carrying that key is taken as coming from
 * somebody who controls the site. Nothing else is involved -- no account, no
 * token exchange, no per-URL signature.
 *
 * The split between this plugin and PokoBlog follows from that. Only the site
 * can host the file, so the plugin does that. Only PokoBlog knows when an
 * article went live, so PokoBlog submits. The plugin never calls the IndexNow
 * API and never calls us: `setup` hands the key back in the response and that
 * is the entire conversation. Which also means the two halves cannot drift --
 * the key PokoBlog submits is the string this file returned, and if the file
 * ever stops matching, `indexnow-status` says so.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PokoBlog_IndexNow {

	const KEY_OPTION  = 'pokoblog_indexnow_key';
	const MODE_OPTION = 'pokoblog_indexnow_mode';

	/** The key file is a real file in the site root. */
	const MODE_FILE = 'file';

	/** The site root is not writable, so WordPress answers for the file itself. */
	const MODE_VIRTUAL = 'virtual';

	public static function register() {
		add_action( 'init', [ __CLASS__, 'maybe_serve' ], 1 );
	}

	/** The stored key, or an empty string. */
	public static function key() {
		$key = get_option( self::KEY_OPTION );

		return is_string( $key ) ? $key : '';
	}

	public static function mode() {
		$mode = get_option( self::MODE_OPTION );

		return $mode === self::MODE_VIRTUAL ? self::MODE_VIRTUAL : self::MODE_FILE;
	}

	/** Where the key has to be readable, which is fixed by the protocol. */
	public static function location( $key ) {
		return trailingslashit( get_site_url() ) . $key . '.txt';
	}

	private static function path( $key ) {
		return ABSPATH . $key . '.txt';
	}

	/**
	 * Mint a key and publish it.
	 *
	 * 16 bytes of hex, which is inside IndexNow's 8-to-128-character range and
	 * has nothing to do with how secret it is -- the key is published at a
	 * guessable-shaped URL by design. It only has to be unguessable enough that
	 * nobody stumbles onto a valid one for a site they do not own, and 128 bits
	 * is far past that.
	 *
	 * ## When the site root is not writable
	 *
	 * A good number of sites cannot write to `ABSPATH`: anything deployed from
	 * git, anything on a read-only container image, most managed WordPress
	 * hosts, and any install where somebody has followed the usual hardening
	 * advice and made the core files owned by a different user than PHP runs as.
	 * On those sites `file_put_contents` fails and a connector that stops there
	 * reports an error the customer cannot fix without a shell.
	 *
	 * So there is a second way to satisfy the protocol: IndexNow asks for a URL
	 * that returns the key, and it does not care whether a file is behind it.
	 * `maybe_serve()` below answers that one URL out of WordPress. It is
	 * strictly cleaner -- nothing is written to the customer's filesystem at all
	 * -- and it is the fallback rather than the default for one reason: it only
	 * works when the web server hands unknown paths to WordPress. With pretty
	 * permalinks that is exactly what happens, and pretty permalinks is what a
	 * blog running this plugin has. With the plain `?p=123` structure the server
	 * answers `/<key>.txt` itself with a 404 and PHP never runs, so a real file
	 * is the only thing that works there.
	 *
	 * Both modes are reported, so PokoBlog can say which one is in use and
	 * `status()` can be honest about whether it is actually working.
	 */
	public static function setup() {
		$key      = bin2hex( random_bytes( 16 ) );
		$previous = self::key();

		$written = @file_put_contents( self::path( $key ), $key );
		$mode    = $written === false ? self::MODE_VIRTUAL : self::MODE_FILE;

		/*
		 * The old file goes only after the new one is in place. The other order
		 * leaves a window where the site has no key at all, and a submission
		 * landing in that window is rejected.
		 */
		if ( $previous !== '' && $previous !== $key ) {
			self::remove_file( $previous );
		}

		update_option( self::KEY_OPTION, $key, false );
		update_option( self::MODE_OPTION, $mode, false );

		return [
			'key'      => $key,
			'location' => self::location( $key ),
			'host'     => wp_parse_url( get_site_url(), PHP_URL_HOST ),
			'mode'     => $mode,
		];
	}

	/**
	 * What is set up, and whether it still works.
	 *
	 * `configured` is not "we once wrote a key"; it is "the key is readable at
	 * the address the protocol requires". In file mode that means checking the
	 * file is still there, because a core update, a migration or a security
	 * plugin's cleanup can all remove a stray text file from the site root
	 * without anybody noticing -- and a key file that has quietly vanished makes
	 * every submission PokoBlog sends fail with an error that names the key
	 * rather than the file.
	 *
	 * In virtual mode there is nothing on disk to check and the handler is
	 * registered unconditionally, so the answer is whether a key exists.
	 */
	public static function status() {
		$key = self::key();

		if ( $key === '' ) {
			return [ 'configured' => false ];
		}

		$mode       = self::mode();
		$configured = $mode === self::MODE_VIRTUAL || file_exists( self::path( $key ) );

		return [
			'configured' => $configured,
			'key'        => $configured ? $key : null,
			'location'   => $configured ? self::location( $key ) : null,
			'host'       => wp_parse_url( get_site_url(), PHP_URL_HOST ),
			'mode'       => $mode,
		];
	}

	/** Remove the key file, if we wrote one. Used by `setup` and by uninstall. */
	public static function remove_file( $key ) {
		if ( ! is_string( $key ) || $key === '' ) {
			return;
		}

		$path = self::path( $key );

		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Answer `/<key>.txt` out of WordPress, in virtual mode.
	 *
	 * Runs on `init` at priority 1, before anything queries the database for a
	 * post. The comparison is against the exact path -- built from the site's
	 * own URL so it is right on a subdirectory install -- and never against a
	 * substring, because "the request mentions the key somewhere" would answer
	 * this for any URL with the key in a query string.
	 *
	 * `exit` rather than returning a response: this is not a WordPress route and
	 * there is no template to render. What follows would be a 404 page.
	 */
	public static function maybe_serve() {
		if ( self::mode() !== self::MODE_VIRTUAL ) {
			return;
		}

		$key = self::key();

		if ( $key === '' || ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}

		$requested = wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );

		if ( ! is_string( $requested ) ) {
			return;
		}

		$base     = wp_parse_url( get_site_url(), PHP_URL_PATH );
		$expected = trailingslashit( is_string( $base ) ? $base : '' ) . $key . '.txt';

		if ( rawurldecode( $requested ) !== $expected ) {
			return;
		}

		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		echo esc_html( $key );
		exit;
	}
}
