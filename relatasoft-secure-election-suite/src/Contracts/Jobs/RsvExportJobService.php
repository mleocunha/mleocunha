<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Contracts\Jobs;

/**
 * Port: chunked electoral-roll (.rsv) export job (A4).
 *
 * Failures return {@see JobResult::fail()} arrays — never host error objects.
 */
interface RsvExportJobService {
	/** @return array<string,mixed> */
	public function start(string $role, int $maxLines): array;

	/** @return array<string,mixed> */
	public function tick(): array;

	/** @return array<string,mixed> */
	public function status(): array;

	/** @return array<string,mixed> */
	public function cancel(): array;

	public function downloadPath(): ?string;

	/** Suggested download filename when ready (empty if none). */
	public function downloadFilename(): string;

	public function hasActive(): bool;

	public function purgeExpired(): bool;
}
