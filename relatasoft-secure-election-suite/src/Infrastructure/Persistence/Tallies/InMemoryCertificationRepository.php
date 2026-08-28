<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Infrastructure\Persistence\Tallies;

use RelataSoft\SecureElectionSuite\Painel\Contracts\Tallies\CertificationRepository;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Persistence\InMemoryStoreTrait;

final class InMemoryCertificationRepository implements CertificationRepository {
	use InMemoryStoreTrait;

	public function create(array $data): int {
		return $this->insertRow($data);
	}

	public function findLatestReportByImport(int $importId): ?array {
		$best = null;
		foreach ($this->allRows() as $row) {
			if ((int) ($row['tally_import_id'] ?? 0) !== $importId) {
				continue;
			}
			if (null === $best || (int) $row['id'] > (int) $best['id']) {
				$best = $row;
			}
		}
		return $best;
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
