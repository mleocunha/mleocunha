<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Branding;

use RelataSoft\SecureElectionSuite\Painel\Application\Branding\LoginBrandingService;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Platform\AssetProvider;
use RelataSoft\SecureElectionSuite\Frontend\JourneySettings;

/**
 * Login branding for all modes (Painel owns login chrome).
 */
final class WordPressLoginBranding {

	private static ?LoginBrandingService $service = null;
	private static ?AssetProvider $assets = null;

	public static function register(LoginBrandingService $service, AssetProvider $assets): void {
		self::$service = $service;
		self::$assets  = $assets;
		add_action( 'login_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_filter( 'login_headerurl', array( self::class, 'headerUrl' ) );
		add_filter( 'login_headertext', array( self::class, 'headerText' ) );
		add_filter( 'login_body_class', array( self::class, 'bodyClass' ) );
		add_action( 'login_footer', array( self::class, 'footerNote' ) );
		add_filter( 'gettext', array( self::class, 'filterLabels' ), 20, 3 );
	}

	public static function enqueue(): void {
		if ( ! self::$service || ! self::$service->isEnabled() || ! self::$assets ) {
			return;
		}
		self::$assets->enqueueLoginBranding();
		$logo = JourneySettings::rses_get_login_logo_url();
		$css  = sprintf(
			'#login h1 a{background-image:url(%s)!important;background-size:contain!important;width:140px!important;height:140px!important;margin:0 auto 1.25rem!important;}',
			esc_url( $logo )
		);
		wp_add_inline_style( 've-painel-login', $css );
	}

	public static function headerUrl(string $url): string {
		if ( ! self::$service || ! self::$service->isEnabled() ) {
			return $url;
		}
		$welcome = JourneySettings::rses_page_url( 'welcome_page_id' );
		return $welcome ?: home_url( '/' );
	}

	public static function headerText(string $text): string {
		if ( ! self::$service || ! self::$service->isEnabled() ) {
			return $text;
		}
		return self::$service->productName();
	}

	/** @param list<string> $classes */
	public static function bodyClass(array $classes): array {
		if ( self::$service && self::$service->isEnabled() ) {
			$classes[] = 've-painel-login';
			$classes[] = 'rses-login-screen';
		}
		return $classes;
	}

	public static function footerNote(): void {
		if ( ! self::$service || ! self::$service->isEnabled() ) {
			return;
		}
		echo '<p class="ve-painel-login-footnote">' . esc_html( self::$service->productName() ) . '</p>';
	}

	public static function filterLabels(string $translation, string $text, string $domain): string {
		if ( ! self::$service || ! self::$service->isEnabled() || 'default' !== $domain ) {
			return $translation;
		}
		global $pagenow;
		if ( 'wp-login.php' !== $pagenow ) {
			return $translation;
		}
		if ( 'Username' === $text || 'Username or Email Address' === $text ) {
			return __( 'Identification', 'relatasoft-secure-election-suite' );
		}
		if ( 'Password' === $text ) {
			return __( 'Secret', 'relatasoft-secure-election-suite' );
		}
		return $translation;
	}
}
