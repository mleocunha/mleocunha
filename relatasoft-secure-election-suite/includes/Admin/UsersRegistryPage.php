<?php
/**
 * Cadastro de usuários do Painel — escopo por modo de operação.
 *
 * @package RelataSoft\SecureElectionSuite\Admin
 */

namespace RelataSoft\SecureElectionSuite\Admin;

use RelataSoft\SecureElectionSuite\Bootstrap\ModeLock;
use RelataSoft\SecureElectionSuite\I18n\Translator;
use RelataSoft\SecureElectionSuite\Security\Capability;
use RelataSoft\SecureElectionSuite\Security\Nonce;

defined( 'ABSPATH' ) || exit;

/**
 * Lista e cria contas adequadas a cada modo (chaves / votação / apuração).
 */
class UsersRegistryPage {

	public const SLUG = 'rses-users-registry';

	public static function register(): void {
		add_action( 'admin_post_rses_create_registry_user', array( self::class, 'handle_create' ) );
	}

	public static function render(): void {
		Capability::rses_require_admin();
		if ( ! ModeLock::rses_has_mode() ) {
			echo '<div class="wrap rses-wrap"><p>' . esc_html__( 'Define o modo de operação antes de gerir o cadastro de usuários.', 'relatasoft-secure-election-suite' ) . '</p></div>';
			return;
		}

		$mode    = ModeLock::rses_get_mode();
		$grouped = UserRegistryService::grouped_users( $mode );
		$labels  = UserRegistryService::role_labels();
		$create  = UserRegistryService::creatable_roles( $mode );
		$notice  = isset( $_GET['ve_notice'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['ve_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$mode_blurb = match ( $mode ) {
			ModeLock::RSES_MODE_KEY_AUTHORITY => __( 'Neste modo (Autoridade-chave) constam Administrador Eleitoral e Autoridades Eleitorais.', 'relatasoft-secure-election-suite' ),
			ModeLock::RSES_MODE_VOTING => __( 'Neste modo (Plataforma de votação) constam Administrador Eleitoral, Autoridades Eleitorais e Eleitores.', 'relatasoft-secure-election-suite' ),
			ModeLock::RSES_MODE_TALLYING => __( 'Neste modo (Apuração / certificação) constam Administrador Eleitoral e Autoridades Eleitorais.', 'relatasoft-secure-election-suite' ),
			default => __( 'Cadastro de usuários do Painel.', 'relatasoft-secure-election-suite' ),
		};
		?>
		<div class="wrap rses-wrap rses-screen" <?php echo Translator::rses_html_attrs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<header class="rses-hero rses-hero--brand">
				<?php Brand::rses_render_hero_brand(); ?>
				<p class="rses-hero-kicker"><?php esc_html_e( 'Acessos do sistema', 'relatasoft-secure-election-suite' ); ?></p>
				<h1 class="rses-hero-title"><?php esc_html_e( 'Cadastro de Usuários', 'relatasoft-secure-election-suite' ); ?></h1>
				<p class="rses-hero-lead"><?php echo esc_html( $mode_blurb ); ?></p>
			</header>

			<?php if ( $notice ) : ?>
				<div class="rses-panel rses-panel-info"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<section class="rses-panel rses-panel-card">
				<header class="rses-panel-header">
					<p class="rses-panel-kicker"><?php esc_html_e( 'Novo acesso', 'relatasoft-secure-election-suite' ); ?></p>
					<h2 class="rses-panel-title"><?php esc_html_e( 'Cadastrar usuário', 'relatasoft-secure-election-suite' ); ?></h2>
					<p class="rses-panel-desc"><?php esc_html_e( 'Cria uma conta WordPress com o papel adequado a este modo.', 'relatasoft-secure-election-suite' ); ?></p>
				</header>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rses-form">
					<input type="hidden" name="action" value="rses_create_registry_user" />
					<?php Nonce::rses_field( 'rses_create_registry_user' ); ?>
					<div class="rses-field-grid">
						<div class="rses-field">
							<label class="rses-field-label" for="rses_reg_login"><?php esc_html_e( 'Login', 'relatasoft-secure-election-suite' ); ?></label>
							<input type="text" name="user_login" id="rses_reg_login" class="regular-text" required autocomplete="off" />
						</div>
						<div class="rses-field">
							<label class="rses-field-label" for="rses_reg_email"><?php esc_html_e( 'E-mail', 'relatasoft-secure-election-suite' ); ?></label>
							<input type="email" name="user_email" id="rses_reg_email" class="regular-text" required autocomplete="off" />
						</div>
						<div class="rses-field">
							<label class="rses-field-label" for="rses_reg_name"><?php esc_html_e( 'Nome de exibição', 'relatasoft-secure-election-suite' ); ?></label>
							<input type="text" name="display_name" id="rses_reg_name" class="regular-text" />
						</div>
						<div class="rses-field">
							<label class="rses-field-label" for="rses_reg_pass"><?php esc_html_e( 'Senha', 'relatasoft-secure-election-suite' ); ?></label>
							<input type="password" name="user_pass" id="rses_reg_pass" class="regular-text" required autocomplete="new-password" minlength="8" />
						</div>
						<div class="rses-field">
							<label class="rses-field-label" for="rses_reg_role"><?php esc_html_e( 'Papel', 'relatasoft-secure-election-suite' ); ?></label>
							<select name="user_role" id="rses_reg_role" required>
								<?php foreach ( $create as $role ) : ?>
									<option value="<?php echo esc_attr( $role ); ?>"><?php echo esc_html( $labels[ $role ] ?? $role ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>
					<p class="rses-form-actions">
						<button type="submit" class="button button-primary rses-btn-primary"><?php esc_html_e( 'Cadastrar', 'relatasoft-secure-election-suite' ); ?></button>
					</p>
				</form>
			</section>

			<?php foreach ( $grouped as $role => $users ) : ?>
				<section class="rses-panel rses-panel-card">
					<header class="rses-panel-header">
						<p class="rses-panel-kicker"><?php esc_html_e( 'Papel', 'relatasoft-secure-election-suite' ); ?></p>
						<h2 class="rses-panel-title">
							<?php echo esc_html( $labels[ $role ] ?? $role ); ?>
							<small>(<?php echo esc_html( (string) count( $users ) ); ?>)</small>
						</h2>
					</header>
					<?php if ( empty( $users ) ) : ?>
						<p><?php esc_html_e( 'Nenhuma conta com este papel.', 'relatasoft-secure-election-suite' ); ?></p>
					<?php else : ?>
						<div class="rses-table-wrap">
							<table class="rses-table">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Nome', 'relatasoft-secure-election-suite' ); ?></th>
										<th><?php esc_html_e( 'Login', 'relatasoft-secure-election-suite' ); ?></th>
										<th><?php esc_html_e( 'E-mail', 'relatasoft-secure-election-suite' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $users as $user ) : ?>
										<tr>
											<td><?php echo esc_html( $user->display_name ); ?></td>
											<td><code><?php echo esc_html( $user->user_login ); ?></code></td>
											<td><?php echo esc_html( $user->user_email ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				</section>
			<?php endforeach; ?>
		</div>
		<?php
	}

	public static function handle_create(): void {
		Capability::rses_require_admin();
		Nonce::rses_verify_or_die( 'rses_create_registry_user' );
		$login = isset( $_POST['user_login'] ) ? (string) wp_unslash( $_POST['user_login'] ) : '';
		$email = isset( $_POST['user_email'] ) ? (string) wp_unslash( $_POST['user_email'] ) : '';
		$name  = isset( $_POST['display_name'] ) ? (string) wp_unslash( $_POST['display_name'] ) : '';
		$pass  = isset( $_POST['user_pass'] ) ? (string) wp_unslash( $_POST['user_pass'] ) : '';
		$role  = isset( $_POST['user_role'] ) ? sanitize_key( (string) wp_unslash( $_POST['user_role'] ) ) : '';

		$result = UserRegistryService::create_user( $login, $email, $name, $pass, $role );
		if ( is_wp_error( $result ) ) {
			$msg = $result->get_error_message();
		} else {
			$msg = __( 'Usuário cadastrado.', 'relatasoft-secure-election-suite' );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&ve_notice=' . rawurlencode( $msg ) ) );
		exit;
	}
}
