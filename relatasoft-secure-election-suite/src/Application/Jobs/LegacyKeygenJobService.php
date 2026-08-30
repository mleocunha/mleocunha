<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Application\Jobs;

use RelataSoft\SecureElectionSuite\KeyAuthority\KeyGenerationJob;
use RelataSoft\SecureElectionSuite\KeyAuthority\KeyGenerationRunner;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Jobs\KeygenJobService;

/**
 * Domain-facing keygen API; delegates orchestration to legacy tick pipeline.
 */
final class LegacyKeygenJobService implements KeygenJobService {

	public function start(array $params): array {
		KeyGenerationJob::rses_delete();
		KeyGenerationJob::rses_create( $params );
		return KeyGenerationRunner::rses_tick();
	}

	public function tick(): array {
		return KeyGenerationRunner::rses_tick();
	}

	public function status(): array {
		return KeyGenerationJob::rses_public_status( KeyGenerationJob::rses_get() );
	}

	public function cancel(): array {
		KeyGenerationJob::rses_cancel();
		return $this->status();
	}

	public function hasActive(): bool {
		return KeyGenerationJob::rses_has_active();
	}

	public function purgeExpired(): bool {
		return KeyGenerationJob::rses_purge_if_expired();
	}
}
