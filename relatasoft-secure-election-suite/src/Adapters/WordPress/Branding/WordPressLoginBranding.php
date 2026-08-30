<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Branding;

use RelataSoft\SecureElectionSuite\Admin\Brand;
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
		add_action( 'login_footer', array( self::class, 'signatureMark' ), 20 );
		add_filter( 'gettext', array( self::class, 'filterLabels' ), 20, 3 );
	}

	/**
	 * Lockup completo no topo (custom attachment ou RelataSoft + slogan).
	 */
	public static function lockupUrl(): string {
		$custom_id = absint( JourneySettings::rses_get()['login_logo_attachment_id'] ?? 0 );
		if ( $custom_id > 0 ) {
			$custom = wp_get_attachment_image_url( $custom_id, 'full' );
			if ( is_string( $custom ) && '' !== $custom ) {
				return $custom;
			}
		}
		return Brand::rses_get_admin_logo_url();
	}

	public static function enqueue(): void {
		if ( ! self::$service || ! self::$service->isEnabled() || ! self::$assets ) {
			return;
		}
		self::$assets->enqueueLoginBranding();
		$lockup = self::lockupUrl();
		$css    = sprintf(
			'#login h1 a{background-image:url(%s)!important;background-color:transparent!important;background-size:contain!important;width:min(20.2rem,100%%)!important;height:5.25rem!important;margin:0 auto 1.75rem!important;box-shadow:none!important;}',
			esc_url( $lockup )
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

	/**
	 * Roda de Fogo pequena — assinatura de qualidade no canto inferior direito.
	 */
	public static function signatureMark(): void {
		if ( ! self::$service || ! self::$service->isEnabled() ) {
			return;
		}
		$url = Brand::rses_asset_url( Brand::RSES_DEFAULT_MARK );
		echo '<div class="ve-login-signature" aria-hidden="true">';
		echo '<img src="' . esc_url( $url ) . '" width="36" height="36" alt="" decoding="async" />';
		echo '</div>';
	}

	public static function filterLabels( $translation, $text = '', $domain = 'default' ) {
		if ( ! is_string( $translation ) || ! is_string( $text ) || ! is_string( $domain ) ) {
			return $translation;
		}
		if ( ! self::$service || ! self::$service->isEnabled() || 'default' !== $domain ) {
			return $translation;
		}
		global $pagenow;
		$is_login = ( isset( $pagenow ) && 'wp-login.php' === $pagenow )
			|| ( defined( 'VE_LOGIN_ENTRY' ) && VE_LOGIN_ENTRY );
		if ( ! $is_login ) {
			return $translation;
		}
		if ( 'Username' === $text || 'Username or Email Address' === $text ) {
			return __( 'Identificação', 'relatasoft-secure-election-suite' );
		}
		if ( 'Password' === $text ) {
			return __( 'Segredo', 'relatasoft-secure-election-suite' );
		}
		if ( 'Lost your password?' === $text || 'Lost Password' === $text ) {
			return __( 'Alterar ou recuperar senha', 'relatasoft-secure-election-suite' );
		}
		if ( 'Remember Me' === $text ) {
			return __( 'Lembrar-me', 'relatasoft-secure-election-suite' );
		}
		if ( 'Log In' === $text ) {
			return __( 'Acessar', 'relatasoft-secure-election-suite' );
		}
		return $translation;
	}
}
