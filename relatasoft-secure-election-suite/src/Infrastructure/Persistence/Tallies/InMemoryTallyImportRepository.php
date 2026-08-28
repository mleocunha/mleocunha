<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Infrastructure\Persistence\Tallies;

use RelataSoft\SecureElectionSuite\Painel\Contracts\Tallies\TallyImportRepository;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Persistence\InMemoryStoreTrait;

final class InMemoryTallyImportRepository implements TallyImportRepository {
	use InMemoryStoreTrait;

	public function create(array $data): int {
		return $this->insertRow($data);
	}

	public function find(int $importId): ?array {
		return $this->findRow($importId);
	}

	public function listSummaries(): array {
		$out = array();
		foreach ($this->allRows() as $row) {
			$copy = $row;
			$manifest = (string) ($copy['import_manifest_json'] ?? '');
			$copy['manifest_bytes'] = strlen($manifest);
			unset($copy['import_manifest_json']);
			$out[] = $copy;
		}
		usort($out, static fn($a, $b) => ((int) $b['id']) <=> ((int) $a['id']));
		return $out;
	}

	public function updateStatus(int $importId, string $status): bool {
		if (!isset($this->rows[$importId])) {
			return false;
		}
		$this->rows[$importId]['status'] = $status;
		return true;
	}

	public function updateSummary(int $importId, array $summary): bool {
		if (!isset($this->rows[$importId])) {
			return false;
		}
		foreach ($summary as $k => $v) {
			$this->rows[$importId][$k] = $v;
		}
		return true;
	}

	public function delete(int $importId): bool {
		if (!isset($this->rows[$importId])) {
			return false;
		}
		unset($this->rows[$importId]);
		return true;
	}
}
