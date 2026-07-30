<?php
/**
 * Plugin settings page.
 *
 * @package RelataSoft\SecureElectionSuite\Admin
 */

namespace RelataSoft\SecureElectionSuite\Admin;

use RelataSoft\SecureElectionSuite\Frontend\JourneySettings;
use RelataSoft\SecureElectionSuite\Security\AuditLogger;
use RelataSoft\SecureElectionSuite\Security\Capability;
use RelataSoft\SecureElectionSuite\Security\Nonce;
use RelataSoft\SecureElectionSuite\I18n\Translator;
use RelataSoft\SecureElectionSuite\Admin\Brand;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings administration.
 */
class SettingsPage {

	/**
	 * Register handlers.
	 */
	public static function register(): void {
		add_action( 'admin_post_rses_save_settings', array( self::class, 'rses_handle_save' ) );
	}

	/**
	 * Render settings page.
	 */
	public static function rses_render(): void {
		Capability::rses_require_admin();

		$rses_settings = JourneySettings::rses_get();
		$rses_allow_full = ! empty( $rses_settings['allow_full_private_export'] );
		?>
		<div class="wrap rses-wrap rses-screen" <?php echo Translator::rses_html_attrs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<header class="rses-hero rses-hero--brand">
				<?php Brand::rses_render_hero_brand(); ?>
				<p class="rses-hero-kicker"><?php esc_html_e( 'Configuration', 'relatasoft-secure-election-suite' ); ?></p>
				<h1 class="rses-hero-title"><?php esc_html_e( 'Election Suite Settings', 'relatasoft-secure-election-suite' ); ?></h1>
				<p class="rses-hero-lead"><?php esc_html_e( 'Tune export and custody options for this locked-mode installation.', 'relatasoft-secure-election-suite' ); ?></p>
			</header>

			<section class="rses-panel rses-panel-card">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rses-form">
				<?php Nonce::rses_field( Nonce::RSES_ACTION_SETTINGS_SAVE ); ?>
				<input type="hidden" name="action" value="rses_save_settings" />

				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Allow Full Private Key Export', 'relatasoft-secure-election-suite' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="rses_allow_full_private_export" value="1" <?php checked( $rses_allow_full ); ?> />
								<?php esc_html_e( 'Enable admin full private key export (disabled by default, requires explicit confirmation)', 'relatasoft-secure-election-suite' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Warning: Exporting full private keys is a significant security risk.', 'relatasoft-secure-election-suite' ); ?></p>
						</td>
					</tr>
				</table>

				<p class="rses-form-actions">
					<?php submit_button( __( 'Save Settings', 'relatasoft-secure-election-suite' ), 'primary rses-btn-primary', 'submit', false ); ?>
				</p>
			</form>
			</section>
		</div>
		<?php
	}

	/**
	 * Handle settings save.
	 */
	public static function rses_handle_save(): void {
		Capability::rses_require_admin();
		Nonce::rses_verify_or_die( Nonce::RSES_ACTION_SETTINGS_SAVE );

		$rses_settings = JourneySettings::rses_get();
		$rses_settings['allow_full_private_export'] = ! empty( $_POST['rses_allow_full_private_export'] );

		update_option( 'rses_settings', $rses_settings );

		AuditLogger::rses_log( 'settings_saved', 'settings', null, $rses_settings );

		wp_safe_redirect( admin_url( 'admin.php?page=rses-settings&rses_saved=1' ) );
		exit;
	}
}
