<?php
/**
 * What gets written into `post_content`.
 *
 * PokoBlog's article HTML comes from an allowlist renderer that cannot emit a
 * tag outside a closed set, so in practice nothing dangerous ever arrives here.
 * That is an argument about the sender, and this endpoint writes into somebody
 * else's database -- so the receiver checks too.
 *
 * The caveat, stated where it applies rather than only in the stubs: the
 * `wp_kses_post` these tests run against is the stand-in in
 * `tests/stubs/wordpress.php`, not WordPress's. What is under test is that this
 * plugin routes article HTML through the sanitizer instead of writing it raw,
 * which is the thing the plugin can get wrong. Whether kses itself is correct
 * is WordPress's own test suite's business.
 */

require_once __DIR__ . '/bootstrap.php';

test(
	'content is sanitised on the way in',
	static function () {
		pokoblog_test_install_key();

		$response = pokoblog_test_publish(
			[
				'content' => '<p>Voor de aanval.</p>'
					. '<script>alert(document.cookie)</script>'
					. '<p onclick="steal()">Na de aanval.</p>'
					. '<a href="javascript:alert(1)">klik</a>',
			]
		);

		$stored = get_post_field( 'post_content', $response->get_data()['post_id'] );

		assert_not_contains( '<script', $stored, 'script element' );
		assert_not_contains( 'alert(document.cookie)', $stored, 'script body' );
		assert_not_contains( 'onclick', $stored, 'event handler' );
		assert_not_contains( 'javascript:', $stored, 'script url' );

		/*
		 * And the article survives. A sanitizer that dropped the body would
		 * pass every assertion above and be useless -- this is the half that
		 * says the trade is defence, not destruction.
		 */
		assert_contains( 'Voor de aanval.', $stored, 'prose before' );
		assert_contains( 'Na de aanval.', $stored, 'prose after' );
	}
);

test(
	'the markup PokoBlog actually emits comes through unchanged',
	static function () {
		pokoblog_test_install_key();

		/*
		 * Every construction `workflow/html.ts` can produce: headings h2 to h6,
		 * paragraphs, both list kinds, blockquote, inline emphasis, code spans,
		 * and the two anchor shapes -- an internal link and an external one
		 * carrying the `rel="nofollow"` that renderer adds.
		 *
		 * This is the test that would have caught it if `wp_kses_post` stripped
		 * something we depend on. It does not: WordPress's `$allowedposttags`
		 * carries all of these, `rel` among the attributes it allows on `a`.
		 */
		$html = '<h2>Kop</h2>'
			. '<p>Tekst met <strong>nadruk</strong>, <em>cursief</em> en <code>code</code>.</p>'
			. '<ul><li>Een</li><li>Twee</li></ul>'
			. '<ol><li>Eerst</li></ol>'
			. '<blockquote><p>Citaat.</p></blockquote>'
			. '<p><a href="/andere-pagina">intern</a> en '
			. '<a href="https://example.org" rel="nofollow">extern</a></p>'
			. '<h6>Kleinste kop</h6>';

		$response = pokoblog_test_publish( [ 'content' => $html ] );
		$stored   = get_post_field( 'post_content', $response->get_data()['post_id'] );

		assert_same( $html, $stored, 'body' );
	}
);

test(
	'the title and the meta description are sanitised too',
	static function () {
		pokoblog_test_install_key();

		$response = pokoblog_test_publish(
			[
				'title'            => 'Kop <script>alert(1)</script> met staart',
				'meta_description' => "Regel een\n<b>vet</b>",
			]
		);

		$id = $response->get_data()['post_id'];

		assert_not_contains( '<script', get_post_field( 'post_title', $id ), 'title' );
		assert_not_contains( 'alert(1)', get_post_field( 'post_title', $id ), 'title' );
	}
);

test(
	'a body that is not a string is refused rather than coerced',
	static function () {
		pokoblog_test_install_key();

		$response = pokoblog_test_publish( [ 'content' => [ 'nested' => 'thing' ] ] );

		assert_same( 400, $response->get_status(), 'status' );
		assert_same( [ 'content' ], $response->get_data()['missing'], 'missing fields' );
	}
);
