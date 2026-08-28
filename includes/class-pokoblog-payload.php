<?php
/**
 * What arrives on `/publish`, turned into the fields WordPress will accept.
 *
 * Split out from the publisher because it is the only part of the request path
 * with no database in it: strings in, strings out. That is what makes it the
 * part worth reading first when something looks wrong.
 *
 * Every scalar goes through a WordPress sanitizer on the way through, and the
 * body goes through `wp_kses_post`. That is belt and braces on purpose --
 * PokoBlog renders article HTML through an allowlist renderer with a closed set
 * of tags and no way to emit anything outside it (`workflow/html.ts` in the
 * PokoBlog repository argues the case at length) -- and the braces are here for
 * two reasons. The first is that a reviewer reading this plugin can check it,
 * and cannot check our renderer. The second is that "the sender is careful" is
 * not a property a receiver can verify, and this endpoint's whole job is to
 * write into somebody else's database.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PokoBlog_Payload {

	/**
	 * The statuses a request may ask for.
	 *
	 * Deliberately two rather than WordPress's full set. `publish` and `draft`
	 * are the two PokoBlog's own publish-mode setting offers, and anything else
	 * -- `private`, `future`, a custom status from another plugin -- is a state
	 * this connector has no way to explain on the article screen. Unknown
	 * values become `draft`, which is the direction that cannot surprise anyone.
	 */
	const STATUSES = [ 'publish', 'draft' ];

	/**
	 * Read a scalar out of the request, sanitized, or an empty string.
	 *
	 * Arrays and objects come back as an empty string rather than being coerced.
	 * `sanitize_text_field` on an array returns an empty string in modern
	 * WordPress but has not always, and a request that sends `{"title": {...}}`
	 * is not a request with a title in it.
	 */
	private static function text( $params, $key ) {
		if ( ! isset( $params[ $key ] ) || ! is_scalar( $params[ $key ] ) ) {
			return '';
		}

		return sanitize_text_field( (string) $params[ $key ] );
	}

	/** The same, for values that may legitimately contain line breaks. */
	private static function multiline( $params, $key ) {
		if ( ! isset( $params[ $key ] ) || ! is_scalar( $params[ $key ] ) ) {
			return '';
		}

		return sanitize_textarea_field( (string) $params[ $key ] );
	}

	/**
	 * Normalize a publish request.
	 *
	 * Returns an array with every field this plugin understands, always with the
	 * same keys, so nothing downstream has to test for presence. Missing fields
	 * are empty strings; the publisher decides what an empty one means.
	 *
	 * `article_id`, `title` and `content` have no default and no fallback:
	 * `missing()` below names them and the route refuses the request. `article_id` in particular is
	 * required rather than optional, which is a deliberate difference from the
	 * obvious design. It is the only thing that makes a republish update the
	 * post it made last time instead of adding a second copy, and a field that
	 * is usually sent is a field that one day is not -- at which point a
	 * customer's blog quietly fills with doubles and nothing in this plugin can
	 * tell which of them it wrote.
	 */
	public static function normalize( $params ) {
		if ( ! is_array( $params ) ) {
			$params = [];
		}

		$slug = self::text( $params, 'slug' );

		return [
			'article_id'         => self::text( $params, 'article_id' ),
			'title'              => self::text( $params, 'title' ),
			/*
			 * The one field that is markup rather than text, and so the one that
			 * gets `wp_kses_post` rather than a scalar sanitizer. Escaping it
			 * would be wrong -- it is HTML on purpose, and escaped it would show
			 * the reader its own tags.
			 */
			'content'            => isset( $params['content'] ) && is_string( $params['content'] )
				? wp_kses_post( $params['content'] )
				: '',
			/*
			 * `sanitize_title` here rather than at the point of use, so the slug
			 * compared against existing posts and the slug written to `post_name`
			 * are the same string by construction. Two calls in two places is how
			 * a conflict check ends up looking at a slug WordPress will not use.
			 */
			'slug'               => $slug === '' ? '' : sanitize_title( $slug ),
			'excerpt'            => self::multiline( $params, 'excerpt' ),
			'status'             => self::status( $params ),
			'meta_title'         => self::text( $params, 'meta_title' ),
			'meta_description'   => self::multiline( $params, 'meta_description' ),
			'focus_keyword'      => self::text( $params, 'focus_keyword' ),
			/*
			 * `esc_url_raw` rather than `sanitize_text_field`: it strips
			 * characters that are not legal in a URL and leaves the rest alone,
			 * where the text sanitizer would happily hand back something that no
			 * longer addresses anything. The scheme and host are checked
			 * separately, in the media class, because that is where the fetch is
			 * and a check far from its fetch is a check somebody removes.
			 */
			'featured_image_url' => isset( $params['featured_image_url'] ) && is_string( $params['featured_image_url'] )
				? esc_url_raw( trim( $params['featured_image_url'] ) )
				: '',
			'featured_image_alt' => self::text( $params, 'featured_image_alt' ),
			'category'           => self::text( $params, 'category' ),
		];
	}

	private static function status( $params ) {
		$status = self::text( $params, 'status' );

		return in_array( $status, self::STATUSES, true ) ? $status : 'draft';
	}

	/**
	 * What is missing, as a list of field names.
	 *
	 * A list rather than a boolean because the response says which field was
	 * wrong, and a connector that answers "bad request" without saying what was
	 * bad is one somebody debugs by guessing.
	 */
	public static function missing( $normalized ) {
		$missing = [];

		foreach ( [ 'article_id', 'title', 'content' ] as $field ) {
			if ( ! isset( $normalized[ $field ] ) || $normalized[ $field ] === '' ) {
				$missing[] = $field;
			}
		}

		return $missing;
	}

	/**
	 * The slug this request should be published at.
	 *
	 * Falls back to the title when no slug was sent. PokoBlog always sends one
	 * -- its `article.slug` column is not nullable -- so this is the path taken
	 * by somebody posting to the endpoint by hand, and deriving it here rather
	 * than letting WordPress do it is what puts that request through the same
	 * conflict check as every other.
	 */
	public static function slug_for( $normalized ) {
		if ( $normalized['slug'] !== '' ) {
			return $normalized['slug'];
		}

		return sanitize_title( $normalized['title'] );
	}
}
