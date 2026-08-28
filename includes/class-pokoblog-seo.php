<?php
/**
 * Handing the meta title, meta description and focus keyword to whichever SEO
 * plugin the customer already runs.
 *
 * PokoBlog writes a meta description for every article and knows the phrase the
 * article was written for. WordPress core has nowhere to put either: it has no
 * meta description field and no concept of a focus keyword. Both live in the
 * SEO plugin, and the customer has already chosen one -- so this class writes
 * into the one they chose and does not ask them to choose again.
 *
 * ## Only what is installed
 *
 * Writing every plugin's keys unconditionally is the obvious shortcut and it is
 * wrong twice over. It leaves rows in `wp_postmeta` for plugins the customer
 * does not have, which is litter in a table that is already the largest one on
 * most sites; and it makes the response lie, because "we bridged your SEO
 * plugin" is then true of a site with no SEO plugin at all. Each bridge below
 * is gated on a constant or a function that only the plugin itself defines.
 *
 * ## Where the key names come from
 *
 * Read out of each plugin's own source rather than from memory or from another
 * connector, because a meta key that is nearly right is invisible: the write
 * succeeds, the row is there, and the site's `<head>` never changes.
 *
 * - Yoast SEO 28.x -- `inc/class-wpseo-meta.php` builds every key from
 *   `$meta_prefix = '_yoast_wpseo_'` plus the field name, and the `general`
 *   group declares `title`, `metadesc` and `focuskw`. The plugin defines
 *   `WPSEO_VERSION` in `wp-seo-main.php`.
 * - Rank Math 1.x -- reads `rank_math_title`, `rank_math_description` and
 *   `rank_math_focus_keyword` as ordinary post meta (its `Meta` trait
 *   special-cases the last one by name). It defines `RANK_MATH_VERSION`.
 * - All in One SEO 4.x and 5.x -- does **not** use post meta. Since 4.0 a
 *   post's SEO data lives in its own `{prefix}aioseo_posts` table, and the
 *   plugin's front end reads it from there. `_aioseo_title` and
 *   `_aioseo_description` do exist in `wp_postmeta`, and this is the trap: AIOSEO
 *   *writes* them, for multilingual plugins to translate (`MetaData.php` and
 *   `Filters::defineMetaFieldsForWpml`), and never reads them back. A connector
 *   that sets `_aioseo_description` therefore appears to work and changes
 *   nothing on the site. There is no `_aioseo_focus_keyword` key anywhere in
 *   AIOSEO at all. So the bridge below goes through the plugin's own model --
 *   `AIOSEO\Plugin\Common\Models\Post::savePost( $post_id, $data )`, present
 *   since 4.0.0 and patch-friendly, so it sets only the fields we send.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PokoBlog_SEO {

	/** Yoast SEO, any version that has defined its version constant. */
	public static function has_yoast() {
		return defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Meta' );
	}

	/** Rank Math. */
	public static function has_rank_math() {
		return defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' );
	}

	/**
	 * All in One SEO, and specifically a version whose model we can call.
	 *
	 * Both halves matter. `aioseo()` says the plugin is loaded; `method_exists`
	 * says the storage API we are about to use is the one this version has. A
	 * future AIOSEO that renames `savePost` should make this bridge go quiet
	 * rather than fatal on a customer's site.
	 */
	public static function has_aioseo() {
		return function_exists( 'aioseo' )
			&& method_exists( 'AIOSEO\Plugin\Common\Models\Post', 'savePost' );
	}

	/** Which of the three are present, for the response. */
	public static function detected() {
		return [
			'yoast'     => self::has_yoast(),
			'rank_math' => self::has_rank_math(),
			'aioseo'    => self::has_aioseo(),
		];
	}

	/**
	 * The post meta to pass to `wp_insert_post` / `wp_update_post`.
	 *
	 * Returned as `meta_input` rather than written with `update_post_meta`
	 * after the insert, and the difference is not cosmetic. Yoast and Rank Math
	 * both build their internal index of a post on `save_post`; core applies
	 * `meta_input` before that hook fires. Written afterwards, the first render
	 * of the post would use an index built from meta that was not there yet, and
	 * the customer would see the description appear only after the next edit.
	 *
	 * Empty values are skipped rather than written as empty strings. An empty
	 * `_yoast_wpseo_metadesc` is not "no opinion", it is "the description is
	 * deliberately blank", and it would override a description the customer had
	 * typed in themselves on a republish.
	 */
	public static function meta_input( $normalized ) {
		$meta = [];

		$title       = $normalized['meta_title'];
		$description = $normalized['meta_description'];
		$keyword     = $normalized['focus_keyword'];

		if ( self::has_yoast() ) {
			if ( $title !== '' ) {
				$meta['_yoast_wpseo_title'] = $title;
			}
			if ( $description !== '' ) {
				$meta['_yoast_wpseo_metadesc'] = $description;
			}
			if ( $keyword !== '' ) {
				$meta['_yoast_wpseo_focuskw'] = $keyword;
			}
		}

		if ( self::has_rank_math() ) {
			if ( $title !== '' ) {
				$meta['rank_math_title'] = $title;
			}
			if ( $description !== '' ) {
				$meta['rank_math_description'] = $description;
			}
			if ( $keyword !== '' ) {
				$meta['rank_math_focus_keyword'] = $keyword;
			}
		}

		return $meta;
	}

	/**
	 * The half that cannot be done through `meta_input`: AIOSEO.
	 *
	 * Its storage is a row in another table keyed on the post id, so the post
	 * has to exist first. Called immediately after the insert or update, which
	 * is early enough -- AIOSEO reads its own table at render time rather than
	 * caching from `save_post`.
	 *
	 * Wrapped in a catch-all, and it is `Throwable` rather than `Exception`
	 * because a signature change in a plugin we do not control raises `Error`.
	 * A publish that succeeded must not be reported as a failure because a third
	 * party's model threw: the post is already on the site by this point, and an
	 * error here would make PokoBlog retry a publish that has already happened.
	 */
	public static function after_save( $post_id, $normalized ) {
		if ( ! self::has_aioseo() ) {
			return false;
		}

		$data = [];

		if ( $normalized['meta_title'] !== '' ) {
			$data['title'] = $normalized['meta_title'];
		}
		if ( $normalized['meta_description'] !== '' ) {
			$data['description'] = $normalized['meta_description'];
		}
		if ( $normalized['focus_keyword'] !== '' ) {
			/*
			 * The nested shape AIOSEO's own editor posts. `getKeyphrasesDefaults`
			 * fills in the score and analysis it expects beside the phrase, so
			 * sending the phrase alone is enough and sending a flat string is
			 * not.
			 */
			$data['keyphrases'] = [ 'focus' => [ 'keyphrase' => $normalized['focus_keyword'] ] ];
		}

		if ( empty( $data ) ) {
			return false;
		}

		try {
			call_user_func( [ 'AIOSEO\Plugin\Common\Models\Post', 'savePost' ], $post_id, $data );

			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Ask Yoast to rebuild its index entry for a post.
	 *
	 * Yoast 14 and later keep a denormalized copy of every post in its own
	 * `indexable` table and serve the front end from that, not from post meta.
	 * A post written by `wp_insert_post` does get an indexable built on
	 * `save_post` -- but one written by a REST request with no logged-in user
	 * has, in practice, been observed to get one built before the meta lands in
	 * some orderings. Rebuilding costs one query on a path that runs once per
	 * article and removes the whole class of "the description is in the database
	 * and not on the page".
	 *
	 * Silent on failure by design. This is a best-effort nudge to another
	 * plugin's internals; there is nothing a customer could do with the error,
	 * and the post is already saved.
	 */
	public static function refresh_yoast( $post_id ) {
		if ( ! function_exists( 'YoastSEO' ) ) {
			return false;
		}

		try {
			$container  = YoastSEO()->classes;
			$repository = $container->get( 'Yoast\WP\SEO\Repositories\Indexable_Repository' );
			$builder    = $container->get( 'Yoast\WP\SEO\Builders\Indexable_Builder' );
			$indexable  = $repository->find_by_id_and_type( $post_id, 'post' );

			if ( $indexable ) {
				$builder->build_for_id_and_type( $post_id, 'post', $indexable );
				$indexable->save();
			}

			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}
}
