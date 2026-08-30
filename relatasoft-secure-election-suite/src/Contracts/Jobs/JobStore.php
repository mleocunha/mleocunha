<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Contracts\Jobs;

/**
 * Port: opaque job-state persistence (A4).
 *
 * Adapter #1 may use host options; domain never calls get_option / admin-ajax.
 */
interface JobStore {
	/** @return array<string,mixed>|null */
	public function get(string $slot): ?array;

	/** @param array<string,mixed> $job */
	public function put(string $slot, array $job): void;

	public function delete(string $slot): void;
}
