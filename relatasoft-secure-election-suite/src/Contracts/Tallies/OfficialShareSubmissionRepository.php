<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Contracts\Tallies;

/**
 * Port: official Shamir share submissions on the tallying site (A2).
 */
interface OfficialShareSubmissionRepository {
	/** @param array<string,mixed> $data */
	public function create(array $data): int;

	public function countByImport(int $importId): int;

	public function countByImportAndIndex(int $importId, int $shareIndex): int;

	/** @return list<array<string,mixed>> */
	public function listByImport(int $importId): array;

	public function deleteByImport(int $importId): int;
}
