<?php
/**
 * Minimal autoloader for bundled phpseclib and dependencies (no Composer on host).
 *
 * @package DecentralizedEvoting
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'EVOTE_VENDOR_AUTOLOAD' ) ) {
	// Allow CLI/crypto tests outside WordPress when explicitly enabled.
	if ( php_sapi_name() !== 'cli' ) {
		exit;
	}
}

$evote_vendor = dirname( __FILE__ );

// random_compat polyfill (loaded before other classes).
$random_compat = $evote_vendor . '/paragonie/random_compat/lib/random.php';
if ( file_exists( $random_compat ) ) {
	require_once $random_compat;
}

spl_autoload_register(
	static function ( $class ) use ( $evote_vendor ) {
		$prefixes = array(
			'phpseclib3\\'                    => $evote_vendor . '/phpseclib/phpseclib/phpseclib/',
			'ParagonIE\\ConstantTime\\'       => $evote_vendor . '/paragonie/constant_time_encoding/src/',
		);

		foreach ( $prefixes as $prefix => $base_dir ) {
			$len = strlen( $prefix );
			if ( strncmp( $prefix, $class, $len ) !== 0 ) {
				continue;
			}
			$relative = substr( $class, $len );
			$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';
			if ( file_exists( $file ) ) {
				require_once $file;
			}
			return;
		}
	}
);

require_once $evote_vendor . '/phpseclib/phpseclib/phpseclib/bootstrap.php';
