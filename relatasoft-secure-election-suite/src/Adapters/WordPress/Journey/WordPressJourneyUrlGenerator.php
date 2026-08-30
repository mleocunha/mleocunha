<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Journey;

use RelataSoft\SecureElectionSuite\Painel\Contracts\Journey\JourneySteps;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Journey\JourneyUrlGenerator;

/**
 * Adapter #1: journey URLs via home_url (no page permalinks).
 */
final class WordPressJourneyUrlGenerator implements JourneyUrlGenerator {

	public function basePath(): string {
		return 'voto';
	}

	public function pathFor( string $step ): string {
		return JourneySteps::pathFor( $step );
	}

	public function urlFor( string $step, array $query = array() ): string {
		$url = home_url( '/' . $this->pathFor( $step ) . '/' );
		if ( array() === $query ) {
			return $url;
		}
		return add_query_arg( $query, $url );
	}
}
