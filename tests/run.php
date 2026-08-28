<?php
/**
 * Run every `tests/test-*.php`, one PHP process each.
 *
 * The one-process-per-file rule is not tidiness. Three of the things this
 * plugin branches on -- `WPSEO_VERSION`, `RANK_MATH_VERSION` and the existence
 * of `aioseo()` -- are a constant, a constant and a function, and PHP has no
 * way to undefine any of them. So "Yoast is installed" and "Yoast is not
 * installed" cannot be two cases in one interpreter, and a suite that ran them
 * in one would be quietly testing only whichever it defined first.
 *
 *   php tests/run.php
 *   php tests/run.php --only "the same article sent twice"
 *
 * `--only` is passed through to each file and matches on the test name. A file
 * that matches nothing is reported and fails, so a mutation test cannot be
 * "proved" by a name that no longer exists.
 */

$directory = __DIR__;
$files     = glob( $directory . '/test-*.php' );

sort( $files );

if ( empty( $files ) ) {
	fwrite( STDERR, "no test files found in {$directory}\n" );
	exit( 1 );
}

$only = null;

foreach ( $argv as $index => $argument ) {
	if ( $argument === '--only' && isset( $argv[ $index + 1 ] ) ) {
		$only = $argv[ $index + 1 ];
	}
}

$failed   = 0;
$attempts = 0;

foreach ( $files as $file ) {
	$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $file );

	if ( $only !== null ) {
		$command .= ' --only ' . escapeshellarg( $only );
	}

	echo basename( $file ) . "\n";

	$output = [];
	$status = 0;

	exec( $command . ' 2>&1', $output, $status );

	echo implode( "\n", $output ) . "\n";

	/*
	 * With `--only`, a file that contains no matching test has nothing to run
	 * and says so. That is not the suite's failure -- but at least one file has
	 * to have matched something, or the filter matched nothing at all and the
	 * run proved nothing while exiting zero.
	 */
	if ( strpos( implode( "\n", $output ), 'no test matching' ) === false ) {
		$attempts++;
	}

	if ( $status !== 0 ) {
		$failed++;
	}
}

if ( $only !== null && $attempts === 0 ) {
	fwrite( STDERR, "no test matched '{$only}'\n" );
	exit( 1 );
}

echo $failed === 0
	? "\nall test files passed\n"
	: "\n{$failed} test file(s) failed\n";

exit( $failed === 0 ? 0 : 1 );
