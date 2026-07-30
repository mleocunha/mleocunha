<?php
/**
 * Database repository utilities.
 *
 * @package RelataSoft\SecureElectionSuite\Database
 */

namespace RelataSoft\SecureElectionSuite\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Generic database repository helpers.
 */
class Repository {

	/**
	 * Insert a row into a table.
	 *
	 * @param string               $table_suffix Table suffix without prefix.
	 * @param array<string,mixed>  $data         Row data.
	 * @param array<string,string> $format       Column formats.
	 * @return int Insert ID or 0 on failure.
	 */
	public static function rses_insert( string $table_suffix, array $data, array $format ): int {
		global $wpdb;

		$rses_table = Schema::rses_table( $table_suffix );
		$rses_result = $wpdb->insert( $rses_table, $data, $format );

		if ( false === $rses_result ) {
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a row.
	 *
	 * @param string               $table_suffix Table suffix.
	 * @param array<string,mixed>  $data         Row data.
	 * @param array<string,mixed>  $where        Where clause.
	 * @param array<string,string> $format       Data formats.
	 * @param array<string,string> $where_format Where formats.
	 * @return bool
	 */
	public static function rses_update(
		string $table_suffix,
		array $data,
		array $where,
		array $format,
		array $where_format
	): bool {
		global $wpdb;

		$rses_table = Schema::rses_table( $table_suffix );
		$rses_result = $wpdb->update( $rses_table, $data, $where, $format, $where_format );

		return false !== $rses_result;
	}

	/**
	 * Get a single row by ID.
	 *
	 * @param string $table_suffix Table suffix.
	 * @param int    $id           Row ID.
	 * @return object|null
	 */
	public static function rses_get_by_id( string $table_suffix, int $id ): ?object {
		global $wpdb;

		$rses_table = Schema::rses_table( $table_suffix );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rses_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$rses_table} WHERE id = %d",
				$id
			)
		);

		return $rses_row ?: null;
	}

	/**
	 * Get rows with prepared query.
	 *
	 * @param string $table_suffix Table suffix.
	 * @param string $where_sql    WHERE clause with placeholders.
	 * @param array  $args         Prepare arguments.
	 * @param string $order_by     ORDER BY clause.
	 * @param int    $limit        Limit.
	 * @return array<int,object>
	 */
	public static function rses_get_rows(
		string $table_suffix,
		string $where_sql = '1=1',
		array $args = array(),
		string $order_by = 'id DESC',
		int $limit = 0
	): array {
		global $wpdb;

		$rses_table = Schema::rses_table( $table_suffix );
		$rses_sql   = "SELECT * FROM {$rses_table} WHERE {$where_sql} ORDER BY {$order_by}";

		if ( $limit > 0 ) {
			$rses_sql .= $wpdb->prepare( ' LIMIT %d', $limit );
		}

		if ( ! empty( $args ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rses_sql = $wpdb->prepare( $rses_sql, $args );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rses_rows = $wpdb->get_results( $rses_sql );

		return is_array( $rses_rows ) ? $rses_rows : array();
	}

	/**
	 * Delete a row by ID.
	 *
	 * @param string $table_suffix Table suffix.
	 * @param int    $id           Row ID.
	 * @return bool
	 */
	public static function rses_delete_by_id( string $table_suffix, int $id ): bool {
		global $wpdb;

		$rses_table = Schema::rses_table( $table_suffix );

		$rses_result = $wpdb->delete(
			$rses_table,
			array( 'id' => $id ),
			array( '%d' )
		);

		return false !== $rses_result;
	}

	/**
	 * Truncate all plugin tables (destructive reset).
	 */
	public static function rses_truncate_all_tables(): void {
		global $wpdb;

		$rses_tables = array_keys( Schema::rses_get_tables() );

		foreach ( $rses_tables as $rses_suffix ) {
			$rses_table = Schema::rses_table( $rses_suffix );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "TRUNCATE TABLE {$rses_table}" );
		}
	}

	/**
	 * Count rows in a table.
	 *
	 * @param string $table_suffix Table suffix.
	 * @param string $where_sql    WHERE clause.
	 * @param array  $args         Prepare args.
	 * @return int
	 */
	public static function rses_count( string $table_suffix, string $where_sql = '1=1', array $args = array() ): int {
		global $wpdb;

		$rses_table = Schema::rses_table( $table_suffix );
		$rses_sql   = "SELECT COUNT(*) FROM {$rses_table} WHERE {$where_sql}";

		if ( ! empty( $args ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rses_sql = $wpdb->prepare( $rses_sql, $args );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $rses_sql );
	}
}
