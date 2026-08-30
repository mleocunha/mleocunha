<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Contracts\Jobs;

/**
 * Port: chunked electoral-roll (.rsv) import job (A4).
 */
interface RsvImportJobService {
	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	public function createReceiving(string $originalName, int $totalChunks, int $totalBytes, bool $updateExisting);

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	public function appendChunk(string $chunkPath, int $chunkIndex);

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	public function ingestFullUpload(string $tmpPath, string $originalName, bool $updateExisting);

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	public function begin();

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	public function tick();

	/** @return array<string,mixed> */
	public function status(): array;

	/** @return array<string,mixed> */
	public function cancel(): array;

	public function hasActive(): bool;

	public function purgeExpired(): bool;
}
