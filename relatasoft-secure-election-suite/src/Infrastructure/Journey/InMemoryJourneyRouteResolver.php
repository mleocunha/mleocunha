<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Infrastructure\Journey;

use RelataSoft\SecureElectionSuite\Painel\Contracts\Journey\JourneyRouteResolver;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Journey\JourneySteps;

/**
 * Path → step resolver shared by tests and Adapter #1.
 */
final class InMemoryJourneyRouteResolver implements JourneyRouteResolver {

	public function resolveStep( string $requestPath ): ?string {
		$path = strtolower( trim( $requestPath ) );
		$path = strtok( $path, '?' ) ?: $path;
		$path = trim( $path, '/' );

		if ( '' === $path ) {
			return null;
		}

		// Aliases for welcome.
		if ( 'voto' === $path || 'voto/boas-vindas' === $path ) {
			return JourneySteps::WELCOME;
		}

		foreach ( JourneySteps::PATHS as $step => $canonical ) {
			if ( $path === $canonical ) {
				return $step;
			}
		}

		return null;
	}
}
