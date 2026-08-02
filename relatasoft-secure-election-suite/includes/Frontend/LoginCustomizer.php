<?php
/**
 * WordPress login / logout screen styling for electors.
 *
 * @package RelataSoft\SecureElectionSuite\Frontend
 */

namespace RelataSoft\SecureElectionSuite\Frontend;

use RelataSoft\SecureElectionSuite\Bootstrap\ModeLock;
use RelataSoft\SecureElectionSuite\I18n\LocaleResolver;
use RelataSoft\SecureElectionSuite\I18n\Translator;

defined( 'ABSPATH' ) || exit;

/**
 * TotalPoll-inspired login skin (voting mode only).
 */
class LoginCustomizer {

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'login_enqueue_scripts', array( self::class, 'rses_enqueue_login_assets' ) );
		add_filter( 'login_headerurl', array( self::class, 'rses_login_header_url' ) );
		add_filter( 'login_headertext', array( self::class, 'rses_login_header_text' ) );
		add_filter( 'gettext', array( self::class, 'rses_filter_login_labels' ), 20, 3 );
		add_filter( 'login_body_class', array( self::class, 'rses_login_body_class' ) );
		add_action( 'login_footer', array( self::class, 'rses_login_footer_note' ) );
	}

	/**
	 * Whether login customization should run.
	 */
	private static function rses_is_active(): bool {
		return ModeLock::rses_is_mode( ModeLock::RSES_MODE_VOTING );
	}

	/**
	 * Enqueue login stylesheet and inline logo.
	 */
	public static function rses_enqueue_login_assets(): void {
		if ( ! self::rses_is_active() ) {
			return;
		}

		wp_enqueue_style(
			'rses-login',
			RSES_PLUGIN_URL . 'assets/css/login.css',
			array(),
			RSES_VERSION
		);

		$rses_logo = JourneySettings::rses_get_login_logo_url();
		$rses_css  = sprintf(
			'#login h1 a{background-image:url(%s)!important;background-size:contain!important;width:120px!important;height:120px!important;margin:0 auto 1.25rem!important;}',
			esc_url( $rses_logo )
		);

		wp_add_inline_style( 'rses-login', $rses_css );
	}

	/**
	 * Logo link target.
	 */
	public static function rses_login_header_url( string $url ): string {
		if ( ! self::rses_is_active() ) {
			return $url;
		}

		$rses_welcome = JourneySettings::rses_page_url( 'welcome_page_id' );
		return $rses_welcome ?: home_url( '/' );
	}

	/**
	 * Logo link text (replaces deprecated login_headertitle since WP 5.2).
	 */
	public static function rses_login_header_text( string $text ): string {
		if ( ! self::rses_is_active() ) {
			return $text;
		}

		return get_bloginfo( 'name', 'display' );
	}

	/**
	 * Rename Username → Identification and Password → Secret on wp-login.php.
	 *
	 * @param string $translation Translated string.
	 * @param string $text        Original.
	 * @param string $domain      Text domain.
	 */
	public static function rses_filter_login_labels( string $translation, string $text, string $domain ): string {
		if ( ! self::rses_is_active() || 'default' !== $domain ) {
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

	/**
	 * Add locale/dir classes to login body.
	 *
	 * @param array<int,string> $classes Body classes.
	 * @return array<int,string>
	 */
	public static function rses_login_body_class( array $classes ): array {
		if ( ! self::rses_is_active() ) {
			return $classes;
		}

		$classes[] = 'rses-login-screen';
		$classes[] = 'locale-' . sanitize_html_class( LocaleResolver::rses_resolve() );

		if ( 'rtl' === Translator::rses_dir_attr() ) {
			$classes[] = 'rses-rtl';
		}

		return $classes;
	}

	/**
	 * Subtle footer note on login (not RelataSoft propaganda — election context only).
	 */
	public static function rses_login_footer_note(): void {
		if ( ! self::rses_is_active() ) {
			return;
		}
		?>
		<p class="rses-login-footnote">
			<?php esc_html_e( 'Secure electronic voting — sign in with your elector credentials.', 'relatasoft-secure-election-suite' ); ?>
		</p>
		<?php
	}
}
