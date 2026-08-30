<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Application\Jobs;

use RelataSoft\SecureElectionSuite\Painel\Contracts\Jobs\JobResult;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Jobs\RsvImportJobService;
use RelataSoft\SecureElectionSuite\Voting\ElectoralRollImportJob;

/**
 * Domain-facing RSV import API; returns public status or {@see JobResult::fail()}.
 */
final class LegacyRsvImportJobService implements RsvImportJobService {

	public function createReceiving( string $originalName, int $totalChunks, int $totalBytes, bool $updateExisting ): array {
		$job = ElectoralRollImportJob::rses_create_receiving( $originalName, $totalChunks, $totalBytes, $updateExisting );
		return $this->map( $job );
	}

	public function appendChunk( string $chunkPath, int $chunkIndex ): array {
		return $this->map( ElectoralRollImportJob::rses_append_chunk( $chunkPath, $chunkIndex ) );
	}

	public function ingestFullUpload( string $tmpPath, string $originalName, bool $updateExisting ): array {
		return $this->map( ElectoralRollImportJob::rses_ingest_full_upload( $tmpPath, $originalName, $updateExisting ) );
	}

	public function begin(): array {
		return $this->map( ElectoralRollImportJob::rses_begin_import() );
	}

	public function tick(): array {
		return $this->map( ElectoralRollImportJob::rses_tick() );
	}

	public function status(): array {
		return ElectoralRollImportJob::rses_public_status( ElectoralRollImportJob::rses_get() );
	}

	public function cancel(): array {
		return ElectoralRollImportJob::rses_public_status( ElectoralRollImportJob::rses_cancel() );
	}

	public function hasActive(): bool {
		return ElectoralRollImportJob::rses_has_active();
	}

	public function purgeExpired(): bool {
		return ElectoralRollImportJob::rses_purge_if_expired();
	}

	/**
	 * @param array<string,mixed>|\WP_Error $job
	 * @return array<string,mixed>
	 */
	private function map( $job ): array {
		if ( is_wp_error( $job ) ) {
			return JobResult::fail( $job->get_error_code() ?: 'rsv_import', $job->get_error_message() );
		}
		return ElectoralRollImportJob::rses_public_status( $job );
	}
}
