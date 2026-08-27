<?php
/**
 * Visual identity (themes) — electoral naming.
 *
 * @package RelataSoft\SecureElectionSuite\Admin
 */

namespace RelataSoft\SecureElectionSuite\Admin;

use RelataSoft\SecureElectionSuite\I18n\Translator;
use RelataSoft\SecureElectionSuite\Security\Capability;
use RelataSoft\SecureElectionSuite\Security\Nonce;

defined( 'ABSPATH' ) || exit;

/**
 * Identidade Visual (temas da plataforma).
 */
class SystemAppearancePage {

	public static function register(): void {
		add_action( 'admin_post_ve_appearance_activate', array( self::class, 'handle_activate' ) );
		add_action( 'admin_post_ve_appearance_delete', array( self::class, 'handle_delete' ) );
		add_action( 'admin_post_ve_appearance_upload', array( self::class, 'handle_upload' ) );
	}

	public static function render(): void {
		Capability::rses_require_admin();
		wp_enqueue_style( 've-painel-system' );
		$themes  = self::list_operator_themes();
		$current = get_stylesheet();
		$notice  = isset( $_GET['ve_notice'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['ve_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap rses-wrap rses-screen ve-system-page" <?php echo Translator::rses_html_attrs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<header class="ve-system-hero">
				<p class="ve-system-kicker"><?php esc_html_e( 'Gestão da plataforma', 'relatasoft-secure-election-suite' ); ?></p>
				<h1><?php esc_html_e( 'Identidade Visual', 'relatasoft-secure-election-suite' ); ?></h1>
				<p class="ve-system-lead">
					<?php esc_html_e( 'Instale, ative ou remova modelos de apresentação do site eleitoral — sem expor nomes técnicos de painéis genéricos.', 'relatasoft-secure-election-suite' ); ?>
				</p>
			</header>

			<?php if ( $notice ) : ?>
				<div class="ve-system-notice"><?php echo esc_html( $notice ); ?></div>
			<?php endif; ?>

			<section class="ve-system-card">
				<h2><?php esc_html_e( 'Instalar identidade (ZIP)', 'relatasoft-secure-election-suite' ); ?></h2>
				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="ve_appearance_upload" />
					<?php Nonce::rses_field( 've_appearance_upload' ); ?>
					<input type="file" name="ve_theme_zip" accept=".zip" required />
					<button type="submit" class="ve-btn ve-btn-primary"><?php esc_html_e( 'Instalar modelo', 'relatasoft-secure-election-suite' ); ?></button>
				</form>
			</section>

			<section class="ve-system-card">
				<h2><?php esc_html_e( 'Modelos instalados', 'relatasoft-secure-election-suite' ); ?></h2>
				<?php if ( empty( $themes ) ) : ?>
					<p class="ve-system-muted">
						<?php esc_html_e( 'Nenhum modelo eleitoral instalado ainda. Envie um ZIP acima para publicar a identidade do site.', 'relatasoft-secure-election-suite' ); ?>
					</p>
				<?php else : ?>
				<div class="ve-system-table-wrap">
					<table class="ve-system-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Modelo', 'relatasoft-secure-election-suite' ); ?></th>
								<th><?php esc_html_e( 'Versão', 'relatasoft-secure-election-suite' ); ?></th>
								<th><?php esc_html_e( 'Estado', 'relatasoft-secure-election-suite' ); ?></th>
								<th><?php esc_html_e( 'Ações', 'relatasoft-secure-election-suite' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $themes as $slug => $theme ) : ?>
							<tr>
								<td>
									<strong><?php echo esc_html( $theme->get( 'Name' ) ); ?></strong>
									<div class="ve-system-muted"><?php echo esc_html( (string) $slug ); ?></div>
								</td>
								<td><?php echo esc_html( (string) $theme->get( 'Version' ) ); ?></td>
								<td>
									<?php
									echo $slug === $current
										? esc_html__( 'Ativo', 'relatasoft-secure-election-suite' )
										: esc_html__( 'Em reserva', 'relatasoft-secure-election-suite' );
									?>
								</td>
								<td class="ve-system-actions">
									<?php if ( $slug !== $current ) : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
											<input type="hidden" name="action" value="ve_appearance_activate" />
											<input type="hidden" name="stylesheet" value="<?php echo esc_attr( (string) $slug ); ?>" />
											<?php Nonce::rses_field( 've_appearance_activate' ); ?>
											<button type="submit" class="ve-btn ve-btn-ghost"><?php esc_html_e( 'Ativar', 'relatasoft-secure-election-suite' ); ?></button>
										</form>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Remover este modelo de identidade?');">
											<input type="hidden" name="action" value="ve_appearance_delete" />
											<input type="hidden" name="stylesheet" value="<?php echo esc_attr( (string) $slug ); ?>" />
											<?php Nonce::rses_field( 've_appearance_delete' ); ?>
											<button type="submit" class="ve-btn ve-btn-danger"><?php esc_html_e( 'Remover', 'relatasoft-secure-election-suite' ); ?></button>
										</form>
									<?php else : ?>
										<span class="ve-system-muted">—</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php endif; ?>
			</section>
		</div>
		<?php
	}

	/**
	 * Themes shown to operators — stock WordPress “Twenty*” packs stay installed as silent fallbacks.
	 *
	 * @return array<string, \WP_Theme>
	 */
	public static function list_operator_themes(): array {
		$out = array();
		foreach ( wp_get_themes() as $slug => $theme ) {
			if ( self::is_stock_wordpress_theme( (string) $slug, $theme ) ) {
				continue;
			}
			$out[ $slug ] = $theme;
		}
		return $out;
	}

	/**
	 * Default WP themes kept on disk for emergency fallback — never listed in Identidade Visual.
	 *
	 * @param string $slug  Stylesheet slug.
	 * @param object $theme Theme-like object with get( string $key ).
	 */
	public static function is_stock_wordpress_theme( string $slug, $theme ): bool {
		$slug = strtolower( $slug );
		if ( preg_match( '/^twenty(ten|eleven|twelve|thirteen|fourteen|fifteen|sixteen|seventeen|nineteen)$/', $slug ) ) {
			return true;
		}
		if ( preg_match( '/^twentytwenty([a-z0-9]*)$/', $slug ) ) {
			return true;
		}
		if ( ! str_starts_with( $slug, 'twenty' ) || ! is_object( $theme ) || ! method_exists( $theme, 'get' ) ) {
			return false;
		}
		$uri    = strtolower( (string) $theme->get( 'ThemeURI' ) );
		$author = strtolower( (string) $theme->get( 'Author' ) );
		return str_contains( $uri, 'wordpress.org' )
			|| str_contains( $author, 'wordpress' );
	}

	public static function handle_activate(): void {
		Capability::rses_require_admin();
		Nonce::rses_verify_or_die( 've_appearance_activate' );
		$sheet = isset( $_POST['stylesheet'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['stylesheet'] ) ) : '';
		if ( $sheet ) {
			switch_theme( $sheet );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=rses-system-appearance&ve_notice=' . rawurlencode( 'Identidade ativada.' ) ) );
		exit;
	}

	public static function handle_delete(): void {
		Capability::rses_require_admin();
		Nonce::rses_verify_or_die( 've_appearance_delete' );
		$sheet = isset( $_POST['stylesheet'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['stylesheet'] ) ) : '';
		if ( $sheet && $sheet !== get_stylesheet() ) {
			$theme = wp_get_theme( $sheet );
			if ( $theme->exists() && ! self::is_stock_wordpress_theme( $sheet, $theme ) ) {
				require_once ABSPATH . 'wp-admin/includes/theme.php';
				delete_theme( $sheet );
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=rses-system-appearance&ve_notice=' . rawurlencode( 'Modelo removido.' ) ) );
		exit;
	}

	public static function handle_upload(): void {
		Capability::rses_require_admin();
		Nonce::rses_verify_or_die( 've_appearance_upload' );
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		$file = $_FILES['ve_theme_zip'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$msg  = 'Falha no envio.';
		if ( is_array( $file ) && ! empty( $file['tmp_name'] ) ) {
			$skin     = new \Automatic_Upgrader_Skin();
			$upgrader = new \Theme_Upgrader( $skin );
			$result   = $upgrader->install( $file['tmp_name'] );
			$msg      = is_wp_error( $result ) ? $result->get_error_message() : ( $result ? 'Modelo instalado.' : 'Instalação não concluída.' );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=rses-system-appearance&ve_notice=' . rawurlencode( $msg ) ) );
		exit;
	}
}
