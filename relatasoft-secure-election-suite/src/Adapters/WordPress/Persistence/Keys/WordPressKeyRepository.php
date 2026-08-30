<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Persistence\Keys;

use RelataSoft\SecureElectionSuite\Database\Repository;
use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Persistence\RowMapper;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Keys\KeyRepository;

final class WordPressKeyRepository implements KeyRepository {

	public function create(array $data): int {
		return Repository::rses_insert(
			'rses_keys',
			$data,
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%d', '%s' )
		);
	}

	public function find(int $keyId): ?array {
		$row = Repository::rses_get_by_id( 'rses_keys', $keyId );
		if ( $row && (int) $row->is_deleted === 1 ) {
			return null;
		}
		return RowMapper::toArray( $row );
	}

	public function listActive(): array {
		return RowMapper::toArrays( Repository::rses_get_rows( 'rses_keys', 'is_deleted = 0', array() ) );
	}

	public function trash(int $keyId): bool {
		return Repository::rses_update(
			'rses_keys',
			array(
				'is_deleted' => 1,
				'deleted_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $keyId ),
			array( '%d', '%s' ),
			array( '%d' )
		);
	}

	public function restore(int $keyId): bool {
		return Repository::rses_update(
			'rses_keys',
			array(
				'is_deleted' => 0,
				'deleted_at' => null,
			),
			array( 'id' => $keyId ),
			array( '%d', '%s' ),
			array( '%d' )
		);
	}

	public function delete(int $keyId): bool {
		return Repository::rses_delete_by_id( 'rses_keys', $keyId );
	}

	public function updateThresholdMeta(int $keyId, string $fieldPrime, int $thresholdT, int $totalN): bool {
		return Repository::rses_update(
			'rses_keys',
			array(
				'field_prime' => $fieldPrime,
				'threshold_t' => $thresholdT,
				'total_n'     => $totalN,
			),
			array( 'id' => $keyId ),
			array( '%s', '%d', '%d' ),
			array( '%d' )
		);
	}
}
