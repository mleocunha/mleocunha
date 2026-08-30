<?php
/**
 * Becape e Restauração — full install ZIP + database dump.
 *
 * @package RelataSoft\SecureElectionSuite\Admin
 */

namespace RelataSoft\SecureElectionSuite\Admin;

use RelataSoft\SecureElectionSuite\I18n\Translator;
use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\System\BecapeService;
use RelataSoft\SecureElectionSuite\Security\Capability;
use RelataSoft\SecureElectionSuite\Security\Nonce;

defined( 'ABSPATH' ) || exit;

/**
 * Becape e Restauração da plataforma eleitoral.
 */
class SystemBecapePage {

	public static function register(): void {
		add_action( 'admin_post_ve_becape_create', array( self::class, 'handle_create' ) );
		add_action( 'admin_post_ve_becape_download', array( self::class, 'handle_download' ) );
		add_action( 'admin_post_ve_becape_delete', array( self::class, 'handle_delete' ) );
		add_action( 'admin_post_ve_becape_restore', array( self::class, 'handle_restore' ) );
	}

	public static function render(): void {
		Capability::rses_require_admin();
		wp_enqueue_style( 've-painel-system' );

		$list   = BecapeService::listBecapes();
		$notice = isset( $_GET['ve_notice'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['ve_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap rses-wrap rses-screen ve-system-page" <?php echo Translator::rses_html_attrs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<header class="ve-system-hero">
				<p class="ve-system-kicker"><?php esc_html_e( 'Gestão da plataforma', 'relatasoft-secure-election-suite' ); ?></p>
				<h1><?php esc_html_e( 'Becape e Restauração', 'relatasoft-secure-election-suite' ); ?></h1>
				<p class="ve-system-lead">
					<?php esc_html_e( 'Gerar um pacote ZIP com a totalidade do diretório da instalação e um dump da base de dados. Restaure exatamente a partir desse modelo.', 'relatasoft-secure-election-suite' ); ?>
				</p>
			</header>

			<?php if ( $notice ) : ?>
				<div class="ve-system-notice"><?php echo esc_html( $notice ); ?></div>
			<?php endif; ?>

			<section class="ve-system-card">
				<h2><?php esc_html_e( 'Gerar becape', 'relatasoft-secure-election-suite' ); ?></h2>
				<p class="ve-system-muted">
					<?php esc_html_e( 'Inclui arquivos da instalação (exceto a pasta de becapes) e exportação SQL da base de dados.', 'relatasoft-secure-election-suite' ); ?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="ve_becape_create" />
					<?php Nonce::rses_field( 've_becape_create' ); ?>
					<button type="submit" class="ve-btn ve-btn-primary"><?php esc_html_e( 'Gerar becape agora', 'relatasoft-secure-election-suite' ); ?></button>
				</form>
			</section>

			<section class="ve-system-card">
				<h2><?php esc_html_e( 'Pacotes disponíveis', 'relatasoft-secure-election-suite' ); ?></h2>
				<?php if ( empty( $list ) ) : ?>
					<p class="ve-system-muted"><?php esc_html_e( 'Ainda não há becapes gerados.', 'relatasoft-secure-election-suite' ); ?></p>
				<?php else : ?>
					<div class="ve-system-table-wrap">
						<table class="ve-system-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Pacote', 'relatasoft-secure-election-suite' ); ?></th>
									<th><?php esc_html_e( 'Criado em', 'relatasoft-secure-election-suite' ); ?></th>
									<th><?php esc_html_e( 'Tamanho', 'relatasoft-secure-election-suite' ); ?></th>
									<th><?php esc_html_e( 'Ações', 'relatasoft-secure-election-suite' ); ?></th>
								</tr>
							</thead>
							<tbody>
							<?php foreach ( $list as $pkg ) : ?>
								<?php
								$name = (string) ( $pkg['name'] ?? '' );
								$size = isset( $pkg['bytes'] ) ? size_format( (int) $pkg['bytes'] ) : '—';
								$when = isset( $pkg['mtime'] ) ? wp_date( 'd/m/Y H:i', (int) $pkg['mtime'] ) : '—';
								?>
								<tr>
									<td><strong><?php echo esc_html( $name ); ?></strong></td>
									<td><?php echo esc_html( (string) $when ); ?></td>
									<td><?php echo esc_html( (string) $size ); ?></td>
									<td class="ve-system-actions">
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
											<input type="hidden" name="action" value="ve_becape_download" />
											<input type="hidden" name="package" value="<?php echo esc_attr( $name ); ?>" />
											<?php Nonce::rses_field( 've_becape_download' ); ?>
											<button type="submit" class="ve-btn ve-btn-ghost"><?php esc_html_e( 'Baixar', 'relatasoft-secure-election-suite' ); ?></button>
										</form>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Excluir este becape?');">
											<input type="hidden" name="action" value="ve_becape_delete" />
											<input type="hidden" name="package" value="<?php echo esc_attr( $name ); ?>" />
											<?php Nonce::rses_field( 've_becape_delete' ); ?>
											<button type="submit" class="ve-btn ve-btn-danger"><?php esc_html_e( 'Excluir', 'relatasoft-secure-election-suite' ); ?></button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</section>

			<section class="ve-system-card ve-system-card--danger">
				<h2><?php esc_html_e( 'Restaurar a partir de becape', 'relatasoft-secure-election-suite' ); ?></h2>
				<p class="ve-system-muted">
					<?php esc_html_e( 'Operação destrutiva: substitui arquivos e base de dados pelo conteúdo do pacote. Digite RESTAURAR para confirmar.', 'relatasoft-secure-election-suite' ); ?>
				</p>
				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ve-system-restore-form">
					<input type="hidden" name="action" value="ve_becape_restore" />
					<?php Nonce::rses_field( 've_becape_restore' ); ?>
					<label class="ve-system-field">
						<span><?php esc_html_e( 'Pacote ZIP de becape', 'relatasoft-secure-election-suite' ); ?></span>
						<input type="file" name="becape_zip" accept=".zip,application/zip" required />
					</label>
					<label class="ve-system-field">
						<span><?php esc_html_e( 'Confirmação', 'relatasoft-secure-election-suite' ); ?></span>
						<input type="text" name="confirm" placeholder="RESTAURAR" autocomplete="off" required />
					</label>
					<button type="submit" class="ve-btn ve-btn-danger"><?php esc_html_e( 'Restaurar sistema', 'relatasoft-secure-election-suite' ); ?></button>
				</form>
			</section>
		</div>
		<?php
	}

	public static function handle_create(): void {
		Capability::rses_require_admin();
		Nonce::rses_verify_or_die( 've_becape_create' );
		$result = BecapeService::createBecape();
		$msg    = ! empty( $result['ok'] )
			? sprintf( 'Becape gerado: %s', (string) ( $result['name'] ?? '' ) )
			: (string) ( $result['error'] ?? 'Falha ao gerar becape.' );
		wp_safe_redirect( admin_url( 'admin.php?page=rses-system-becape&ve_notice=' . rawurlencode( $msg ) ) );
		exit;
	}

	public static function handle_download(): void {
		Capability::rses_require_admin();
		Nonce::rses_verify_or_die( 've_becape_download' );
		$name = isset( $_POST['package'] ) ? sanitize_file_name( wp_unslash( (string) $_POST['package'] ) ) : '';
		$path = '';
		foreach ( BecapeService::listBecapes() as $pkg ) {
			if ( ( $pkg['name'] ?? '' ) === $name ) {
				$path = (string) ( $pkg['path'] ?? '' );
				break;
			}
		}
		if ( '' === $path || ! is_readable( $path ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=rses-system-becape&ve_notice=' . rawurlencode( 'Pacote não encontrado.' ) ) );
			exit;
		}
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $path );
		exit;
	}

	public static function handle_delete(): void {
		Capability::rses_require_admin();
		Nonce::rses_verify_or_die( 've_becape_delete' );
		$name = isset( $_POST['package'] ) ? sanitize_file_name( wp_unslash( (string) $_POST['package'] ) ) : '';
		$ok   = false;
		foreach ( BecapeService::listBecapes() as $pkg ) {
			if ( ( $pkg['name'] ?? '' ) === $name && ! empty( $pkg['path'] ) && is_file( $pkg['path'] ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				$ok = @unlink( $pkg['path'] );
				break;
			}
		}
		$msg = $ok ? 'Becape excluído.' : 'Não foi possível excluir o becape.';
		wp_safe_redirect( admin_url( 'admin.php?page=rses-system-becape&ve_notice=' . rawurlencode( $msg ) ) );
		exit;
	}

	public static function handle_restore(): void {
		Capability::rses_require_admin();
		Nonce::rses_verify_or_die( 've_becape_restore' );
		$confirm = isset( $_POST['confirm'] ) ? trim( wp_unslash( (string) $_POST['confirm'] ) ) : '';
		$file    = $_FILES['becape_zip'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! is_array( $file ) || empty( $file['tmp_name'] ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=rses-system-becape&ve_notice=' . rawurlencode( 'Selecione um pacote ZIP.' ) ) );
			exit;
		}
		$tmp = trailingslashit( BecapeService::storageDir() ) . 'upload-' . wp_generate_password( 8, false ) . '.zip';
		if ( ! move_uploaded_file( (string) $file['tmp_name'], $tmp ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=rses-system-becape&ve_notice=' . rawurlencode( 'Falha ao receber o pacote.' ) ) );
			exit;
		}
		$result = BecapeService::restoreFromZip( $tmp, $confirm );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		@unlink( $tmp );
		$msg = ! empty( $result['ok'] )
			? (string) ( $result['message'] ?? 'Sistema restaurado a partir do becape.' )
			: (string) ( $result['error'] ?? 'Falha na restauração.' );
		wp_safe_redirect( admin_url( 'admin.php?page=rses-system-becape&ve_notice=' . rawurlencode( $msg ) ) );
		exit;
	}
}
