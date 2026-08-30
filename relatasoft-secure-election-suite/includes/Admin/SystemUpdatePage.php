<?php
/**
 * Platform system update (core) — electoral naming, VE chrome.
 *
 * @package RelataSoft\SecureElectionSuite\Admin
 */

namespace RelataSoft\SecureElectionSuite\Admin;

use RelataSoft\SecureElectionSuite\I18n\Translator;
use RelataSoft\SecureElectionSuite\Security\Capability;
use RelataSoft\SecureElectionSuite\Security\Nonce;

defined( 'ABSPATH' ) || exit;

/**
 * Atualizar o Sistema (núcleo da plataforma).
 */
class SystemUpdatePage {

	public static function register(): void {
		add_action( 'admin_post_ve_system_check_updates', array( self::class, 'handle_check' ) );
		add_action( 'admin_post_ve_system_upgrade_core', array( self::class, 'handle_upgrade' ) );
	}

	public static function render(): void {
		Capability::rses_require_admin();
		wp_enqueue_style( 've-painel-system' );
		require_once ABSPATH . 'wp-admin/includes/update.php';

		$current = get_bloginfo( 'version' );
		$updates = function_exists( 'get_core_updates' ) ? get_core_updates() : array();
		$latest  = is_array( $updates ) && isset( $updates[0] ) ? $updates[0] : null;
		$notice  = isset( $_GET['ve_notice'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['ve_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap rses-wrap rses-screen ve-system-page" <?php echo Translator::rses_html_attrs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<header class="ve-system-hero">
				<p class="ve-system-kicker"><?php esc_html_e( 'Gestão da plataforma', 'relatasoft-secure-election-suite' ); ?></p>
				<h1><?php esc_html_e( 'Atualizar o Sistema', 'relatasoft-secure-election-suite' ); ?></h1>
				<p class="ve-system-lead">
					<?php esc_html_e( 'Atualize o núcleo da plataforma eleitoral com segurança. As funcionalidades técnicas são preservadas sob nomes democráticos e gerenciais.', 'relatasoft-secure-election-suite' ); ?>
				</p>
			</header>

			<?php if ( $notice ) : ?>
				<div class="ve-system-notice"><?php echo esc_html( $notice ); ?></div>
			<?php endif; ?>

			<section class="ve-system-card">
				<h2><?php esc_html_e( 'Núcleo da plataforma', 'relatasoft-secure-election-suite' ); ?></h2>
				<p>
					<strong><?php esc_html_e( 'Versão instalada:', 'relatasoft-secure-election-suite' ); ?></strong>
					<?php echo esc_html( $current ); ?>
				</p>
				<?php if ( $latest && ! empty( $latest->response ) && 'latest' !== $latest->response ) : ?>
					<p>
						<strong><?php esc_html_e( 'Atualização disponível:', 'relatasoft-secure-election-suite' ); ?></strong>
						<?php echo esc_html( (string) ( $latest->version ?? '' ) ); ?>
					</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="ve_system_upgrade_core" />
						<?php Nonce::rses_field( 've_system_upgrade_core' ); ?>
						<button type="submit" class="ve-btn ve-btn-primary"><?php esc_html_e( 'Aplicar atualização do núcleo', 'relatasoft-secure-election-suite' ); ?></button>
					</form>
				<?php else : ?>
					<p class="ve-system-ok"><?php esc_html_e( 'O núcleo está atualizado.', 'relatasoft-secure-election-suite' ); ?></p>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ve-system-inline-form">
					<input type="hidden" name="action" value="ve_system_check_updates" />
					<?php Nonce::rses_field( 've_system_check_updates' ); ?>
					<button type="submit" class="ve-btn ve-btn-ghost"><?php esc_html_e( 'Verificar atualizações', 'relatasoft-secure-election-suite' ); ?></button>
				</form>
			</section>
		</div>
		<?php
	}

	public static function handle_check(): void {
		Capability::rses_require_admin();
		Nonce::rses_verify_or_die( 've_system_check_updates' );
		require_once ABSPATH . 'wp-admin/includes/update.php';
		wp_version_check( array(), true );
		wp_safe_redirect( admin_url( 'admin.php?page=rses-system-update&ve_notice=' . rawurlencode( 'Verificação concluída.' ) ) );
		exit;
	}

	public static function handle_upgrade(): void {
		Capability::rses_require_admin();
		Nonce::rses_verify_or_die( 've_system_upgrade_core' );
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		$updates = get_core_updates();
		$offer   = ( is_array( $updates ) && isset( $updates[0] ) && is_object( $updates[0] ) ) ? $updates[0] : null;
		if ( ! $offer || empty( $offer->response ) || 'latest' === $offer->response ) {
			wp_safe_redirect( admin_url( 'admin.php?page=rses-system-update&ve_notice=' . rawurlencode( 'Nenhuma atualização do núcleo disponível.' ) ) );
			exit;
		}
		$skin     = new \Automatic_Upgrader_Skin();
		$upgrader = new \Core_Upgrader( $skin );
		$result   = $upgrader->upgrade( $offer );
		$msg      = is_wp_error( $result )
			? $result->get_error_message()
			: 'Núcleo atualizado com sucesso.';
		wp_safe_redirect( admin_url( 'admin.php?page=rses-system-update&ve_notice=' . rawurlencode( $msg ) ) );
		exit;
	}
}
