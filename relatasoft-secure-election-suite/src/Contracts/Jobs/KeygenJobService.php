<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Contracts\Jobs;

/**
 * Port: chunked ElGamal key-generation job (A4).
 *
 * @phpstan-type JobStatus array<string,mixed>
 */
interface KeygenJobService {
	/** @param array<string,mixed> $params */
	public function start(array $params): array;

	public function tick(): array;

	public function status(): array;

	public function cancel(): array;

	public function hasActive(): bool;

	public function purgeExpired(): bool;
}
