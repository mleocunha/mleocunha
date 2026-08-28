<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Infrastructure\Persistence\Audit;

use RelataSoft\SecureElectionSuite\Painel\Contracts\Audit\AuditLogRepository;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Persistence\InMemoryStoreTrait;

final class InMemoryAuditLogRepository implements AuditLogRepository {
	use InMemoryStoreTrait;

	public function append(array $entry): int {
		return $this->insertRow($entry);
	}

	public function lastHash(): ?string {
		$best = null;
		foreach ($this->allRows() as $row) {
			if (null === $best || (int) $row['id'] > (int) $best['id']) {
				$best = $row;
			}
		}
		if (null === $best) {
			return null;
		}
		$hash = $best['current_hash'] ?? null;
		return null === $hash || '' === $hash ? null : (string) $hash;
	}

	public function listRecent(int $limit = 100): array {
		$rows = $this->allRows();
		usort($rows, static fn($a, $b) => ((int) $b['id']) <=> ((int) $a['id']));
		return array_slice($rows, 0, max(0, $limit));
	}

	public function listAllOrdered(): array {
		$rows = $this->allRows();
		usort($rows, static fn($a, $b) => ((int) $a['id']) <=> ((int) $b['id']));
		return $rows;
	}

	public function updateHashes(int $id, ?string $previous, string $current): void {
		if (!isset($this->rows[$id])) {
			return;
		}
		$this->rows[$id]['previous_hash'] = $previous;
		$this->rows[$id]['current_hash']  = $current;
	}
}
