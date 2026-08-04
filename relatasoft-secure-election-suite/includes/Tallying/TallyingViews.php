<?php
/**
 * Tallying admin views.
 *
 * @package RelataSoft\SecureElectionSuite\Tallying
 */

namespace RelataSoft\SecureElectionSuite\Tallying;

use RelataSoft\SecureElectionSuite\Security\Capability;
use RelataSoft\SecureElectionSuite\Security\Nonce;
use RelataSoft\SecureElectionSuite\I18n\RoleLabels;
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
	 * Election/round subtitle for import cards and tables.
	 *
	 * @param object $import Import row.
	 */
	private static function rses_import_election_meta_html( object $import, ?array $manifest = null ): string {
		$rses_election = TallyImportRepository::rses_display_election_title( $import );
		$rses_round    = TallyImportRepository::rses_display_round_title( $import );
		$rses_key      = TallyImportRepository::rses_key_identity( $import, $manifest );
		$rses_bits     = array();

		$rses_bits[] = '<strong class="rses-import-election-title">' . esc_html( $rses_election ) . '</strong>';
		if ( '' !== $rses_round ) {
			$rses_bits[] = esc_html( $rses_round );
		}
		if ( $rses_key['key_id'] > 0 || '' !== $rses_key['key_label'] || '' !== $rses_key['fingerprint'] ) {
			$rses_bits[] = esc_html(
				sprintf(
					/* translators: 1: key id, 2: key label, 3: fingerprint */
					__( 'Key #%1$s — %2$s (fp %3$s)', 'relatasoft-secure-election-suite' ),
					(string) ( $rses_key['key_id'] ?: '—' ),
					'' !== $rses_key['key_label'] ? $rses_key['key_label'] : '—',
					'' !== $rses_key['fingerprint'] ? $rses_key['fingerprint'] : '—'
				)
			);
		}
		if ( isset( $import->ballot_count ) && null !== $import->ballot_count && '' !== (string) $import->ballot_count ) {
			$rses_bits[] = esc_html(
				sprintf(
					/* translators: %d: ballot count */
					__( '%d ballots', 'relatasoft-secure-election-suite' ),
					(int) $import->ballot_count
				)
			);
		}
		if ( ! empty( $import->election_external_id ) || ! empty( $import->round_external_id ) ) {
			$rses_bits[] = esc_html(
				sprintf(
					/* translators: 1: election id, 2: round id */
					__( 'IDs %1$s / %2$s', 'relatasoft-secure-election-suite' ),
					(string) ( $import->election_external_id ?: '—' ),
					(string) ( $import->round_external_id ?: '—' )
				)
			);
		}
		if ( ! empty( $import->source_site_url ) ) {
			$rses_bits[] = esc_html( (string) $import->source_site_url );
		}

		return '<p class="rses-panel-desc rses-import-election-meta">' . implode( ' · ', $rses_bits ) . '</p>';
	}

	/**
	 * Render tally import page.
	 */
	public static function rses_render_import_page(): void {
		Capability::rses_require_tally_admin();

		// Recover from older imports that stored full encrypted_votes (white screen on list).
		$rses_purged = TallyImportRepository::rses_purge_oversized_manifests();
		TallyImportRepository::rses_backfill_summaries();

		$rses_imports = TallyImportRepository::rses_list();
		?>
		<div class="wrap rses-wrap rses-screen" <?php echo Translator::rses_html_attrs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<header class="rses-hero">
				<?php Brand::rses_render_hero_brand(); ?>

				<p class="rses-hero-kicker"><?php esc_html_e( 'Tallying', 'relatasoft-secure-election-suite' ); ?></p>
				<h1 class="rses-hero-title"><?php esc_html_e( 'Tally Import', 'relatasoft-secure-election-suite' ); ?></h1>
				<p class="rses-hero-lead"><?php esc_html_e( 'Import sealed voting packages (ZIP or JSON) from the Voting site to begin share collection and decryption.', 'relatasoft-secure-election-suite' ); ?></p>
				<p class="rses-hero-lead"><code><?php echo esc_html( 'plugin ' . RSES_VERSION ); ?></code></p>
			</header>

			<?php if ( $rses_purged > 0 ) : ?>
				<div class="rses-panel notice notice-warning">
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: number of rows */
								__( 'Cleared %d oversized import record(s) that were exhausting PHP memory. Please import the ZIP again with this plugin version.', 'relatasoft-secure-election-suite' ),
								$rses_purged
							)
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php
			if ( ! empty( $_GET['rses_imported'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$rses_flash_id   = absint( $_GET['rses_imported'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$rses_flash_data = $rses_flash_id ? get_transient( 'rses_tally_import_flash_' . $rses_flash_id ) : false;
				if ( is_array( $rses_flash_data ) ) {
					delete_transient( 'rses_tally_import_flash_' . $rses_flash_id );
				}
				$rses_flash_row    = $rses_flash_id ? TallyImportRepository::rses_get( $rses_flash_id ) : null;
				$rses_flash_status = is_array( $rses_flash_data ) ? (string) ( $rses_flash_data['status'] ?? '' ) : '';
				if ( '' === $rses_flash_status && $rses_flash_row ) {
					$rses_flash_status = (string) $rses_flash_row->status;
				}
				$rses_flash_errors = is_array( $rses_flash_data ) && ! empty( $rses_flash_data['errors'] ) ? (array) $rses_flash_data['errors'] : array();
				if ( empty( $rses_flash_errors ) && $rses_flash_row ) {
					$rses_flash_manifest = TallyImportRepository::rses_get_manifest( $rses_flash_row );
					if ( ! empty( $rses_flash_manifest['validation_errors'] ) && is_array( $rses_flash_manifest['validation_errors'] ) ) {
						$rses_flash_errors = $rses_flash_manifest['validation_errors'];
					}
				}
				$rses_flash_election = '';
				if ( is_array( $rses_flash_data ) && ! empty( $rses_flash_data['election_title'] ) ) {
					$rses_flash_election = (string) $rses_flash_data['election_title'];
				} elseif ( $rses_flash_row ) {
					$rses_flash_election = TallyImportRepository::rses_display_election_title( $rses_flash_row );
				}
				$rses_flash_round = '';
				if ( is_array( $rses_flash_data ) && ! empty( $rses_flash_data['round_title'] ) ) {
					$rses_flash_round = (string) $rses_flash_data['round_title'];
				} elseif ( $rses_flash_row ) {
					$rses_flash_round = TallyImportRepository::rses_display_round_title( $rses_flash_row );
				}

				if ( 'rejected' === $rses_flash_status ) :
					?>
				<div class="rses-panel rses-panel-danger notice notice-error">
					<p><?php esc_html_e( 'Import stored but rejected validation. Fix the package and try again.', 'relatasoft-secure-election-suite' ); ?></p>
					<?php if ( '' !== $rses_flash_election ) : ?>
						<p>
							<strong><?php echo esc_html( $rses_flash_election ); ?></strong>
							<?php if ( '' !== $rses_flash_round ) : ?>
								— <?php echo esc_html( $rses_flash_round ); ?>
							<?php endif; ?>
						</p>
					<?php endif; ?>
					<?php if ( ! empty( $rses_flash_errors ) ) : ?>
						<ul>
							<?php foreach ( $rses_flash_errors as $rses_err ) : ?>
								<li><?php echo esc_html( (string) $rses_err ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php else : ?>
						<p><?php esc_html_e( 'No detail was stored. Typical causes: missing public key fields (p/q/g/y) or empty encrypted tallies. Update both sites to 1.0.27.6+, close the round, re-export ZIP, and import again.', 'relatasoft-secure-election-suite' ); ?></p>
					<?php endif; ?>
				</div>
				<?php else : ?>
				<div class="rses-panel rses-panel-success">
					<p><?php esc_html_e( 'Voting package imported successfully.', 'relatasoft-secure-election-suite' ); ?></p>
					<?php if ( '' !== $rses_flash_election ) : ?>
						<p>
							<?php esc_html_e( 'Election:', 'relatasoft-secure-election-suite' ); ?>
							<strong><?php echo esc_html( $rses_flash_election ); ?></strong>
							<?php if ( '' !== $rses_flash_round ) : ?>
								— <?php echo esc_html( $rses_flash_round ); ?>
							<?php endif; ?>
						</p>
					<?php endif; ?>
				</div>
				<?php endif; ?>
			<?php endif; ?>

			<section class="rses-panel rses-panel-card">
				<header class="rses-panel-header">
					<p class="rses-panel-kicker"><?php esc_html_e( 'Upload', 'relatasoft-secure-election-suite' ); ?></p>
					<h2 class="rses-panel-title"><?php esc_html_e( 'Import voting export', 'relatasoft-secure-election-suite' ); ?></h2>
					<p class="rses-panel-desc"><?php esc_html_e( 'Prefer the ZIP from Voting Export after the round is closed. Individual encrypted votes are verified by checksum and not loaded into memory (required for large elections on 128MB PHP hosts).', 'relatasoft-secure-election-suite' ); ?></p>
				</header>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="rses-form">
					<?php Nonce::rses_field( Nonce::RSES_ACTION_TALLY_IMPORT ); ?>
					<input type="hidden" name="action" value="rses_tally_import" />
					<div class="rses-field-grid">
						<div class="rses-field rses-field-full rses-file-field">
							<label for="rses_import_file"><?php esc_html_e( 'Voting Export (ZIP recommended)', 'relatasoft-secure-election-suite' ); ?></label>
							<input type="file" name="rses_import_file" id="rses_import_file" accept=".zip,.json,application/zip,application/json" required />
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
					<p class="rses-panel-desc"><?php esc_html_e( 'Each row is one closed election/round package. Verified packages appear on Share Submission for officials.', 'relatasoft-secure-election-suite' ); ?></p>
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
									<th><?php esc_html_e( 'Election', 'relatasoft-secure-election-suite' ); ?></th>
									<th><?php esc_html_e( 'Round', 'relatasoft-secure-election-suite' ); ?></th>
									<th><?php esc_html_e( 'Ballots', 'relatasoft-secure-election-suite' ); ?></th>
									<th><?php esc_html_e( 'Source', 'relatasoft-secure-election-suite' ); ?></th>
									<th><?php esc_html_e( 'Status', 'relatasoft-secure-election-suite' ); ?></th>
									<th><?php esc_html_e( 'Imported', 'relatasoft-secure-election-suite' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $rses_imports as $rses_imp ) : ?>
									<tr>
										<td><?php echo esc_html( (string) $rses_imp->id ); ?></td>
										<td>
											<strong><?php echo esc_html( TallyImportRepository::rses_display_election_title( $rses_imp ) ); ?></strong>
											<?php if ( ! empty( $rses_imp->election_external_id ) ) : ?>
												<br /><span class="description">#<?php echo esc_html( (string) $rses_imp->election_external_id ); ?></span>
											<?php endif; ?>
										</td>
										<td>
											<?php
											$rses_round_label = TallyImportRepository::rses_display_round_title( $rses_imp );
											echo esc_html( '' !== $rses_round_label ? $rses_round_label : '—' );
											?>
											<?php if ( ! empty( $rses_imp->round_external_id ) ) : ?>
												<br /><span class="description">#<?php echo esc_html( (string) $rses_imp->round_external_id ); ?></span>
											<?php endif; ?>
										</td>
										<td>
											<?php
											echo isset( $rses_imp->ballot_count ) && null !== $rses_imp->ballot_count
												? esc_html( (string) (int) $rses_imp->ballot_count )
												: '—';
											?>
										</td>
										<td><?php echo esc_html( $rses_imp->source_site_url ?? '—' ); ?></td>
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

		TallyImportRepository::rses_backfill_summaries();
		$rses_imports = TallyImportRepository::rses_list();
		$rses_user_id = get_current_user_id();
		$rses_flash_import = isset( $_GET['import'] ) ? absint( $_GET['import'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap rses-wrap rses-screen" <?php echo Translator::rses_html_attrs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<header class="rses-hero">
				<?php Brand::rses_render_hero_brand(); ?>

				<p class="rses-hero-kicker"><?php esc_html_e( 'Tallying', 'relatasoft-secure-election-suite' ); ?></p>
				<h1 class="rses-hero-title"><?php esc_html_e( 'Official Share Submission', 'relatasoft-secure-election-suite' ); ?></h1>
				<p class="rses-hero-lead"><?php
					echo esc_html(
						sprintf(
							/* translators: %s: electoral authority role label (singular) */
							__( 'Each imported election has its own card. Every %s pastes one Shamir fraction per election (N elections ⇒ N fractions). Fractions are matched by public-key fingerprint and source site — key labels may be identical across servers.', 'relatasoft-secure-election-suite' ),
							RoleLabels::rses_editor_singular()
						)
					);
				?></p>
			</header>

			<?php if ( ! empty( $_GET['rses_submitted'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="rses-panel rses-panel-success">
					<p>
						<?php
						if ( $rses_flash_import > 0 ) {
							$rses_flash_row = TallyImportRepository::rses_get( $rses_flash_import );
							echo esc_html(
								sprintf(
									/* translators: %s: election title */
									__( 'Your Shamir fraction for “%s” was submitted successfully.', 'relatasoft-secure-election-suite' ),
									$rses_flash_row
										? TallyImportRepository::rses_display_election_title( $rses_flash_row )
										: ( '#' . $rses_flash_import )
								)
							);
						} else {
							esc_html_e( 'Your Shamir share was submitted successfully.', 'relatasoft-secure-election-suite' );
						}
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $_GET['rses_shares_cleared'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="rses-panel rses-panel-success">
					<p>
						<?php
						$rses_cleared_count = isset( $_GET['count'] ) ? absint( $_GET['count'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
						$rses_cleared_row   = $rses_flash_import > 0 ? TallyImportRepository::rses_get( $rses_flash_import ) : null;
						echo esc_html(
							sprintf(
								/* translators: 1: number of fractions cleared, 2: election title */
								__( 'Cleared %1$d submitted Shamir fraction(s) for “%2$s”. Officials may submit again for this election.', 'relatasoft-secure-election-suite' ),
								$rses_cleared_count,
								$rses_cleared_row
									? TallyImportRepository::rses_display_election_title( $rses_cleared_row )
									: ( $rses_flash_import > 0 ? '#' . $rses_flash_import : __( 'this election', 'relatasoft-secure-election-suite' ) )
							)
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<div class="rses-panel rses-panel-info">
				<p><?php esc_html_e( 'Only verified imported election packages appear below. Open the card for the correct election, compare fingerprint / source site with your fraction JSON, then paste. Never paste another official’s fraction, and never reuse a fraction from a different election.', 'relatasoft-secure-election-suite' ); ?></p>
			</div>

			<?php
			$rses_any = false;
			foreach ( $rses_imports as $rses_imp ) :
				if ( 'verified' !== $rses_imp->status ) {
					continue;
				}
				$rses_any        = true;
				$rses_manifest   = TallyImportRepository::rses_get_manifest( $rses_imp );
				$rses_threshold  = (int) ( $rses_manifest['round']['threshold_t'] ?? $rses_manifest['manifest']['threshold_t'] ?? 3 );
				$rses_submitted  = OfficialShareSubmissionController::rses_get_submission_count( (int) $rses_imp->id );
				$rses_pct        = min( 100, ( $rses_submitted / max( 1, $rses_threshold ) ) * 100 );
				$rses_key        = TallyImportRepository::rses_key_identity( $rses_imp, $rses_manifest );
				$rses_form_key   = $rses_key['key_id'] > 0
					? $rses_key['key_id']
					: (int) ( $rses_manifest['round']['key_id'] ?? 0 );
				$rses_mine       = OfficialShareSubmissionController::rses_get_official_submission( (int) $rses_imp->id, $rses_user_id );
				$rses_title      = TallyImportRepository::rses_display_election_title( $rses_imp );
				$rses_round      = TallyImportRepository::rses_display_round_title( $rses_imp );
				?>
				<article class="rses-import-card" id="rses-share-election-<?php echo esc_attr( (string) $rses_imp->id ); ?>">
					<header class="rses-import-card-header">
						<p class="rses-panel-kicker"><?php esc_html_e( 'Imported election', 'relatasoft-secure-election-suite' ); ?></p>
						<h3><?php echo esc_html( $rses_title ); ?></h3>
						<?php if ( '' !== $rses_round ) : ?>
							<p class="rses-panel-desc"><strong><?php echo esc_html( $rses_round ); ?></strong></p>
						<?php endif; ?>
						<?php echo self::rses_import_election_meta_html( $rses_imp, $rses_manifest ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<ul class="rses-panel-desc rses-share-bind-list">
							<li>
								<strong><?php esc_html_e( 'Import', 'relatasoft-secure-election-suite' ); ?>:</strong>
								#<?php echo esc_html( (string) $rses_imp->id ); ?>
							</li>
							<li>
								<strong><?php esc_html_e( 'Source site', 'relatasoft-secure-election-suite' ); ?>:</strong>
								<?php echo esc_html( $rses_key['source_site_url'] !== '' ? $rses_key['source_site_url'] : '—' ); ?>
							</li>
							<li>
								<strong><?php esc_html_e( 'Public-key fingerprint', 'relatasoft-secure-election-suite' ); ?>:</strong>
								<code><?php echo esc_html( $rses_key['fingerprint'] !== '' ? $rses_key['fingerprint'] : '—' ); ?></code>
								<?php if ( '' !== $rses_key['public_y_prefix'] ) : ?>
									<span class="description">(y <?php echo esc_html( $rses_key['public_y_prefix'] ); ?>…)</span>
								<?php endif; ?>
							</li>
							<li>
								<strong><?php esc_html_e( 'Key label', 'relatasoft-secure-election-suite' ); ?>:</strong>
								<?php
								echo esc_html(
									'' !== $rses_key['key_label']
										? $rses_key['key_label']
										: __( '(not in package)', 'relatasoft-secure-election-suite' )
								);
								?>
								<span class="description">— <?php esc_html_e( 'labels may repeat across servers; do not match by label alone', 'relatasoft-secure-election-suite' ); ?></span>
							</li>
						</ul>
					</header>
					<div class="rses-import-card-body">
						<div class="rses-threshold-meta">
							<span><?php esc_html_e( 'Threshold progress for this election', 'relatasoft-secure-election-suite' ); ?></span>
							<strong><?php echo esc_html( (string) $rses_submitted ); ?> / <?php echo esc_html( (string) $rses_threshold ); ?></strong>
						</div>
						<div class="rses-progress-bar" role="progressbar" aria-valuenow="<?php echo esc_attr( (string) (int) $rses_pct ); ?>" aria-valuemin="0" aria-valuemax="100">
							<div class="rses-progress-fill" style="width: <?php echo esc_attr( (string) $rses_pct ); ?>%"></div>
						</div>

						<?php if ( $rses_mine ) : ?>
							<div class="rses-panel rses-panel-success">
								<p>
									<?php
									echo esc_html(
										sprintf(
											/* translators: 1: election title, 2: share index */
											__( 'You already submitted fraction index %2$d for “%1$s”. Submit a different fraction under another election card if you hold more.', 'relatasoft-secure-election-suite' ),
											$rses_title,
											(int) $rses_mine->share_index
										)
									);
									?>
								</p>
							</div>
						<?php else : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rses-form">
								<?php Nonce::rses_field( Nonce::RSES_ACTION_SHARE_SUBMIT ); ?>
								<input type="hidden" name="action" value="rses_submit_share" />
								<input type="hidden" name="tally_import_id" value="<?php echo esc_attr( (string) $rses_imp->id ); ?>" />
								<input type="hidden" name="key_id" value="<?php echo esc_attr( (string) $rses_form_key ); ?>" />
								<input type="hidden" name="election_round_id" value="<?php echo esc_attr( (string) ( $rses_manifest['round']['id'] ?? 0 ) ); ?>" />
								<div class="rses-field-grid">
									<div class="rses-field rses-field-full">
										<label for="rses_share_json_<?php echo esc_attr( (string) $rses_imp->id ); ?>">
											<?php
											echo esc_html(
												sprintf(
													/* translators: %s: election title */
													__( 'Shamir fraction JSON for “%s”', 'relatasoft-secure-election-suite' ),
													$rses_title
												)
											);
											?>
										</label>
										<textarea
											name="rses_share_json"
											id="rses_share_json_<?php echo esc_attr( (string) $rses_imp->id ); ?>"
											rows="8"
											class="rses-code-area"
											required
											placeholder='{"rses_package":"shamir-share-v1","public_key_fingerprint":"…","source_site":"…","share":{…}}'
										></textarea>
										<p class="description">
											<?php
											echo esc_html(
												sprintf(
													/* translators: %s: fingerprint */
													__( 'Must match fingerprint %s for this imported election. Wrong election ⇒ rejected.', 'relatasoft-secure-election-suite' ),
													$rses_key['fingerprint'] !== '' ? $rses_key['fingerprint'] : '—'
												)
											);
											?>
										</p>
									</div>
								</div>
								<p class="rses-form-actions">
									<?php
									submit_button(
										sprintf(
											/* translators: %s: election title */
											__( 'Submit fraction for “%s”', 'relatasoft-secure-election-suite' ),
											$rses_title
										),
										'primary rses-btn-primary',
										'submit',
										false
									);
									?>
								</p>
							</form>
						<?php endif; ?>

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

						<?php if ( $rses_submitted > 0 && Capability::rses_can_tally_and_certify() ) : ?>
							<form
								method="post"
								action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
								class="rses-form rses-clear-shares-form"
								onsubmit="return confirm('<?php echo esc_js( sprintf( __( 'Clear all %1$d submitted Shamir fraction(s) for “%2$s”? Officials will need to submit again. Cached decryption for this election will also be discarded.', 'relatasoft-secure-election-suite' ), $rses_submitted, $rses_title ) ); ?>');"
							>
								<?php Nonce::rses_field( Nonce::RSES_ACTION_SHARE_CLEAR ); ?>
								<input type="hidden" name="action" value="rses_clear_shares" />
								<input type="hidden" name="tally_import_id" value="<?php echo esc_attr( (string) $rses_imp->id ); ?>" />
								<p class="rses-form-actions">
									<?php
									submit_button(
										sprintf(
											/* translators: %s: election title */
											__( 'Clear all fractions for “%s”', 'relatasoft-secure-election-suite' ),
											$rses_title
										),
										'delete',
										'submit',
										false
									);
									?>
								</p>
								<p class="description"><?php esc_html_e( 'Administrators only. Use this to undo mistaken submissions for this imported election.', 'relatasoft-secure-election-suite' ); ?></p>
							</form>
						<?php endif; ?>
					</div>
				</article>
			<?php endforeach; ?>

			<?php if ( ! $rses_any ) : ?>
				<div class="rses-panel rses-panel-info">
					<p><?php esc_html_e( 'No verified imports yet. Ask an Administrator to import a voting package first — fraction submission is only available for loaded elections.', 'relatasoft-secure-election-suite' ); ?></p>
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

		TallyImportRepository::rses_backfill_summaries();
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
						<h3><?php echo esc_html( TallyImportRepository::rses_display_election_title( $rses_imp ) ); ?></h3>
						<?php echo self::rses_import_election_meta_html( $rses_imp ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<p class="rses-panel-desc">
							<?php echo self::rses_status_pill( (string) $rses_imp->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<span class="description"> · <?php esc_html_e( 'Import', 'relatasoft-secure-election-suite' ); ?> #<?php echo esc_html( (string) $rses_imp->id ); ?></span>
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
