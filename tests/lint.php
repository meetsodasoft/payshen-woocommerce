<?php
/**
 * Runs `php -l` on every PHP file of the plugin and the tests.
 *
 * @package Payshen_For_WooCommerce
 */

$root   = dirname( __DIR__ );
$files  = array_merge( glob( $root . '/*.php' ), glob( $root . '/includes/*.php' ), glob( $root . '/tests/*.php' ), glob( $root . '/tests/unit/*.php' ) );
$failed = 0;
foreach ( $files as $file ) {
	$out = array();
	exec( escapeshellarg( PHP_BINARY ) . ' -n -l ' . escapeshellarg( $file ) . ' 2>&1', $out, $code );
	echo ( 0 === $code ? 'ok   ' : 'FAIL ' ) . substr( $file, strlen( $root ) + 1 ) . ( 0 === $code ? '' : "\n" . implode( "\n", $out ) ) . "\n";
	$failed += 0 === $code ? 0 : 1;
}
exit( $failed > 0 ? 1 : 0 );
