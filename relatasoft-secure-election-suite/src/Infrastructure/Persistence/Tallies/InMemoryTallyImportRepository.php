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

	public function listIdsNeedingSummary(int $limit, int $maxManifestBytes): array {
		$ids = array();
		$rows = $this->allRows();
		usort($rows, static fn($a, $b) => ((int) $b['id']) <=> ((int) $a['id']));
		foreach ($rows as $row) {
			$title = (string) ($row['election_title'] ?? '');
			$manifest = (string) ($row['import_manifest_json'] ?? '');
			if ('' !== $title) {
				continue;
			}
			if (strlen($manifest) > $maxManifestBytes) {
				continue;
			}
			$ids[] = (int) $row['id'];
			if (count($ids) >= $limit) {
				break;
			}
		}
		return $ids;
	}

	public function purgeOversizedManifests(string $stubJson, int $maxBytes): int {
		$n = 0;
		foreach ($this->rows as $id => $row) {
			$manifest = (string) ($row['import_manifest_json'] ?? '');
			if (strlen($manifest) > $maxBytes) {
				$this->rows[$id]['import_manifest_json'] = $stubJson;
				$this->rows[$id]['status'] = 'rejected';
				++$n;
			}
		}
		return $n;
	}
}
