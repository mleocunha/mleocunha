<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Infrastructure\Persistence\Tallies;

use RelataSoft\SecureElectionSuite\Painel\Contracts\Tallies\EncryptedTallyRepository;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Persistence\InMemoryStoreTrait;

final class InMemoryEncryptedTallyRepository implements EncryptedTallyRepository {
	use InMemoryStoreTrait;

	public function replaceForRound(int $roundId, array $rows): int {
		$this->deleteByRound($roundId);
		$count = 0;
		foreach ($rows as $row) {
			$row['round_id'] = $roundId;
			$this->insertRow($row);
			++$count;
		}
		return $count;
	}

	public function listByRound(int $roundId): array {
		$out = array();
		foreach ($this->allRows() as $row) {
			if ((int) ($row['round_id'] ?? 0) === $roundId) {
				$out[] = $row;
			}
		}
		return $out;
	}

	public function deleteByRound(int $roundId): void {
		foreach (array_keys($this->rows) as $id) {
			if ((int) ($this->rows[$id]['round_id'] ?? 0) === $roundId) {
				unset($this->rows[$id]);
			}
		}
	}
}
