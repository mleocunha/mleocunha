<?php
/**
 * Admin UI: export (key_authority) / import (voting, tallying) electoral authorities.
 *
 * @package RelataSoft\SecureElectionSuite\Admin
 */

namespace RelataSoft\SecureElectionSuite\Admin;

use RelataSoft\SecureElectionSuite\Bootstrap\ModeLock;
use RelataSoft\SecureElectionSuite\I18n\RoleLabels;
use RelataSoft\SecureElectionSuite\I18n\Translator;
use RelataSoft\SecureElectionSuite\Security\Capability;
use RelataSoft\SecureElectionSuite\Security\Nonce;

defined( 'ABSPATH' ) || exit;

/**
 * Página de transferência de autoridades eleitorais.
 */
class ElectoralAuthoritiesPage {

	public const SLUG = 'rses-electoral-authorities';

	public static function register(): void {
		add_action( 'admin_post_rses_export_electoral_authorities', array( self::class, 'handle_export' ) );
		add_action( 'admin_post_rses_import_electoral_authorities', array( self::class, 'handle_import' ) );
	}

	public static function render(): void {
		Capability::rses_require_admin();
		$mode = ModeLock::rses_get_mode();

		if ( ModeLock::RSES_MODE_KEY_AUTHORITY === $mode ) {
			self::render_export();
			return;
		}
		if ( in_array( $mode, array( ModeLock::RSES_MODE_VOTING, ModeLock::RSES_MODE_TALLYING ), true ) ) {
			self::render_import();
			return;
		}

		echo '<div class="wrap rses-wrap"><p>' . esc_html__( 'Esta ferramenta não está disponível neste modo de operação.', 'relatasoft-secure-election-suite' ) . '</p></div>';
	}

	private static function render_export(): void {
		$users = \RelataSoft\SecureElectionSuite\Painel\Application\Identity\IdentityGateway::get()->users->listByRole(
			Capability::RSES_OFFICIAL_ROLE
		);
		$notice = isset( $_GET['ve_notice'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['ve_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap rses-wrap rses-screen" <?php echo Translator::rses_html_attrs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<header class="rses-hero rses-hero--brand">
				<?php Brand::rses_render_hero_brand(); ?>
				<p class="rses-hero-kicker"><?php esc_html_e( 'Gestão de autoridades', 'relatasoft-secure-election-suite' ); ?></p>
				<h1 class="rses-hero-title">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: electoral authorities plural label */
							__( 'Exportar %s', 'relatasoft-secure-election-suite' ),
							RoleLabels::rses_editor_plural()
						)
					);
					?>
				</h1>
				<p class="rses-hero-lead">
					<?php esc_html_e( 'Gerar um pacote JSON para provisionar as mesmas autoridades na Plataforma de votação e na Plataforma de apuração e certificação. O formato de exportação é exatamente o de importação.', 'relatasoft-secure-election-suite' ); ?>
				</p>
			</header>

			<?php if ( $notice ) : ?>
				<div class="rses-panel rses-panel-info"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<section class="rses-panel rses-panel-card">
				<header class="rses-panel-header">
					<p class="rses-panel-kicker"><?php esc_html_e( 'Pacote', 'relatasoft-secure-election-suite' ); ?></p>
					<h2 class="rses-panel-title"><?php esc_html_e( 'Autoridades neste site', 'relatasoft-secure-election-suite' ); ?></h2>
					<p class="rses-panel-desc">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: count, 2: role plural */
								__( '%1$d conta(s) com o papel %2$s serão incluídas (login, e-mail, nome e hash de senha portável; metadados de parcela quando existirem). Parcelas Shamir secretas não entram no pacote.', 'relatasoft-secure-election-suite' ),
								count( $users ),
								RoleLabels::rses_editor_plural()
							)
						);
						?>
					</p>
				</header>

