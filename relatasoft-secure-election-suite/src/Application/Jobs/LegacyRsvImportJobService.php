<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Application\Jobs;

use RelataSoft\SecureElectionSuite\Painel\Contracts\Jobs\RsvImportJobService;
use RelataSoft\SecureElectionSuite\Voting\ElectoralRollImportJob;

/**
 * Domain-facing RSV import API; returns public status payloads for the UI client.
 */
final class LegacyRsvImportJobService implements RsvImportJobService {

	public function createReceiving( string $originalName, int $totalChunks, int $totalBytes, bool $updateExisting ) {
		$job = ElectoralRollImportJob::rses_create_receiving( $originalName, $totalChunks, $totalBytes, $updateExisting );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		return ElectoralRollImportJob::rses_public_status( $job );
	}

	public function appendChunk( string $chunkPath, int $chunkIndex ) {
		$job = ElectoralRollImportJob::rses_append_chunk( $chunkPath, $chunkIndex );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		return ElectoralRollImportJob::rses_public_status( $job );
	}

	public function ingestFullUpload( string $tmpPath, string $originalName, bool $updateExisting ) {
		$job = ElectoralRollImportJob::rses_ingest_full_upload( $tmpPath, $originalName, $updateExisting );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		return ElectoralRollImportJob::rses_public_status( $job );
	}

	public function begin() {
		$job = ElectoralRollImportJob::rses_begin_import();
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		return ElectoralRollImportJob::rses_public_status( $job );
	}

	public function tick() {
		$job = ElectoralRollImportJob::rses_tick();
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		return ElectoralRollImportJob::rses_public_status( $job );
	}

	public function status(): array {
		return ElectoralRollImportJob::rses_public_status( ElectoralRollImportJob::rses_get() );
	}

	public function cancel(): array {
		$job = ElectoralRollImportJob::rses_cancel();
		return ElectoralRollImportJob::rses_public_status( $job );
	}

	public function hasActive(): bool {
		return ElectoralRollImportJob::rses_has_active();
	}

	public function purgeExpired(): bool {
		return ElectoralRollImportJob::rses_purge_if_expired();
	}
}
