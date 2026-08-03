<?php
/**
 * RelataSoft admin branding (never used on voting booth).
 *
 * @package RelataSoft\SecureElectionSuite\Admin
 */

namespace RelataSoft\SecureElectionSuite\Admin;

use RelataSoft\SecureElectionSuite\Frontend\JourneySettings;

defined( 'ABSPATH' ) || exit;

/**
 * Brand chrome for administrative RelataSoft screens only.
 */
class Brand {

	/**
	 * Default full lockup asset (official PNG — not AI-regenerated).
	 */
	public const RSES_DEFAULT_LOCKUP = 'relatasoft-logo-lockup-dark.png';

	/**
	 * Compact pinwheel mark (login fallback / solo decoration).
	 */
	public const RSES_DEFAULT_MARK = 'relatasoft-mark.svg';

	/**
	 * URL to a brand asset.
	 *
	 * @param string $filename Asset filename under assets/brand/.
	 */
	public static function rses_asset_url( string $filename ): string {
		return RSES_PLUGIN_URL . 'assets/brand/' . ltrim( $filename, '/' );
	}

	/**
	 * Admin hero logo URL (custom Media Library attachment or default lockup).
	 */
	public static function rses_get_admin_logo_url(): string {
		$rses_id = absint( JourneySettings::rses_get()['admin_logo_attachment_id'] ?? 0 );
		if ( $rses_id > 0 ) {
			$rses_url = wp_get_attachment_image_url( $rses_id, 'full' );
			if ( is_string( $rses_url ) && '' !== $rses_url ) {
				return $rses_url;
			}
		}

		return self::rses_asset_url( self::RSES_DEFAULT_LOCKUP );
	}

	/**
	 * Render admin brand lockup for dark heroes.
	 *
	 * Single image only (no regenerated mark + HTML text). Aspect ratio preserved.
	 *
	 * @param string $context Optional context class suffix.
	 */
	public static function rses_render_hero_brand( string $context = '' ): void {
		$rses_class = 'rses-brand-lockup';
		if ( '' !== $context ) {
			$rses_class .= ' rses-brand-lockup--' . sanitize_html_class( $context );
		}
		?>
		<div class="<?php echo esc_attr( $rses_class ); ?>">
			<img
				class="rses-brand-lockup-img"
				src="<?php echo esc_url( self::rses_get_admin_logo_url() ); ?>"
				alt="RelataSoft — Participação mais Inteligente"
				decoding="async"
			/>
		</div>
		<?php
	}

	/**
	 * Compact mark-only decoration (panels / footers).
	 */
	public static function rses_render_mark( int $size = 28 ): void {
		?>
		<img
			class="rses-brand-mark rses-brand-mark--solo"
			src="<?php echo esc_url( self::rses_asset_url( self::RSES_DEFAULT_MARK ) ); ?>"
			alt="RelataSoft"
			width="<?php echo esc_attr( (string) $size ); ?>"
			height="<?php echo esc_attr( (string) $size ); ?>"
			decoding="async"
		/>
		<?php
	}
}
