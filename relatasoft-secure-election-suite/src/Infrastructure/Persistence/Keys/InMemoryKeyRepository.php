<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Infrastructure\Persistence\Keys;

use RelataSoft\SecureElectionSuite\Painel\Contracts\Keys\KeyRepository;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Persistence\InMemoryStoreTrait;

final class InMemoryKeyRepository implements KeyRepository {
	use InMemoryStoreTrait;

	public function create(array $data): int {
		$data['is_deleted'] = (int) ($data['is_deleted'] ?? 0);
		return $this->insertRow($data);
	}

	public function find(int $keyId): ?array {
		$row = $this->findRow($keyId);
		if (null === $row || (int) ($row['is_deleted'] ?? 0) === 1) {
			return null;
		}
		return $row;
	}

	public function listActive(): array {
		$out = array();
		foreach ($this->allRows() as $row) {
			if ((int) ($row['is_deleted'] ?? 0) === 0) {
				$out[] = $row;
			}
		}
		return $out;
	}

	public function trash(int $keyId): bool {
		if (!isset($this->rows[$keyId])) {
			return false;
		}
		$this->rows[$keyId]['is_deleted'] = 1;
		$this->rows[$keyId]['deleted_at'] = $this->rows[$keyId]['deleted_at'] ?? gmdate('Y-m-d H:i:s');
		return true;
	}

	public function restore(int $keyId): bool {
		if (!isset($this->rows[$keyId])) {
			return false;
		}
		$this->rows[$keyId]['is_deleted'] = 0;
		$this->rows[$keyId]['deleted_at'] = null;
		return true;
	}

	public function delete(int $keyId): bool {
		if (!isset($this->rows[$keyId])) {
			return false;
		}
		unset($this->rows[$keyId]);
		return true;
	}

	public function updateThresholdMeta(int $keyId, string $fieldPrime, int $thresholdT, int $totalN): bool {
		if (!isset($this->rows[$keyId])) {
			return false;
		}
		$this->rows[$keyId]['field_prime'] = $fieldPrime;
		$this->rows[$keyId]['threshold_t'] = $thresholdT;
		$this->rows[$keyId]['total_n']     = $totalN;
		return true;
	}
}
