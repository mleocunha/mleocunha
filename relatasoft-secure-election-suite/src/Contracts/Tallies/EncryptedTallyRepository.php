<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Contracts\Tallies;

/**
 * Port: homomorphic aggregates per round (A2).
 */
interface EncryptedTallyRepository {
	/** @param list<array<string,mixed>> $rows */
	public function replaceForRound(int $roundId, array $rows): int;

	/** @return list<array<string,mixed>> */
	public function listByRound(int $roundId): array;

	public function deleteByRound(int $roundId): void;
}
