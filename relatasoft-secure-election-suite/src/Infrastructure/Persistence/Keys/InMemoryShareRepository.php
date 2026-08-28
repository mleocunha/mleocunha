<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Infrastructure\Persistence\Keys;

use RelataSoft\SecureElectionSuite\Painel\Contracts\Keys\ShareRepository;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Persistence\InMemoryStoreTrait;

final class InMemoryShareRepository implements ShareRepository {
	use InMemoryStoreTrait;

	public function create(array $data): int {
		return $this->insertRow($data);
	}

	public function listByKey(int $keyId): array {
		$out = array();
		foreach ($this->allRows() as $row) {
			if ((int) ($row['key_id'] ?? 0) === $keyId) {
				$out[] = $row;
			}
		}
		return $out;
	}

	public function findForUser(int $keyId, int $userId): ?array {
		foreach ($this->allRows() as $row) {
			if ((int) ($row['key_id'] ?? 0) === $keyId && (int) ($row['official_user_id'] ?? 0) === $userId) {
				return $row;
			}
		}
		return null;
	}
}
