<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Persistence\Tallies;

use RelataSoft\SecureElectionSuite\Database\Repository;
use RelataSoft\SecureElectionSuite\Database\Schema;
use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Persistence\RowMapper;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Tallies\OfficialShareSubmissionRepository;

final class WordPressOfficialShareSubmissionRepository implements OfficialShareSubmissionRepository {

	public function create(array $data): int {
		return Repository::rses_insert(
			'rses_official_share_submissions',
			$data,
			array( '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s' )
		);
	}

	public function countByImport(int $importId): int {
		return Repository::rses_count(
			'rses_official_share_submissions',
			'tally_import_id = %d',
			array( $importId )
		);
	}

	public function countByImportAndIndex(int $importId, int $shareIndex): int {
		return Repository::rses_count(
			'rses_official_share_submissions',
			'tally_import_id = %d AND share_index = %d',
			array( $importId, $shareIndex )
		);
	}

	public function listByImport(int $importId): array {
		return RowMapper::toArrays(
			Repository::rses_get_rows(
				'rses_official_share_submissions',
				'tally_import_id = %d',
				array( $importId )
			)
		);
	}

	public function deleteByImport(int $importId): int {
		global $wpdb;
		if ( $importId < 1 ) {
			return 0;
		}
		$before = $this->countByImport( $importId );
		$table  = Schema::rses_table( 'rses_official_share_submissions' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $table, array( 'tally_import_id' => $importId ), array( '%d' ) );
		return $before;
	}
}
