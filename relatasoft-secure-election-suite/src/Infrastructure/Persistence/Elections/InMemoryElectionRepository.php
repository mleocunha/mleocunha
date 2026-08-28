<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Infrastructure\Persistence\Elections;

use RelataSoft\SecureElectionSuite\Painel\Contracts\Elections\ElectionRepository;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Persistence\InMemoryStoreTrait;

final class InMemoryElectionRepository implements ElectionRepository {

	/** @var array<int,array<string,mixed>> */
	private array $elections = array();
	/** @var array<int,array<string,mixed>> */
	private array $rounds = array();
	/** @var array<int,array<string,mixed>> */
	private array $questions = array();
	/** @var array<int,array<string,mixed>> */
	private array $options = array();

	private int $electionId = 1;
	private int $roundId = 1;
	private int $questionId = 1;
	private int $optionId = 1;

	public function createElection(array $data): int {
		$id = $this->electionId++;
		$data['id'] = $id;
		$this->elections[$id] = $data;
		return $id;
	}

	public function findElection(int $electionId): ?array {
		return $this->elections[$electionId] ?? null;
	}

	public function listElections(): array {
		return array_values($this->elections);
	}

	public function updateElectionStatus(int $electionId, string $status): bool {
		if (!isset($this->elections[$electionId])) {
			return false;
		}
		$this->elections[$electionId]['status'] = $status;
		return true;
	}

	public function createRound(array $data): int {
		$id = $this->roundId++;
		$data['id'] = $id;
		$this->rounds[$id] = $data;
		$electionId = (int) ($data['election_id'] ?? 0);
		if (isset($this->elections[$electionId])) {
			$this->elections[$electionId]['current_round_id'] = $id;
		}
		return $id;
	}

	public function findRound(int $roundId): ?array {
		return $this->rounds[$roundId] ?? null;
	}

	public function listRounds(int $electionId): array {
		$out = array();
		foreach ($this->rounds as $row) {
			if ((int) ($row['election_id'] ?? 0) === $electionId) {
				$out[] = $row;
			}
		}
		usort($out, static fn($a, $b) => ((int) ($a['round_number'] ?? 0)) <=> ((int) ($b['round_number'] ?? 0)));
		return $out;
	}

	public function updateRoundStatus(int $roundId, string $status, ?string $openedAt = null, ?string $closedAt = null): bool {
		if (!isset($this->rounds[$roundId])) {
			return false;
		}
		$this->rounds[$roundId]['status'] = $status;
		if (null !== $openedAt) {
			$this->rounds[$roundId]['opened_at'] = $openedAt;
		}
		if (null !== $closedAt) {
			$this->rounds[$roundId]['closed_at'] = $closedAt;
		}
		return true;
	}

	public function createQuestion(array $data): int {
		$id = $this->questionId++;
		$data['id'] = $id;
		$this->questions[$id] = $data;
		return $id;
	}

	public function createOption(array $data): int {
		$id = $this->optionId++;
		$data['id'] = $id;
		$this->options[$id] = $data;
		return $id;
	}

	public function listQuestions(int $roundId): array {
		$out = array();
		foreach ($this->questions as $row) {
			if ((int) ($row['round_id'] ?? 0) === $roundId) {
				$out[] = $row;
			}
		}
		usort($out, static fn($a, $b) => ((int) ($a['order_index'] ?? 0)) <=> ((int) ($b['order_index'] ?? 0)));
		return $out;
	}

	public function listOptions(int $questionId): array {
		$out = array();
		foreach ($this->options as $row) {
			if ((int) ($row['question_id'] ?? 0) === $questionId) {
				$out[] = $row;
			}
		}
		usort($out, static fn($a, $b) => ((int) ($a['order_index'] ?? 0)) <=> ((int) ($b['order_index'] ?? 0)));
		return $out;
	}
}
