<?php
/**
 * Mode setup admin page.
 *
 * @package RelataSoft\SecureElectionSuite\Admin
 */

namespace RelataSoft\SecureElectionSuite\Admin;

use RelataSoft\SecureElectionSuite\Bootstrap\ModeLock;
use RelataSoft\SecureElectionSuite\Security\Capability;
use RelataSoft\SecureElectionSuite\Security\Escaper;
use RelataSoft\SecureElectionSuite\Security\Nonce;
use RelataSoft\SecureElectionSuite\Security\Sanitizer;
use RelataSoft\SecureElectionSuite\I18n\Translator;
use RelataSoft\SecureElectionSuite\Admin\Brand;

defined( 'ABSPATH' ) || exit;

/**
 * Mode selection and destructive reset page.
 */
class ModeSetupPage {

	/**
	 * Register handlers.
	 */
	public static function register(): void {
		add_action( 'admin_post_rses_set_mode', array( self::class, 'rses_handle_set_mode' ) );
		add_action( 'admin_post_rses_destructive_reset', array( self::class, 'rses_handle_destructive_reset' ) );
	}

	/**
	 * Render mode setup page.
	 */
	public static function rses_render(): void {
		Capability::rses_require_admin();

		$rses_locked = ModeLock::rses_is_locked();
		$rses_mode   = ModeLock::rses_get_mode();
		?>
		<div class="wrap rses-wrap rses-screen" <?php echo Translator::rses_html_attrs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<header class="rses-hero rses-hero--brand">
				<?php Brand::rses_render_hero_brand(); ?>
				<p class="rses-hero-kicker"><?php esc_html_e( 'Setup', 'relatasoft-secure-election-suite' ); ?></p>
				<h1 class="rses-hero-title"><?php esc_html_e( 'Mode Setup', 'relatasoft-secure-election-suite' ); ?></h1>
				<p class="rses-hero-lead"><?php esc_html_e( 'Select exactly one mode for this WordPress installation. Once selected, the mode is locked.', 'relatasoft-secure-election-suite' ); ?></p>
			</header>

			<div class="rses-panel rses-panel-warning">
				<p><?php esc_html_e( 'Select exactly one mode for this WordPress installation. Once selected, the mode is locked. Switching requires a destructive reset that removes all election data.', 'relatasoft-secure-election-suite' ); ?></p>
			</div>

			<?php if ( $rses_locked && $rses_mode ) : ?>
				<p>
					<strong><?php esc_html_e( 'Current Mode:', 'relatasoft-secure-election-suite' ); ?></strong>
					<?php echo esc_html( ModeLock::rses_get_mode_label( $rses_mode ) ); ?>
					<strong><?php esc_html_e( '(Locked)', 'relatasoft-secure-election-suite' ); ?></strong>
				</p>

				<h2><?php esc_html_e( 'Destructive Reset', 'relatasoft-secure-election-suite' ); ?></h2>
				<p><?php esc_html_e( 'To change modes, you must perform a destructive reset. This removes all keys, shares, elections, votes, tally imports, certifications, and audit logs.', 'relatasoft-secure-election-suite' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'This will permanently delete ALL election data. Are you sure?', 'relatasoft-secure-election-suite' ) ); ?>');">
					<?php Nonce::rses_field( Nonce::RSES_ACTION_DESTRUCTIVE_RESET ); ?>
					<input type="hidden" name="action" value="rses_destructive_reset" />
					<input type="hidden" name="rses_confirm_reset" value="1" />
					<?php submit_button( __( 'Destructive Reset', 'relatasoft-secure-election-suite' ), 'delete' ); ?>
				</form>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php Nonce::rses_field( Nonce::RSES_ACTION_MODE_SET ); ?>
					<input type="hidden" name="action" value="rses_set_mode" />

					<table class="form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'Select Mode', 'relatasoft-secure-election-suite' ); ?></th>
							<td>
								<?php foreach ( ModeLock::rses_get_valid_modes() as $rses_slug => $rses_label ) : ?>
									<label>
										<input type="radio" name="rses_mode" value="<?php echo esc_attr( $rses_slug ); ?>" required />
										<?php echo esc_html( ModeLock::rses_get_mode_label( $rses_slug ) ); ?>
									</label><br />
								<?php endforeach; ?>
							</td>
						</tr>
					</table>

					<?php submit_button( __( 'Lock Mode', 'relatasoft-secure-election-suite' ) ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handle mode set.
	 */
	public static function rses_handle_set_mode(): void {
		Capability::rses_require_admin();
		Nonce::rses_verify_or_die( Nonce::RSES_ACTION_MODE_SET );

		$rses_mode = Sanitizer::rses_mode( $_POST['rses_mode'] ?? '' );

		if ( empty( $rses_mode ) ) {
			wp_die( esc_html__( 'Invalid mode selected.', 'relatasoft-secure-election-suite' ) );
		}

		if ( ! ModeLock::rses_set_mode( $rses_mode ) ) {
			wp_die( esc_html__( 'Failed to set mode.', 'relatasoft-secure-election-suite' ) );
		}

		$rses_landing = array(
			ModeLock::RSES_MODE_KEY_AUTHORITY => 'rses-key-authority',
			ModeLock::RSES_MODE_VOTING        => 'rses-elections',
			ModeLock::RSES_MODE_TALLYING      => 'rses-tally-import',
		);

		$rses_page = $rses_landing[ $rses_mode ] ?? 'rses-dashboard';

		wp_safe_redirect(
			admin_url(
				'admin.php?page=' . rawurlencode( $rses_page ) . '&rses_mode_set=1'
			)
		);
		exit;
	}

	/**
	 * Handle destructive reset.
	 */
	public static function rses_handle_destructive_reset(): void {
		Capability::rses_require_admin();
		Nonce::rses_verify_or_die( Nonce::RSES_ACTION_DESTRUCTIVE_RESET );

		if ( empty( $_POST['rses_confirm_reset'] ) ) {
			wp_die( esc_html__( 'Reset confirmation required.', 'relatasoft-secure-election-suite' ) );
		}

		if ( ! ModeLock::rses_destructive_reset() ) {
			wp_die( esc_html__( 'Destructive reset failed.', 'relatasoft-secure-election-suite' ) );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=rses-mode-setup&rses_reset=1' ) );
		exit;
	}
}
