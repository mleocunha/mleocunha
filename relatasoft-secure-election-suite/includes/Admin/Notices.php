<?php
/**
 * Admin notices.
 *
 * @package RelataSoft\SecureElectionSuite\Admin
 */

namespace RelataSoft\SecureElectionSuite\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Displays admin notices for dependency and status issues.
 */
class Notices {

	/**
	 * Register notice hooks.
	 */
	public static function register(): void {
		add_action( 'admin_notices', array( self::class, 'rses_display_notices' ) );
	}

	/**
	 * Display admin notices.
	 */
	public static function rses_display_notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( get_option( 'rses_gmp_missing_notice' ) && ! extension_loaded( 'gmp' ) ) {
			?>
			<div class="notice notice-error">
				<p>
					<strong><?php esc_html_e( 'RelataSoft Secure Election Suite:', 'relatasoft-secure-election-suite' ); ?></strong>
					<?php esc_html_e( 'The GMP extension is required for cryptographic operations. Crypto actions are blocked.', 'relatasoft-secure-election-suite' ); ?>
				</p>
			</div>
			<?php
		}

		if ( isset( $_GET['rses_saved'] ) ) {
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Settings saved.', 'relatasoft-secure-election-suite' ); ?></p>
			</div>
			<?php
		}

		if ( isset( $_GET['rses_mode_set'] ) ) {
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Plugin mode has been locked.', 'relatasoft-secure-election-suite' ); ?></p>
			</div>
			<?php
		}
	}
}
