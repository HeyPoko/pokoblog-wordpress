<?php
/**
 * Turning one publish request into one post.
 *
 * Everything difficult about this connector is in this file: not duplicating,
 * not stealing an address that belongs to somebody else's page, and not
 * un-publishing something the customer has already got readers on.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PokoBlog_Publisher {

	/**
	 * The post meta that ties a WordPress post to a PokoBlog article.
	 *
	 * This one key is the entire idempotency mechanism, which is why it is a
	 * constant and why `uninstall.php` is the only other file that mentions it.
	 */
	const ARTICLE_ID_META = '_pokoblog_article_id';

	/** When we last wrote this post. Bookkeeping; nothing depends on it. */
	const WRITTEN_AT_META = '_pokoblog_written_at';

	/**
	 * The statuses a post can be in and still be the post we made.
	 *
	 * `trash` is deliberately absent and handled separately below. Everything
	 * else a post can ordinarily be is here, including the ones this plugin
	 * never sets, because the customer may have moved it there by hand and it is
	 * still their copy of our article.
	 */
	const LIVE_STATUSES = [ 'publish', 'draft', 'pending', 'private', 'future' ];

	/** How long a publish may hold the lock before another request may break it. */
	const LOCK_SECONDS = 120;

	/**
	 * Publish, or republish.
	 *
	 * Returns an array the REST layer turns into a response. It never throws:
	 * everything that can go wrong here is a state PokoBlog needs to be told
	 * about in a form it can act on, and an exception crossing this boundary
	 * becomes a 500 that says nothing.
	 */
	public static function publish( $normalized ) {
		$article_id = $normalized['article_id'];

		if ( ! self::lock( $article_id ) ) {
			/*
			 * Another request is already writing this exact article.
			 *
			 * This is not a theoretical race. PokoBlog's delivery has a timeout;
			 * a site that takes longer than that -- because it is downloading a
			 * featured image over a slow link, which is the normal case -- gets
			 * the request abandoned and retried while the first one is still
			 * running. Both would then look up the article id, both would find
			 * nothing, and both would insert. The customer ends up with two
			 * copies of one article and no way to tell which is which.
			 *
			 * The lock is `add_option`, which fails when the row exists, and the
			 * options table has a unique index on `option_name` -- so the winner
			 * is decided by the database rather than by which request read first.
			 * Refusing the second one is the whole point: a publish that is told
			 * to come back is recoverable, and a duplicated blog is not.
			 */
			return self::failure( 'locked' );
		}

		try {
			return self::write( $normalized );
		} catch ( \Throwable $e ) {
			return self::failure( 'exception', $e->getMessage() );
		} finally {
			self::unlock( $article_id );
		}
	}

	private static function write( $normalized ) {
		$existing = self::find_by_article_id( $normalized['article_id'] );

		if ( $existing !== null && get_post_status( $existing ) === 'trash' ) {
			/*
			 * The customer put our post in the bin. Leave it there.
			 *
			 * Both other answers are worse. Restoring it overrules a deliberate
			 * act of the person whose site this is; creating a second post
			 * quietly re-adds the thing they just deleted, and leaves a copy in
			 * the trash to be restored later into a duplicate. Reporting it back
			 * is the only option that lets PokoBlog say something true on the
			 * article screen.
			 */
			return self::failure( 'trashed', '', $existing );
		}

		$slug     = PokoBlog_Payload::slug_for( $normalized );
		$holder   = self::slug_holder( $slug, $existing );
		$conflict = $holder !== null;

		$status = self::resolve_status(
			$normalized['status'],
			$existing === null ? '' : (string) get_post_status( $existing ),
			$conflict
		);

		$fields = [
			'post_type'    => 'post',
			'post_title'   => $normalized['title'],
			'post_content' => $normalized['content'],
			'post_status'  => $status,
			'post_name'    => $slug,
			'meta_input'   => array_merge(
				PokoBlog_SEO::meta_input( $normalized ),
				[
					self::ARTICLE_ID_META => $normalized['article_id'],
					self::WRITTEN_AT_META => current_time( 'mysql' ),
				]
			),
		];

		if ( $normalized['excerpt'] !== '' ) {
			$fields['post_excerpt'] = $normalized['excerpt'];
		}

		$category = self::category_for( $normalized['category'] );

		if ( $category > 0 ) {
			$fields['post_category'] = [ $category ];
		}

		if ( $existing === null ) {
			$author = self::author();

			if ( $author > 0 ) {
				$fields['post_author'] = $author;
			}

			$post_id = wp_insert_post( $fields, true );
		} else {
			/*
			 * The author is set on insert and never on update. Whoever the
			 * customer has since assigned the post to is their decision about
			 * their own site, and a republish that reassigns it back is this
			 * plugin editing bylines.
			 */
			$fields['ID'] = $existing;
			$post_id      = wp_update_post( $fields, true );
		}

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return self::failure(
				'write_failed',
				is_wp_error( $post_id ) ? $post_id->get_error_message() : ''
			);
		}

		$post_id = (int) $post_id;

		PokoBlog_SEO::after_save( $post_id, $normalized );
		PokoBlog_SEO::refresh_yoast( $post_id );

		/*
		 * The image last, and its failure is not the request's failure.
		 *
		 * By this line the post exists on the customer's site. Reporting a
		 * failed publish now would be false, and worse than false: PokoBlog
		 * would retry, and the retry would find the article id and update the
		 * post it already made -- which is harmless today only because
		 * idempotency exists. Reporting the truth, "published, no picture", is
		 * both accurate and the thing a customer can act on.
		 */
		$image = PokoBlog_Media::attach(
			$post_id,
			$normalized['featured_image_url'],
			$normalized['featured_image_alt']
		);

		return [
			'ok'                => true,
			'code'              => '',
			'error'             => '',
			'post_id'           => $post_id,
			'created'           => $existing === null,
			'status'            => get_post_status( $post_id ),
			'slug'              => get_post_field( 'post_name', $post_id ),
			'slug_conflict'     => $conflict ? [
				'requested' => $slug,
				'held_by'   => $holder,
			] : null,
			'featured_image_id' => $image,
			'seo'               => PokoBlog_SEO::detected(),
		];
	}

	/**
	 * What status this write should leave the post in.
	 *
	 * Three rules, in order.
	 *
	 * A slug conflict forces `draft`, whatever was asked for. See
	 * `slug_holder()` for the argument.
	 *
	 * A post that is already published stays published, even when the request
	 * asks for a draft. A customer who switches PokoBlog to draft mode is saying
	 * "let me review the next ones", not "take the article that has been on my
	 * blog for a month off it" -- and a connector that can silently unpublish is
	 * a connector that will one day unpublish a page with links pointing at it.
	 * The same reasoning covers `pending`, `private` and `future`: if the
	 * customer has moved our post somewhere, that is where it stays.
	 *
	 * So the only status transition this plugin makes on an existing post is
	 * `draft` to `publish`, which is a customer pressing publish.
	 */
	public static function resolve_status( $requested, $existing_status, $conflict ) {
		if ( $conflict ) {
			return 'draft';
		}

		if ( $existing_status === '' || $existing_status === 'draft' ) {
			return $requested;
		}

		return $existing_status;
	}

	/**
	 * The post this article was published as last time, or null.
	 *
	 * Looked up by meta rather than by slug, which is the difference between
	 * idempotency and hope: a customer who renames the post, or an article whose
	 * slug PokoBlog changed, would both be invisible to a lookup by address.
	 *
	 * `post_type` is `any` rather than `post`. If somebody has converted our
	 * post to a page, it is still the post we made, and finding it is what stops
	 * us making a second one beside it.
	 */
	public static function find_by_article_id( $article_id ) {
		if ( ! is_string( $article_id ) || $article_id === '' ) {
			return null;
		}

		$found = get_posts(
			[
				'post_type'        => 'any',
				'post_status'      => array_merge( self::LIVE_STATUSES, [ 'trash' ] ),
				'meta_key'         => self::ARTICLE_ID_META,
				'meta_value'       => $article_id,
				'numberposts'      => 1,
				'fields'           => 'ids',
				'suppress_filters' => false,
				/* No pagination count needed for one row, and it costs a query. */
				'no_found_rows'    => true,
			]
		);

		if ( empty( $found ) ) {
			return null;
		}

		return (int) $found[0];
	}

	/**
	 * The id of a post or page already sitting at this address, or null.
	 *
	 * ## What we do when the address is taken, and why
	 *
	 * WordPress's own answer is to publish at `magento-migratie-2`. That is the
	 * wrong answer for this connector, because PokoBlog has already told the
	 * customer -- on the article screen, in the "View on website" link -- what
	 * address the article will have. Publishing somewhere else while continuing
	 * to show that link is not a small inconsistency; it is the product stating
	 * something untrue about the customer's own site, and it does it silently.
	 *
	 * Taking the address is worse still. The page already there is the
	 * customer's, they chose its URL, and it may be the page their ads point at.
	 *
	 * So the third answer: the article is written, in full, as a **draft**, and
	 * the response says the address was taken and by which post. Nothing goes
	 * live at the wrong address, nothing of the customer's is overwritten, the
	 * writing is not lost, and PokoBlog records a delivery -- which matters,
	 * because a request that simply failed would be retried, and a retry that
	 * fails the same way every night is a connector that looks broken instead of
	 * a decision that needs a person.
	 *
	 * The cost, stated plainly: an article can land as a draft the customer has
	 * to publish by hand, and if they never look at their WordPress drafts they
	 * will not know. That is why the conflict is in the response rather than
	 * only in a log -- it is PokoBlog's job to say so on the article screen.
	 *
	 * One detail this turns on: WordPress does not uniquify the slug of a draft.
	 * `wp_insert_post` skips `wp_unique_post_slug` for `draft`, `pending` and
	 * `auto-draft`, so the draft keeps the address we asked for and the customer
	 * sees the conflict when they publish rather than finding a `-2` already
	 * baked in.
	 *
	 * `$self` is the post this same article was published as before, and
	 * excluding it is not an optimisation. Without it, the second delivery of
	 * every article would find its own post holding its own slug, call that a
	 * conflict, and demote a live post to a draft -- which is the exact failure
	 * this function exists to prevent, arriving by the other door.
	 */
	public static function slug_holder( $slug, $self = null ) {
		if ( ! is_string( $slug ) || $slug === '' ) {
			return null;
		}

		$found = get_posts(
			[
				/*
				 * Posts and pages. Both share the permalink space at the root of
				 * most sites, so a page called `over-ons` and a post called
				 * `over-ons` are a real collision even though they are different
				 * post types. Attachments and custom types are not checked: they
				 * live under their own prefixes on a default install, and
				 * widening this would start refusing addresses that are free.
				 */
				'post_type'        => [ 'post', 'page' ],
				'post_status'      => self::LIVE_STATUSES,
				'name'             => $slug,
				'numberposts'      => 2,
				'fields'           => 'ids',
				'suppress_filters' => false,
				'no_found_rows'    => true,
			]
		);

		foreach ( $found as $id ) {
			if ( $self === null || (int) $id !== (int) $self ) {
				return (int) $id;
			}
		}

		return null;
	}

	/**
	 * The category id for a name, or the one configured in the settings, or 0.
	 *
	 * A name that matches nothing falls through to the configured default rather
	 * than creating a category. Creating taxonomy terms on somebody's site
	 * because a JSON field had a typo in it is not this plugin's business.
	 */
	private static function category_for( $name ) {
		if ( $name !== '' ) {
			$id = get_cat_ID( $name );

			if ( $id > 0 ) {
				return (int) $id;
			}
		}

		return (int) get_option( 'pokoblog_post_category', 0 );
	}

	private static function author() {
		return (int) get_option( 'pokoblog_post_author', 0 );
	}

	private static function lock_name( $article_id ) {
		/*
		 * Hashed rather than concatenated. `option_name` is 191 characters and
		 * an article id is ours, so a raw id would fit today -- but a lock whose
		 * name is truncated is a lock two different articles can share, and the
		 * failure mode is one article silently refusing to publish because
		 * another one is.
		 */
		return 'pokoblog_lock_' . md5( $article_id );
	}

	/**
	 * Take the lock, or fail.
	 *
	 * `add_option` rather than `get_option` then `update_option`: the read and
	 * the write in that pair are two statements, and two requests can both pass
	 * the read. `add_option` is one `INSERT` against a unique index, so exactly
	 * one of them can win.
	 *
	 * Autoload is off. A lock that is loaded into memory on every request of
	 * every page is a lock that costs the whole site something.
	 *
	 * The staleness branch is what stops a crashed request wedging an article
	 * forever. It is a small hole -- two requests can both decide a lock is
	 * stale -- and it is the right size of hole: it needs a first request to
	 * have died mid-write and a second and third to arrive in the same
	 * millisecond two minutes later, against a duplicate that would otherwise be
	 * permanent.
	 */
	private static function lock( $article_id ) {
		$name = self::lock_name( $article_id );

		if ( add_option( $name, (string) time(), '', false ) ) {
			return true;
		}

		$taken = (int) get_option( $name, 0 );

		if ( $taken > 0 && ( time() - $taken ) < self::LOCK_SECONDS ) {
			return false;
		}

		delete_option( $name );

		return (bool) add_option( $name, (string) time(), '', false );
	}

	private static function unlock( $article_id ) {
		delete_option( self::lock_name( $article_id ) );
	}

	private static function failure( $code, $error = '', $post_id = null ) {
		return [
			'ok'                => false,
			'code'              => $code,
			'error'             => $error,
			'post_id'           => $post_id,
			'created'           => false,
			'status'            => '',
			'slug'              => '',
			'slug_conflict'     => null,
			'featured_image_id' => null,
			'seo'               => PokoBlog_SEO::detected(),
		];
	}
}
