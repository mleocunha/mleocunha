<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Application\Journey;

use RelataSoft\SecureElectionSuite\Painel\Contracts\Journey\JourneyPresenter;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Journey\JourneyRouteResolver;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Journey\JourneyUrlGenerator;

/**
 * Composition root for A5 journey ports.
 */
final class JourneyGateway {

	private static ?self $instance = null;

	public function __construct(
		public readonly JourneyUrlGenerator $urls,
		public readonly JourneyRouteResolver $routes,
		public readonly JourneyPresenter $presenter,
	) {}

	public static function boot( self $gateway ): self {
		self::$instance = $gateway;
		return $gateway;
	}

	public static function get(): self {
		if ( null === self::$instance ) {
			throw new \RuntimeException( 'JourneyGateway is not booted.' );
		}
		return self::$instance;
	}

	public static function isBooted(): bool {
		return null !== self::$instance;
	}

	/** @internal tests */
	public static function reset(): void {
		self::$instance = null;
	}

	/**
	 * @param array<string,scalar> $query
	 */
	public function url( string $step, array $query = array() ): string {
		return $this->urls->urlFor( $step, $query );
	}

	/**
	 * @param array<string,mixed> $context
	 */
	public function render( string $step, array $context = array() ): string {
		return $this->presenter->render( $step, $context );
	}

	public function resolve( string $requestPath ): ?string {
		return $this->routes->resolveStep( $requestPath );
	}
}
