<?php
/**
 * Plugin settings page.
 *
 * @package RelataSoft\SecureElectionSuite\Admin
 */

namespace RelataSoft\SecureElectionSuite\Admin;

use RelataSoft\SecureElectionSuite\Frontend\JourneySettings;
use RelataSoft\SecureElectionSuite\Painel\Domain\Settings\SettingsSchema;
use RelataSoft\SecureElectionSuite\Security\AuditLogger;
use RelataSoft\SecureElectionSuite\Security\Capability;
use RelataSoft\SecureElectionSuite\Security\Nonce;
use RelataSoft\SecureElectionSuite\I18n\Translator;

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

		$rses_settings   = JourneySettings::rses_get();
		$rses_allow_full = ! empty( $rses_settings['allow_full_private_export'] );
		$rses_logo_id    = absint( $rses_settings['admin_logo_attachment_id'] ?? 0 );
		$rses_logo_url   = $rses_logo_id > 0 ? wp_get_attachment_image_url( $rses_logo_id, 'medium' ) : '';
		$rses_default    = Brand::rses_asset_url( Brand::RSES_DEFAULT_LOCKUP );
		// E1: prefer rses_settings; fall back to Painel schema (ve_painel_settings) for future orchestrator.
		$painel          = get_option( SettingsSchema::OPTION_KEY, array() );
		$painel          = is_array( $painel ) ? $painel : array();
		$rses_cliente_id = (string) ( $rses_settings['cliente_id'] ?? $painel['cliente_id'] ?? '' );
		$rses_cliente_nome = (string) ( $rses_settings['cliente_nome'] ?? $painel['cliente_nome'] ?? '' );
		?>
		<div class="wrap rses-wrap rses-screen" <?php echo Translator::rses_html_attrs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<header class="rses-hero rses-hero--brand">
				<?php Brand::rses_render_hero_brand(); ?>
				<p class="rses-hero-kicker"><?php esc_html_e( 'Configuration', 'relatasoft-secure-election-suite' ); ?></p>
				<h1 class="rses-hero-title"><?php esc_html_e( 'Election Suite Settings', 'relatasoft-secure-election-suite' ); ?></h1>
				<p class="rses-hero-lead"><?php esc_html_e( 'Tune branding, export, and custody options for this locked-mode installation.', 'relatasoft-secure-election-suite' ); ?></p>
			</header>

			<section class="rses-panel rses-panel-card">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rses-form" id="rses-settings-form">
				<?php Nonce::rses_field( Nonce::RSES_ACTION_SETTINGS_SAVE ); ?>
				<input type="hidden" name="action" value="rses_save_settings" />

				<h2 class="rses-panel-title"><?php esc_html_e( 'Cliente (E1)', 'relatasoft-secure-election-suite' ); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="rses_cliente_id"><?php esc_html_e( 'ID do cliente', 'relatasoft-secure-election-suite' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" name="rses_cliente_id" id="rses_cliente_id" value="<?php echo esc_attr( $rses_cliente_id ); ?>" autocomplete="off" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rses_cliente_nome"><?php esc_html_e( 'Nome do cliente', 'relatasoft-secure-election-suite' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" name="rses_cliente_nome" id="rses_cliente_nome" value="<?php echo esc_attr( $rses_cliente_nome ); ?>" autocomplete="organization" />
						</td>
					</tr>
				</table>

				<h2 class="rses-panel-title"><?php esc_html_e( 'Admin branding', 'relatasoft-secure-election-suite' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Logo shown in the top-left of Election Suite admin heroes. When unset, the RelataSoft lockup is used. Aspect ratio is always preserved.', 'relatasoft-secure-election-suite' ); ?></p>

				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Admin logo', 'relatasoft-secure-election-suite' ); ?></th>
						<td>
							<div class="rses-admin-logo-picker">
								<input type="hidden" name="rses_admin_logo_attachment_id" id="rses_admin_logo_attachment_id" value="<?php echo esc_attr( (string) $rses_logo_id ); ?>" />
								<div class="rses-admin-logo-preview" id="rses_admin_logo_preview">
									<img
										src="<?php echo esc_url( $rses_logo_url ? $rses_logo_url : $rses_default ); ?>"
										alt=""
										class="rses-admin-logo-preview-img"
										data-rses-default-src="<?php echo esc_url( $rses_default ); ?>"
									/>
								</div>
								<p>
									<button type="button" class="button" id="rses_pick_admin_logo"><?php esc_html_e( 'Choose logo', 'relatasoft-secure-election-suite' ); ?></button>
									<button type="button" class="button" id="rses_clear_admin_logo"><?php esc_html_e( 'Use default RelataSoft logo', 'relatasoft-secure-election-suite' ); ?></button>
								</p>
							</div>
						</td>
					</tr>
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
		$rses_settings['admin_logo_attachment_id']  = absint( $_POST['rses_admin_logo_attachment_id'] ?? 0 );
		$rses_settings['cliente_id']                = sanitize_text_field( wp_unslash( (string) ( $_POST['rses_cliente_id'] ?? '' ) ) );
		$rses_settings['cliente_nome']              = sanitize_text_field( wp_unslash( (string) ( $_POST['rses_cliente_nome'] ?? '' ) ) );

		// get_option/update_option: persist runtime settings for this WP install.
		update_option( 'rses_settings', $rses_settings );

		// Mirror E1 into Painel schema so a future orchestrator can read one canonical key.
		$painel = get_option( SettingsSchema::OPTION_KEY, array() );
		$painel = is_array( $painel ) ? $painel : array();
		$painel = array_merge( SettingsSchema::defaults(), $painel );
		$painel['cliente_id']   = $rses_settings['cliente_id'];
		$painel['cliente_nome'] = $rses_settings['cliente_nome'];
		update_option( SettingsSchema::OPTION_KEY, $painel, false );

		AuditLogger::rses_log( 'settings_saved', 'settings', null, $rses_settings );

		wp_safe_redirect( admin_url( 'admin.php?page=rses-settings&rses_saved=1' ) );
		exit;
	}
}
