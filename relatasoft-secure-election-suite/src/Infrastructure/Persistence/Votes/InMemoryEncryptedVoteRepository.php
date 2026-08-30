<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Infrastructure\Persistence\Votes;

use RelataSoft\SecureElectionSuite\Painel\Contracts\Votes\EncryptedVoteRepository;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Persistence\InMemoryStoreTrait;

final class InMemoryEncryptedVoteRepository implements EncryptedVoteRepository {
	use InMemoryStoreTrait;

	public function store(array $data): int {
		return $this->insertRow($data);
	}

	public function hasVoted(int $voterId, int $roundId, int $questionId): bool {
		foreach ($this->allRows() as $row) {
			if (
				(int) ($row['voter_user_id'] ?? 0) === $voterId
				&& (int) ($row['round_id'] ?? 0) === $roundId
				&& (int) ($row['question_id'] ?? 0) === $questionId
			) {
				return true;
			}
		}
		return false;
	}

	public function hasVotedRound(int $voterId, int $roundId): bool {
		foreach ($this->allRows() as $row) {
			if ((int) ($row['voter_user_id'] ?? 0) === $voterId && (int) ($row['round_id'] ?? 0) === $roundId) {
				return true;
			}
		}
		return false;
	}

	public function countDistinctVoters(int $roundId): int {
		$seen = array();
		foreach ($this->allRows() as $row) {
			if ((int) ($row['round_id'] ?? 0) === $roundId) {
				$seen[(int) ($row['voter_user_id'] ?? 0)] = true;
			}
		}
		return count($seen);
	}

	public function forEachExportRow(int $roundId, callable $callback, int $batch = 100): void {
		$rows = array();
		foreach ($this->allRows() as $row) {
			if ((int) ($row['round_id'] ?? 0) === $roundId) {
				$rows[] = $row;
			}
		}
		usort($rows, static fn($a, $b) => ((int) $a['id']) <=> ((int) $b['id']));
		foreach ($rows as $row) {
			$callback(array(
				'id'               => (int) $row['id'],
				'question_id'      => (int) ($row['question_id'] ?? 0),
				'option_id'        => isset($row['option_id']) ? (int) $row['option_id'] : null,
				'ciphertext_alpha' => $row['ciphertext_alpha'] ?? null,
				'ciphertext_beta'  => $row['ciphertext_beta'] ?? null,
				'vote_hash'        => $row['vote_hash'] ?? null,
				'cast_at'          => $row['cast_at'] ?? null,
			));
		}
	}

	public function receiptHash(int $voterId, int $roundId): ?string {
		$hashes = array();
		foreach ($this->allRows() as $row) {
			if ((int) ($row['voter_user_id'] ?? 0) === $voterId && (int) ($row['round_id'] ?? 0) === $roundId) {
				$hashes[] = array('id' => (int) $row['id'], 'hash' => (string) ($row['vote_hash'] ?? ''));
			}
		}
		if (empty($hashes)) {
			return null;
		}
		usort($hashes, static fn($a, $b) => $a['id'] <=> $b['id']);
		$concat = '';
		foreach ($hashes as $h) {
			$concat .= $h['hash'];
		}
		return hash('sha256', $concat);
	}
}
