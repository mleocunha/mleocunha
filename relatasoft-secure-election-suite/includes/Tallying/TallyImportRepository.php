<?php
/**
 * Tally import repository.
 *
 * @package RelataSoft\SecureElectionSuite\Tallying
 */

namespace RelataSoft\SecureElectionSuite\Tallying;

use RelataSoft\SecureElectionSuite\Database\Repository;
use RelataSoft\SecureElectionSuite\Exports\HashService;

defined( 'ABSPATH' ) || exit;

/**
 * Tally import database operations.
 */
class TallyImportRepository {

	/**
	 * Create import record.
	 *
	 * @param array<string,mixed> $data Import data.
	 * @return int
	 */
	public static function rses_create( array $data ): int {
		$rses_row = array(
			'source_site_url'       => $data['source_site_url'] ?? null,
			'election_external_id'  => $data['election_external_id'] ?? null,
			'round_external_id'     => $data['round_external_id'] ?? null,
			'import_manifest_json'  => $data['import_manifest_json'],
			'import_hash'           => $data['import_hash'],
			'imported_by'           => get_current_user_id(),
			'imported_at'           => current_time( 'mysql', true ),
			'status'                => $data['status'] ?? 'pending',
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
	 * List imports.
	 *
	 * @return array<int,object>
	 */
	public static function rses_list(): array {
		return Repository::rses_get_rows( 'rses_tally_imports' );
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
	 * Get manifest as array.
	 *
	 * @param object $import Import row.
	 * @return array<string,mixed>
	 */
	public static function rses_get_manifest( object $import ): array {
		$rses_data = json_decode( $import->import_manifest_json, true );
		return is_array( $rses_data ) ? $rses_data : array();
	}
}
