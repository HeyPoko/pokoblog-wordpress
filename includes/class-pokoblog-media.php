<?php
/**
 * The featured image: downloaded into the customer's media library, not linked
 * to ours.
 *
 * ## Why it is copied rather than hot-linked
 *
 * Setting a post's thumbnail to a URL on PokoBlog's storage is one line and it
 * is a trap. The article is the customer's content and it is on their domain;
 * the day they stop paying, or we move a bucket, or a CDN rule changes, every
 * picture on their blog turns into a broken image and there is nothing they can
 * do about it from their own admin. It also means every visitor to their site
 * makes a request to us, which is a privacy claim they never agreed to make on
 * their readers' behalf. Copying it costs one download per article, once.
 *
 * ## What this plugin will and will not fetch
 *
 * It will fetch: exactly one URL per publish request, the one named in
 * `featured_image_url` by a request that has already proved it holds this
 * site's API key, over http or https, from a host WordPress's own
 * `wp_http_validate_url` accepts.
 *
 * It will not fetch: anything else. There is no endpoint here that takes a URL
 * and returns its contents, no redirect follower of our own, and no second
 * request derived from the first one's body. The image is fetched with
 * `download_url()`, which since WordPress 4.6 goes through
 * `wp_safe_remote_get()` -- that is the `reject_unsafe_urls` path, which runs
 * `wp_http_validate_url` and refuses loopback and private address ranges,
 * non-standard ports and non-http schemes. So the worst a stolen API key buys
 * is making this site fetch a public URL, which is what a web server does all
 * day, and not a probe of the customer's internal network.
 *
 * The scheme is checked here as well rather than left to WordPress. It is two
 * lines, it makes the rule readable at the point it applies, and it does not
 * depend on a core function keeping a behaviour its documentation does not
 * promise.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PokoBlog_Media {

	/**
	 * What we will accept as a featured image, and the extension each one gets.
	 *
	 * WebP is in the list because that is what PokoBlog generates. AVIF is not:
	 * WordPress only learned to handle it in 6.5 and this plugin supports 5.6,
	 * so accepting one would mean handing `media_handle_sideload` a file it
	 * refuses on most of the installs this runs on. SVG is deliberately absent
	 * and would be even if we generated it -- an SVG is a document that can
	 * carry script, and this is the one place in the plugin where bytes from
	 * outside become a file inside the customer's site.
	 */
	const TYPES = [
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/gif'  => 'gif',
		'image/webp' => 'webp',
	];

	/** Thirty seconds to fetch, and ten megabytes at most. */
	const TIMEOUT   = 30;
	const MAX_BYTES = 10485760;

	/**
	 * Download the image, attach it to the post, make it the featured image.
	 *
	 * Returns the attachment id, or null. Null is an ordinary outcome rather
	 * than an error: an article with no picture is a correct article, and the
	 * caller treats a failure here as "published, without an image" rather than
	 * as a failed publish. That is the important half -- a publish reported as
	 * failed is a publish PokoBlog retries, and a retry that succeeds where the
	 * first one already created the post is how a blog fills with duplicates.
	 */
	public static function attach( $post_id, $url, $alt ) {
		if ( ! is_string( $url ) || $url === '' ) {
			return null;
		}

		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );

		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
			return null;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$temp = download_url( $url, self::TIMEOUT );

		if ( is_wp_error( $temp ) ) {
			return null;
		}

		$size = @filesize( $temp );

		if ( $size === false || $size === 0 || $size > self::MAX_BYTES ) {
			wp_delete_file( $temp );

			return null;
		}

		$mime = self::mime_of( $temp, $url );

		if ( ! isset( self::TYPES[ $mime ] ) ) {
			wp_delete_file( $temp );

			return null;
		}

		/*
		 * The filename is what decides whether this works, which is the part
		 * that surprises people.
		 *
		 * `media_handle_sideload` does not trust the mime we detected; it runs
		 * `wp_check_filetype_and_ext` on the temporary file, and that function
		 * starts from the *name*. Our images come from object storage, where the
		 * path may carry no extension at all or may end in a signed query
		 * string, so the name `download_url` produced is frequently one
		 * WordPress will refuse as "sorry, this file type is not permitted".
		 * Naming the file after the mime we detected is what turns a rejected
		 * upload into an accepted one.
		 */
		$name = self::filename( $alt, self::TYPES[ $mime ] );

		$attachment = media_handle_sideload(
			[
				'name'     => $name,
				'tmp_name' => $temp,
			],
			$post_id,
			/*
			 * No title passed. `media_handle_sideload` uses its third argument
			 * as the attachment's post_title, and left null it derives one from
			 * the filename -- which is derived from the alt text, so the two
			 * agree without this function inventing a third string.
			 */
			null
		);

		/*
		 * `media_handle_sideload` moves the file on success and leaves it on
		 * failure, so the delete is conditional rather than unconditional. An
		 * unconditional one is a race with the file it just moved.
		 */
		if ( is_wp_error( $attachment ) ) {
			if ( file_exists( $temp ) ) {
				wp_delete_file( $temp );
			}

			return null;
		}

		set_post_thumbnail( $post_id, $attachment );

		/*
		 * Alt text only when we were given some.
		 *
		 * The temptation is to fall back to the article title, and it is wrong.
		 * `alt` is a claim about the picture, and `alt=""` is a different claim
		 * again -- it tells a screen reader the image is decoration and to say
		 * nothing, which a hero image is not. Writing the title would put a
		 * sentence about the article where a description of the image belongs,
		 * and it would look filled-in in the media library, so nobody would ever
		 * fix it. Left unset, WordPress shows it as missing and the customer can
		 * write one.
		 */
		if ( is_string( $alt ) && $alt !== '' ) {
			update_post_meta( $attachment, '_wp_attachment_image_alt', $alt );
		}

		/* Ours, so `uninstall.php` can tell our attachments from the customer's. */
		update_post_meta( $attachment, '_pokoblog_attachment', '1' );

		return $attachment;
	}

	/**
	 * A filename for the downloaded image.
	 *
	 * Derived from the alt text when there is one and from the post id when
	 * there is not, capped short, and always ending in the extension for the
	 * mime we detected. Never derived from the remote URL's path: that string is
	 * chosen elsewhere and this is a filename on the customer's disk.
	 */
	private static function filename( $alt, $extension ) {
		$stem = is_string( $alt ) ? sanitize_title( $alt ) : '';

		if ( $stem === '' ) {
			$stem = 'pokoblog-image';
		}

		return sanitize_file_name( substr( $stem, 0, 60 ) . '.' . $extension );
	}

	/**
	 * The image's type, by four methods in descending order of trust.
	 *
	 * The fallbacks are not defensive padding; the first method is missing on a
	 * large share of the hosts this plugin runs on. `mime_content_type` and the
	 * `finfo_*` family both come from PHP's `fileinfo` extension, which is
	 * compiled in by default but is switched off by a good number of shared
	 * hosts and by some hardened configurations -- it is common enough that
	 * WordPress core carries its own fallback in `wp_get_image_mime`. On those
	 * hosts a connector that asks `mime_content_type` and gives up gets no
	 * featured images at all, and the customer sees a blog of pictureless posts
	 * with nothing in any log to say why.
	 *
	 * The order is deliberate. Reading the file is authoritative; reading the
	 * URL's extension is a guess about a string somebody else wrote; so the
	 * magic bytes go *before* the extension rather than last, and the extension
	 * is only reached for a format whose signature we do not check. The result
	 * is checked against `TYPES` by the caller either way, so a wrong answer
	 * here refuses an image, it does not smuggle one in.
	 */
	public static function mime_of( $path, $url = '' ) {
		if ( function_exists( 'mime_content_type' ) ) {
			$mime = @mime_content_type( $path );

			if ( is_string( $mime ) && $mime !== '' ) {
				return $mime;
			}
		}

		if ( function_exists( 'finfo_open' ) ) {
			$finfo = @finfo_open( FILEINFO_MIME_TYPE );

			if ( $finfo ) {
				$mime = @finfo_file( $finfo, $path );
				finfo_close( $finfo );

				if ( is_string( $mime ) && $mime !== '' ) {
					return $mime;
				}
			}
		}

		$magic = self::mime_from_magic( $path );

		if ( $magic !== '' ) {
			return $magic;
		}

		return self::mime_from_url( $url );
	}

	/**
	 * The type from the file's first bytes.
	 *
	 * The four signatures are fixed by the formats themselves and are what
	 * `wp_get_image_mime` falls back to as well. WebP is the awkward one: its
	 * container is RIFF, so the check is a pair -- `RIFF` at 0 and `WEBP` at 8 --
	 * and testing only the first would call a WAV file an image.
	 *
	 * The byte sequences are written as escapes rather than as literal bytes.
	 * A control character typed into a source file makes git record the file as
	 * binary, which is how a file stops being diffable and stops being linted.
	 *
	 * Public, and only because of the tests. `fileinfo` cannot be switched off
	 * from inside a running interpreter, so a test that went through `mime_of`
	 * would always take the first branch and never reach this one -- the
	 * fallback that exists precisely for the hosts where the first branch is
	 * missing would be the one line of this class nothing ever ran.
	 */
	public static function mime_from_magic( $path ) {
		$handle = @fopen( $path, 'rb' );

		if ( ! $handle ) {
			return '';
		}

		$bytes = fread( $handle, 12 );
		fclose( $handle );

		if ( ! is_string( $bytes ) || strlen( $bytes ) < 12 ) {
			return '';
		}

		if ( substr( $bytes, 0, 3 ) === "\xFF\xD8\xFF" ) {
			return 'image/jpeg';
		}

		if ( substr( $bytes, 0, 8 ) === "\x89PNG\x0D\x0A\x1A\x0A" ) {
			return 'image/png';
		}

		if ( substr( $bytes, 0, 6 ) === 'GIF87a' || substr( $bytes, 0, 6 ) === 'GIF89a' ) {
			return 'image/gif';
		}

		if ( substr( $bytes, 0, 4 ) === 'RIFF' && substr( $bytes, 8, 4 ) === 'WEBP' ) {
			return 'image/webp';
		}

		return '';
	}

	/**
	 * The type from the URL's extension. The weakest of the four, so it is last.
	 *
	 * Public for the same reason as `mime_from_magic`.
	 */
	public static function mime_from_url( $url ) {
		if ( ! is_string( $url ) || $url === '' ) {
			return '';
		}

		$path = wp_parse_url( $url, PHP_URL_PATH );

		if ( ! is_string( $path ) || $path === '' ) {
			return '';
		}

		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		$map = [
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
		];

		return isset( $map[ $extension ] ) ? $map[ $extension ] : '';
	}
}
