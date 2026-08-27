<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress;

use RelataSoft\SecureElectionSuite\Bootstrap\ModeLock;
use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Admin\AdminBarCleaner;
use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Admin\AdminFooterBranding;
use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Admin\AdminRedirect;
use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Assets\WordPressAssetLoader;
use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Branding\SiteIconBranding;
use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Branding\WordPressLoginBranding;
use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Logging\WordPressLogger;
use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Navigation\WordPressMenuChrome;
use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Platform\FingerprintHardening;
use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Platform\PlatformUrlMask;
use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Settings\WordPressSettingsRepository;
use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\User\GestorRoleRegistrar;
use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\User\WordPressCapabilityResolver;
use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\User\WordPressUserProvider;
use RelataSoft\SecureElectionSuite\Painel\Application\Access\PermissionResolver;
use RelataSoft\SecureElectionSuite\Painel\Application\Branding\LoginBrandingService;
use RelataSoft\SecureElectionSuite\Painel\Application\Dashboard\DashboardHomeService;
use RelataSoft\SecureElectionSuite\Painel\Application\Navigation\NavigationService;
use RelataSoft\SecureElectionSuite\Painel\Application\Settings\SettingsService;
use RelataSoft\SecureElectionSuite\Painel\Core\PainelKernel;
use RelataSoft\SecureElectionSuite\Painel\Domain\Access\AccessPolicy;
use RelataSoft\SecureElectionSuite\Painel\Domain\Navigation\NavigationRegistry;
use RelataSoft\SecureElectionSuite\Painel\Presentation\Admin\HomeView;
use RelataSoft\SecureElectionSuite\Painel\Presentation\Admin\ShellView;

/**
 * WordPress adapter entry for the Painel module.
 */
final class Bootstrap {

	public static function register(): void {
		$logger       = new WordPressLogger();
		$settingsRepo = new WordPressSettingsRepository();
		$users        = new WordPressUserProvider();
		$caps         = new WordPressCapabilityResolver();
		$navRegistrar = new WordPressMenuChrome();
		$assets       = new WordPressAssetLoader();
		$policy       = new AccessPolicy();
		$registry     = new NavigationRegistry();
		$settingsSvc  = new SettingsService( $settingsRepo, $logger );
		$permissions  = new PermissionResolver( $caps, $policy );
		$navigation   = new NavigationService( $registry, $policy, $permissions );
		$dashboard    = new DashboardHomeService( $permissions, $policy );
		$loginBrand   = new LoginBrandingService( $settingsSvc );

		$kernel = PainelKernel::boot(
			new PainelKernel(
				$settingsRepo,
				$users,
				$caps,
				$navRegistrar,
				$assets,
				$logger,
				$policy,
				$registry,
				$settingsSvc,
				$permissions,
				$navigation,
				$dashboard,
				$loginBrand,
			)
		);

		GestorRoleRegistrar::register();
		$settingsSvc->migrate();

		$mode = ModeLock::rses_has_mode() ? ModeLock::rses_get_mode() : '';
		$navigation->seedDefaultItems( $mode );

		add_action( 'init', array( GestorRoleRegistrar::class, 'ensureRole' ), 5 );
		add_action( 'admin_init', array( AdminRedirect::class, 'maybeRedirectDashboard' ) );
		add_action( 'admin_menu', array( WordPressMenuChrome::class, 'hideNativeMenus' ), 999 );
		add_action( 'admin_bar_menu', array( AdminBarCleaner::class, 'filter' ), 999 );
		AdminFooterBranding::register();
		add_action( 'admin_enqueue_scripts', array( $assets, 'enqueueAdminShell' ) );
		add_action( 'in_admin_header', array( ShellView::class, 'renderOpen' ), 1 );
		add_action( 'in_admin_footer', array( ShellView::class, 'renderClose' ), 999 );
		add_filter( 'admin_body_class', array( ShellView::class, 'bodyClass' ) );

		WordPressLoginBranding::register( $loginBrand, $assets );
		SiteIconBranding::register();
		PlatformUrlMask::register();
		FingerprintHardening::register();

		$logger->info(
			'painel.booted',
			array(
				'mode'    => $mode !== '' ? $mode : 'unset',
				'version' => defined( 'RSES_VERSION' ) ? RSES_VERSION : '',
			)
		);
	}

	/**
	 * Render Painel home (used by AdminMenu dashboard callback wrapper).
	 */
	public static function renderHome(): void {
		HomeView::render();
	}
}
