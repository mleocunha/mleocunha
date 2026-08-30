<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Journey;

use RelataSoft\SecureElectionSuite\Painel\Contracts\Journey\JourneyRouteResolver;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Journey\InMemoryJourneyRouteResolver;

/**
 * Adapter #1 route resolver — same path rules as InMemory (portable).
 */
final class WordPressJourneyRouteResolver implements JourneyRouteResolver {

	private InMemoryJourneyRouteResolver $inner;

	public function __construct() {
		$this->inner = new InMemoryJourneyRouteResolver();
	}

	public function resolveStep( string $requestPath ): ?string {
		return $this->inner->resolveStep( $requestPath );
	}
}
