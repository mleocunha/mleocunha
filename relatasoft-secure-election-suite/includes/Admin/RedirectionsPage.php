<?php
/**
 * Admin page for elector journey redirections.
 *
 * @package RelataSoft\SecureElectionSuite\Admin
 */

namespace RelataSoft\SecureElectionSuite\Admin;

use RelataSoft\SecureElectionSuite\Bootstrap\ModeLock;
use RelataSoft\SecureElectionSuite\Frontend\JourneySettings;
use RelataSoft\SecureElectionSuite\Frontend\VoterJourney;
use RelataSoft\SecureElectionSuite\I18n\RoleLabels;
use RelataSoft\SecureElectionSuite\I18n\Translator;
use RelataSoft\SecureElectionSuite\Security\AuditLogger;
use RelataSoft\SecureElectionSuite\Security\Capability;
use RelataSoft\SecureElectionSuite\Security\Nonce;

defined( 'ABSPATH' ) || exit;

/**
 * Configure login branding and elector page flow.
 */
class RedirectionsPage {

	/**
	 * Register handlers.
	 */
	public static function register(): void {
		add_action( 'admin_post_rses_save_redirections', array( self::class, 'rses_handle_save' ) );
		add_action( 'admin_post_rses_provision_journey_pages', array( self::class, 'rses_handle_provision' ) );
	}

