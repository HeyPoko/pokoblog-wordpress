<?php
/**
 * What the customer gets when the address PokoBlog promised is already taken.
 *
 * The decision, argued at length on `PokoBlog_Publisher::slug_holder()`: the
 * article is written in full as a draft at the address we asked for, nothing
 * goes live at a different address, and the response says what happened so the
 * article screen can too.
 */

require_once __DIR__ . '/bootstrap.php';

test(
	'an article whose address is taken lands as a draft, not published at a different address',
	static function () {
		pokoblog_test_install_key();

		/* The customer's own page, at the address PokoBlog wanted. */
		$theirs = pokoblog_test_seed_post( 'magento-migratie', 'page' );

		$response = pokoblog_test_publish( [ 'slug' => 'magento-migratie', 'status' => 'publish' ] );
		$data     = $response->get_data();

		assert_same( 201, $response->get_status(), 'status' );

		/*
		 * Not published. This is the whole decision: PokoBlog has already shown
		 * the customer a "View on website" link pointing at
		 * /magento-migratie, and publishing at /magento-migratie-2 while
		 * continuing to show that link is the product stating something untrue
		 * about the customer's own site.
		 */
		assert_same( 'draft', $data['status'], 'the article is a draft:' );

		/*
		 * And at the address we asked for, not a uniquified one. WordPress does
		 * not run `wp_unique_post_slug` on a draft, so the draft holds the name
		 * it was given -- which is what makes the conflict visible to the
		 * customer when they come to publish it rather than baked in already.
		 */
		assert_same( 'magento-migratie', $data['slug'], 'slug' );

		/* The customer's page is untouched, at its own address. */
		assert_same( 'publish', get_post_status( $theirs ), 'their page status:' );
		assert_same( 'magento-migratie', get_post_field( 'post_name', $theirs ), 'their page slug:' );

		/* The writing is not lost. */
		assert_contains(
			'Een artikel over migreren.',
			get_post_field( 'post_content', $data['post_id'] ),
			'body'
		);

		/* And PokoBlog is told, by whom, so a person can be shown the choice. */
		assert_same( 'magento-migratie', $data['slug_conflict']['requested'], 'reported slug' );
		assert_same( $theirs, $data['slug_conflict']['held_by'], 'reported holder' );
	}
);

test(
	'an article whose address is free is published at it',
	static function () {
		pokoblog_test_install_key();

		$data = pokoblog_test_publish( [ 'slug' => 'magento-migratie' ] )->get_data();

		assert_same( 'publish', $data['status'], 'status' );
		assert_same( 'magento-migratie', $data['slug'], 'slug' );
		assert_same( null, $data['slug_conflict'], 'conflict' );
	}
);

test(
	'a republish is not a conflict with its own post',
	static function () {
		pokoblog_test_install_key();

		$first = pokoblog_test_publish( [ 'slug' => 'magento-migratie' ] );

		assert_same( 'publish', $first->get_data()['status'], 'first status' );

		/*
		 * The second delivery finds a post at `magento-migratie` -- its own.
		 * Counting that as a conflict would demote a live article to a draft on
		 * every republish, which is the failure the conflict check exists to
		 * prevent, arriving by the other door.
		 */
		$second = pokoblog_test_publish( [ 'slug' => 'magento-migratie' ] )->get_data();

		assert_same( null, $second['slug_conflict'], 'conflict' );
		assert_same( 'publish', $second['status'], 'status' );
		assert_same( $first->get_data()['post_id'], $second['post_id'], 'post id' );
	}
);

test(
	'a draft the customer has not published yet still holds its address',
	static function () {
		pokoblog_test_install_key();

		/*
		 * A conflict with a draft counts. Its address is not serving anything
		 * yet, but it is claimed -- and publishing over it would produce two
		 * posts fighting for one URL the moment the customer publishes theirs.
		 */
		pokoblog_test_seed_post( 'magento-migratie', 'post', 'draft' );

		$data = pokoblog_test_publish( [ 'slug' => 'magento-migratie' ] )->get_data();

		assert_same( 'draft', $data['status'], 'status' );
		assert_true( is_array( $data['slug_conflict'] ), 'a conflict is reported:' );
	}
);

test(
	'a conflict that has since been cleared lets the next delivery publish',
	static function () {
		pokoblog_test_install_key();

		$theirs = pokoblog_test_seed_post( 'magento-migratie', 'page' );

		$blocked = pokoblog_test_publish( [ 'slug' => 'magento-migratie' ] )->get_data();

		assert_same( 'draft', $blocked['status'], 'blocked status' );

		/* The customer moves their page out of the way. */
		$GLOBALS['pokoblog_test']['posts'][ $theirs ]['post_name'] = 'magento-migratie-oud';

		$freed = pokoblog_test_publish( [ 'slug' => 'magento-migratie' ] )->get_data();

		assert_same( 'publish', $freed['status'], 'status' );
		assert_same( null, $freed['slug_conflict'], 'conflict' );
	}
);
