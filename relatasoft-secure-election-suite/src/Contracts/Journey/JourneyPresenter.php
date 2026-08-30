<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Contracts\Journey;

/**
 * Port: render HTML for a journey step (A5).
 *
 * Shortcodes on Adapter #1 are thin clients of this port.
 */
interface JourneyPresenter {

	/**
	 * @param array<string,mixed> $context Step-specific context (election_id, round_id, …).
	 */
	public function render( string $step, array $context = array() ): string;
}
