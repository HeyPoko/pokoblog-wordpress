<?php
/**
 * Bridging the meta description and the focus keyword into the customer's SEO
 * plugin -- and into no other.
 *
 * This file's fake site has **Yoast SEO installed and Rank Math and All in One
 * SEO not installed**, which is why both halves of the rule can be asserted in
 * one place. That is also why the runner starts a fresh PHP process per file:
 * `WPSEO_VERSION` cannot be undefined once defined, so "installed" and "not
 * installed" have to be different processes, and `tests/test-seo-aioseo.php` is
 * the other one.
 */

require_once __DIR__ . '/bootstrap.php';

/* Yoast, at the version constant its own `wp-seo-main.php` defines. */
define( 'WPSEO_VERSION', '28.4' );

test(
	'meta is written for the installed SEO plugin and not for the absent one',
	static function () {
		pokoblog_test_install_key();

		assert_true( PokoBlog_SEO::has_yoast(), 'fixture: Yoast is installed:' );
		assert_false( PokoBlog_SEO::has_rank_math(), 'fixture: Rank Math is absent:' );
		assert_false( PokoBlog_SEO::has_aioseo(), 'fixture: AIOSEO is absent:' );

		$response = pokoblog_test_publish(
			[
				'meta_title'       => 'Magento migratie in 2026',
				'meta_description' => 'Alles over een Magento migratie in 2026.',
				'focus_keyword'    => 'magento migratie',
			]
		);

		$meta = get_post_meta( $response->get_data()['post_id'] );

		/* Yoast is installed, so its three keys carry the article's SEO fields. */
		assert_same(
			'Magento migratie in 2026',
			isset( $meta['_yoast_wpseo_title'] ) ? $meta['_yoast_wpseo_title'] : null,
			'yoast title'
		);
		assert_same(
			'Alles over een Magento migratie in 2026.',
			isset( $meta['_yoast_wpseo_metadesc'] ) ? $meta['_yoast_wpseo_metadesc'] : null,
			'yoast description'
		);
		assert_same(
			'magento migratie',
			isset( $meta['_yoast_wpseo_focuskw'] ) ? $meta['_yoast_wpseo_focuskw'] : null,
			'yoast focus keyword'
		);

		/*
		 * Rank Math is not installed, so not one of its keys is written.
		 *
		 * Writing them anyway is the tempting shortcut -- it is three more array
		 * entries and it "works" for whoever installs Rank Math later. What it
		 * actually does is leave rows in `wp_postmeta` for a plugin the customer
		 * does not have, in the largest table on most WordPress sites, times
		 * every article for the life of the account. It also makes the response
		 * lie: `seo.rank_math` would be reported false while its keys were being
		 * written.
		 */
		foreach ( [ 'rank_math_title', 'rank_math_description', 'rank_math_focus_keyword' ] as $absent ) {
			assert_false(
				array_key_exists( $absent, $meta ),
				'wrote ' . $absent . ' for a plugin that is not installed:'
			);
		}

		/*
		 * And nothing at all for AIOSEO. `_aioseo_description` is the specific
		 * trap here: the key exists, AIOSEO writes it itself for multilingual
		 * plugins to translate, and AIOSEO never reads it back -- so a connector
		 * that sets it appears to work and changes nothing on the site. See
		 * `class-pokoblog-seo.php`.
		 */
		foreach ( [ '_aioseo_title', '_aioseo_description', '_aioseo_focus_keyword' ] as $absent ) {
			assert_false(
				array_key_exists( $absent, $meta ),
				'wrote ' . $absent . ':'
			);
		}
	}
);

test(
	'the response says which SEO plugins are actually installed',
	static function () {
		pokoblog_test_install_key();

		$seo = pokoblog_test_publish()->get_data()['seo'];

		assert_true( $seo['yoast'], 'yoast' );
		assert_false( $seo['rank_math'], 'rank_math' );
		assert_false( $seo['aioseo'], 'aioseo' );
	}
);

test(
	'an empty meta description does not overwrite one the customer typed',
	static function () {
		pokoblog_test_install_key();

		$first = pokoblog_test_publish();
		$id    = $first->get_data()['post_id'];

		update_post_meta( $id, '_yoast_wpseo_metadesc', 'Wat de klant zelf schreef.' );

		pokoblog_test_publish( [ 'meta_description' => '' ] );

		assert_same(
			'Wat de klant zelf schreef.',
			get_post_meta( $id, '_yoast_wpseo_metadesc', true ),
			'description'
		);
	}
);
