<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Infrastructure\Jobs;

use RelataSoft\SecureElectionSuite\Painel\Contracts\Jobs\JobStore;

final class InMemoryJobStore implements JobStore {

	/** @var array<string,array<string,mixed>> */
	private array $jobs = array();

	public function get(string $slot): ?array {
		return $this->jobs[$slot] ?? null;
	}

	public function put(string $slot, array $job): void {
		$this->jobs[$slot] = $job;
	}

	public function delete(string $slot): void {
		unset($this->jobs[$slot]);
	}
}
