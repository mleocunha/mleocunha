<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Application\Jobs;

use RelataSoft\SecureElectionSuite\Painel\Contracts\Jobs\JobResult;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Jobs\RsvExportJobService;
use RelataSoft\SecureElectionSuite\Voting\ElectoralRollExportJob;

/**
 * Domain-facing RSV export API; returns public status or {@see JobResult::fail()}.
 */
final class LegacyRsvExportJobService implements RsvExportJobService {

	public function start( string $role, int $maxLines ): array {
		$job = ElectoralRollExportJob::rses_create( $role, $maxLines );
		if ( is_wp_error( $job ) ) {
			return JobResult::fail( $job->get_error_code() ?: 'rsv_export', $job->get_error_message() );
		}
		return ElectoralRollExportJob::rses_public_status( $job );
	}

	public function tick(): array {
		$job = ElectoralRollExportJob::rses_tick();
		if ( is_wp_error( $job ) ) {
			return JobResult::fail( $job->get_error_code() ?: 'rsv_export', $job->get_error_message() );
		}
		return ElectoralRollExportJob::rses_public_status( $job );
	}

	public function status(): array {
		return ElectoralRollExportJob::rses_public_status( ElectoralRollExportJob::rses_get() );
	}

	public function cancel(): array {
		return ElectoralRollExportJob::rses_public_status( ElectoralRollExportJob::rses_cancel() );
	}

	public function downloadPath(): ?string {
		return ElectoralRollExportJob::rses_download_path();
	}

	public function downloadFilename(): string {
		$status = $this->status();
		$name   = (string) ( $status['original_name'] ?? '' );
		return '' !== $name ? $name : 'cadastro.rsv';
	}

	public function hasActive(): bool {
		return ElectoralRollExportJob::rses_has_active();
	}

	public function purgeExpired(): bool {
		return ElectoralRollExportJob::rses_purge_if_expired();
	}
}
