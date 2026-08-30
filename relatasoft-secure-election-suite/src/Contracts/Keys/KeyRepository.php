<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Contracts\Keys;

/**
 * Port: ElGamal key records (A2).
 */
interface KeyRepository {
	/** @param array<string,mixed> $data */
	public function create(array $data): int;

	/** @return array<string,mixed>|null */
	public function find(int $keyId): ?array;

	/** @return list<array<string,mixed>> */
	public function listActive(): array;

	public function trash(int $keyId): bool;

	public function restore(int $keyId): bool;

	public function delete(int $keyId): bool;

	public function updateThresholdMeta(int $keyId, string $fieldPrime, int $thresholdT, int $totalN): bool;
}
