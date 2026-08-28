<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Infrastructure\Persistence\Tallies;

use RelataSoft\SecureElectionSuite\Painel\Contracts\Tallies\OfficialShareSubmissionRepository;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Persistence\InMemoryStoreTrait;

final class InMemoryOfficialShareSubmissionRepository implements OfficialShareSubmissionRepository {
	use InMemoryStoreTrait;

	public function create(array $data): int {
		return $this->insertRow($data);
	}

	public function countByImport(int $importId): int {
		$n = 0;
		foreach ($this->allRows() as $row) {
			if ((int) ($row['tally_import_id'] ?? 0) === $importId) {
				++$n;
			}
		}
		return $n;
	}

	public function countByImportAndIndex(int $importId, int $shareIndex): int {
		$n = 0;
		foreach ($this->allRows() as $row) {
			if ((int) ($row['tally_import_id'] ?? 0) === $importId && (int) ($row['share_index'] ?? 0) === $shareIndex) {
				++$n;
			}
		}
		return $n;
	}

	public function listByImport(int $importId): array {
		$out = array();
		foreach ($this->allRows() as $row) {
			if ((int) ($row['tally_import_id'] ?? 0) === $importId) {
				$out[] = $row;
			}
		}
		return $out;
	}

	public function deleteByImport(int $importId): int {
		$n = 0;
		foreach (array_keys($this->rows) as $id) {
			if ((int) ($this->rows[$id]['tally_import_id'] ?? 0) === $importId) {
				unset($this->rows[$id]);
				++$n;
			}
		}
		return $n;
	}
}