				<?php if ( empty( $users ) ) : ?>
					<p><?php
						echo esc_html(
							sprintf(
								/* translators: %s: singular role */
								__( 'Nenhuma conta %s encontrada. Criar usuários com esse papel antes de exportar.', 'relatasoft-secure-election-suite' ),
								RoleLabels::rses_editor_singular()
							)
						);
					?></p>
				<?php else : ?>
					<ul class="rses-plain-list">
						<?php foreach ( $users as $user ) : ?>
							<li><strong><?php echo esc_html( (string) ( $user['displayName'] ?? '' ) ); ?></strong>
								— <code><?php echo esc_html( (string) ( $user['login'] ?? '' ) ); ?></code>
								— <?php echo esc_html( (string) ( $user['email'] ?? '' ) ); ?></li>
						<?php endforeach; ?>
					</ul>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="rses_export_electoral_authorities" />
						<?php Nonce::rses_field( Nonce::RSES_ACTION_ELECTORAL_AUTHORITIES_EXPORT ); ?>
						<p class="rses-form-actions">
							<button type="submit" class="button button-primary rses-btn-primary"><?php esc_html_e( 'Baixar pacote JSON', 'relatasoft-secure-election-suite' ); ?></button>
						</p>
					</form>
				<?php endif; ?>
			</section>
		</div>
		<?php
	}

	private static function render_import(): void {
		$flash = get_transient( 'rses_ea_import_' . get_current_user_id() );
		delete_transient( 'rses_ea_import_' . get_current_user_id() );
		?>
		<div class="wrap rses-wrap rses-screen" <?php echo Translator::rses_html_attrs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<header class="rses-hero rses-hero--brand">
				<?php Brand::rses_render_hero_brand(); ?>
				<p class="rses-hero-kicker"><?php esc_html_e( 'Gestão de autoridades', 'relatasoft-secure-election-suite' ); ?></p>
				<h1 class="rses-hero-title">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: electoral authorities plural */
							__( 'Importar %s', 'relatasoft-secure-election-suite' ),
							RoleLabels::rses_editor_plural()
						)
					);
					?>
				</h1>
				<p class="rses-hero-lead">
					<?php esc_html_e( 'Carregue o pacote JSON gerado no modo Autoridade-chave. O formato é exatamente o mesmo da exportação.', 'relatasoft-secure-election-suite' ); ?>
				</p>
			</header>

			<?php if ( is_array( $flash ) ) : ?>
				<div class="rses-panel <?php echo empty( $flash['errors'] ) ? 'rses-panel-success' : 'rses-panel-warning'; ?>">
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: created, 2: updated, 3: skipped */
								__( 'Importação concluída: %1$d criadas, %2$d atualizadas, %3$d ignoradas.', 'relatasoft-secure-election-suite' ),
								(int) ( $flash['created'] ?? 0 ),
								(int) ( $flash['updated'] ?? 0 ),
								(int) ( $flash['skipped'] ?? 0 )
							)
						);
						?>
					</p>
					<?php if ( ! empty( $flash['errors'] ) && is_array( $flash['errors'] ) ) : ?>
						<ul>
							<?php foreach ( array_slice( $flash['errors'], 0, 20 ) as $err ) : ?>
								<li><?php echo esc_html( (string) $err ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<section class="rses-panel rses-panel-card">
				<header class="rses-panel-header">
					<p class="rses-panel-kicker"><?php esc_html_e( 'Pacote', 'relatasoft-secure-election-suite' ); ?></p>
					<h2 class="rses-panel-title"><?php esc_html_e( 'Enviar JSON de autoridades', 'relatasoft-secure-election-suite' ); ?></h2>
					<p class="rses-panel-desc">
						<?php esc_html_e( 'Contas existentes (mesmo login ou e-mail) são atualizadas para o papel de autoridade eleitoral. Senhas portáveis do pacote são restauradas quando presentes.', 'relatasoft-secure-election-suite' ); ?>
					</p>
				</header>
				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rses-form">
					<input type="hidden" name="action" value="rses_import_electoral_authorities" />
					<?php Nonce::rses_field( Nonce::RSES_ACTION_ELECTORAL_AUTHORITIES_IMPORT ); ?>
					<p>
						<label for="rses-ea-json"><?php esc_html_e( 'Arquivo JSON', 'relatasoft-secure-election-suite' ); ?></label><br />
						<input type="file" id="rses-ea-json" name="authorities_json" accept=".json,application/json" required />
					</p>
					<p class="rses-form-actions">
						<button type="submit" class="button button-primary rses-btn-primary"><?php esc_html_e( 'Importar autoridades', 'relatasoft-secure-election-suite' ); ?></button>
					</p>
				</form>
			</section>
		</div>
		<?php
	}

	public static function handle_export(): void {
		Capability::rses_require_admin();
		Nonce::rses_verify_or_die( Nonce::RSES_ACTION_ELECTORAL_AUTHORITIES_EXPORT );
		ModeLock::rses_require_mode( ModeLock::RSES_MODE_KEY_AUTHORITY );

		$package = ElectoralAuthoritiesTransferService::build_export_package();
		$json    = ElectoralAuthoritiesPackage::to_json( $package );
		$stamp   = gmdate( 'Ymd-His' );
		$fname   = 'autoridades-eleitorais-' . $stamp . '.json';

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $fname . '"' );
		header( 'Content-Length: ' . (string) strlen( $json ) );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	public static function handle_import(): void {
		Capability::rses_require_admin();
		Nonce::rses_verify_or_die( Nonce::RSES_ACTION_ELECTORAL_AUTHORITIES_IMPORT );
		$mode = ModeLock::rses_get_mode();
		if ( ! in_array( $mode, array( ModeLock::RSES_MODE_VOTING, ModeLock::RSES_MODE_TALLYING ), true ) ) {
			wp_die( esc_html__( 'Importação disponível apenas na Plataforma de votação ou na Plataforma de apuração e certificação.', 'relatasoft-secure-election-suite' ), 403 );
		}

		$file = $_FILES['authorities_json'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! is_array( $file ) || empty( $file['tmp_name'] ) || ! is_readable( $file['tmp_name'] ) ) {
			set_transient(
				'rses_ea_import_' . get_current_user_id(),
				array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => array( __( 'Selecione um arquivo JSON válido.', 'relatasoft-secure-election-suite' ) ) ),
				60
			);
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
			exit;
		}

		$raw = (string) file_get_contents( $file['tmp_name'] );
		$pkg = ElectoralAuthoritiesPackage::from_json( $raw );
		if ( is_wp_error( $pkg ) ) {
			set_transient(
				'rses_ea_import_' . get_current_user_id(),
				array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => array( $pkg->get_error_message() ) ),
				60
			);
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
			exit;
		}

		$result = ElectoralAuthoritiesTransferService::import_package( $pkg );
		set_transient( 'rses_ea_import_' . get_current_user_id(), $result, 120 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}
}
