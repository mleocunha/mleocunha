<?php
/**
 * Tally import repository.
 *
 * @package RelataSoft\SecureElectionSuite\Tallying
 */

namespace RelataSoft\SecureElectionSuite\Tallying;

use RelataSoft\SecureElectionSuite\Database\Repository;
use RelataSoft\SecureElectionSuite\Database\Schema;
use RelataSoft\SecureElectionSuite\Exports\HashService;

defined( 'ABSPATH' ) || exit;

/**
 * Tally import database operations.
 */
class TallyImportRepository {

	/**
	 * Manifests larger than this are treated as unsafe to load on 128M hosts.
	 */
	public const RSES_MAX_SAFE_MANIFEST_BYTES = 524288; // 512 KiB

	/**
	 * Create import record.
	 *
	 * @param array<string,mixed> $data Import data.
	 * @return int
	 */
	public static function rses_create( array $data ): int {
		$rses_row = array(
			'source_site_url'      => $data['source_site_url'] ?? null,
			'election_external_id' => $data['election_external_id'] ?? null,
			'round_external_id'    => $data['round_external_id'] ?? null,
			'import_manifest_json' => $data['import_manifest_json'],
			'import_hash'          => $data['import_hash'],
			'imported_by'          => get_current_user_id(),
			'imported_at'          => current_time( 'mysql', true ),
			'status'               => $data['status'] ?? 'pending',
		);

		$rses_row['audit_hash'] = HashService::rses_hash_json( $rses_row );

		return Repository::rses_insert(
			'rses_tally_imports',
			$rses_row,
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Get import by ID.
	 *
	 * @param int $import_id Import ID.
	 * @return object|null
	 */
	public static function rses_get( int $import_id ): ?object {
		return Repository::rses_get_by_id( 'rses_tally_imports', $import_id );
	}

	/**
	 * List imports without loading import_manifest_json (can be multi‑MB).
	 *
	 * @return array<int,object>
	 */
	public static function rses_list(): array {
		global $wpdb;

		$rses_table = Schema::rses_table( 'rses_tally_imports' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rses_rows = $wpdb->get_results(
			"SELECT id, source_site_url, election_external_id, round_external_id, import_hash,
				imported_by, imported_at, status, audit_hash,
				LENGTH(import_manifest_json) AS manifest_bytes
			FROM {$rses_table}
			ORDER BY id DESC"
		);

		return is_array( $rses_rows ) ? $rses_rows : array();
	}

	/**
	 * Replace oversized manifests with a tiny stub so admin pages stop white-screening.
	 *
	 * Uses SQL LENGTH only — never reads the huge JSON into PHP.
	 *
	 * @param int $max_bytes Max safe stored manifest size.
	 * @return int Rows updated.
	 */
	public static function rses_purge_oversized_manifests( int $max_bytes = self::RSES_MAX_SAFE_MANIFEST_BYTES ): int {
		global $wpdb;

		$rses_table = Schema::rses_table( 'rses_tally_imports' );
		$rses_stub  = wp_json_encode(
			array(
				'purged'  => true,
				'reason'  => 'import_manifest_json exceeded safe size for this PHP memory_limit; re-import with RelataSoft Secure Election Suite 1.0.27.4+',
				'public_key' => array(),
				'encrypted_tallies' => array(),
			),
			JSON_UNESCAPED_SLASHES
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rses_result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$rses_table}
				SET import_manifest_json = %s, status = 'rejected'
				WHERE LENGTH(import_manifest_json) > %d",
				$rses_stub,
				$max_bytes
			)
		);

		return false === $rses_result ? 0 : (int) $rses_result;
	}

	/**
	 * Update import status.
	 *
	 * @param int    $import_id Import ID.
	 * @param string $status    Status.
	 * @return bool
	 */
	public static function rses_update_status( int $import_id, string $status ): bool {
		return Repository::rses_update(
			'rses_tally_imports',
			array( 'status' => $status ),
			array( 'id' => $import_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Get manifest as array (refuses to decode oversized JSON).
	 *
	 * @param object $import Import row.
	 * @return array<string,mixed>
	 */
	public static function rses_get_manifest( object $import ): array {
		$rses_json = (string) ( $import->import_manifest_json ?? '' );
		if ( strlen( $rses_json ) > self::RSES_MAX_SAFE_MANIFEST_BYTES ) {
			return array(
				'purged' => true,
				'reason' => 'manifest_too_large_to_decode',
			);
		}
		$rses_data = json_decode( $rses_json, true );
		return is_array( $rses_data ) ? $rses_data : array();
	}
}
