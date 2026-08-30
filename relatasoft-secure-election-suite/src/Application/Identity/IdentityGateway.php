<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Application\Identity;

use RelataSoft\SecureElectionSuite\Painel\Contracts\Security\SecretKeyProvider;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Session\SessionPort;
use RelataSoft\SecureElectionSuite\Painel\Contracts\User\CapabilityResolver;
use RelataSoft\SecureElectionSuite\Painel\Contracts\User\UserDirectory;
use RelataSoft\SecureElectionSuite\Painel\Contracts\User\UserProvider;

/**
 * Composition root for A3 identity / session / secret ports.
 */
final class IdentityGateway {

	private static ?self $instance = null;

	public function __construct(
		public readonly UserProvider $currentUser,
		public readonly UserDirectory $users,
		public readonly CapabilityResolver $capabilities,
		public readonly SessionPort $session,
		public readonly SecretKeyProvider $secrets,
	) {}

	public static function boot(self $gateway): self {
		self::$instance = $gateway;
		return $gateway;
	}

	public static function get(): self {
		if (null === self::$instance) {
			throw new \RuntimeException('IdentityGateway is not booted.');
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
}
