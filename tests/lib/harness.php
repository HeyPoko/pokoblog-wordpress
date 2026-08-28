<?php
/**
 * The whole test framework, which is forty lines because that is all it needs
 * to be.
 *
 * `test( 'name', function () { ... } )` registers a case. Assertions throw. The
 * runner in `run.php` starts one PHP process per test file -- see the note on
 * isolation there -- so a file may define constants and classes at the top and
 * still leave the next file a clean interpreter.
 *
 * `--only <substring>` runs the cases whose names contain the substring, which
 * is how a single named test is run when proving that it fails against a broken
 * source.
 */

final class PokoBlog_Assertion_Failed extends Exception {}

$GLOBALS['pokoblog_tests']  = [];
$GLOBALS['pokoblog_passed'] = 0;
$GLOBALS['pokoblog_failed'] = 0;

function test( $name, $body ) {
	$GLOBALS['pokoblog_tests'][] = [ $name, $body ];
}

function fail( $message ) {
	throw new PokoBlog_Assertion_Failed( $message );
}

function assert_same( $expected, $actual, $what = '' ) {
	if ( $expected !== $actual ) {
		fail(
			trim( $what . ' ' ) . 'expected ' . var_export( $expected, true )
			. ', got ' . var_export( $actual, true )
		);
	}
}

function assert_true( $value, $what = '' ) {
	if ( $value !== true ) {
		fail( trim( $what . ' ' ) . 'expected true, got ' . var_export( $value, true ) );
	}
}

function assert_false( $value, $what = '' ) {
	if ( $value !== false ) {
		fail( trim( $what . ' ' ) . 'expected false, got ' . var_export( $value, true ) );
	}
}

function assert_contains( $needle, $haystack, $what = '' ) {
	if ( strpos( (string) $haystack, (string) $needle ) === false ) {
		fail( trim( $what . ' ' ) . 'expected to find ' . var_export( $needle, true ) . ' in ' . var_export( $haystack, true ) );
	}
}

function assert_not_contains( $needle, $haystack, $what = '' ) {
	if ( strpos( (string) $haystack, (string) $needle ) !== false ) {
		fail( trim( $what . ' ' ) . 'did not expect to find ' . var_export( $needle, true ) . ' in ' . var_export( $haystack, true ) );
	}
}

/**
 * Run everything registered, print a line per case, return an exit code.
 *
 * Called from a shutdown function in `bootstrap.php` rather than from the
 * bottom of each test file, so a test file is a list of `test()` calls and
 * nothing else -- there is no runner call to forget, and a file that forgot one
 * would otherwise pass silently by running nothing.
 *
 * It returns rather than exits so that the bootstrap can remove the temporary
 * site root afterwards: `exit()` inside a shutdown function skips every
 * shutdown function still queued behind it.
 */
function pokoblog_run_tests() {
	$only = pokoblog_only_filter();
	$file = basename( $GLOBALS['pokoblog_test_file'] );

	foreach ( $GLOBALS['pokoblog_tests'] as $case ) {
		list( $name, $body ) = $case;

		if ( $only !== null && strpos( $name, $only ) === false ) {
			continue;
		}

		pokoblog_test_reset();

		try {
			$body();
			$GLOBALS['pokoblog_passed']++;
			echo "  ok    {$name}\n";
		} catch ( Throwable $e ) {
			$GLOBALS['pokoblog_failed']++;
			echo "  FAIL  {$name}\n";
			echo '        ' . $e->getMessage() . "\n";
			echo '        ' . $e->getFile() . ':' . $e->getLine() . "\n";
		}
	}

	$ran = $GLOBALS['pokoblog_passed'] + $GLOBALS['pokoblog_failed'];

	if ( $ran === 0 ) {
		if ( $only !== null ) {
			/*
			 * Under `--only`, a file with no matching test has nothing to run
			 * and that is not a failure -- the run as a whole still has to have
			 * matched something somewhere, and `run.php` is what checks that by
			 * looking for this line.
			 */
			echo "  --    no test matching '{$only}'\n";

			return 0;
		}

		/*
		 * Without a filter, a file that ran nothing is a failure rather than a
		 * pass. "No test failed" and "the suite never started" look identical
		 * from the outside, and the second one is how a mutation comes to look
		 * as though it was caught by a test that never executed.
		 */
		echo "  FAIL  {$file} ran no tests\n";

		return 1;
	}

	return $GLOBALS['pokoblog_failed'] > 0 ? 1 : 0;
}

/**
 * The source text of one method.
 *
 * For the one property in this plugin that cannot be observed from outside the
 * function that has it: whether a comparison is constant-time. Timing it in a
 * unit test measures the machine rather than the code -- a loaded CI runner
 * produces a spread far wider than the effect -- so the assertion is on the
 * construction instead, which is exactly what a reviewer would check.
 */
function pokoblog_method_source( $class, $method ) {
	$reflection = new ReflectionMethod( $class, $method );
	$lines      = file( $reflection->getFileName() );

	return implode(
		'',
		array_slice(
			$lines,
			$reflection->getStartLine() - 1,
			$reflection->getEndLine() - $reflection->getStartLine() + 1
		)
	);
}

function pokoblog_only_filter() {
	$argv = isset( $GLOBALS['argv'] ) ? $GLOBALS['argv'] : [];

	foreach ( $argv as $index => $argument ) {
		if ( $argument === '--only' && isset( $argv[ $index + 1 ] ) ) {
			return $argv[ $index + 1 ];
		}
	}

	return null;
}
