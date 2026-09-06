<?php
/**
 * The stylesheet, and the posts it is allowed to appear on.
 *
 * An article can carry a comparison table, a callout and a task list, and the
 * class names those use are ones no theme has heard of. The rules are three
 * lines; the decision worth testing is where they load. A plugin installed to
 * receive articles that puts CSS on every page of the site -- the shop, the
 * contact form, the checkout -- is a plugin taking liberties, and it is the
 * kind of liberty nobody notices until a stylesheet collides.
 *
 * @package PokoBlog
 */

require_once __DIR__ . '/bootstrap.php';

test(
	'a delivered article gets the rules its markup needs',
	static function () {
		pokoblog_test_install_key();

		$response = pokoblog_test_publish(
			[ 'content' => '<div class="poko-table-wrap"><table></table></div>' ]
		);

		pokoblog_test_view( $response->get_data()['post_id'] );
		PokoBlog_Styles::enqueue();

		$style = pokoblog_test_state()['styles'][ PokoBlog_Styles::HANDLE ] ?? null;

		assert_true( null !== $style, 'the stylesheet is registered' );
		assert_true( ! empty( $style['enqueued'] ), 'the stylesheet is enqueued' );
		assert_contains(
			'.poko-table-wrap{overflow-x:auto',
			$style['inline'],
			'a wide table has somewhere to scroll'
		);
		assert_contains( '.poko-callout{', $style['inline'], 'a callout has its bar' );
	}
);

test(
	'a post the customer wrote themselves gets nothing',
	static function () {
		pokoblog_test_install_key();

		/*
		 * Identified by the same post meta the publisher uses for idempotency,
		 * so there is one answer to "is this ours" rather than two that can
		 * disagree.
		 */
		$id = wp_insert_post( [ 'post_title' => 'Eigen bericht', 'post_status' => 'publish' ] );

		pokoblog_test_view( $id );
		PokoBlog_Styles::enqueue();

		assert_same( [], pokoblog_test_state()['styles'], 'styles' );
	}
);

test(
	'no page that is not a single post gets it, article or not',
	static function () {
		pokoblog_test_install_key();
		pokoblog_test_publish( [ 'content' => '<p>Tekst.</p>' ] );

		/* An index, an archive, the home page: nothing single is being shown. */
		pokoblog_test_view( null );
		PokoBlog_Styles::enqueue();

		assert_same( [], pokoblog_test_state()['styles'], 'styles' );
	}
);

test(
	'the body of an article is marked, so a theme default can be supplied',
	static function () {
		pokoblog_test_install_key();

		$response = pokoblog_test_publish( [ 'content' => '<pre><code>ls</code></pre>' ] );

		pokoblog_test_view( $response->get_data()['post_id'] );

		assert_true(
			in_array( PokoBlog_Styles::BODY_CLASS, PokoBlog_Styles::body_class( [] ), true ),
			'an article carries the class'
		);

		/*
		 * The defaults for `pre` and `td` hang off that class and are written
		 * with `:where()`, which has zero specificity. A theme with any opinion
		 * about either element wins without raising its own selectors, and that
		 * is the whole reason this is safe to put on somebody else\'s site.
		 */
		assert_contains( ':where(.poko-article) pre{', PokoBlog_Styles::CSS, 'a floor for pre' );
		assert_not_contains( '} pre{', PokoBlog_Styles::CSS, 'and never a bare one' );
	}
);

test(
	'a post the customer wrote is not marked either',
	static function () {
		pokoblog_test_install_key();

		$id = wp_insert_post( [ 'post_title' => 'Eigen', 'post_status' => 'publish' ] );

		pokoblog_test_view( $id );

		assert_same( [], PokoBlog_Styles::body_class( [] ), 'classes' );
	}
);
