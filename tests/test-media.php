<?php
/**
 * The featured image.
 *
 * No test here reaches the network: `download_url` is stubbed to hand back a
 * file the test wrote itself, and a URL the test did not arrange comes back as
 * a `WP_Error` -- which is the same thing the plugin sees when a real fetch
 * fails, so a badly written test fails rather than quietly making a request.
 */

require_once __DIR__ . '/bootstrap.php';

/**
 * A one-pixel PNG, as bytes.
 *
 * A real file rather than a signature followed by zeros, because
 * `mime_content_type` runs libmagic and libmagic does not call a truncated PNG
 * a PNG -- so a synthetic fixture would make the end-to-end test fail for a
 * reason that has nothing to do with the plugin.
 */
const POKOBLOG_TEST_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

function pokoblog_test_file( $bytes ) {
	$path = tempnam( sys_get_temp_dir(), 'pokoblog-image' );

	file_put_contents( $path, $bytes );

	return $path;
}

function pokoblog_test_png() {
	return pokoblog_test_file( base64_decode( POKOBLOG_TEST_PNG ) );
}

/** Arrange what `download_url` returns for a URL. */
function pokoblog_test_arrange_download( $url, $path ) {
	$GLOBALS['pokoblog_test']['downloads'][ $url ] = $path;
}

test(
	'the image is copied into the media library and made the featured image',
	static function () {
		pokoblog_test_install_key();

		$url = 'https://media.pokoblog.test/art_0001.png';

		pokoblog_test_arrange_download( $url, pokoblog_test_png() );

		$response = pokoblog_test_publish(
			[
				'featured_image_url' => $url,
				'featured_image_alt' => 'Een laptop met een Magento beheerscherm',
			]
		);

		$data = $response->get_data();

		assert_true( $data['featured_image_id'] > 0, 'an attachment was created:' );
		assert_same(
			$data['featured_image_id'],
			$GLOBALS['pokoblog_test']['thumbnails'][ $data['post_id'] ],
			'thumbnail'
		);
		assert_same(
			'Een laptop met een Magento beheerscherm',
			get_post_meta( $data['featured_image_id'], '_wp_attachment_image_alt', true ),
			'alt text'
		);
	}
);

test(
	'an article with no image is published without one',
	static function () {
		pokoblog_test_install_key();

		/*
		 * The ordinary case while featured images are still being built, and the
		 * permanent case for a website with image generation switched off. An
		 * article with no picture is a correct article.
		 */
		$response = pokoblog_test_publish( [ 'featured_image_url' => '' ] );
		$data     = $response->get_data();

		assert_same( 201, $response->get_status(), 'status' );
		assert_same( null, $data['featured_image_id'], 'attachment' );
		assert_same( 0, count( $GLOBALS['pokoblog_test']['downloads']['requested'] ?? [] ), 'fetches made' );
	}
);

test(
	'a failed image download does not fail the publish',
	static function () {
		pokoblog_test_install_key();

		/* Nothing arranged for this URL, so the stubbed fetch returns an error. */
		$response = pokoblog_test_publish(
			[ 'featured_image_url' => 'https://media.pokoblog.test/missing.png' ]
		);

		assert_same( 201, $response->get_status(), 'status' );
		assert_same( null, $response->get_data()['featured_image_id'], 'attachment' );
		assert_true( $response->get_data()['post_id'] > 0, 'the post exists:' );
	}
);

test(
	'a downloaded file that is not an image is discarded',
	static function () {
		pokoblog_test_install_key();

		$url  = 'https://media.pokoblog.test/not-an-image';
		$path = pokoblog_test_file( str_repeat( 'not an image at all ', 8 ) );

		pokoblog_test_arrange_download( $url, $path );

		$data = pokoblog_test_publish( [ 'featured_image_url' => $url ] )->get_data();

		assert_same( null, $data['featured_image_id'], 'attachment' );
		assert_false( file_exists( $path ), 'the temporary file was cleaned up:' );
	}
);

test(
	'only http and https are fetched',
	static function () {
		pokoblog_test_install_key();

		foreach ( [ 'file:///etc/passwd', 'ftp://example.test/x.png', 'gopher://example.test/' ] as $url ) {
			pokoblog_test_publish( [ 'article_id' => 'art_' . md5( $url ), 'featured_image_url' => $url ] );
		}

		assert_same(
			0,
			count( $GLOBALS['pokoblog_test']['downloads']['requested'] ?? [] ),
			'fetches made'
		);
	}
);

test(
	'each fallback identifies the type on its own',
	static function () {
		/*
		 * `mime_of` tries `fileinfo` first, and `fileinfo` cannot be switched
		 * off from inside a running interpreter -- so a test that went in
		 * through the front door would take the first branch every time and
		 * never execute the fallbacks. Which is the wrong way round: the
		 * fallbacks exist *for* the hosts where the first branch is missing, so
		 * they are the part that most needs a test, and the only way to reach
		 * them here is to call them.
		 *
		 * Signature-and-zeros is enough here. libmagic refuses a truncated PNG,
		 * which is why the end-to-end test above uses a real one, but these two
		 * functions read exactly the bytes they check and nothing else.
		 */
		$cases = [
			'image/png'  => "\x89PNG\x0D\x0A\x1A\x0A" . str_repeat( "\x00", 64 ),
			'image/jpeg' => "\xFF\xD8\xFF\xE0" . str_repeat( "\x00", 64 ),
			'image/gif'  => 'GIF89a' . str_repeat( "\x00", 64 ),
			'image/webp' => 'RIFF' . "\x00\x00\x00\x00" . 'WEBP' . str_repeat( "\x00", 64 ),
		];

		foreach ( $cases as $type => $bytes ) {
			$path = pokoblog_test_file( $bytes );

			assert_same( $type, PokoBlog_Media::mime_from_magic( $path ), 'magic bytes' );

			unlink( $path );
		}

		/* Something that is not an image at all gets no answer rather than a wrong one. */
		$plain = pokoblog_test_file( str_repeat( 'plain text ', 16 ) );

		assert_same( '', PokoBlog_Media::mime_from_magic( $plain ), 'not an image' );

		unlink( $plain );

		/*
		 * The last resort. It has to survive a signed query string, because that
		 * is what a URL from object storage looks like.
		 */
		assert_same(
			'image/webp',
			PokoBlog_Media::mime_from_url( 'https://media.pokoblog.test/a.webp?sig=abc' ),
			'from url'
		);
		assert_same(
			'image/jpeg',
			PokoBlog_Media::mime_from_url( 'https://media.pokoblog.test/a.JPEG' ),
			'from url, upper case'
		);
		/* No extension at all, which is the case that makes the fallback matter. */
		assert_same(
			'',
			PokoBlog_Media::mime_from_url( 'https://media.pokoblog.test/art_0001' ),
			'no extension'
		);
	}
);

test(
	'a file whose bytes disagree with its URL is trusted for its bytes',
	static function () {
		/*
		 * The URL claims JPEG, the file is a PNG. Whichever branch `mime_of`
		 * takes on this machine, the answer has to come from the file -- naming
		 * a sideloaded PNG `.jpg` is exactly how `wp_check_filetype_and_ext`
		 * comes to refuse an image that was fine.
		 */
		$path = pokoblog_test_png();

		assert_same(
			'image/png',
			PokoBlog_Media::mime_of( $path, 'https://media.pokoblog.test/a.jpg' ),
			'mime'
		);

		unlink( $path );
	}
);
