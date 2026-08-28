<?php
/**
 * IndexNow key management.
 *
 * Nothing here submits anything: the plugin's whole part in IndexNow is hosting
 * a key file, and PokoBlog does the submitting. What these tests hold in place
 * is that the key it reports and the key it publishes are the same string --
 * a mismatch makes every submission fail with an error naming the key rather
 * than the file, which is a very hard afternoon.
 */

require_once __DIR__ . '/bootstrap.php';

test(
	'setup publishes the key at the address the protocol requires',
	static function () {
		$result = PokoBlog_IndexNow::setup();

		assert_same( 32, strlen( $result['key'] ), 'key length' );
		assert_same( PokoBlog_IndexNow::MODE_FILE, $result['mode'], 'mode' );
		assert_same(
			'https://example.test/' . $result['key'] . '.txt',
			$result['location'],
			'location'
		);

		/*
		 * The file has to contain the key itself. Bing fetches that URL and
		 * compares; a file containing anything else is a site that fails
		 * verification while looking configured.
		 */
		assert_same(
			$result['key'],
			file_get_contents( ABSPATH . $result['key'] . '.txt' ),
			'file contents'
		);
	}
);

test(
	'setting up again replaces the old key and removes its file',
	static function () {
		$first  = PokoBlog_IndexNow::setup();
		$second = PokoBlog_IndexNow::setup();

		assert_false( $first['key'] === $second['key'], 'a new key was minted:' );
		assert_false(
			file_exists( ABSPATH . $first['key'] . '.txt' ),
			'the old file is gone:'
		);
		assert_true(
			file_exists( ABSPATH . $second['key'] . '.txt' ),
			'the new file is there:'
		);
	}
);

test(
	'status reports a key file that has since disappeared',
	static function () {
		$result = PokoBlog_IndexNow::setup();

		assert_true( PokoBlog_IndexNow::status()['configured'], 'configured after setup:' );

		/*
		 * A stray text file in a site root is exactly the sort of thing a core
		 * update, a migration or a security plugin removes. "We wrote a key
		 * once" is not the same claim as "the key is readable", and only the
		 * second one is worth reporting.
		 */
		unlink( ABSPATH . $result['key'] . '.txt' );

		$status = PokoBlog_IndexNow::status();

		assert_false( $status['configured'], 'configured after the file vanished:' );
		assert_same( null, $status['key'], 'key' );
	}
);

test(
	'a site that has never been set up says so',
	static function () {
		assert_same( [ 'configured' => false ], PokoBlog_IndexNow::status(), 'status' );
	}
);

test(
	'a site root that cannot be written falls back to serving the key from WordPress',
	static function () {
		/*
		 * Read-only site roots are ordinary: anything deployed from git,
		 * anything on a read-only image, most managed hosts. A connector that
		 * stops there reports an error the customer cannot fix.
		 */
		chmod( ABSPATH, 0555 );

		$result = PokoBlog_IndexNow::setup();

		chmod( ABSPATH, 0755 );

		assert_same( PokoBlog_IndexNow::MODE_VIRTUAL, $result['mode'], 'mode' );
		assert_same( 32, strlen( $result['key'] ), 'key length' );

		/* Nothing on disk, and still configured -- because nothing needs to be. */
		assert_false( file_exists( ABSPATH . $result['key'] . '.txt' ), 'no file written:' );
		assert_true( PokoBlog_IndexNow::status()['configured'], 'configured:' );
	}
);