	/**
	 * Render admin page.
	 */
	public static function rses_render(): void {
		Capability::rses_require_admin();
		ModeLock::rses_require_mode( ModeLock::RSES_MODE_VOTING );

		$rses_settings = JourneySettings::rses_get();
		$rses_pages    = get_pages( array( 'sort_column' => 'post_title' ) );
		$rses_logo_id  = absint( $rses_settings['login_logo_attachment_id'] ?? 0 );
		$rses_logo_url = $rses_logo_id > 0 ? wp_get_attachment_image_url( $rses_logo_id, 'thumbnail' ) : '';
		?>
		<div class="wrap rses-wrap rses-screen" <?php echo Translator::rses_html_attrs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<header class="rses-hero rses-hero--brand">
				<?php Brand::rses_render_hero_brand(); ?>
				<p class="rses-hero-kicker"><?php esc_html_e( 'Voter experience', 'relatasoft-secure-election-suite' ); ?></p>
				<h1 class="rses-hero-title"><?php esc_html_e( 'Redirections', 'relatasoft-secure-election-suite' ); ?></h1>
				<p class="rses-hero-lead"><?php esc_html_e( 'Plan the elector journey: branded sign-in, welcome instructions, voting booth, and thank-you page.', 'relatasoft-secure-election-suite' ); ?></p>
			</header>

			<?php if ( ! empty( $_GET['rses_saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="rses-panel rses-panel-success"><p><?php esc_html_e( 'Redirection settings saved.', 'relatasoft-secure-election-suite' ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! empty( $_GET['rses_provisioned'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="rses-panel rses-panel-success"><p><?php esc_html_e( 'Welcome and thank-you pages are ready. Assign the voting booth page below if needed.', 'relatasoft-secure-election-suite' ); ?></p></div>
			<?php endif; ?>

			<section class="rses-panel rses-panel-card">
				<h2 class="rses-panel-title"><?php esc_html_e( 'Login branding', 'relatasoft-secure-election-suite' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Substitui o logo padrão do login. Sem logo personalizado, usa a Roda de Fogo RelataSoft.', 'relatasoft-secure-election-suite' ); ?></p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rses-form" id="rses-redirections-form">
					<?php Nonce::rses_field( Nonce::RSES_ACTION_REDIRECTIONS_SAVE ); ?>
					<input type="hidden" name="action" value="rses_save_redirections" />

					<table class="form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'Login logo', 'relatasoft-secure-election-suite' ); ?></th>
							<td>
								<div class="rses-login-logo-picker">
									<input type="hidden" name="rses_login_logo_attachment_id" id="rses_login_logo_attachment_id" value="<?php echo esc_attr( (string) $rses_logo_id ); ?>" />
									<div class="rses-login-logo-preview" id="rses_login_logo_preview">
										<?php if ( $rses_logo_url ) : ?>
											<img src="<?php echo esc_url( $rses_logo_url ); ?>" alt="" width="80" height="80" />
										<?php else : ?>
											<img src="<?php echo esc_url( Brand::rses_asset_url( Brand::RSES_DEFAULT_MARK ) ); ?>" alt="" width="80" height="80" />
										<?php endif; ?>
									</div>
									<p>
										<button type="button" class="button" id="rses_pick_login_logo"><?php esc_html_e( 'Choose logo', 'relatasoft-secure-election-suite' ); ?></button>
										<button type="button" class="button" id="rses_clear_login_logo"><?php esc_html_e( 'Usar Roda de Fogo padrão', 'relatasoft-secure-election-suite' ); ?></button>
									</p>
								</div>
							</td>
						</tr>
					</table>

					<h2 class="rses-panel-title"><?php esc_html_e( 'Elector journey', 'relatasoft-secure-election-suite' ); ?></h2>
					<p class="description"><?php esc_html_e( 'After sign-in, electors land on welcome. One click opens the booth. After casting a vote they are sent to thank-you. Native routes do not require host pages or shortcodes.', 'relatasoft-secure-election-suite' ); ?></p>

					<?php
					$rses_native = array(
						'welcome'   => JourneySettings::rses_page_url( 'welcome_page_id' ),
						'booth'     => JourneySettings::rses_page_url( 'booth_page_id' ),
						'thank_you' => JourneySettings::rses_page_url( 'thank_you_page_id' ),
					);
					?>
					<table class="form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'Rotas nativas', 'relatasoft-secure-election-suite' ); ?></th>
							<td>
								<ul class="rses-native-journey-urls" style="margin:0;">
									<li><code><?php echo esc_html( $rses_native['welcome'] ?: '/voto/' ); ?></code> — <?php esc_html_e( 'boas-vindas', 'relatasoft-secure-election-suite' ); ?></li>
									<li><code><?php echo esc_html( $rses_native['booth'] ?: '/voto/cabina/' ); ?></code> — <?php esc_html_e( 'cabina', 'relatasoft-secure-election-suite' ); ?></li>
									<li><code><?php echo esc_html( $rses_native['thank_you'] ?: '/voto/obrigado/' ); ?></code> — <?php esc_html_e( 'obrigado', 'relatasoft-secure-election-suite' ); ?></li>
								</ul>
								<p class="description"><?php esc_html_e( 'Itinerário principal do eleitor (A5). Login, redirects e recibo usam estas URLs.', 'relatasoft-secure-election-suite' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="rses_welcome_page_id"><?php esc_html_e( 'Welcome page (legacy)', 'relatasoft-secure-election-suite' ); ?></label></th>
							<td>
								<?php self::rses_render_page_select( 'rses_welcome_page_id', (int) $rses_settings['welcome_page_id'], $rses_pages ); ?>
								<p class="description"><?php esc_html_e( 'Optional host page with [rses_voter_welcome]. Redirects use the native /voto route.', 'relatasoft-secure-election-suite' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="rses_booth_page_id"><?php esc_html_e( 'Voting booth page (legacy)', 'relatasoft-secure-election-suite' ); ?></label></th>
							<td>
								<?php self::rses_render_page_select( 'rses_booth_page_id', (int) $rses_settings['booth_page_id'], $rses_pages ); ?>
								<p class="description"><?php esc_html_e( 'Optional page with [rses_voting_booth] as a thin adapter. Prefer /voto/cabina/.', 'relatasoft-secure-election-suite' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="rses_thank_you_page_id"><?php esc_html_e( 'Thank-you page (legacy)', 'relatasoft-secure-election-suite' ); ?></label></th>
							<td>
								<?php self::rses_render_page_select( 'rses_thank_you_page_id', (int) $rses_settings['thank_you_page_id'], $rses_pages ); ?>
								<p class="description"><?php
									echo esc_html(
										sprintf(
											/* translators: %s: electoral authority role label (plural) */
											__( 'Optional [rses_voter_thank_you] adapter. Prefer /voto/obrigado/. Still editable by administrators, authors, and %s.', 'relatasoft-secure-election-suite' ),
											RoleLabels::rses_editor_plural()
										)
									);
								?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="rses_logout_redirect_url"><?php esc_html_e( 'Logout redirect URL', 'relatasoft-secure-election-suite' ); ?></label></th>
							<td>
								<input type="url" class="large-text" name="rses_logout_redirect_url" id="rses_logout_redirect_url" value="<?php echo esc_attr( (string) ( $rses_settings['logout_redirect_url'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( JourneySettings::rses_page_url( 'welcome_page_id' ) ?: home_url( '/' ) ); ?>" />
								<p class="description"><?php esc_html_e( 'Optional. Leave empty to return electors to the welcome route.', 'relatasoft-secure-election-suite' ); ?></p>
							</td>
						</tr>
					</table>

					<p class="rses-form-actions">
						<?php submit_button( __( 'Save redirections', 'relatasoft-secure-election-suite' ), 'primary rses-btn-primary', 'submit', false ); ?>
					</p>
				</form>
			</section>

			<section class="rses-panel rses-panel-card">
				<h2 class="rses-panel-title"><?php esc_html_e( 'Create journey pages', 'relatasoft-secure-election-suite' ); ?></h2>
				<p><?php esc_html_e( 'Optional legacy: creates host pages with shortcode adapters. Prefer the native /voto routes above. For the password-change PoC, add [enviar_redefinicao_senha] where needed.', 'relatasoft-secure-election-suite' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php Nonce::rses_field( Nonce::RSES_ACTION_JOURNEY_PROVISION ); ?>
					<input type="hidden" name="action" value="rses_provision_journey_pages" />
					<?php submit_button( __( 'Create welcome, booth & thank-you pages', 'relatasoft-secure-election-suite' ), 'secondary', 'submit', false ); ?>
				</form>
			</section>
		</div>
		<?php
		wp_enqueue_media();
	}

	/**
	 * Page dropdown helper.
	 *
	 * @param string            $rses_name    Field name.
	 * @param int               $rses_current Selected ID.
	 * @param array<int,\WP_Post> $rses_pages Page list.
	 */
	private static function rses_render_page_select( string $rses_name, int $rses_current, array $rses_pages ): void {
		?>
		<select name="<?php echo esc_attr( $rses_name ); ?>" id="<?php echo esc_attr( $rses_name ); ?>">
			<option value="0"><?php esc_html_e( '— Select —', 'relatasoft-secure-election-suite' ); ?></option>
			<?php foreach ( $rses_pages as $rses_page ) : ?>
				<option value="<?php echo esc_attr( (string) $rses_page->ID ); ?>" <?php selected( $rses_current, (int) $rses_page->ID ); ?>>
					<?php echo esc_html( $rses_page->post_title ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Save redirection settings.
	 */
	public static function rses_handle_save(): void {
		Capability::rses_require_admin();
		Nonce::rses_verify_or_die( Nonce::RSES_ACTION_REDIRECTIONS_SAVE );
		ModeLock::rses_require_mode( ModeLock::RSES_MODE_VOTING );

		$rses_partial = array(
			'login_logo_attachment_id' => absint( $_POST['rses_login_logo_attachment_id'] ?? 0 ),
			'welcome_page_id'          => absint( $_POST['rses_welcome_page_id'] ?? 0 ),
			'booth_page_id'            => absint( $_POST['rses_booth_page_id'] ?? 0 ),
			'thank_you_page_id'        => absint( $_POST['rses_thank_you_page_id'] ?? 0 ),
			'logout_redirect_url'      => esc_url_raw( wp_unslash( $_POST['rses_logout_redirect_url'] ?? '' ) ),
		);

		JourneySettings::rses_save( $rses_partial );
		AuditLogger::rses_log( 'redirections_saved', 'settings', null, $rses_partial );

		wp_safe_redirect( admin_url( 'admin.php?page=rses-redirections&rses_saved=1' ) );
		exit;
	}

	/**
	 * Provision welcome, booth, and thank-you pages.
	 */
	public static function rses_handle_provision(): void {
		Capability::rses_require_admin();
		Nonce::rses_verify_or_die( Nonce::RSES_ACTION_JOURNEY_PROVISION );
		ModeLock::rses_require_mode( ModeLock::RSES_MODE_VOTING );

		VoterJourney::rses_provision_pages();
		AuditLogger::rses_log( 'journey_pages_provisioned', 'settings', null );

		wp_safe_redirect( admin_url( 'admin.php?page=rses-redirections&rses_provisioned=1' ) );
		exit;
	}
}
