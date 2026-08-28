<?php
/**
 * What every test file loads first.
 *
 * Order matters: `ABSPATH` has to exist before the stubs, because the plugin's
 * files bail out when it does not, and the media class `require_once`s three
 * files under it. So the bootstrap builds a throwaway directory that looks
 * enough like a WordPress root, points `ABSPATH` at it, and removes it again
 * when the process ends.
 *
 * A temporary directory rather than a fixture inside the repository, for two
 * reasons: the IndexNow tests write a key file into the site root and would
 * otherwise leave it in the working tree, and a test that can only run from a
 * particular working directory is a test that fails in CI for a reason nobody
 * can see.
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

$GLOBALS['pokoblog_test_file'] = isset( $GLOBALS['argv'][0] ) ? $GLOBALS['argv'][0] : 'unknown';

$root = sys_get_temp_dir() . '/pokoblog-test-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );

mkdir( $root . '/wp-admin/includes', 0777, true );

foreach ( [ 'file', 'media', 'image' ] as $stub ) {
	file_put_contents( $root . '/wp-admin/includes/' . $stub . '.php', "<?php\n" );
}

define( 'ABSPATH', $root . '/' );

function pokoblog_test_rmdir( $path ) {
	if ( ! is_dir( $path ) ) {
		return;
	}

	foreach ( scandir( $path ) as $entry ) {
		if ( $entry === '.' || $entry === '..' ) {
			continue;
		}

		$child = $path . '/' . $entry;

		is_dir( $child ) ? pokoblog_test_rmdir( $child ) : unlink( $child );
	}

	rmdir( $path );
}

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/stubs/wordpress.php';

pokoblog_test_reset();

/*
 * The plugin as it actually loads, entry point included, rather than its
 * classes cherry-picked. `pokoblog.php` is the file that decides which classes
 * exist and which hooks they are on, and a test suite that skips it is a suite
 * that keeps passing after somebody removes a `require_once`.
 */
require_once dirname( __DIR__ ) . '/pokoblog.php';

/*
 * Run at shutdown rather than at the bottom of each test file. See the comment
 * on `pokoblog_run_tests()`. The temporary root is removed between the run and
 * the exit, because `exit()` inside a shutdown function abandons whatever else
 * was queued -- so a cleanup registered after this one would never happen.
 */
register_shutdown_function(
	static function () use ( $root ) {
		$code = pokoblog_run_tests();

		pokoblog_test_rmdir( $root );

		exit( $code );
	}
);

/* -------------------------------------------------------------------------- */
/* Shared fixtures                                                            */
/* -------------------------------------------------------------------------- */

/** The key the fake site has issued, and which the tests present. */
const POKOBLOG_TEST_KEY = 'e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0';

function pokoblog_test_install_key( $key = POKOBLOG_TEST_KEY ) {
	update_option( PokoBlog_Key::OPTION, $key );

	return $key;
}

/** A publish request, with anything the caller wants overridden. */
function pokoblog_test_payload( $overrides = [] ) {
	return array_merge(
		[
			'article_id'       => 'art_0001',
			'title'            => 'Magento migratie in 2026',
			'content'          => '<p>Een artikel over migreren.</p>',
			'slug'             => 'magento-migratie',
			'excerpt'          => 'Een artikel over migreren.',
			'status'           => 'publish',
			'meta_description' => 'Alles over een Magento migratie in 2026.',
			'focus_keyword'    => 'magento migratie',
		],
		$overrides
	);
}

/** Send a publish request through the REST handler, key included. */
function pokoblog_test_publish( $overrides = [], $key = POKOBLOG_TEST_KEY ) {
	$request = new WP_REST_Request(
		pokoblog_test_payload( $overrides ),
		[ 'X-PokoBlog-Key' => $key ]
	);

	$authorized = PokoBlog_Rest::authorize( $request );

	if ( $authorized !== true ) {
		return new WP_REST_Response(
			[
				'ok'   => false,
				'code' => $authorized->get_error_code(),
			],
			401
		);
	}

	return PokoBlog_Rest::publish( $request );
}

/** Every post in the fake database that is not an attachment. */
function pokoblog_test_posts() {
	return array_values(
		array_filter(
			$GLOBALS['pokoblog_test']['posts'],
			static function ( $post ) {
				return $post['post_type'] !== 'attachment';
			}
		)
	);
}

/** A post the customer wrote themselves, at a given address. */
function pokoblog_test_seed_post( $slug, $type = 'page', $status = 'publish' ) {
	return wp_insert_post(
		[
			'post_type'   => $type,
			'post_title'  => 'Something the customer wrote',
			'post_status' => $status,
			'post_name'   => $slug,
		]
	);
}
