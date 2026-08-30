<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Journey;

use RelataSoft\SecureElectionSuite\Bootstrap\ModeLock;
use RelataSoft\SecureElectionSuite\Frontend\VoterJourney;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Journey\JourneyPresenter;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Journey\JourneySteps;
use RelataSoft\SecureElectionSuite\Voting\VotingViews;

/**
 * Adapter #1 presenter — shared by native /voto routes and shortcode facades.
 */
final class WordPressJourneyPresenter implements JourneyPresenter {

	public function render( string $step, array $context = array() ): string {
		if ( ! JourneySteps::isValid( $step ) ) {
			throw new \InvalidArgumentException( 'Unknown journey step: ' . $step );
		}

		if ( JourneySteps::BOOTH === $step && ! ModeLock::rses_is_mode( ModeLock::RSES_MODE_VOTING ) ) {
			return '<p>' . esc_html__( 'Voting is not available on this site.', 'relatasoft-secure-election-suite' ) . '</p>';
		}

		return match ( $step ) {
			JourneySteps::WELCOME   => VoterJourney::rses_render_welcome(),
			JourneySteps::THANK_YOU => VoterJourney::rses_render_thank_you( $context ),
			JourneySteps::BOOTH     => $this->renderBooth( $context ),
			default                 => '',
		};
	}

	/**
	 * @param array<string,mixed> $context
	 */
	private function renderBooth( array $context ): string {
		$election = absint( $context['election_id'] ?? 0 );
		$round    = absint( $context['round_id'] ?? 0 );

		if ( $election < 1 && isset( $_GET['election_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$election = absint( $_GET['election_id'] );
		}
		if ( $round < 1 && isset( $_GET['round_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$round = absint( $_GET['round_id'] );
		}

		ob_start();
		VotingViews::rses_render_voting_booth( $election, $round );
		return (string) ob_get_clean();
	}
}
