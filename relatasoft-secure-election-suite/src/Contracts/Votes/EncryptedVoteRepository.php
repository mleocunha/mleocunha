<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Contracts\Votes;

/**
 * Port: sealed ballots (A2).
 */
interface EncryptedVoteRepository {
	/** @param array<string,mixed> $data */
	public function store(array $data): int;

	public function hasVoted(int $voterId, int $roundId, int $questionId): bool;

	public function hasVotedRound(int $voterId, int $roundId): bool;

	public function countDistinctVoters(int $roundId): int;

	/**
	 * @param callable(array<string,mixed>):void $callback
	 */
	public function forEachExportRow(int $roundId, callable $callback, int $batch = 100): void;

	public function receiptHash(int $voterId, int $roundId): ?string;
}
