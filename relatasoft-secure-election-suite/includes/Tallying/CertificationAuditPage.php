<?php
/**
 * Auditor page: verify signed election certification packages.
 *
 * @package RelataSoft\SecureElectionSuite\Tallying
 */

namespace RelataSoft\SecureElectionSuite\Tallying;

use RelataSoft\SecureElectionSuite\Admin\Brand;
use RelataSoft\SecureElectionSuite\I18n\Translator;
use RelataSoft\SecureElectionSuite\Security\Capability;
use RelataSoft\SecureElectionSuite\Security\Nonce;

defined( 'ABSPATH' ) || exit;

/**
 * Upload/paste signed JSON (+ optional PDF) and verify dual Schnorr signatures.
 */
class CertificationAuditPage {

	public const SLUG = 'rses-audit-certification';

	/**
	 * Register admin_post is handled by SignedResultsService; page render only.
	 */
	public static function register(): void {
		// Intentionally empty — menu wires render(); verify POST is on SignedResultsService.
	}

	/**
	 * Render auditor certification verify page.
	 */
	public static function rses_render(): void {
		Capability::rses_require_audit_view();

		$rses_flash = get_transient( 'rses_audit_verify_flash_' . get_current_user_id() );
		if ( is_array( $rses_flash ) ) {
			delete_transient( 'rses_audit_verify_flash_' . get_current_user_id() );
		}
		?>
		<div class="wrap rses-wrap rses-screen" <?php echo Translator::rses_html_attrs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<header class="rses-hero">
				<?php Brand::rses_render_hero_brand(); ?>
				<p class="rses-hero-kicker"><?php esc_html_e( 'Auditoria', 'relatasoft-secure-election-suite' ); ?></p>
				<h1 class="rses-hero-title"><?php esc_html_e( 'Auditar certificação', 'relatasoft-secure-election-suite' ); ?></h1>
				<p class="rses-hero-lead"><?php esc_html_e( 'Verificar um signed-results.json (e opcionalmente o PDF correspondente) contra a chave pública embutida. Não é necessária chave privada nem confiança no sítio.', 'relatasoft-secure-election-suite' ); ?></p>
			</header>

			<?php if ( is_array( $rses_flash ) ) : ?>
				<div class="rses-panel <?php echo ! empty( $rses_flash['valid'] ) ? 'rses-panel-success' : 'rses-panel-warning'; ?>">
					<p>
						<strong>
							<?php
							echo ! empty( $rses_flash['valid'] )
								? esc_html__( 'Assinatura VÁLIDA — os resultados correspondem à chave pública da eleição.', 'relatasoft-secure-election-suite' )
								: esc_html__( 'Assinatura INVÁLIDA ou pacote inconsistente.', 'relatasoft-secure-election-suite' );
							?>
						</strong>
					</p>
					<?php if ( ! empty( $rses_flash['errors'] ) && is_array( $rses_flash['errors'] ) ) : ?>
						<ul>
							<?php foreach ( $rses_flash['errors'] as $rses_err ) : ?>
								<li><?php echo esc_html( (string) $rses_err ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
					<?php if ( ! empty( $rses_flash['details'] ) && is_array( $rses_flash['details'] ) ) : ?>
						<details>
							<summary><?php esc_html_e( 'Detalhes da verificação', 'relatasoft-secure-election-suite' ); ?></summary>
							<pre class="rses-decrypted-results"><?php echo esc_html( wp_json_encode( $rses_flash['details'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
						</details>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<section class="rses-panel rses-panel-card">
				<header class="rses-panel-header">
					<p class="rses-panel-kicker"><?php esc_html_e( 'Verificar', 'relatasoft-secure-election-suite' ); ?></p>
					<h2 class="rses-panel-title"><?php esc_html_e( 'Pacote assinado', 'relatasoft-secure-election-suite' ); ?></h2>
					<p class="rses-panel-desc"><?php esc_html_e( 'Paste or upload the signed JSON. Optionally upload the PDF to check pdf_sha256 and the PDF-binding Schnorr signature.', 'relatasoft-secure-election-suite' ); ?></p>
				</header>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="rses-form">
					<?php Nonce::rses_field( Nonce::RSES_ACTION_CERTIFICATION ); ?>
					<input type="hidden" name="action" value="rses_verify_signed_results" />
					<div class="rses-field-grid">
						<div class="rses-field rses-field-full">
							<label for="rses_signed_json"><?php esc_html_e( 'Signed results JSON', 'relatasoft-secure-election-suite' ); ?></label>
							<textarea name="rses_signed_json" id="rses_signed_json" rows="12" class="rses-code-area"></textarea>
						</div>
						<div class="rses-field">
							<label for="rses_signed_json_file"><?php esc_html_e( 'Or upload signed JSON file', 'relatasoft-secure-election-suite' ); ?></label>
							<input type="file" name="rses_signed_json_file" id="rses_signed_json_file" accept=".json,application/json" />
						</div>
						<div class="rses-field">
							<label for="rses_signed_pdf_file"><?php esc_html_e( 'Optional signed PDF', 'relatasoft-secure-election-suite' ); ?></label>
							<input type="file" name="rses_signed_pdf_file" id="rses_signed_pdf_file" accept=".pdf,application/pdf" />
						</div>
					</div>
					<p class="rses-form-actions">
						<?php submit_button( __( 'Verify signature', 'relatasoft-secure-election-suite' ), 'primary rses-btn-primary', 'submit', false ); ?>
					</p>
				</form>
			</section>
		</div>
		<?php
	}
}
