<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Contracts\Journey;

/**
 * Port: map a request path to a journey step (A5).
 */
interface JourneyRouteResolver {

	/**
	 * @param string $requestPath Path only (e.g. "/voto/cabina/" or "voto/cabina").
	 * @return string|null One of {@see JourneySteps} constants, or null if not a journey route.
	 */
	public function resolveStep( string $requestPath ): ?string;
}
