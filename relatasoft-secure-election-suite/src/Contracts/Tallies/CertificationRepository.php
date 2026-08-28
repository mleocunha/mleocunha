<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Contracts\Tallies;

/**
 * Port: certification records (A2).
 */
interface CertificationRepository {
	/** @param array<string,mixed> $data */
	public function create(array $data): int;

	/** @return array<string,mixed>|null */
	public function findLatestReportByImport(int $importId): ?array;

	public function deleteByImport(int $importId): int;
}
