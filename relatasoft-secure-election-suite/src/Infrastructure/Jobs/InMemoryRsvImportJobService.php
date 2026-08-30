<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Infrastructure\Jobs;

use RelataSoft\SecureElectionSuite\Painel\Contracts\Jobs\JobSlots;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Jobs\JobStore;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Jobs\RsvImportJobService;

final class InMemoryRsvImportJobService implements RsvImportJobService {

	public function __construct(
		private readonly JobStore $store,
		private readonly int $ownerId = 1,
	) {}

	private function slot(): string {
		return JobSlots::rsvImport($this->ownerId);
	}

	public function createReceiving(string $originalName, int $totalChunks, int $totalBytes, bool $updateExisting) {
		$job = array(
			'stage'           => 'receiving',
			'original_name'   => $originalName,
			'total_chunks'    => $totalChunks,
			'received_chunks' => 0,
			'total_bytes'     => $totalBytes,
			'update_existing' => $updateExisting,
			'progress'        => 0,
			'message'         => 'receiving',
			'created_at'      => time(),
			'updated_at'      => time(),
		);
		$this->store->put($this->slot(), $job);
		return $this->status();
	}

	public function appendChunk(string $chunkPath, int $chunkIndex) {
		$job = $this->store->get($this->slot());
		if (null === $job) {
			return array('ok' => false, 'error' => 'no job');
		}
		$job['received_chunks'] = (int) ($job['received_chunks'] ?? 0) + 1;
		$job['updated_at'] = time();
		if ($job['received_chunks'] >= (int) ($job['total_chunks'] ?? 0)) {
			$job['stage'] = 'ready';
			$job['message'] = 'ready';
		}
		$this->store->put($this->slot(), $job);
		return $this->status();
	}

	public function ingestFullUpload(string $tmpPath, string $originalName, bool $updateExisting) {
		return $this->createReceiving($originalName, 1, 10, $updateExisting);
	}

	public function begin() {
		$job = $this->store->get($this->slot()) ?? array();
		$job['stage'] = 'importing';
		$job['progress'] = 10;
		$job['message'] = 'importing';
		$job['updated_at'] = time();
		$this->store->put($this->slot(), $job);
		return $this->status();
	}

	public function tick(): array {
		$job = $this->store->get($this->slot());
		if (null === $job) {
			return $this->status();
		}
		if ('importing' === ($job['stage'] ?? '')) {
			$job['progress'] = 100;
			$job['stage'] = 'complete';
			$job['message'] = 'complete';
			$job['updated_at'] = time();
			$this->store->put($this->slot(), $job);
		}
		return $this->status();
	}

	public function status(): array {
		$job = $this->store->get($this->slot());
		if (null === $job) {
			return array('active' => false, 'stage' => null, 'progress' => 0, 'message' => '');
		}
		$stage = (string) ($job['stage'] ?? '');
		return array(
			'active'   => in_array($stage, array('receiving', 'ready', 'importing'), true),
			'stage'    => $stage,
			'progress' => (int) ($job['progress'] ?? 0),
			'message'  => (string) ($job['message'] ?? ''),
		);
	}

	public function cancel(): array {
		$job = $this->store->get($this->slot()) ?? array();
		$job['stage'] = 'cancelled';
		$job['progress'] = 0;
		$job['message'] = 'cancelled';
		$this->store->put($this->slot(), $job);
		return $this->status();
	}

	public function hasActive(): bool {
		return (bool) ($this->status()['active'] ?? false);
	}

	public function purgeExpired(): bool {
		return false;
	}
}
