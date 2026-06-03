<?php
/**
 * Custom tables for electors and encrypted ballots (polling node).
 *
 * @package DecentralizedEvoting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database schema and table name helpers.
 */
class EVote_Database {

	/**
	 * Install or upgrade custom tables.
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$electors        = self::table_electors();
		$ballots         = self::table_ballots();

		$sql_electors = "CREATE TABLE {$electors} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			running_id bigint(20) unsigned NOT NULL,
			voter_token_hash varchar(64) NOT NULL,
			has_voted tinyint(1) NOT NULL DEFAULT 0,
			authorized_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			voted_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_running (running_id, voter_token_hash),
			KEY running_id (running_id)
		) {$charset_collate};";

		$sql_ballots = "CREATE TABLE {$ballots} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			running_id bigint(20) unsigned NOT NULL,
			encrypted_payload longtext NOT NULL,
			payload_version varchar(16) NOT NULL DEFAULT '1',
			recorded_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY running_id (running_id),
			KEY recorded_at (recorded_at)
		) {$charset_collate};";

		dbDelta( $sql_electors );
		dbDelta( $sql_ballots );
	}

	/**
	 * @return string
	 */
	public static function table_electors() {
		global $wpdb;
		return $wpdb->prefix . 'evote_electors';
	}

	/**
	 * @return string
	 */
	public static function table_ballots() {
		global $wpdb;
		return $wpdb->prefix . 'evote_ballots';
	}
}
