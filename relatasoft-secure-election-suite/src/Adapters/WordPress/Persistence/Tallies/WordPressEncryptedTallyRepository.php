<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Persistence\Tallies;

use RelataSoft\SecureElectionSuite\Database\Repository;
use RelataSoft\SecureElectionSuite\Database\Schema;
use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Persistence\RowMapper;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Tallies\EncryptedTallyRepository;

final class WordPressEncryptedTallyRepository implements EncryptedTallyRepository {

	public function replaceForRound(int $roundId, array $rows): int {
		$this->deleteByRound( $roundId );
		$count = 0;
		foreach ( $rows as $row ) {
			$row['round_id'] = $roundId;
			Repository::rses_insert(
				'rses_encrypted_tallies',
				$row,
				array( '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%s' )
			);
			++$count;
		}
		return $count;
	}

	public function listByRound(int $roundId): array {
		return RowMapper::toArrays(
			Repository::rses_get_rows( 'rses_encrypted_tallies', 'round_id = %d', array( $roundId ) )
		);
	}

	public function deleteByRound(int $roundId): void {
		global $wpdb;
		$table = Schema::rses_table( 'rses_encrypted_tallies' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE round_id = %d",
				$roundId
			)
		);
	}
}
