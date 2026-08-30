<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Contracts\Audit;

/**
 * Port: hash-chained audit log (A2).
 */
interface AuditLogRepository {
	/** @param array<string,mixed> $entry */
	public function append(array $entry): int;

	public function lastHash(): ?string;

	/** @return list<array<string,mixed>> */
	public function listRecent(int $limit = 100): array;

	/** @return list<array<string,mixed>> */
	public function listAllOrdered(): array;

	public function updateHashes(int $id, ?string $previous, string $current): void;
}
