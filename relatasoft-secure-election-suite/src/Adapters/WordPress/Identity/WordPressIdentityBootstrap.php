<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Identity;

use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Security\WordPressSecretKeyProvider;
use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Session\WordPressSessionPort;
use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\User\WordPressCapabilityResolver;
use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\User\WordPressUserDirectory;
use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\User\WordPressUserProvider;
use RelataSoft\SecureElectionSuite\Painel\Application\Identity\IdentityGateway;

/**
 * Boots Adapter #1 identity / session / secret ports into {@see IdentityGateway}.
 */
final class WordPressIdentityBootstrap {

	public static function boot(): IdentityGateway {
		$current = new WordPressUserProvider();
		$users   = new WordPressUserDirectory();
		$caps    = new WordPressCapabilityResolver( $users );
		$session = new WordPressSessionPort();
		$secrets = new WordPressSecretKeyProvider();

		return IdentityGateway::boot(
			new IdentityGateway( $current, $users, $caps, $session, $secrets )
		);
	}
}
