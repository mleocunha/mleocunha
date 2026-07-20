<?php
/**
 * Tallying admin views.
 *
 * @package RelataSoft\SecureElectionSuite\Tallying
 */

namespace RelataSoft\SecureElectionSuite\Tallying;

use RelataSoft\SecureElectionSuite\Security\Capability;
use RelataSoft\SecureElectionSuite\Security\Nonce;

defined( 'ABSPATH' ) || exit;

/**
 * Tallying and certification UI.
 */
class TallyingViews {

	/**
	 * Render tally import page.
	 */
	public static function rses_render_import_page(): void {
		Capability::rses_require_tally_admin();

		$rses_imports = TallyImportRepository::rses_list();
		?>
		<div class="wrap rses-wrap">
			<h1><?php esc_html_e( 'Tally Import', 'relatasoft-secure-election-suite' ); ?></h1>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<?php Nonce::rses_field( Nonce::RSES_ACTION_TALLY_IMPORT ); ?>
				<input type="hidden" name="action" value="rses_tally_import" />
				<p>
					<label for="rses_import_file"><?php esc_html_e( 'Upload Voting Export (ZIP or JSON)', 'relatasoft-secure-election-suite' ); ?></label><br />
					<input type="file" name="rses_import_file" id="rses_import_file" accept=".zip,.json" required />
				</p>
				<?php submit_button( __( 'Import', 'relatasoft-secure-election-suite' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Imports', 'relatasoft-secure-election-suite' ); ?></h2>
			<table class="widefat striped">
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
							<td><?php echo esc_html( $rses_imp->status ); ?></td>
							<td><?php echo esc_html( $rses_imp->imported_at ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render share submission page.
	 */
	public static function rses_render_share_submission_page(): void {
		Capability::rses_require_official();

		$rses_imports = TallyImportRepository::rses_list();
		$rses_import_id = isset( $_GET['import'] ) ? absint( $_GET['import'] ) : 0;
		?>
		<div class="wrap rses-wrap">
			<h1><?php esc_html_e( 'Official Share Submission', 'relatasoft-secure-election-suite' ); ?></h1>
			<?php if ( ! empty( $_GET['rses_submitted'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Your Shamir share was submitted successfully.', 'relatasoft-secure-election-suite' ); ?></p></div>
			<?php endif; ?>
			<div class="rses-notice rses-notice-info">
				<p><?php esc_html_e( 'Paste the share JSON you copied or downloaded from Key Authority (My Shamir Shares). Each Editor submits independently. Only an Administrator can decrypt after the threshold is met.', 'relatasoft-secure-election-suite' ); ?></p>
			</div>

			<?php foreach ( $rses_imports as $rses_imp ) : ?>
				<?php if ( 'verified' !== $rses_imp->status ) { continue; } ?>
				<?php
				$rses_manifest   = TallyImportRepository::rses_get_manifest( $rses_imp );
				$rses_threshold  = (int) ( $rses_manifest['round']['threshold_t'] ?? 3 );
				$rses_submitted  = OfficialShareSubmissionController::rses_get_submission_count( (int) $rses_imp->id );
				?>
				<div class="rses-import-card">
					<h3><?php esc_html_e( 'Import', 'relatasoft-secure-election-suite' ); ?> #<?php echo esc_html( (string) $rses_imp->id ); ?></h3>
					<p><?php esc_html_e( 'Threshold progress:', 'relatasoft-secure-election-suite' ); ?>
						<strong><?php echo esc_html( (string) $rses_submitted ); ?> / <?php echo esc_html( (string) $rses_threshold ); ?></strong>
					</p>

					<div class="rses-progress-bar">
						<div class="rses-progress-fill" style="width: <?php echo esc_attr( (string) min( 100, ( $rses_submitted / max( 1, $rses_threshold ) ) * 100 ) ); ?>%"></div>
					</div>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php Nonce::rses_field( Nonce::RSES_ACTION_SHARE_SUBMIT ); ?>
						<input type="hidden" name="action" value="rses_submit_share" />
						<input type="hidden" name="tally_import_id" value="<?php echo esc_attr( (string) $rses_imp->id ); ?>" />
						<input type="hidden" name="key_id" value="<?php echo esc_attr( (string) ( $rses_manifest['round']['key_id'] ?? 0 ) ); ?>" />
						<input type="hidden" name="election_round_id" value="<?php echo esc_attr( (string) ( $rses_manifest['round']['id'] ?? 0 ) ); ?>" />
						<p>
							<label for="rses_share_json_<?php echo esc_attr( (string) $rses_imp->id ); ?>"><?php esc_html_e( 'Your Share JSON', 'relatasoft-secure-election-suite' ); ?></label><br />
							<textarea name="rses_share_json" id="rses_share_json_<?php echo esc_attr( (string) $rses_imp->id ); ?>" rows="8" class="large-text code" required></textarea>
						</p>
						<?php submit_button( __( 'Submit Share', 'relatasoft-secure-election-suite' ) ); ?>
					</form>

					<?php if ( $rses_submitted >= $rses_threshold && Capability::rses_can_tally_and_certify() ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php Nonce::rses_field( Nonce::RSES_ACTION_DECRYPTION ); ?>
							<input type="hidden" name="action" value="rses_run_decryption" />
							<input type="hidden" name="tally_import_id" value="<?php echo esc_attr( (string) $rses_imp->id ); ?>" />
							<?php submit_button( __( 'Decrypt Tally (Threshold Met)', 'relatasoft-secure-election-suite' ), 'primary' ); ?>
						</form>
					<?php elseif ( $rses_submitted >= $rses_threshold ) : ?>
						<p class="description"><?php esc_html_e( 'Threshold met. An Administrator must run tally decryption and certification.', 'relatasoft-secure-election-suite' ); ?></p>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
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
		<div class="wrap rses-wrap">
			<h1><?php esc_html_e( 'Certification', 'relatasoft-secure-election-suite' ); ?></h1>

			<?php foreach ( $rses_imports as $rses_imp ) : ?>
				<?php if ( 'verified' !== $rses_imp->status ) { continue; } ?>
				<div class="rses-cert-card">
					<h3><?php esc_html_e( 'Import', 'relatasoft-secure-election-suite' ); ?> #<?php echo esc_html( (string) $rses_imp->id ); ?></h3>

					<?php
					$rses_result = get_transient( 'rses_decryption_result_' . $rses_imp->id );
					if ( $rses_result ) :
						?>
						<h4><?php esc_html_e( 'Decrypted Results', 'relatasoft-secure-election-suite' ); ?></h4>
						<pre class="rses-decrypted-results"><?php echo esc_html( wp_json_encode( $rses_result['decrypted_results'], JSON_PRETTY_PRINT ) ); ?></pre>

						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php Nonce::rses_field( Nonce::RSES_ACTION_CERTIFICATION ); ?>
							<input type="hidden" name="action" value="rses_certify" />
							<input type="hidden" name="tally_import_id" value="<?php echo esc_attr( (string) $rses_imp->id ); ?>" />
							<?php submit_button( __( 'Generate Certification', 'relatasoft-secure-election-suite' ) ); ?>
						</form>

						<p>
							<a class="button" href="<?php echo esc_url( Nonce::rses_url( admin_url( 'admin-post.php?action=rses_export_certification&import_id=' . $rses_imp->id . '&format=zip' ), Nonce::RSES_ACTION_CERTIFICATION ) ); ?>"><?php esc_html_e( 'Export ZIP', 'relatasoft-secure-election-suite' ); ?></a>
							<a class="button" href="<?php echo esc_url( Nonce::rses_url( admin_url( 'admin-post.php?action=rses_export_certification&import_id=' . $rses_imp->id . '&format=pdf' ), Nonce::RSES_ACTION_CERTIFICATION ) ); ?>"><?php esc_html_e( 'Export PDF', 'relatasoft-secure-election-suite' ); ?></a>
						</p>
					<?php else : ?>
						<p><?php esc_html_e( 'Awaiting threshold share submissions and decryption.', 'relatasoft-secure-election-suite' ); ?></p>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}
}
