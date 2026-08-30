<?php
/**
 * Brand assets and white-label logo resolution.
 *
 * Prefers RelataSoft Secure Election Suite settings when the plugin is active.
 *
 * @package VotoEletronicoTemaBase
 */

namespace VotoEletronicoTemaBase;

defined( 'ABSPATH' ) || exit;

/**
 * Logo helpers (aspect ratio always preserved by CSS).
 */
final class Branding {

	public const LOCKUP_HORIZONTAL = 'lockup-horizontal-on-dark.png';
	public const LOCKUP_VERTICAL   = 'lockup-vertical-light-text.png';
	public const PINWHEEL          = 'pinwheel.svg';

	/**
	 * Hook registration.
	 */
	public static function init(): void {
		add_action( 'wp_head', array( self::class, 'output_favicon' ), 2 );
	}

	/**
	 * Theme brand asset URL.
	 */
	public static function asset_url( string $filename ): string {
		return VETB_URI . '/assets/brand/' . ltrim( $filename, '/' );
	}

	/**
	 * Plugin settings array when RSES is active.
	 *
	 * @return array<string,mixed>
	 */
	public static function plugin_settings(): array {
		if ( ! class_exists( '\\RelataSoft\\SecureElectionSuite\\Frontend\\JourneySettings' ) ) {
			$saved = get_option( 'rses_settings', array() );
			return is_array( $saved ) ? $saved : array();
		}

		return \RelataSoft\SecureElectionSuite\Frontend\JourneySettings::rses_get();
	}

	/**
	 * Primary front lockup (horizontal) — plugin admin logo or default.
	 */
	public static function lockup_url(): string {
		$settings = self::plugin_settings();
		$id       = absint( $settings['admin_logo_attachment_id'] ?? 0 );
		if ( $id > 0 ) {
			$url = wp_get_attachment_image_url( $id, 'full' );
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}

		$custom = get_theme_mod( 'custom_logo' );
		if ( $custom ) {
			$url = wp_get_attachment_image_url( (int) $custom, 'full' );
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}

		return self::asset_url( self::LOCKUP_HORIZONTAL );
	}

	/**
	 * Compact Roda de Fogo mark (favicon / low-clutter marks).
	 */
	public static function pinwheel_url(): string {
		$settings = self::plugin_settings();
		$id       = absint( $settings['login_logo_attachment_id'] ?? 0 );
		if ( $id > 0 ) {
			$url = wp_get_attachment_image_url( $id, 'thumbnail' );
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}

		if ( defined( 'RSES_PLUGIN_URL' ) ) {
			return RSES_PLUGIN_URL . 'assets/brand/relatasoft-mark.png';
		}

		return self::asset_url( self::PINWHEEL );
	}

	/**
	 * Favicon / apple touch using Roda de Fogo.
	 */
	public static function output_favicon(): void {
		$url = esc_url( self::pinwheel_url() );
		$type = str_ends_with( strtolower( $url ), '.svg' ) ? 'image/svg+xml' : 'image/png';
		echo '<link rel="icon" href="' . $url . '" type="' . esc_attr( $type ) . '" sizes="any" />' . "\n";
		echo '<link rel="shortcut icon" href="' . $url . '" type="' . esc_attr( $type ) . '" />' . "\n";
		echo '<link rel="apple-touch-icon" href="' . $url . '" />' . "\n";
	}

	/**
	 * Vertical lockup for formal/official screens (PDF / printable tone).
	 */
	public static function vertical_lockup_url(): string {
		return self::asset_url( self::LOCKUP_VERTICAL );
	}

	/**
	 * Render horizontal brand lockup (common expression: mark left of name, slogan below).
	 *
	 * @param string $modifier Optional BEM modifier.
	 */
	public static function render_lockup( string $modifier = '' ): void {
		$class = 'vetb-brand-lockup';
		if ( '' !== $modifier ) {
			$class .= ' vetb-brand-lockup--' . sanitize_html_class( $modifier );
		}
		?>
		<div class="<?php echo esc_attr( $class ); ?>">
			<img
				class="vetb-brand-lockup__img"
				src="<?php echo esc_url( self::lockup_url() ); ?>"
				alt="<?php echo esc_attr( I18n::translate( 'RelataSoft — Participação mais Inteligente' ) ); ?>"
				decoding="async"
			/>
		</div>
		<?php
	}

	/**
	 * Render vertical lockup (official / PDF tone: mark above name + slogan).
	 *
	 * @param string $modifier Optional BEM modifier.
	 */
	public static function render_vertical_lockup( string $modifier = '' ): void {
		$class = 'vetb-brand-vertical';
		if ( '' !== $modifier ) {
			$class .= ' vetb-brand-vertical--' . sanitize_html_class( $modifier );
		}
		?>
		<div class="<?php echo esc_attr( $class ); ?>">
			<img
				class="vetb-brand-vertical__img"
				src="<?php echo esc_url( self::vertical_lockup_url() ); ?>"
				alt="<?php echo esc_attr( I18n::translate( 'RelataSoft — Participação mais Inteligente' ) ); ?>"
				decoding="async"
			/>
		</div>
		<?php
	}

	/**
	 * Render pinwheel mark (optionally animated for waiting screens).
	 *
	 * Animation is CSS rotate only — never regenerates the official artwork.
	 *
	 * @param bool   $animated Rotate via CSS (does not alter the artwork).
	 * @param string $modifier Optional modifier.
	 */
	public static function render_pinwheel( bool $animated = false, string $modifier = '' ): void {
		$class = 'vetb-pinwheel';
		if ( $animated ) {
			$class .= ' vetb-pinwheel--spin';
		}
		if ( '' !== $modifier ) {
			$class .= ' vetb-pinwheel--' . sanitize_html_class( $modifier );
		}
		?>
		<img
			class="<?php echo esc_attr( $class ); ?>"
			src="<?php echo esc_url( self::pinwheel_url() ); ?>"
			alt=""
			width="64"
			height="64"
			decoding="async"
		/>
		<?php
	}
}
