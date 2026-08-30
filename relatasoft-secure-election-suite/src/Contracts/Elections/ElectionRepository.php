<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Contracts\Elections;

/**
 * Port: elections, rounds, ballot questions/options (A2).
 */
interface ElectionRepository {
	/** @param array<string,mixed> $data */
	public function createElection(array $data): int;

	/** @return array<string,mixed>|null */
	public function findElection(int $electionId): ?array;

	/** @return list<array<string,mixed>> */
	public function listElections(): array;

	public function updateElectionStatus(int $electionId, string $status): bool;

	/** @param array<string,mixed> $data */
	public function createRound(array $data): int;

	/** @return array<string,mixed>|null */
	public function findRound(int $roundId): ?array;

	/** @return list<array<string,mixed>> */
	public function listRounds(int $electionId): array;

	public function updateRoundStatus(int $roundId, string $status, ?string $openedAt = null, ?string $closedAt = null): bool;

	/** @param array<string,mixed> $data */
	public function createQuestion(array $data): int;

	/** @param array<string,mixed> $data */
	public function createOption(array $data): int;

	/** @return list<array<string,mixed>> */
	public function listQuestions(int $roundId): array;

	/** @return list<array<string,mixed>> */
	public function listOptions(int $questionId): array;
}
