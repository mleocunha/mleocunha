<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Contracts\Jobs;

/**
 * Port: chunked electoral-roll (.rsv) export job (A4).
 */
interface RsvExportJobService {
	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	public function start(string $role, int $maxLines);

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	public function tick();

	/** @return array<string,mixed> */
	public function status(): array;

	/** @return array<string,mixed> */
	public function cancel(): array;

	public function downloadPath(): ?string;

	public function hasActive(): bool;

	public function purgeExpired(): bool;
}
