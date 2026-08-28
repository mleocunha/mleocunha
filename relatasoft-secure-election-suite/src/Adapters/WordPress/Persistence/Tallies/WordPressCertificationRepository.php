<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Persistence\Tallies;

use RelataSoft\SecureElectionSuite\Database\Repository;
use RelataSoft\SecureElectionSuite\Database\Schema;
use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Persistence\RowMapper;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Tallies\CertificationRepository;

final class WordPressCertificationRepository implements CertificationRepository {

	public function create(array $data): int {
		$fmt = array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' );
		if ( isset( $data['pdf_attachment_id'] ) ) {
			$fmt[] = '%d';
		}
		return Repository::rses_insert( 'rses_certifications', $data, $fmt );
	}

	public function findLatestReportByImport(int $importId): ?array {
		$rows = Repository::rses_get_rows(
			'rses_certifications',
			'tally_import_id = %d',
			array( $importId ),
			'id DESC',
			1
		);
		return RowMapper::toArray( $rows[0] ?? null );
	}

	public function deleteByImport(int $importId): int {
		global $wpdb;
		if ( $importId < 1 ) {
			return 0;
		}
		$table = Schema::rses_table( 'rses_certifications' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete( $table, array( 'tally_import_id' => $importId ), array( '%d' ) );
		return false === $result ? 0 : (int) $result;
	}
}
