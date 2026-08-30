<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Infrastructure\Journey;

use RelataSoft\SecureElectionSuite\Painel\Contracts\Journey\JourneySteps;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Journey\JourneyUrlGenerator;

/**
 * In-memory URL builder for journey ports (no sítio boot).
 */
final class InMemoryJourneyUrlGenerator implements JourneyUrlGenerator {

	public function __construct(
		private readonly string $origin = 'https://voto.test',
	) {}

	public function basePath(): string {
		return 'voto';
	}

	public function pathFor( string $step ): string {
		return JourneySteps::pathFor( $step );
	}

	public function urlFor( string $step, array $query = array() ): string {
		$url = rtrim( $this->origin, '/' ) . '/' . $this->pathFor( $step ) . '/';
		if ( array() === $query ) {
			return $url;
		}
		return $url . '?' . http_build_query( $query );
	}
}
