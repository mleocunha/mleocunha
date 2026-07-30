<?php
/**
 * Tallying admin views.
 *
 * @package RelataSoft\SecureElectionSuite\Tallying
 */

namespace RelataSoft\SecureElectionSuite\Tallying;

use RelataSoft\SecureElectionSuite\Security\Capability;
use RelataSoft\SecureElectionSuite\Security\Nonce;
use RelataSoft\SecureElectionSuite\I18n\Translator;
use RelataSoft\SecureElectionSuite\Admin\Brand;

defined( 'ABSPATH' ) || exit;

/**
 * Tallying and certification UI.
 */
class TallyingViews {

	/**
	 * Status pill markup.
	 *
	 * @param string $status Status slug.
	 */
	private static function rses_status_pill( string $status ): string {
		$rses_slug = sanitize_html_class( strtolower( $status ) );
		return '<span class="rses-status-pill rses-status-pill--' . esc_attr( $rses_slug ) . '">' . esc_html( $status ) . '</span>';
	}

	/**
	 * Render tally import page.
	 */
	public static function rses_render_import_page(): void {
		Capability::rses_require_tally_admin();

		$rses_imports = TallyImportRepository::rses_list();
		?>
		<div class="wrap rses-wrap rses-screen" <?php echo Translator::rses_html_attrs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<header class="rses-hero">
				<?php Brand::rses_render_hero_brand(); ?>

				<p class="rses-hero-kicker"><?php esc_html_e( 'Tallying', 'relatasoft-secure-election-suite' ); ?></p>
				<h1 class="rses-hero-title"><?php esc_html_e( 'Tally Import', 'relatasoft-secure-election-suite' ); ?></h1>
				<p class="rses-hero-lead"><?php esc_html_e( 'Import sealed voting packages (ZIP or JSON) from the Voting site to begin share collection and decryption.', 'relatasoft-secure-election-suite' ); ?></p>
			</header>

			<?php if ( ! empty( $_GET['rses_imported'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="rses-panel rses-panel-success">
					<p><?php esc_html_e( 'Voting package imported successfully.', 'relatasoft-secure-election-suite' ); ?></p>
				</div>
			<?php endif; ?>

			<section class="rses-panel rses-panel-card">
				<header class="rses-panel-header">
					<p class="rses-panel-kicker"><?php esc_html_e( 'Upload', 'relatasoft-secure-election-suite' ); ?></p>
					<h2 class="rses-panel-title"><?php esc_html_e( 'Import voting export', 'relatasoft-secure-election-suite' ); ?></h2>
					<p class="rses-panel-desc"><?php esc_html_e( 'Accepts the ZIP/JSON produced by Voting Export after a round is closed.', 'relatasoft-secure-election-suite' ); ?></p>
				</header>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="rses-form">
					<?php Nonce::rses_field( Nonce::RSES_ACTION_TALLY_IMPORT ); ?>
					<input type="hidden" name="action" value="rses_tally_import" />
					<div class="rses-field-grid">
						<div class="rses-field rses-field-full rses-file-field">
							<label for="rses_import_file"><?php esc_html_e( 'Voting Export (ZIP or JSON)', 'relatasoft-secure-election-suite' ); ?></label>
							<input type="file" name="rses_import_file" id="rses_import_file" accept=".zip,.json" required />
						</div>
					</div>
					<p class="rses-form-actions">
						<?php submit_button( __( 'Import', 'relatasoft-secure-election-suite' ), 'primary rses-btn-primary', 'submit', false ); ?>
					</p>
				</form>
			</section>

			<section class="rses-panel rses-panel-card">
				<header class="rses-panel-header">
					<p class="rses-panel-kicker"><?php esc_html_e( 'Library', 'relatasoft-secure-election-suite' ); ?></p>
					<h2 class="rses-panel-title"><?php esc_html_e( 'Imports', 'relatasoft-secure-election-suite' ); ?></h2>
					<p class="rses-panel-desc"><?php esc_html_e( 'Verified packages appear on Share Submission for officials.', 'relatasoft-secure-election-suite' ); ?></p>
				</header>

				<?php if ( empty( $rses_imports ) ) : ?>
					<div class="rses-panel-body">
						<p class="rses-empty"><?php esc_html_e( 'No imports yet.', 'relatasoft-secure-election-suite' ); ?></p>
					</div>
				<?php else : ?>
					<div class="rses-table-wrap">
						<table class="rses-table">
							<thead>
								<tr>
									<th>ID</th>
									<th><?php esc_html_e( 'Source', 'relatasoft-secure-election-suite' ); ?></th>
									<th><?php esc_html_e( 'Status', 'relatasoft-secure-election-suite' ); ?></th>
									<th><?php esc_html_e( 'Imported', 'relatasoft-secure-election-suite' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $rses_imports as $rses_imp ) : ?>
									<tr>
										<td><?php echo esc_html( (string) $rses_imp->id ); ?></td>
										<td><?php echo esc_html( $rses_imp->source_site_url ?? '-' ); ?></td>
										<td><?php echo self::rses_status_pill( (string) $rses_imp->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
										<td><?php echo esc_html( $rses_imp->imported_at ); ?></td>
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
	 * Render share submission page.
	 */
	public static function rses_render_share_submission_page(): void {
		Capability::rses_require_official();

		$rses_imports = TallyImportRepository::rses_list();
		?>
		<div class="wrap rses-wrap rses-screen" <?php echo Translator::rses_html_attrs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<header class="rses-hero">
				<?php Brand::rses_render_hero_brand(); ?>

				<p class="rses-hero-kicker"><?php esc_html_e( 'Tallying', 'relatasoft-secure-election-suite' ); ?></p>
				<h1 class="rses-hero-title"><?php esc_html_e( 'Official Share Submission', 'relatasoft-secure-election-suite' ); ?></h1>
				<p class="rses-hero-lead"><?php esc_html_e( 'Paste the share JSON from Key Authority. Each Editor submits independently; only an Administrator can decrypt after the threshold is met.', 'relatasoft-secure-election-suite' ); ?></p>
			</header>

			<?php if ( ! empty( $_GET['rses_submitted'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="rses-panel rses-panel-success">
					<p><?php esc_html_e( 'Your Shamir share was submitted successfully.', 'relatasoft-secure-election-suite' ); ?></p>
				</div>
			<?php endif; ?>

			<div class="rses-panel rses-panel-info">
				<p><?php esc_html_e( 'Use the same share JSON you copied or downloaded under My Shamir Shares on the Key Authority site. Never paste another official’s share.', 'relatasoft-secure-election-suite' ); ?></p>
			</div>

			<?php
			$rses_any = false;
			foreach ( $rses_imports as $rses_imp ) :
				if ( 'verified' !== $rses_imp->status ) {
					continue;
				}
				$rses_any        = true;
				$rses_manifest   = TallyImportRepository::rses_get_manifest( $rses_imp );
				$rses_threshold  = (int) ( $rses_manifest['round']['threshold_t'] ?? 3 );
				$rses_submitted  = OfficialShareSubmissionController::rses_get_submission_count( (int) $rses_imp->id );
				$rses_pct        = min( 100, ( $rses_submitted / max( 1, $rses_threshold ) ) * 100 );
				?>
				<article class="rses-import-card">
					<header class="rses-import-card-header">
						<h3><?php esc_html_e( 'Import', 'relatasoft-secure-election-suite' ); ?> #<?php echo esc_html( (string) $rses_imp->id ); ?></h3>
						<p class="rses-panel-desc"><?php echo esc_html( $rses_imp->source_site_url ?? '' ); ?></p>
					</header>
					<div class="rses-import-card-body">
						<div class="rses-threshold-meta">
							<span><?php esc_html_e( 'Threshold progress', 'relatasoft-secure-election-suite' ); ?></span>
							<strong><?php echo esc_html( (string) $rses_submitted ); ?> / <?php echo esc_html( (string) $rses_threshold ); ?></strong>
						</div>
						<div class="rses-progress-bar" role="progressbar" aria-valuenow="<?php echo esc_attr( (string) (int) $rses_pct ); ?>" aria-valuemin="0" aria-valuemax="100">
							<div class="rses-progress-fill" style="width: <?php echo esc_attr( (string) $rses_pct ); ?>%"></div>
						</div>

						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rses-form">
							<?php Nonce::rses_field( Nonce::RSES_ACTION_SHARE_SUBMIT ); ?>
							<input type="hidden" name="action" value="rses_submit_share" />
							<input type="hidden" name="tally_import_id" value="<?php echo esc_attr( (string) $rses_imp->id ); ?>" />
							<input type="hidden" name="key_id" value="<?php echo esc_attr( (string) ( $rses_manifest['round']['key_id'] ?? 0 ) ); ?>" />
							<input type="hidden" name="election_round_id" value="<?php echo esc_attr( (string) ( $rses_manifest['round']['id'] ?? 0 ) ); ?>" />
							<div class="rses-field-grid">
								<div class="rses-field rses-field-full">
									<label for="rses_share_json_<?php echo esc_attr( (string) $rses_imp->id ); ?>"><?php esc_html_e( 'Your Share JSON', 'relatasoft-secure-election-suite' ); ?></label>
									<textarea name="rses_share_json" id="rses_share_json_<?php echo esc_attr( (string) $rses_imp->id ); ?>" rows="8" class="rses-code-area" required></textarea>
								</div>
							</div>
							<p class="rses-form-actions">
								<?php submit_button( __( 'Submit Share', 'relatasoft-secure-election-suite' ), 'primary rses-btn-primary', 'submit', false ); ?>
							</p>
						</form>

						<?php if ( $rses_submitted >= $rses_threshold && Capability::rses_can_tally_and_certify() ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rses-form">
								<?php Nonce::rses_field( Nonce::RSES_ACTION_DECRYPTION ); ?>
								<input type="hidden" name="action" value="rses_run_decryption" />
								<input type="hidden" name="tally_import_id" value="<?php echo esc_attr( (string) $rses_imp->id ); ?>" />
								<p class="rses-form-actions">
									<?php submit_button( __( 'Decrypt Tally (Threshold Met)', 'relatasoft-secure-election-suite' ), 'primary rses-btn-primary', 'submit', false ); ?>
								</p>
							</form>
						<?php elseif ( $rses_submitted >= $rses_threshold ) : ?>
							<p class="description"><?php esc_html_e( 'Threshold met. An Administrator must run tally decryption and certification.', 'relatasoft-secure-election-suite' ); ?></p>
						<?php endif; ?>
					</div>
				</article>
			<?php endforeach; ?>

			<?php if ( ! $rses_any ) : ?>
				<div class="rses-panel rses-panel-info">
					<p><?php esc_html_e( 'No verified imports yet. Ask an Administrator to import a voting package first.', 'relatasoft-secure-election-suite' ); ?></p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render certification page.
	 */
	public static function rses_render_certification_page(): void {
		Capability::rses_require_tally_admin();

		$rses_imports = TallyImportRepository::rses_list();
		?>
		<div class="wrap rses-wrap rses-screen" <?php echo Translator::rses_html_attrs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<header class="rses-hero">
				<?php Brand::rses_render_hero_brand(); ?>

				<p class="rses-hero-kicker"><?php esc_html_e( 'Tallying', 'relatasoft-secure-election-suite' ); ?></p>
				<h1 class="rses-hero-title"><?php esc_html_e( 'Certification', 'relatasoft-secure-election-suite' ); ?></h1>
				<p class="rses-hero-lead"><?php esc_html_e( 'Review decrypted tallies, generate certification records, and export ZIP or PDF packages.', 'relatasoft-secure-election-suite' ); ?></p>
			</header>

			<?php if ( ! empty( $_GET['rses_decrypted'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="rses-panel rses-panel-success">
					<p><?php esc_html_e( 'Tally decrypted successfully. Review results below and generate certification.', 'relatasoft-secure-election-suite' ); ?></p>
				</div>
			<?php endif; ?>

			<?php
			$rses_any = false;
			foreach ( $rses_imports as $rses_imp ) :
				if ( 'verified' !== $rses_imp->status ) {
					continue;
				}
				$rses_any    = true;
				$rses_result = get_transient( 'rses_decryption_result_' . $rses_imp->id );
				?>
				<article class="rses-cert-card">
					<header class="rses-cert-card-header">
						<h3><?php esc_html_e( 'Import', 'relatasoft-secure-election-suite' ); ?> #<?php echo esc_html( (string) $rses_imp->id ); ?></h3>
						<p class="rses-panel-desc">
							<?php echo self::rses_status_pill( (string) $rses_imp->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php if ( ! empty( $rses_imp->source_site_url ) ) : ?>
								— <?php echo esc_html( $rses_imp->source_site_url ); ?>
							<?php endif; ?>
						</p>
					</header>
					<div class="rses-cert-card-body">
						<?php if ( $rses_result ) : ?>
							<p class="rses-field-label"><?php esc_html_e( 'Decrypted Results', 'relatasoft-secure-election-suite' ); ?></p>
							<pre class="rses-decrypted-results"><?php echo esc_html( wp_json_encode( $rses_result['decrypted_results'], JSON_PRETTY_PRINT ) ); ?></pre>

							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rses-form">
								<?php Nonce::rses_field( Nonce::RSES_ACTION_CERTIFICATION ); ?>
								<input type="hidden" name="action" value="rses_certify" />
								<input type="hidden" name="tally_import_id" value="<?php echo esc_attr( (string) $rses_imp->id ); ?>" />
								<p class="rses-form-actions">
									<?php submit_button( __( 'Generate Certification', 'relatasoft-secure-election-suite' ), 'primary rses-btn-primary', 'submit', false ); ?>
								</p>
							</form>

							<div class="rses-inline-actions" style="margin-top: 0.85rem;">
								<a class="button rses-btn-secondary" href="<?php echo esc_url( Nonce::rses_url( admin_url( 'admin-post.php?action=rses_export_certification&import_id=' . $rses_imp->id . '&format=zip' ), Nonce::RSES_ACTION_CERTIFICATION ) ); ?>"><?php esc_html_e( 'Export ZIP', 'relatasoft-secure-election-suite' ); ?></a>
								<a class="button rses-btn-secondary" href="<?php echo esc_url( Nonce::rses_url( admin_url( 'admin-post.php?action=rses_export_certification&import_id=' . $rses_imp->id . '&format=pdf' ), Nonce::RSES_ACTION_CERTIFICATION ) ); ?>"><?php esc_html_e( 'Export PDF', 'relatasoft-secure-election-suite' ); ?></a>
							</div>
						<?php else : ?>
							<p class="rses-empty"><?php esc_html_e( 'Awaiting threshold share submissions and decryption.', 'relatasoft-secure-election-suite' ); ?></p>
						<?php endif; ?>
					</div>
				</article>
			<?php endforeach; ?>

			<?php if ( ! $rses_any ) : ?>
				<div class="rses-panel rses-panel-info">
					<p><?php esc_html_e( 'No verified imports yet. Import a voting package first, then collect official shares.', 'relatasoft-secure-election-suite' ); ?></p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
