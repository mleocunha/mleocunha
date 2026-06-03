<?php
/**
 * Activation and schema management.
 *
 * @package DecentralizedEvoting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs install routines per node type.
 */
class EVote_Activator {

	const DB_VERSION_OPTION = 'evote_db_version';
	const DB_VERSION        = '0.1.0';

	/**
	 * Plugin activation.
	 */
	public static function activate() {
		EVote_Database::install();
		flush_rewrite_rules();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Plugin deactivation.
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}
}
