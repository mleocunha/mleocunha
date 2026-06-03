<?php
/**
 * Data access for electors and encrypted ballots.
 *
 * @package DecentralizedEvoting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Custom table operations for the polling station.
 */
class EVote_Ballot_Repository {

	/**
	 * Hash a voter token for storage.
	 *
	 * @param string $token Plain token.
	 * @return string
	 */
	public static function hash_token( $token ) {
		return hash( 'sha256', trim( (string) $token ) );
	}

	/**
	 * Import elector tokens for a running.
	 *
	 * @param int      $running_id Running post ID.
	 * @param string[] $tokens     Plain tokens.
	 * @return array{imported: int, skipped: int}|WP_Error
	 */
	public static function import_electors( $running_id, array $tokens ) {
		global $wpdb;

		$running_id = absint( $running_id );
		if ( $running_id < 1 || 'evote_running' !== get_post_type( $running_id ) ) {
			return new WP_Error( 'evote_invalid_running', __( 'Invalid running.', 'decentralized-evoting' ) );
		}

		$table    = EVote_Database::table_electors();
		$imported = 0;
		$skipped  = 0;

		foreach ( $tokens as $token ) {
			$token = trim( (string) $token );
			if ( '' === $token ) {
				continue;
			}
			$hash = self::hash_token( $token );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$inserted = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$table} (running_id, voter_token_hash) VALUES (%d, %s)",
					$running_id,
					$hash
				)
			);
			if ( false === $inserted ) {
				return new WP_Error( 'evote_db_error', __( 'Database error while importing electors.', 'decentralized-evoting' ) );
			}
			if ( $inserted > 0 ) {
				++$imported;
			} else {
				++$skipped;
			}
		}

		return array(
			'imported' => $imported,
			'skipped'  => $skipped,
		);
	}

	/**
	 * @param int    $running_id Running ID.
	 * @param string $token      Plain token.
	 * @return object|null Row object.
	 */
	public static function get_elector_by_token( $running_id, $token ) {
		global $wpdb;
		$table = EVote_Database::table_electors();
		$hash  = self::hash_token( $token );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE running_id = %d AND voter_token_hash = %s",
				absint( $running_id ),
				$hash
			)
		);
	}

	/**
	 * Store encrypted ballot(s) and mark elector voted.
	 *
	 * @param int                         $running_id Running ID.
	 * @param string                      $token      Plain token.
	 * @param array<int, array<string,mixed>> $ballots    Ballot JSON objects.
	 * @return true|WP_Error
	 */
	public static function cast_ballots( $running_id, $token, array $ballots ) {
		global $wpdb;

		$elector = self::get_elector_by_token( $running_id, $token );
		if ( ! $elector ) {
			return new WP_Error( 'evote_invalid_token', __( 'Invalid voting token.', 'decentralized-evoting' ) );
		}
		if ( (int) $elector->has_voted === 1 ) {
			return new WP_Error( 'evote_already_voted', __( 'This token has already been used.', 'decentralized-evoting' ) );
		}

		$ballot_table = EVote_Database::table_ballots();
		$elector_table = EVote_Database::table_electors();

		foreach ( $ballots as $ballot ) {
			$valid = EVote_Json_Payloads::validate_encrypted_ballot( $ballot );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
			$payload = wp_json_encode( $ballot );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ok = $wpdb->insert(
				$ballot_table,
				array(
					'running_id'        => absint( $running_id ),
					'encrypted_payload' => $payload,
					'payload_version'   => EVote_Json_Payloads::VERSION,
				),
				array( '%d', '%s', '%s' )
			);
			if ( false === $ok ) {
				return new WP_Error( 'evote_db_error', __( 'Failed to store ballot.', 'decentralized-evoting' ) );
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$elector_table,
			array(
				'has_voted' => 1,
				'voted_at'  => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $elector->id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		return true;
	}

	/**
	 * Fetch all ballot payloads for a running.
	 *
	 * @param int $running_id Running ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_ballots_for_running( $running_id ) {
		global $wpdb;
		$table = EVote_Database::table_ballots();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT encrypted_payload FROM {$table} WHERE running_id = %d ORDER BY id ASC",
				absint( $running_id )
			)
		);
		$ballots = array();
		foreach ( $rows as $row ) {
			$decoded = json_decode( $row, true );
			if ( is_array( $decoded ) ) {
				$ballots[] = $decoded;
			}
		}
		return $ballots;
	}

	/**
	 * Count electors and ballots for a running.
	 *
	 * @param int $running_id Running ID.
	 * @return array{electors: int, voted: int, ballots: int}
	 */
	public static function count_stats( $running_id ) {
		global $wpdb;
		$running_id = absint( $running_id );
		$electors   = EVote_Database::table_electors();
		$ballots    = EVote_Database::table_ballots();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total_electors = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$electors} WHERE running_id = %d", $running_id ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$voted = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$electors} WHERE running_id = %d AND has_voted = 1", $running_id ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ballot_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ballots} WHERE running_id = %d", $running_id ) );
		return array(
			'electors' => $total_electors,
			'voted'    => $voted,
			'ballots'  => $ballot_count,
		);
	}
}
