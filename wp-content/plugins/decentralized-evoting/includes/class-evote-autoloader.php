<?php
/**
 * PSR-4-style autoloader for plugin classes.
 *
 * @package DecentralizedEvoting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers EVote_* classes from includes/.
 */
class EVote_Autoloader {

	/**
	 * Register autoloader.
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Load class file by name.
	 *
	 * @param string $class Class name.
	 */
	public static function load( $class ) {
		if ( strpos( $class, 'EVote_' ) !== 0 ) {
			return;
		}

		$file = EVOTE_PLUGIN_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
}
