<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Contracts\Keys;

/**
 * Port: Shamir share records assigned to officials (A2).
 */
interface ShareRepository {
	/** @param array<string,mixed> $data */
	public function create(array $data): int;

	/** @return list<array<string,mixed>> */
	public function listByKey(int $keyId): array;

	/** @return array<string,mixed>|null */
	public function findForUser(int $keyId, int $userId): ?array;
}
