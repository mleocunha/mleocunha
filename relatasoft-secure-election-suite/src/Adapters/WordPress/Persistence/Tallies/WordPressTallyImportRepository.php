<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Persistence\Tallies;

use RelataSoft\SecureElectionSuite\Database\Repository;
use RelataSoft\SecureElectionSuite\Database\Schema;
use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Persistence\RowMapper;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Tallies\TallyImportRepository;

final class WordPressTallyImportRepository implements TallyImportRepository {

	public function create(array $data): int {
		return Repository::rses_insert(
			'rses_tally_imports',
			$data,
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
	}

	public function find(int $importId): ?array {
		return RowMapper::toArray( Repository::rses_get_by_id( 'rses_tally_imports', $importId ) );
	}

	public function listSummaries(): array {
		global $wpdb;
		$table = Schema::rses_table( 'rses_tally_imports' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT id, source_site_url, election_external_id, round_external_id,
				election_title, round_title, ballot_count,
				import_hash, imported_by, imported_at, status, audit_hash,
				LENGTH(import_manifest_json) AS manifest_bytes
			FROM {$table}
			ORDER BY id DESC"
		);
		return RowMapper::toArrays( is_array( $rows ) ? $rows : array() );
	}

	public function updateStatus(int $importId, string $status): bool {
		return Repository::rses_update(
			'rses_tally_imports',
			array( 'status' => $status ),
			array( 'id' => $importId ),
			array( '%s' ),
			array( '%d' )
		);
	}

	public function updateSummary(int $importId, array $summary): bool {
		return Repository::rses_update(
			'rses_tally_imports',
			array(
				'election_title' => $summary['election_title'] ?? null,
				'round_title'    => $summary['round_title'] ?? null,
				'ballot_count'   => $summary['ballot_count'] ?? null,
			),
			array( 'id' => $importId ),
			array( '%s', '%s', '%d' ),
			array( '%d' )
		);
	}

	public function delete(int $importId): bool {
		return Repository::rses_delete_by_id( 'rses_tally_imports', $importId );
	}
}
