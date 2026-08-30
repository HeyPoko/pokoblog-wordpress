<?php
/**
 * What PokoBlog did to this site, on this site.
 *
 * ## Why the plugin keeps its own record
 *
 * Every delivery already produces an outcome, and until now all of it went one
 * way: PokoBlog logged what it heard back, on a server the customer cannot
 * read. So the person whose blog it is could see that an article said
 * "published" in PokoBlog and could not see what their own WordPress did with
 * it -- and the interesting outcomes are exactly the ones where those two
 * differ.
 *
 * Three of them, all real and all silent today:
 *
 * - **A slug conflict.** Another page already holds the address, so the post
 *   is written as a *draft* rather than stealing it. PokoBlog says published;
 *   the site has a draft nobody is looking at.
 * - **A picture that did not arrive.** `PokoBlog_Media::attach` is deliberately
 *   not allowed to fail the request -- an article without its image is better
 *   than no article -- so it returns null and nothing says so.
 * - **A post in the bin.** The article is refused rather than resurrected, on
 *   purpose, and the customer is the only one who knows they binned it.
 *
 * ## Why an option and not a table
 *
 * A ring buffer in one autoloaded-off option. A table means an activation
 * hook, a schema version, a migration path and an uninstall step, for a
 * feature whose whole job is to answer "what happened to the last few
 * articles". `LIMIT` entries is enough to cover a fortnight of publishing at
 * any cadence anybody runs, and the oldest falling off is the correct
 * behaviour rather than a compromise.
 *
 * `autoload = false`, so a site that never opens the settings screen never
 * pays for this on any other request.
 *
 * ## What is deliberately not in here
 *
 * The article body, the meta description, the key, and anything that came from
 * the request that is not an identifier or an outcome. This is a record of
 * what happened, readable by any administrator, and a copy of the content is
 * both useless for that and a second place the content lives.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PokoBlog_Log {

	const OPTION = 'pokoblog_log';

	/**
	 * How many deliveries are kept.
	 *
	 * Twenty is a fortnight at daily and three months at weekly, and the
	 * question this answers -- "what happened to the last few" -- has never
	 * needed more. It is also small enough that the whole option stays a few
	 * kilobytes, which is what lets this be an option at all.
	 */
	const LIMIT = 20;

	/**
	 * Record one delivery. Never throws, never fails the request.
	 *
	 * Called from the REST layer rather than the publisher, because the
	 * publisher answers the same shape for success and failure and the REST
	 * layer is where both arrive. A logger that could break a publish would be
	 * a worse bug than the one it exists to diagnose, so everything here is
	 * inside a try/catch and its return value is ignored.
	 */
	public static function record( $article_id, $result, $status, $wanted_image = false ) {
		try {
			$entries = self::entries();

			array_unshift(
				$entries,
				[
					'at'         => time(),
					'article_id' => (string) $article_id,
					'post_id'    => isset( $result['post_id'] ) ? $result['post_id'] : null,
					'ok'         => ! empty( $result['ok'] ),
					'http'       => (int) $status,
					'code'       => isset( $result['code'] ) ? (string) $result['code'] : '',
					'created'    => ! empty( $result['created'] ),
					'status'     => isset( $result['status'] ) ? (string) $result['status'] : '',
					/*
					 * The three facts that are otherwise invisible. `image` is
					 * a tri-state on purpose: null when no picture was sent,
					 * false when one was sent and did not arrive, and the
					 * attachment id when it did. "No picture" and "the picture
					 * failed" are different problems.
					 */
					'image'      => self::image_outcome( $result, $wanted_image ),
					'conflict'   => isset( $result['slug_conflict'] ) && $result['slug_conflict']
						? (string) $result['slug_conflict']['requested']
						: '',
					'error'      => isset( $result['error'] ) ? (string) $result['error'] : '',
				]
			);

			update_option( self::OPTION, array_slice( $entries, 0, self::LIMIT ), false );
		} catch ( Throwable $e ) { // phpcs:ignore
			/* A record of what happened is not worth failing a publish over. */
		}
	}

	/**
	 * Whether a picture was asked for, and whether it arrived.
	 *
	 * Three answers, and the distinction between the last two is the whole
	 * point: `featured_image_id` comes back null both when no picture was sent
	 * and when one was sent and could not be fetched, and those are different
	 * problems for the person reading this. "No picture" is a choice; "the
	 * picture did not arrive" is a fault they can go and look at.
	 *
	 * So whether one was *asked for* comes from the payload, which is the only
	 * thing that knows. An earlier version asked `has_post_thumbnail` instead
	 * and was wrong twice over: it is a second source of truth for something
	 * the request already stated, and it would have reported an image that a
	 * previous delivery had attached as though this one had.
	 */
	private static function image_outcome( $result, $wanted_image ) {
		/*
		 * A refusal never got as far as a picture, so it has no verdict to
		 * give. `describe` does not print one either way, but the stored
		 * entries are read by people -- and "the picture failed" beside "that
		 * article is in your bin" is two problems reported where there was
		 * one.
		 */
		if ( empty( $result['ok'] ) || ! $wanted_image ) {
			return null;
		}

		return empty( $result['featured_image_id'] )
			? false
			: (int) $result['featured_image_id'];
	}

	/** Newest first. Always an array, whatever is in the option. */
	public static function entries() {
		$stored = get_option( self::OPTION, [] );

		return is_array( $stored ) ? $stored : [];
	}

	public static function clear() {
		delete_option( self::OPTION );
	}

	/**
	 * One line of plain English per entry, for the settings screen.
	 *
	 * Written here rather than in the template because it is the part worth
	 * testing: the difference between "published" and "published, but as a
	 * draft because something else holds that address" is the whole reason
	 * this file exists, and it should not live inside markup.
	 */
	public static function describe( $entry ) {
		if ( empty( $entry['ok'] ) ) {
			$reasons = [
				'locked'         => __( 'Another delivery of the same article was already running.', 'pokoblog' ),
				'trashed'        => __( 'That article is in your bin. PokoBlog left it there rather than restoring it.', 'pokoblog' ),
				'missing_fields' => __( 'The request arrived incomplete.', 'pokoblog' ),
				'write_failed'   => __( 'WordPress refused to save the post.', 'pokoblog' ),
			];

			$code = isset( $entry['code'] ) ? $entry['code'] : '';

			return isset( $reasons[ $code ] )
				? $reasons[ $code ]
				: __( 'Something went wrong saving the post.', 'pokoblog' );
		}

		$parts = [];

		$parts[] = ! empty( $entry['created'] )
			? __( 'Created a new post.', 'pokoblog' )
			: __( 'Updated the post it made earlier.', 'pokoblog' );

		if ( ! empty( $entry['conflict'] ) ) {
			$parts[] = sprintf(
				/* translators: %s: the address PokoBlog wanted to use. */
				__( 'Saved as a draft: something else on this site already uses the address %s.', 'pokoblog' ),
				$entry['conflict']
			);
		}

		if ( isset( $entry['image'] ) && false === $entry['image'] ) {
			$parts[] = __( 'The picture could not be fetched, so the post has none.', 'pokoblog' );
		}

		return implode( ' ', $parts );
	}
}
