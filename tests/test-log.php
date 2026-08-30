<?php
/**
 * The record of what this site did with each article.
 *
 * The three outcomes worth logging are the three that are deliberate,
 * invisible from PokoBlog's own screens, and easy to mistake for a fault: a
 * slug conflict writes a draft rather than stealing an address, a picture that
 * will not download is skipped rather than failing the article, and an article
 * in the bin is refused rather than restored.
 *
 * These are about the log saying so. What it must never do is affect the
 * delivery it is describing.
 */

require_once __DIR__ . '/bootstrap.php';

test(
	'a delivery is recorded, newest first',
	static function () {
		pokoblog_test_install_key();

		pokoblog_test_publish( [ 'article_id' => 'first' ] );
		pokoblog_test_publish( [ 'article_id' => 'second' ] );

		$entries = PokoBlog_Log::entries();

		assert_same( 2, count( $entries ), 'two deliveries' );
		assert_same( 'second', $entries[0]['article_id'], 'newest first' );
		assert_true( $entries[0]['ok'], 'ok' );
		assert_same( 201, $entries[0]['http'], 'created' );
	}
);

test(
	'a republish is described as an update rather than a new post',
	static function () {
		pokoblog_test_install_key();

		pokoblog_test_publish();
		pokoblog_test_publish();

		$entries = PokoBlog_Log::entries();

		assert_false( $entries[0]['created'], 'the second did not create' );
		assert_contains( 'Updated', PokoBlog_Log::describe( $entries[0] ), 'wording' );
		assert_contains( 'Created', PokoBlog_Log::describe( $entries[1] ), 'wording' );
	}
);

/*
 * The case that costs a customer readers if nobody tells them. The article is
 * published in PokoBlog and sitting as an unpublished draft on their site,
 * because something else already holds the address.
 */
test(
	'a slug conflict says the post was saved as a draft, and why',
	static function () {
		pokoblog_test_install_key();
		pokoblog_test_seed_post( 'wat-onderhoud-kost', 'page' );

		pokoblog_test_publish( [ 'slug' => 'wat-onderhoud-kost' ] );

		$entry = PokoBlog_Log::entries()[0];
		$said  = PokoBlog_Log::describe( $entry );

		assert_same( 'wat-onderhoud-kost', $entry['conflict'], 'the address' );
		assert_contains( 'draft', $said, 'says it is a draft' );
		assert_contains( 'wat-onderhoud-kost', $said, 'names the address' );
	}
);

/*
 * `featured_image_id` is null both when no picture was sent and when one was
 * sent and could not be fetched. Those are different problems, and the log is
 * the only place that says which happened.
 */
test(
	'no picture asked for is not the same as a picture that failed',
	static function () {
		pokoblog_test_install_key();

		pokoblog_test_publish(
			[
				'article_id'         => 'no-picture',
				'featured_image_url' => '',
			]
		);

		$entry = PokoBlog_Log::entries()[0];

		assert_same( null, $entry['image'], 'nothing was asked for' );
		assert_not_contains(
			'picture',
			PokoBlog_Log::describe( $entry ),
			'says nothing about a picture nobody asked for'
		);
	}
);

test(
	'a picture that was asked for and did not arrive says so',
	static function () {
		pokoblog_test_install_key();

		pokoblog_test_publish(
			[
				'article_id'         => 'wanted-a-picture',
				'featured_image_url' => 'https://example.test/nope.jpg',
			]
		);

		$entry = PokoBlog_Log::entries()[0];

		assert_false( $entry['image'], 'asked for, did not arrive' );
		assert_contains(
			'picture could not be fetched',
			PokoBlog_Log::describe( $entry ),
			'says so'
		);
	}
);

/*
 * A refusal is a delivery too, and the one somebody is most likely to come
 * looking for -- from PokoBlog's side it is a warning in a log they cannot
 * read.
 */
test(
	'a refusal is recorded with the reason in plain words',
	static function () {
		pokoblog_test_install_key();

		pokoblog_test_publish( [ 'title' => '' ] );

		$entry = PokoBlog_Log::entries()[0];

		assert_false( $entry['ok'], 'not ok' );
		assert_same( 'missing_fields', $entry['code'], 'the code' );
		assert_same( 400, $entry['http'], 'the status' );
		assert_contains(
			'incomplete',
			PokoBlog_Log::describe( $entry ),
			'plain words'
		);
	}
);

/*
 * A ring buffer, because this is one option rather than a table. The oldest
 * falling off is the intended behaviour, not a limitation to work around.
 */
/*
 * A refusal stopped before anything was fetched, so it has no picture verdict.
 * Recording one would report two problems where the site had one.
 */
test(
	'a refusal records no verdict about a picture',
	static function () {
		pokoblog_test_install_key();

		pokoblog_test_publish(
			[
				'title'              => '',
				'featured_image_url' => 'https://example.test/some.jpg',
			]
		);

		assert_same( null, PokoBlog_Log::entries()[0]['image'], 'no verdict' );
	}
);

test(
	'the log keeps a bounded number of deliveries',
	static function () {
		pokoblog_test_install_key();

		for ( $i = 0; $i < PokoBlog_Log::LIMIT + 5; $i++ ) {
			pokoblog_test_publish( [ 'article_id' => 'article-' . $i ] );
		}

		$entries = PokoBlog_Log::entries();

		assert_same( PokoBlog_Log::LIMIT, count( $entries ), 'bounded' );
		assert_same(
			'article-' . ( PokoBlog_Log::LIMIT + 4 ),
			$entries[0]['article_id'],
			'the newest survived'
		);
	}
);
