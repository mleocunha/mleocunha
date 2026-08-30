<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Infrastructure\Journey;

use RelataSoft\SecureElectionSuite\Painel\Contracts\Journey\JourneyPresenter;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Journey\JourneySteps;

/**
 * Deterministic HTML stubs for journey port tests.
 */
final class InMemoryJourneyPresenter implements JourneyPresenter {

	public function render( string $step, array $context = array() ): string {
		if ( ! JourneySteps::isValid( $step ) ) {
			throw new \InvalidArgumentException( 'Unknown journey step: ' . $step );
		}

		$election = (int) ( $context['election_id'] ?? 0 );
		$round    = (int) ( $context['round_id'] ?? 0 );

		return sprintf(
			'<div data-rses-journey="%s" data-election="%d" data-round="%d"></div>',
			$step,
			$election,
			$round
		);
	}
}
