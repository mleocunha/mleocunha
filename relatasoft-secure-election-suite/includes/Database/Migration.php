<?php
/**
 * Database migration handler.
 *
 * @package RelataSoft\SecureElectionSuite\Database
 */

namespace RelataSoft\SecureElectionSuite\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Handles database installation and migrations.
 */
class Migration {

	/**
	 * Install database tables on activation.
	 */
	public static function rses_install(): void {
		self::rses_run_migrations();
	}

	/**
	 * Run migrations if version changed.
	 */
	public static function rses_maybe_migrate(): void {
		$rses_current = get_option( 'rses_db_version', '' );
		if ( $rses_current !== Schema::RSES_DB_VERSION ) {
			self::rses_run_migrations();
		}
	}

	/**
	 * Execute migrations.
	 */
	private static function rses_run_migrations(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$rses_tables = Schema::rses_get_tables();

		foreach ( $rses_tables as $rses_sql ) {
			dbDelta( $rses_sql );
		}

		update_option( 'rses_db_version', Schema::RSES_DB_VERSION );
	}
}
