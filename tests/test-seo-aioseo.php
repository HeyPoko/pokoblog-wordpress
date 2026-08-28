<?php
/**
 * All in One SEO, which is the one of the three that does not use post meta.
 *
 * Since AIOSEO 4.0 a post's SEO data lives in the plugin's own
 * `{prefix}aioseo_posts` table and is written through
 * `AIOSEO\Plugin\Common\Models\Post::savePost( $postId, $data )`. The fake
 * below is that class's shape: a static `savePost` recording what it was given,
 * plus the `aioseo()` function whose existence is how the plugin announces
 * itself.
 *
 * This file's fake site has AIOSEO installed and the other two absent -- the
 * mirror of `test-seo.php`, and the reason the runner gives each file its own
 * process.
 */

namespace AIOSEO\Plugin\Common\Models {

	class Post {

		/** Everything `savePost` was called with, by post id. */
		public static $saved = [];

		public static function savePost( $post_id, $data ) {
			self::$saved[ (int) $post_id ] = $data;
		}
	}
}

namespace {

	require_once __DIR__ . '/bootstrap.php';

	/** AIOSEO's global accessor. Its presence is the detection. */
	function aioseo() {
		return new \stdClass();
	}

	test(
		'the meta description reaches AIOSEO through its own model, not through post meta',
		static function () {
			\AIOSEO\Plugin\Common\Models\Post::$saved = [];

			pokoblog_test_install_key();

			assert_true( PokoBlog_SEO::has_aioseo(), 'fixture: AIOSEO is installed:' );
			assert_false( PokoBlog_SEO::has_yoast(), 'fixture: Yoast is absent:' );

			$response = pokoblog_test_publish(
				[
					'meta_title'       => 'Magento migratie in 2026',
					'meta_description' => 'Alles over een Magento migratie in 2026.',
					'focus_keyword'    => 'magento migratie',
				]
			);

			$id    = $response->get_data()['post_id'];
			$saved = \AIOSEO\Plugin\Common\Models\Post::$saved;

			assert_true( isset( $saved[ $id ] ), 'savePost was called for the post:' );
			assert_same( 'Magento migratie in 2026', $saved[ $id ]['title'], 'title' );
			assert_same(
				'Alles over een Magento migratie in 2026.',
				$saved[ $id ]['description'],
				'description'
			);
			/* The nested shape AIOSEO's own editor posts. A flat string is ignored. */
			assert_same(
				'magento migratie',
				$saved[ $id ]['keyphrases']['focus']['keyphrase'],
				'focus keyphrase'
			);

			/*
			 * And nothing in post meta. This is the assertion that distinguishes
			 * a working AIOSEO bridge from the one that looks like it works:
			 * writing `_aioseo_description` succeeds, is visible in the
			 * database, and never appears on the site.
			 */
			$meta = get_post_meta( $id );

			foreach ( [ '_aioseo_title', '_aioseo_description', '_aioseo_focus_keyword' ] as $key ) {
				assert_false( array_key_exists( $key, $meta ), 'wrote ' . $key . ':' );
			}

			/* Yoast is absent here, so its keys are absent too. */
			foreach ( [ '_yoast_wpseo_title', '_yoast_wpseo_metadesc' ] as $key ) {
				assert_false( array_key_exists( $key, $meta ), 'wrote ' . $key . ':' );
			}
		}
	);

	test(
		'a model that throws does not turn a completed publish into a failure',
		static function () {
			pokoblog_test_install_key();

			/*
			 * By the time the bridge runs, the post is on the customer's site.
			 * Reporting a failure would make PokoBlog retry a publish that has
			 * already happened -- harmless only because idempotency exists, and
			 * not something to lean on.
			 */
			assert_false(
				PokoBlog_SEO::after_save( 999999, PokoBlog_Payload::normalize( [] ) ),
				'nothing to write:'
			);

			$response = pokoblog_test_publish();

			assert_same( 201, $response->get_status(), 'status' );
		}
	);
}
