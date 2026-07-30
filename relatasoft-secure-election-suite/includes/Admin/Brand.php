<?php
/**
 * RelataSoft admin branding (never used on voting booth).
 *
 * @package RelataSoft\SecureElectionSuite\Admin
 */

namespace RelataSoft\SecureElectionSuite\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Brand chrome for administrative RelataSoft screens only.
 */
class Brand {

	/**
	 * URL to a brand asset.
	 *
	 * @param string $filename Asset filename under assets/brand/.
	 */
	public static function rses_asset_url( string $filename ): string {
		return RSES_PLUGIN_URL . 'assets/brand/' . ltrim( $filename, '/' );
	}

	/**
	 * Render admin brand lockup for dark heroes.
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
				class="rses-brand-mark"
				src="<?php echo esc_url( self::rses_asset_url( 'relatasoft-mark.svg' ) ); ?>"
				alt=""
				width="44"
				height="44"
				decoding="async"
			/>
			<div class="rses-brand-text">
				<p class="rses-brand-name">RelataSoft</p>
				<p class="rses-brand-tagline"><?php echo esc_html__( 'Participação mais Inteligente', 'relatasoft-secure-election-suite' ); ?></p>
			</div>
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
			src="<?php echo esc_url( self::rses_asset_url( 'relatasoft-mark.svg' ) ); ?>"
			alt="RelataSoft"
			width="<?php echo esc_attr( (string) $size ); ?>"
			height="<?php echo esc_attr( (string) $size ); ?>"
			decoding="async"
		/>
		<?php
	}
}
