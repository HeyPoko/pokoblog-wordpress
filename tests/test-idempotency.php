<?php
/**
 * Republishing.
 *
 * PokoBlog sends the same article twice more often than it looks: a delivery
 * that timed out and was retried, an article the customer regenerated, an
 * article they pressed publish on again. Every one of those has to land on the
 * post that already exists.
 */

require_once __DIR__ . '/bootstrap.php';

test(
	'the same article sent twice updates one post rather than creating two',
	static function () {
		pokoblog_test_install_key();

		$first = pokoblog_test_publish();

		assert_same( 201, $first->get_status(), 'first delivery' );
		assert_true( $first->get_data()['created'], 'first delivery created a post:' );

		$second = pokoblog_test_publish(
			[
				'title'   => 'Magento migratie in 2026, herzien',
				'content' => '<p>Herschreven.</p>',
			]
		);

		assert_same( 200, $second->get_status(), 'second delivery' );
		assert_false( $second->get_data()['created'], 'second delivery created a post:' );

		/* One post, not two. */
		assert_same( 1, count( pokoblog_test_posts() ), 'posts on the site' );

		/* And the same one. */
		assert_same(
			$first->get_data()['post_id'],
			$second->get_data()['post_id'],
			'post id'
		);

		/*
		 * Updated, not merely found. Returning the existing post untouched
		 * would satisfy "no duplicates" and would mean a regenerated article
		 * never reaches the customer's site -- so the assertion is on the
		 * content, not on the count alone.
		 */
		assert_same(
			'Magento migratie in 2026, herzien',
			get_post_field( 'post_title', $second->get_data()['post_id'] ),
			'title'
		);
		assert_contains(
			'Herschreven.',
			get_post_field( 'post_content', $second->get_data()['post_id'] ),
			'body'
		);
	}
);

test(
	'two different articles get two posts',
	static function () {
		pokoblog_test_install_key();

		pokoblog_test_publish( [ 'article_id' => 'art_0001', 'slug' => 'een' ] );
		pokoblog_test_publish( [ 'article_id' => 'art_0002', 'slug' => 'twee' ] );

		assert_same( 2, count( pokoblog_test_posts() ), 'posts on the site' );
	}
);

test(
	'a request with no article id is refused rather than published',
	static function () {
		pokoblog_test_install_key();

		$response = pokoblog_test_publish( [ 'article_id' => '' ] );

		assert_same( 400, $response->get_status(), 'status' );
		assert_same( [ 'article_id' ], $response->get_data()['missing'], 'missing fields' );
		assert_same( 0, count( pokoblog_test_posts() ), 'posts created' );
	}
);

test(
	'a republish does not unpublish a post that is already live',
	static function () {
		pokoblog_test_install_key();

		$first = pokoblog_test_publish( [ 'status' => 'publish' ] );

		assert_same( 'publish', get_post_status( $first->get_data()['post_id'] ), 'first status' );

		/*
		 * The customer has since switched PokoBlog to draft mode. That is a
		 * statement about articles they have not seen yet, not a request to
		 * take a live page off their blog.
		 */
		$second = pokoblog_test_publish( [ 'status' => 'draft' ] );

		assert_same( 'publish', get_post_status( $second->get_data()['post_id'] ), 'status after' );
	}
);

test(
	'an article whose post the customer trashed is left in the trash',
	static function () {
		pokoblog_test_install_key();

		$first = pokoblog_test_publish();
		$id    = $first->get_data()['post_id'];

		$GLOBALS['pokoblog_test']['posts'][ $id ]['post_status'] = 'trash';

		$second = pokoblog_test_publish();

		assert_same( 409, $second->get_status(), 'status' );
		assert_same( 'trashed', $second->get_data()['code'], 'code' );
		assert_same( 'trash', get_post_status( $id ), 'the post stays in the trash:' );

		/* And no second copy quietly appears beside it. */
		assert_same( 1, count( pokoblog_test_posts() ), 'posts on the site' );
	}
);

test(
	'a delivery arriving while another is running is refused rather than duplicated',
	static function () {
		pokoblog_test_install_key();

		$payload = PokoBlog_Payload::normalize( pokoblog_test_payload() );

		/*
		 * Standing in for the first request, which in production is still
		 * inside `write()` downloading a featured image when the retry arrives.
		 * The lock is an option row, so holding it by hand is the same state
		 * the real first request would have left.
		 */
		add_option( 'pokoblog_lock_' . md5( $payload['article_id'] ), (string) time(), '', false );

		$response = pokoblog_test_publish();

		assert_same( 409, $response->get_status(), 'status' );
		assert_same( 'locked', $response->get_data()['code'], 'code' );
		assert_same( 0, count( pokoblog_test_posts() ), 'posts created' );
	}
);

test(
	'a lock left behind by a crashed request does not wedge the article forever',
	static function () {
		pokoblog_test_install_key();

		$payload = PokoBlog_Payload::normalize( pokoblog_test_payload() );

		add_option(
			'pokoblog_lock_' . md5( $payload['article_id'] ),
			(string) ( time() - PokoBlog_Publisher::LOCK_SECONDS - 1 ),
			'',
			false
		);

		$response = pokoblog_test_publish();

		assert_same( 201, $response->get_status(), 'status' );
	}
);

test(
	'the lock is released so the next delivery is not refused',
	static function () {
		pokoblog_test_install_key();

		pokoblog_test_publish();

		$second = pokoblog_test_publish();

		assert_same( 200, $second->get_status(), 'status' );
	}
);
