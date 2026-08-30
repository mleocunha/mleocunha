<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Application\Jobs;

use RelataSoft\SecureElectionSuite\Painel\Contracts\Jobs\RsvExportJobService;
use RelataSoft\SecureElectionSuite\Voting\ElectoralRollExportJob;

/**
 * Domain-facing RSV export API; returns public status payloads for the UI client.
 */
final class LegacyRsvExportJobService implements RsvExportJobService {

	public function start( string $role, int $maxLines ) {
		$job = ElectoralRollExportJob::rses_create( $role, $maxLines );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		return ElectoralRollExportJob::rses_public_status( $job );
	}

	public function tick() {
		$job = ElectoralRollExportJob::rses_tick();
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		return ElectoralRollExportJob::rses_public_status( $job );
	}

	public function status(): array {
		return ElectoralRollExportJob::rses_public_status( ElectoralRollExportJob::rses_get() );
	}

	public function cancel(): array {
		$job = ElectoralRollExportJob::rses_cancel();
		return ElectoralRollExportJob::rses_public_status( $job );
	}

	public function downloadPath(): ?string {
		return ElectoralRollExportJob::rses_download_path();
	}

	public function hasActive(): bool {
		return ElectoralRollExportJob::rses_has_active();
	}

	public function purgeExpired(): bool {
		return ElectoralRollExportJob::rses_purge_if_expired();
	}
}
