<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Persistence\Keys;

use RelataSoft\SecureElectionSuite\Database\Repository;
use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Persistence\RowMapper;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Keys\ShareRepository;

final class WordPressShareRepository implements ShareRepository {

	public function create(array $data): int {
		return Repository::rses_insert(
			'rses_shares',
			$data,
			array( '%d', '%d', '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	public function listByKey(int $keyId): array {
		return RowMapper::toArrays(
			Repository::rses_get_rows( 'rses_shares', 'key_id = %d', array( $keyId ) )
		);
	}

	public function findForUser(int $keyId, int $userId): ?array {
		$rows = Repository::rses_get_rows(
			'rses_shares',
			'key_id = %d AND official_user_id = %d',
			array( $keyId, $userId ),
			'id ASC',
			1
		);
		return RowMapper::toArray( $rows[0] ?? null );
	}
}
