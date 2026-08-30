<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Infrastructure\Jobs;

use RelataSoft\SecureElectionSuite\Painel\Contracts\Jobs\JobSlots;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Jobs\JobStore;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Jobs\RsvExportJobService;

final class InMemoryRsvExportJobService implements RsvExportJobService {

	public function __construct(
		private readonly JobStore $store,
		private readonly int $ownerId = 1,
	) {}

	private function slot(): string {
		return JobSlots::rsvExport($this->ownerId);
	}

	public function start(string $role, int $maxLines): array {
		$job = array(
			'stage'         => 'exporting',
			'role'          => $role,
			'max_lines'     => $maxLines,
			'progress'      => 5,
			'message'       => 'exporting',
			'file_path'     => '/tmp/test-export.rsv',
			'original_name' => 'cadastro-teste.rsv',
			'created_at'    => time(),
			'updated_at'    => time(),
		);
		$this->store->put($this->slot(), $job);
		return $this->status();
	}

	public function tick(): array {
		$job = $this->store->get($this->slot());
		if (null === $job) {
			return $this->status();
		}
		$job['stage'] = 'complete';
		$job['progress'] = 100;
		$job['message'] = 'complete';
		$job['updated_at'] = time();
		$this->store->put($this->slot(), $job);
		return $this->status();
	}

	public function status(): array {
		$job = $this->store->get($this->slot());
		if (null === $job) {
			return array('active' => false, 'stage' => null, 'progress' => 0, 'message' => '');
		}
		$stage = (string) ($job['stage'] ?? '');
		return array(
			'active'          => in_array($stage, array('preparing', 'exporting'), true),
			'stage'           => $stage,
			'progress'        => (int) ($job['progress'] ?? 0),
			'message'         => (string) ($job['message'] ?? ''),
			'role'            => $job['role'] ?? '',
			'original_name'   => (string) ($job['original_name'] ?? ''),
			'download_ready'  => 'complete' === $stage && null !== $this->downloadPath(),
		);
	}

	public function cancel(): array {
		$job = $this->store->get($this->slot()) ?? array();
		$job['stage'] = 'cancelled';
		$job['progress'] = 0;
		$this->store->put($this->slot(), $job);
		return $this->status();
	}

	public function downloadPath(): ?string {
		$job = $this->store->get($this->slot());
		if (null === $job || 'complete' !== ($job['stage'] ?? '')) {
			return null;
		}
		return (string) ($job['file_path'] ?? null) ?: null;
	}

	public function downloadFilename(): string {
		$job = $this->store->get($this->slot());
		$name = (string) ($job['original_name'] ?? '');
		return '' !== $name ? $name : 'cadastro.rsv';
	}

	public function hasActive(): bool {
		return (bool) ($this->status()['active'] ?? false);
	}

	public function purgeExpired(): bool {
		return false;
	}
}
