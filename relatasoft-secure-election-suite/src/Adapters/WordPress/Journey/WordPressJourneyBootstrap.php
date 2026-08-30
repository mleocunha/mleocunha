<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Journey;

use RelataSoft\SecureElectionSuite\Painel\Application\Journey\JourneyGateway;

/**
 * Boots Adapter #1 journey ports into {@see JourneyGateway}.
 */
final class WordPressJourneyBootstrap {

	public static function boot(): JourneyGateway {
		$gateway = JourneyGateway::boot(
			new JourneyGateway(
				new WordPressJourneyUrlGenerator(),
				new WordPressJourneyRouteResolver(),
				new WordPressJourneyPresenter(),
			)
		);

		WordPressJourneyFrontController::register();
		add_action( 'init', array( WordPressJourneyFrontController::class, 'maybeFlushRewrites' ), 20 );

		return $gateway;
	}
}
