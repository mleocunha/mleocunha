<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Infrastructure\Jobs;

use RelataSoft\SecureElectionSuite\Painel\Contracts\Jobs\JobSlots;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Jobs\JobStore;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Jobs\KeygenJobService;

/**
 * Deterministic keygen job double for PHPUnit (no crypto / WP).
 */
final class InMemoryKeygenJobService implements KeygenJobService {

	public function __construct(private readonly JobStore $store) {}

	public function start(array $params): array {
		$this->store->delete(JobSlots::KEYGEN);
		$job = array(
			'job_id'     => 'test-keygen',
			'stage'      => 'safe_prime',
			'progress'   => 1,
			'message'    => 'started',
			'bits'       => (int) ($params['bits'] ?? 512),
			'label'      => (string) ($params['label'] ?? 'k'),
			'created_at' => time(),
			'updated_at' => time(),
		);
		$this->store->put(JobSlots::KEYGEN, $job);
		return $this->publicStatus($job);
	}

	public function tick(): array {
		$job = $this->store->get(JobSlots::KEYGEN);
		if (null === $job) {
			return $this->publicStatus(null);
		}
		$stage = (string) ($job['stage'] ?? '');
		$map = array(
			'safe_prime' => array('generator', 25),
			'generator'  => array('keypair', 50),
			'keypair'    => array('persist', 75),
			'persist'    => array('shamir', 90),
			'shamir'     => array('complete', 100),
		);
		if (isset($map[$stage])) {
			$job['stage'] = $map[$stage][0];
			$job['progress'] = $map[$stage][1];
			$job['message'] = $job['stage'];
			$job['updated_at'] = time();
			if ('complete' === $job['stage']) {
				$job['key_id'] = 1;
			}
			$this->store->put(JobSlots::KEYGEN, $job);
		}
		return $this->publicStatus($job);
	}

	public function status(): array {
		return $this->publicStatus($this->store->get(JobSlots::KEYGEN));
	}

	public function cancel(): array {
		$job = $this->store->get(JobSlots::KEYGEN) ?? array();
		$job['stage'] = 'cancelled';
		$job['progress'] = 0;
		$job['message'] = 'cancelled';
		$job['updated_at'] = time();
		$this->store->put(JobSlots::KEYGEN, $job);
		return $this->publicStatus($job);
	}

	public function hasActive(): bool {
		$job = $this->store->get(JobSlots::KEYGEN);
		if (null === $job) {
			return false;
		}
		return !in_array((string) ($job['stage'] ?? ''), array('complete', 'failed', 'cancelled'), true);
	}

	public function purgeExpired(): bool {
		return false;
	}

	/** @param array<string,mixed>|null $job */
	private function publicStatus(?array $job): array {
		if (null === $job) {
			return array('active' => false, 'stage' => null, 'progress' => 0, 'message' => '');
		}
		$stage = (string) ($job['stage'] ?? '');
		return array(
			'active'   => !in_array($stage, array('complete', 'failed', 'cancelled'), true),
			'job_id'   => $job['job_id'] ?? '',
			'stage'    => $stage,
			'progress' => (int) ($job['progress'] ?? 0),
			'message'  => (string) ($job['message'] ?? ''),
			'key_id'   => $job['key_id'] ?? null,
		);
	}
}
