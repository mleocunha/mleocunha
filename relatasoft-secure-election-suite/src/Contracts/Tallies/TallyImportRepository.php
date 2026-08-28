<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Contracts\Tallies;

/**
 * Port: sealed packages imported for tallying (A2).
 */
interface TallyImportRepository {
	/** @param array<string,mixed> $data */
	public function create(array $data): int;

	/** @return array<string,mixed>|null */
	public function find(int $importId): ?array;

	/** @return list<array<string,mixed>> */
	public function listSummaries(): array;

	public function updateStatus(int $importId, string $status): bool;

	/** @param array<string,mixed> $summary */
	public function updateSummary(int $importId, array $summary): bool;

	public function delete(int $importId): bool;
}
