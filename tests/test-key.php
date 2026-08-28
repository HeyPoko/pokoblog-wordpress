<?php
/**
 * The API key is the only credential this plugin has, so these are the tests
 * that matter most: everything else assumes the request got past them.
 */

require_once __DIR__ . '/bootstrap.php';

test(
	'a request with the wrong key is refused and writes nothing',
	static function () {
		pokoblog_test_install_key();

		$response = pokoblog_test_publish( [], 'not-the-key' );

		assert_same( 401, $response->get_status(), 'status' );

		/*
		 * The second half, and the one that would still be worth having if the
		 * status code were right by accident. A 401 that had already created
		 * the post would be a refusal in name only.
		 */
		assert_same( 0, count( pokoblog_test_posts() ), 'posts created' );
	}
);

test(
	'a request with the right key is not refused',
	static function () {
		pokoblog_test_install_key();

		$response = pokoblog_test_publish();

		assert_same( 201, $response->get_status(), 'status' );
	}
);

test(
	'the key comparison is constant time',
	static function () {
		/*
		 * A structural assertion, deliberately, and worth saying why rather
		 * than leaving it to look like laziness.
		 *
		 * The property under test is "how long this takes does not depend on
		 * how much of the key was right". Measuring that in a unit test means
		 * measuring nanoseconds through a PHP interpreter on a shared machine,
		 * where the noise is orders of magnitude larger than the signal -- such
		 * a test either fails at random or passes at random, and either way it
		 * tells nobody anything.
		 *
		 * What can be asserted exactly is the construction, which is what a
		 * reviewer checks and what a careless edit changes: the comparison goes
		 * through `hash_equals` over two digests of fixed width, and there is no
		 * `===`, `==` or `strcmp` deciding the answer. See
		 * `PokoBlog_Key::matches` for why the digests are there -- a bare
		 * `hash_equals` still leaks the stored key's length.
		 */
		$source = pokoblog_method_source( 'PokoBlog_Key', 'matches' );

		assert_contains( 'hash_equals(', $source, 'timing-safe comparison' );
		assert_contains( "hash( 'sha256', \$stored )", $source, 'stored side hashed' );
		assert_contains( "hash( 'sha256', \$presented )", $source, 'presented side hashed' );

		/*
		 * Both sides of the presented/stored pair, in either order, in any of
		 * the comparisons that short-circuit. The `!==` guards on `is_string`
		 * are fine and are not matched, because they do not compare the two
		 * secrets to each other.
		 */
		foreach ( [ '===', '==', '!=', 'strcmp', 'strcasecmp' ] as $unsafe ) {
			assert_not_contains(
				'$stored ' . $unsafe . ' $presented',
				$source,
				'unsafe comparison'
			);
			assert_not_contains(
				'$presented ' . $unsafe . ' $stored',
				$source,
				'unsafe comparison'
			);
			assert_not_contains(
				$unsafe . '( $stored, $presented )',
				$source,
				'unsafe comparison'
			);
		}
	}
);

test(
	'no key of any shape is accepted against a stored key',
	static function () {
		$stored = pokoblog_test_install_key();

		/*
		 * The shapes a length-leaking comparison treats differently: shorter,
		 * longer, right length and wrong, and one that shares a long prefix.
		 * All four have to come back the same, which they do by construction --
		 * this is here so that a rewrite that reintroduces an early return has
		 * something behavioural to trip over as well.
		 */
		$wrong = [
			'',
			'a',
			substr( $stored, 0, 63 ),
			substr( $stored, 0, 63 ) . 'f',
			$stored . 'f',
			str_repeat( 'e', 64 ),
		];

		foreach ( $wrong as $candidate ) {
			if ( $candidate === $stored ) {
				fail( 'fixture error: a "wrong" key equals the stored key' );
			}

			assert_false(
				PokoBlog_Key::matches( $stored, $candidate ),
				'refused ' . var_export( $candidate, true ) . ':'
			);
		}

		assert_true( PokoBlog_Key::matches( $stored, $stored ), 'the right key:' );
	}
);

test(
	'a site with no stored key accepts nothing, including an empty key',
	static function () {
		/*
		 * The case a constant-time comparison alone gets wrong. With no stored
		 * key both sides hash to the digest of the empty string, and a caller
		 * sending nothing would match perfectly.
		 */
		assert_false( PokoBlog_Key::matches( '', '' ), 'empty against empty:' );
		assert_false( PokoBlog_Key::matches( '', 'anything' ), 'empty stored key:' );
	}
);

test(
	'the key is read from either header PokoBlog might send',
	static function () {
		$bearer = new WP_REST_Request( [], [ 'Authorization' => 'Bearer ' . POKOBLOG_TEST_KEY ] );
		$direct = new WP_REST_Request( [], [ 'X-PokoBlog-Key' => POKOBLOG_TEST_KEY ] );
		$none   = new WP_REST_Request( [], [] );

		assert_same( POKOBLOG_TEST_KEY, PokoBlog_Key::presented( $bearer ), 'bearer' );
		assert_same( POKOBLOG_TEST_KEY, PokoBlog_Key::presented( $direct ), 'header' );
		assert_same( '', PokoBlog_Key::presented( $none ), 'no header' );
	}
);
