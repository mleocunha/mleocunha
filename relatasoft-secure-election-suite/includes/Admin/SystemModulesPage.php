<?php
/**
 * System modules (plugins) — electoral naming, VE chrome.
 *
 * @package RelataSoft\SecureElectionSuite\Admin
 */

namespace RelataSoft\SecureElectionSuite\Admin;

use RelataSoft\SecureElectionSuite\I18n\Translator;
use RelataSoft\SecureElectionSuite\Security\Capability;
use RelataSoft\SecureElectionSuite\Security\Nonce;

defined( 'ABSPATH' ) || exit;

/**
 * Módulos do Sistema (extensões da plataforma).
 */
class SystemModulesPage {

	public static function register(): void {
		add_action( 'admin_post_ve_module_toggle', array( self::class, 'handle_toggle' ) );
		add_action( 'admin_post_ve_module_delete', array( self::class, 'handle_delete' ) );
		add_action( 'admin_post_ve_module_upload', array( self::class, 'handle_upload' ) );
		add_action( 'admin_post_ve_module_upgrade', array( self::class, 'handle_upgrade' ) );
	}

	public static function render(): void {
		Capability::rses_require_admin();
		wp_enqueue_style( 've-painel-system' );

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = get_plugins();
		$active  = (array) get_option( 'active_plugins', array() );
		$updates = get_site_transient( 'update_plugins' );
		$notice  = isset( $_GET['ve_notice'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['ve_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap rses-wrap rses-screen ve-system-page" <?php echo Translator::rses_html_attrs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<header class="ve-system-hero">
				<p class="ve-system-kicker"><?php esc_html_e( 'Gestão da plataforma', 'relatasoft-secure-election-suite' ); ?></p>
				<h1><?php esc_html_e( 'Módulos do Sistema', 'relatasoft-secure-election-suite' ); ?></h1>
				<p class="ve-system-lead">
					<?php esc_html_e( 'Instale, ative, atualize ou remova módulos que estendem a plataforma eleitoral.', 'relatasoft-secure-election-suite' ); ?>
				</p>
			</header>

			<?php if ( $notice ) : ?>
				<div class="ve-system-notice"><?php echo esc_html( $notice ); ?></div>
			<?php endif; ?>

			<section class="ve-system-card">
				<h2><?php esc_html_e( 'Instalar ou atualizar módulo (ZIP)', 'relatasoft-secure-election-suite' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Envie um ZIP para instalar um módulo novo ou substituir um já instalado (inclusive esta suíte), sem usar a interface clássica do WordPress.', 'relatasoft-secure-election-suite' ); ?>
				</p>
				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="ve_module_upload" />
					<?php Nonce::rses_field( 've_module_upload' ); ?>
					<input type="file" name="ve_module_zip" accept=".zip,application/zip" required />
					<button type="submit" class="ve-btn ve-btn-primary"><?php esc_html_e( 'Instalar / atualizar', 'relatasoft-secure-election-suite' ); ?></button>
				</form>
			</section>

			<section class="ve-system-card">
				<h2><?php esc_html_e( 'Módulos instalados', 'relatasoft-secure-election-suite' ); ?></h2>
				<div class="ve-system-table-wrap">
					<table class="ve-system-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Módulo', 'relatasoft-secure-election-suite' ); ?></th>
								<th><?php esc_html_e( 'Versão', 'relatasoft-secure-election-suite' ); ?></th>
								<th><?php esc_html_e( 'Estado', 'relatasoft-secure-election-suite' ); ?></th>
								<th><?php esc_html_e( 'Ações', 'relatasoft-secure-election-suite' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $plugins as $file => $data ) : ?>
							<?php
							$is_active  = in_array( $file, $active, true );
							$has_update = is_object( $updates ) && isset( $updates->response[ $file ] );
							$is_self    = ( 0 === strpos( $file, 'relatasoft-secure-election-suite/' ) );
							$name       = (string) ( $data['Name'] ?? $file );
							$version    = (string) ( $data['Version'] ?? '—' );
							$desc       = (string) ( $data['Description'] ?? '' );
							$new_ver    = $has_update ? (string) ( $updates->response[ $file ]->new_version ?? '' ) : '';
							?>
							<tr>
								<td>
									<strong><?php echo esc_html( $name ); ?></strong>
									<?php if ( $desc !== '' ) : ?>
										<div class="ve-system-muted"><?php echo esc_html( wp_trim_words( $desc, 18 ) ); ?></div>
									<?php endif; ?>
								</td>
								<td>
									<?php echo esc_html( $version ); ?>
									<?php if ( $has_update && $new_ver !== '' ) : ?>
										<span class="ve-system-badge ve-system-badge--warn"><?php echo esc_html( '→ ' . $new_ver ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php
									echo $is_active
										? esc_html__( 'Ativo', 'relatasoft-secure-election-suite' )
										: esc_html__( 'Inativo', 'relatasoft-secure-election-suite' );
									?>
								</td>
								<td class="ve-system-actions">
									<?php if ( $is_self ) : ?>
										<span class="ve-system-muted"><?php esc_html_e( 'Núcleo da suíte — protegido', 'relatasoft-secure-election-suite' ); ?></span>
									<?php else : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
											<input type="hidden" name="action" value="ve_module_toggle" />
											<input type="hidden" name="plugin" value="<?php echo esc_attr( $file ); ?>" />
											<input type="hidden" name="do" value="<?php echo esc_attr( $is_active ? 'deactivate' : 'activate' ); ?>" />
											<?php Nonce::rses_field( 've_module_toggle' ); ?>
											<button type="submit" class="ve-btn ve-btn-ghost">
												<?php echo esc_html( $is_active ? __( 'Desativar', 'relatasoft-secure-election-suite' ) : __( 'Ativar', 'relatasoft-secure-election-suite' ) ); ?>
											</button>
										</form>
										<?php if ( $has_update ) : ?>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
												<input type="hidden" name="action" value="ve_module_upgrade" />
												<input type="hidden" name="plugin" value="<?php echo esc_attr( $file ); ?>" />
												<?php Nonce::rses_field( 've_module_upgrade' ); ?>
												<button type="submit" class="ve-btn ve-btn-primary"><?php esc_html_e( 'Atualizar', 'relatasoft-secure-election-suite' ); ?></button>
											</form>
										<?php endif; ?>
										<?php if ( ! $is_active ) : ?>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Remover este módulo permanentemente?');">
												<input type="hidden" name="action" value="ve_module_delete" />
												<input type="hidden" name="plugin" value="<?php echo esc_attr( $file ); ?>" />
												<?php Nonce::rses_field( 've_module_delete' ); ?>
												<button type="submit" class="ve-btn ve-btn-danger"><?php esc_html_e( 'Remover', 'relatasoft-secure-election-suite' ); ?></button>
											</form>
										<?php endif; ?>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</section>
		</div>
		<?php
	}

	public static function handle_toggle(): void {
		Capability::rses_require_admin();
		Nonce::rses_verify_or_die( 've_module_toggle' );
		$file = isset( $_POST['plugin'] ) ? wp_unslash( (string) $_POST['plugin'] ) : '';
		$do   = isset( $_POST['do'] ) ? sanitize_key( (string) $_POST['do'] ) : '';
		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$msg = 'Operação não concluída.';
		if ( 'activate' === $do && $file ) {
			$r   = activate_plugin( $file );
			$msg = is_wp_error( $r ) ? $r->get_error_message() : 'Módulo ativado.';
		} elseif ( 'deactivate' === $do && $file ) {
			deactivate_plugins( $file, true );
			$msg = 'Módulo desativado.';
		}
		wp_safe_redirect( admin_url( 'admin.php?page=rses-system-modules&ve_notice=' . rawurlencode( $msg ) ) );
		exit;
	}

	public static function handle_delete(): void {
		Capability::rses_require_admin();
		Nonce::rses_verify_or_die( 've_module_delete' );
		$file = isset( $_POST['plugin'] ) ? wp_unslash( (string) $_POST['plugin'] ) : '';
		if ( 0 === strpos( $file, 'relatasoft-secure-election-suite/' ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=rses-system-modules&ve_notice=' . rawurlencode( 'Não é possível remover o núcleo da suíte.' ) ) );
			exit;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$r   = delete_plugins( array( $file ) );
		$msg = is_wp_error( $r ) ? $r->get_error_message() : 'Módulo removido.';
		wp_safe_redirect( admin_url( 'admin.php?page=rses-system-modules&ve_notice=' . rawurlencode( $msg ) ) );
		exit;
	}

	public static function handle_upload(): void {
		Capability::rses_require_admin();
		Nonce::rses_verify_or_die( 've_module_upload' );
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		$file = $_FILES['ve_module_zip'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$msg  = 'Falha no envio.';
		if ( is_array( $file ) && ! empty( $file['tmp_name'] ) && is_uploaded_file( (string) $file['tmp_name'] ) ) {
			$skin     = new \Automatic_Upgrader_Skin();
			$upgrader = new \Plugin_Upgrader( $skin );
			// overwrite_package (WP 5.5+): atualiza no lugar se a pasta já existir
			// (sem apagar via CLI nem abrir plugins.php clássico).
			$result = $upgrader->install(
				(string) $file['tmp_name'],
				array( 'overwrite_package' => true )
			);
			if ( is_wp_error( $result ) ) {
				$msg = $result->get_error_message();
			} elseif ( $result ) {
				$msg      = 'Módulo instalado ou atualizado.';
				$basename = 'relatasoft-secure-election-suite/relatasoft-secure-election-suite.php';
				if ( ! is_plugin_active( $basename ) && file_exists( WP_PLUGIN_DIR . '/' . $basename ) ) {
					$reactivate = activate_plugin( $basename );
					if ( is_wp_error( $reactivate ) ) {
						$msg .= ' ' . $reactivate->get_error_message();
					}
				}
			} else {
				$feedback = method_exists( $skin, 'get_upgrade_messages' ) ? $skin->get_upgrade_messages() : array();
				$msg      = $feedback ? implode( ' ', array_map( 'wp_strip_all_tags', (array) $feedback ) ) : 'Instalação não concluída.';
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=rses-system-modules&ve_notice=' . rawurlencode( $msg ) ) );
		exit;
	}

	public static function handle_upgrade(): void {
		Capability::rses_require_admin();
		Nonce::rses_verify_or_die( 've_module_upgrade' );
		$file = isset( $_POST['plugin'] ) ? wp_unslash( (string) $_POST['plugin'] ) : '';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$skin     = new \Automatic_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result   = $upgrader->upgrade( $file );
		$msg      = false !== $result && ! is_wp_error( $result ) ? 'Módulo atualizado.' : 'Falha na atualização do módulo.';
		if ( is_wp_error( $result ) ) {
			$msg = $result->get_error_message();
		}
		wp_safe_redirect( admin_url( 'admin.php?page=rses-system-modules&ve_notice=' . rawurlencode( $msg ) ) );
		exit;
	}
}
