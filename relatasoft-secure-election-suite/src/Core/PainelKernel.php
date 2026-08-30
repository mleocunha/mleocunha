<?php
/**
 * Painel de Controle Eleitoral kernel (platform-agnostic wiring).
 *
 * @package RelataSoft\SecureElectionSuite\Painel\Core
 */

declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Core;

use RelataSoft\SecureElectionSuite\Painel\Application\Access\PermissionResolver;
use RelataSoft\SecureElectionSuite\Painel\Application\Branding\LoginBrandingService;
use RelataSoft\SecureElectionSuite\Painel\Application\Dashboard\DashboardHomeService;
use RelataSoft\SecureElectionSuite\Painel\Application\Navigation\NavigationService;
use RelataSoft\SecureElectionSuite\Painel\Application\Settings\SettingsService;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Navigation\NavigationRegistrar;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Platform\AssetProvider;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Platform\Logger;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Settings\SettingsRepository;
use RelataSoft\SecureElectionSuite\Painel\Contracts\User\CapabilityResolver;
use RelataSoft\SecureElectionSuite\Painel\Contracts\User\UserProvider;
use RelataSoft\SecureElectionSuite\Painel\Domain\Access\AccessPolicy;
use RelataSoft\SecureElectionSuite\Painel\Domain\Navigation\NavigationRegistry;

/**
 * Composition root for the Painel module.
 */
final class PainelKernel {

	private static ?self $instance = null;

	public function __construct(
		public readonly SettingsRepository $settings,
		public readonly UserProvider $users,
		public readonly CapabilityResolver $capabilities,
		public readonly NavigationRegistrar $navigationRegistrar,
		public readonly AssetProvider $assets,
		public readonly Logger $logger,
		public readonly AccessPolicy $accessPolicy,
		public readonly NavigationRegistry $navigationRegistry,
		public readonly SettingsService $settingsService,
		public readonly PermissionResolver $permissions,
		public readonly NavigationService $navigation,
		public readonly DashboardHomeService $dashboardHome,
		public readonly LoginBrandingService $loginBranding,
	) {}

	public static function instance(): ?self {
		return self::$instance;
	}

	public static function boot(self $kernel): self {
		self::$instance = $kernel;
		return $kernel;
	}
}
