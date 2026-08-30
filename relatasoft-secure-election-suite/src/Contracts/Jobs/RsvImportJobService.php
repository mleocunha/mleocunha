<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Contracts\Jobs;

/**
 * Port: chunked electoral-roll (.rsv) import job (A4).
 *
 * Failures return {@see JobResult::fail()} arrays — never host error objects.
 */
interface RsvImportJobService {
	/** @return array<string,mixed> */
	public function createReceiving(string $originalName, int $totalChunks, int $totalBytes, bool $updateExisting): array;

	/** @return array<string,mixed> */
	public function appendChunk(string $chunkPath, int $chunkIndex): array;

	/** @return array<string,mixed> */
	public function ingestFullUpload(string $tmpPath, string $originalName, bool $updateExisting): array;

	/** @return array<string,mixed> */
	public function begin(): array;

	/** @return array<string,mixed> */
	public function tick(): array;

	/** @return array<string,mixed> */
	public function status(): array;

	/** @return array<string,mixed> */
	public function cancel(): array;

	public function hasActive(): bool;

	public function purgeExpired(): bool;
}
