<?php
/**
 * Key Authority admin views.
 *
 * @package RelataSoft\SecureElectionSuite\KeyAuthority
 */

namespace RelataSoft\SecureElectionSuite\KeyAuthority;

use RelataSoft\SecureElectionSuite\Bootstrap\Plugin;
use RelataSoft\SecureElectionSuite\Security\Capability;
use RelataSoft\SecureElectionSuite\Security\Escaper;
use RelataSoft\SecureElectionSuite\Security\Nonce;
use RelataSoft\SecureElectionSuite\I18n\RoleLabels;
use RelataSoft\SecureElectionSuite\I18n\Translator;
use RelataSoft\SecureElectionSuite\Admin\Brand;

defined( 'ABSPATH' ) || exit;

/**
 * Renders Key Authority admin pages.
 */
class KeyAuthorityViews {

	/**
	 * Render Key Authority dashboard.
	 */
	public static function rses_render_dashboard(): void {
		Capability::rses_require_official();

		if ( ! Capability::rses_can_manage_election() ) {
			self::rses_render_official_shares();
			return;
		}

		$rses_keys      = KeyRepository::rses_list_active();
		$rses_officials = get_users( array( 'role__in' => array( 'editor', 'administrator' ) ) );
		$rses_settings  = get_option( 'rses_settings', array() );
		?>
		<div class="wrap rses-wrap rses-ka" <?php echo Translator::rses_html_attrs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<header class="rses-ka-hero">
				<?php Brand::rses_render_hero_brand(); ?>

				<p class="rses-ka-kicker"><?php esc_html_e( 'Key Authority', 'relatasoft-secure-election-suite' ); ?></p>
				<h1 class="rses-ka-title"><?php esc_html_e( 'ElGamal Key Manager', 'relatasoft-secure-election-suite' ); ?></h1>
				<p class="rses-ka-lead"><?php
					echo esc_html(
						sprintf(
							/* translators: %s: electoral authority role label (plural) */
							__( 'Generate keys, assign Shamir shares to %s, import public parameters, and export packages for Voting / Tallying sites.', 'relatasoft-secure-election-suite' ),
							RoleLabels::rses_editor_plural()
						)
					);
				?></p>
			</header>

			<?php if ( ! empty( $_GET['rses_mode_set'] ) || ! empty( $_GET['rses_key_created'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="rses-panel rses-panel-success">
					<p>
						<?php
						if ( ! empty( $_GET['rses_key_created'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
							esc_html_e( 'Key generated and Shamir shares assigned (when officials were selected).', 'relatasoft-secure-election-suite' );
						} else {
							esc_html_e( 'Mode locked. Generate an ElGamal key below.', 'relatasoft-secure-election-suite' );
						}
						?>
					</p>
				</div>
			<?php endif; ?>

			<div class="rses-panel rses-panel-warning">
				<p><?php esc_html_e( 'Private keys are split into Shamir Secret Sharing shares immediately. Full private keys are not persisted by default.', 'relatasoft-secure-election-suite' ); ?></p>
			</div>

			<?php if ( empty( $rses_officials ) ) : ?>
				<div class="rses-panel rses-panel-warning">
					<p><?php
						echo esc_html(
							sprintf(
								/* translators: %s: electoral authority role label (singular) */
								__( 'No %1$s accounts found. Create WordPress users with the %1$s role before assigning Shamir shares.', 'relatasoft-secure-election-suite' ),
								RoleLabels::rses_editor_singular()
							)
						);
					?></p>
				</div>
			<?php endif; ?>

			<?php
			// Failsafe: register/enqueue during render if admin_enqueue_scripts missed this screen.
			Plugin::rses_enqueue_key_authority_script();
			?>

			<section class="rses-panel rses-panel-card" id="rses-ka-generate">
				<header class="rses-panel-header">
					<p class="rses-panel-kicker"><?php esc_html_e( 'Generate', 'relatasoft-secure-election-suite' ); ?></p>
					<h2 class="rses-panel-title"><?php esc_html_e( 'New ElGamal key & share assignment', 'relatasoft-secure-election-suite' ); ?></h2>
					<p class="rses-panel-desc"><?php esc_html_e( 'Chunked AJAX generation (≤25s per step) with a live progress bar so PHP time limits are not exceeded.', 'relatasoft-secure-election-suite' ); ?></p>
				</header>

				<div id="rses-keygen-progress" class="rses-keygen-progress is-idle" hidden>
					<div class="rses-panel rses-panel-info rses-keygen-progress-inner">
						<p id="rses-keygen-message"><?php esc_html_e( 'Preparing…', 'relatasoft-secure-election-suite' ); ?></p>
						<div class="rses-progress-bar rses-keygen-bar">
							<div id="rses-keygen-bar-fill" class="rses-progress-fill" style="width:0%"></div>
						</div>
						<p class="rses-keygen-meta">
							<span id="rses-keygen-percent">0%</span>
							— <span id="rses-keygen-stage"></span>
							— <span id="rses-keygen-attempts"></span>
						</p>
						<p>
							<button type="button" class="button rses-btn-secondary" id="rses-keygen-cancel"><?php esc_html_e( 'Cancel', 'relatasoft-secure-election-suite' ); ?></button>
						</p>
					</div>
				</div>

				<form
					method="post"
					action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
					id="rses-keygen-form"
					class="rses-form rses-ka-form"
					data-rses-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
					data-rses-nonce="<?php echo esc_attr( wp_create_nonce( 'rses_keygen' ) ); ?>"
					data-rses-done-url="<?php echo esc_url( admin_url( 'admin.php?page=rses-key-authority' ) ); ?>"
				>
					<?php Nonce::rses_field( Nonce::RSES_ACTION_KEY_GENERATE ); ?>
					<input type="hidden" name="action" value="rses_keygen_start" />

					<div class="rses-field-grid">
						<div class="rses-field">
							<label for="rses_key_label"><?php esc_html_e( 'Key Label', 'relatasoft-secure-election-suite' ); ?></label>
							<input type="text" name="rses_key_label" id="rses_key_label" class="regular-text" required />
						</div>
						<div class="rses-field">
							<label for="rses_key_size"><?php esc_html_e( 'Key Size (bits)', 'relatasoft-secure-election-suite' ); ?></label>
							<select name="rses_key_size" id="rses_key_size">
								<option value="512"><?php esc_html_e( '512 (local testing only)', 'relatasoft-secure-election-suite' ); ?></option>
								<option value="1024"><?php esc_html_e( '1024 (development)', 'relatasoft-secure-election-suite' ); ?></option>
								<option value="2048" selected><?php esc_html_e( '2048 (recommended minimum)', 'relatasoft-secure-election-suite' ); ?></option>
								<option value="3072"><?php esc_html_e( '3072 (stronger — chunked)', 'relatasoft-secure-election-suite' ); ?></option>
								<option value="4096"><?php esc_html_e( '4096 (strongest — chunked)', 'relatasoft-secure-election-suite' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'After you click Generate, a progress bar appears above this form.', 'relatasoft-secure-election-suite' ); ?></p>
						</div>
						<div class="rses-field">
							<label for="rses_threshold_t"><?php esc_html_e( 'Shamir Threshold (t)', 'relatasoft-secure-election-suite' ); ?></label>
							<input type="number" name="rses_threshold_t" id="rses_threshold_t" value="3" min="2" />
							<p class="description"><?php esc_html_e( 'Total shares (n) is set automatically from the number of officials you select.', 'relatasoft-secure-election-suite' ); ?></p>
						</div>
						<div class="rses-field rses-field-full">
							<span class="rses-field-label"><?php esc_html_e( 'Assign Officials', 'relatasoft-secure-election-suite' ); ?></span>
							<?php if ( empty( $rses_officials ) ) : ?>
								<em><?php esc_html_e( 'No officials available.', 'relatasoft-secure-election-suite' ); ?></em>
							<?php else : ?>
								<div class="rses-official-grid">
									<?php foreach ( $rses_officials as $rses_user ) : ?>
										<?php $rses_oid = 'rses-official-' . (int) $rses_user->ID; ?>
										<label class="rses-official-choice" for="<?php echo esc_attr( $rses_oid ); ?>">
											<input type="checkbox" name="rses_officials[]" id="<?php echo esc_attr( $rses_oid ); ?>" value="<?php echo esc_attr( (string) $rses_user->ID ); ?>" />
											<span class="rses-official-selector" aria-hidden="true"></span>
											<span class="rses-official-meta">
												<strong><?php echo esc_html( $rses_user->display_name ); ?></strong>
												<small><?php echo esc_html( $rses_user->user_login ); ?></small>
											</span>
										</label>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
						</div>
						<div class="rses-field rses-field-full">
							<label for="rses_key_description"><?php esc_html_e( 'Description', 'relatasoft-secure-election-suite' ); ?></label>
							<textarea name="rses_key_description" id="rses_key_description" rows="3" class="large-text"></textarea>
						</div>
						<div class="rses-field">
							<label for="rses_attachment_id"><?php esc_html_e( 'Media Attachment ID', 'relatasoft-secure-election-suite' ); ?></label>
							<input type="number" name="rses_attachment_id" id="rses_attachment_id" min="0" />
						</div>
					</div>

					<p class="rses-form-actions">
						<?php submit_button( __( 'Generate Key & Assign Shares', 'relatasoft-secure-election-suite' ), 'primary rses-btn-primary', 'rses_keygen_submit', false ); ?>
					</p>
				</form>
			</section>

			<section class="rses-panel rses-panel-card" id="rses-ka-import">
				<header class="rses-panel-header">
					<p class="rses-panel-kicker"><?php esc_html_e( 'Import', 'relatasoft-secure-election-suite' ); ?></p>
					<h2 class="rses-panel-title"><?php esc_html_e( 'Import public key', 'relatasoft-secure-election-suite' ); ?></h2>
					<p class="rses-panel-desc"><?php esc_html_e( 'Paste a public-key.json export from another Key Authority site, or a package that includes a public_key object.', 'relatasoft-secure-election-suite' ); ?></p>
				</header>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rses-form rses-ka-form">
					<?php Nonce::rses_field( Nonce::RSES_ACTION_KEY_IMPORT ); ?>
					<input type="hidden" name="action" value="rses_import_key" />
					<div class="rses-field-grid">
						<div class="rses-field">
							<label for="rses_import_label"><?php esc_html_e( 'Label', 'relatasoft-secure-election-suite' ); ?></label>
							<input type="text" name="rses_import_label" id="rses_import_label" class="regular-text" />
						</div>
						<div class="rses-field rses-field-full">
							<label for="rses_import_json"><?php esc_html_e( 'Public Key JSON', 'relatasoft-secure-election-suite' ); ?></label>
							<textarea name="rses_import_json" id="rses_import_json" rows="8" class="large-text code rses-code-area" required></textarea>
						</div>
					</div>
					<p class="rses-form-actions">
						<?php submit_button( __( 'Import Key', 'relatasoft-secure-election-suite' ), 'secondary rses-btn-secondary', 'submit', false ); ?>
					</p>
				</form>
			</section>

			<section class="rses-panel rses-panel-card" id="rses-ka-export">
				<header class="rses-panel-header">
					<p class="rses-panel-kicker"><?php esc_html_e( 'Export', 'relatasoft-secure-election-suite' ); ?></p>
					<h2 class="rses-panel-title"><?php esc_html_e( 'Key cards & exports', 'relatasoft-secure-election-suite' ); ?></h2>
					<p class="rses-panel-desc"><?php esc_html_e( 'Export public ZIP/JSON packages for Voting sites, or your own Shamir share if one is assigned to this account.', 'relatasoft-secure-election-suite' ); ?></p>
				</header>

				<?php if ( empty( $rses_keys ) ) : ?>
					<p class="rses-empty"><?php esc_html_e( 'No keys generated yet.', 'relatasoft-secure-election-suite' ); ?></p>
				<?php else : ?>
					<div class="rses-key-card-list">
						<?php foreach ( $rses_keys as $rses_key ) : ?>
							<?php self::rses_render_key_card( $rses_key, $rses_settings ); ?>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</section>
		</div>
		<?php
	}

	/**
	 * Official-only view: see, copy, and download own Shamir share.
	 */
	private static function rses_render_official_shares(): void {
		$rses_user_id = get_current_user_id();
		$rses_keys    = KeyRepository::rses_list_active();
		?>
		<div class="wrap rses-wrap rses-ka" <?php echo Translator::rses_html_attrs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<header class="rses-ka-hero">
				<?php Brand::rses_render_hero_brand(); ?>

				<p class="rses-ka-kicker"><?php esc_html_e( 'Key Authority', 'relatasoft-secure-election-suite' ); ?></p>
				<h1 class="rses-ka-title"><?php esc_html_e( 'My Shamir Shares', 'relatasoft-secure-election-suite' ); ?></h1>
				<p class="rses-ka-lead"><?php
					echo esc_html(
						sprintf(
							/* translators: %s: electoral authority role label (singular) */
							__( 'View, copy, and download the share assigned to your %s account. Keep it offline for Tallying.', 'relatasoft-secure-election-suite' ),
							RoleLabels::rses_editor_singular()
						)
					);
				?></p>
			</header>

			<div class="rses-panel rses-panel-warning">
				<p><?php esc_html_e( 'Store your share offline and keep it confidential. You will paste this same JSON on the Tallying site when submitting your share. Never share it with other officials.', 'relatasoft-secure-election-suite' ); ?></p>
			</div>

			<?php
			$rses_found = false;
			foreach ( $rses_keys as $rses_key ) {
				$rses_share = KeyRepository::rses_get_share_for_user( (int) $rses_key->id, $rses_user_id );
				if ( ! $rses_share ) {
					continue;
				}
				$rses_found   = true;
				$rses_payload = KeyExportService::rses_package_own_share( (int) $rses_key->id );
				$rses_json    = $rses_payload
					? (string) wp_json_encode( $rses_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
					: '';
				$rses_ta_id   = 'rses-own-share-' . (int) $rses_key->id;
				$rses_linked  = KeyExportService::rses_linked_election_labels( (int) $rses_key->id );
				?>
				<section class="rses-panel rses-panel-card rses-key-card rses-official-share-card">
					<header class="rses-panel-header">
						<p class="rses-panel-kicker"><?php esc_html_e( 'Assigned share', 'relatasoft-secure-election-suite' ); ?></p>
						<h2 class="rses-panel-title">
							<?php echo esc_html( $rses_key->key_label ); ?>
							<small>#<?php echo esc_html( (string) $rses_key->id ); ?></small>
						</h2>
						<p class="rses-panel-desc">
							<?php
							printf(
								/* translators: %d: share index */
								esc_html__( 'Your share index: %d', 'relatasoft-secure-election-suite' ),
								(int) $rses_share->share_index
							);
							?>
						</p>
						<?php if ( ! empty( $rses_key->description ) ) : ?>
							<p class="rses-panel-desc"><?php echo esc_html( (string) $rses_key->description ); ?></p>
						<?php endif; ?>
						<?php if ( ! empty( $rses_linked ) ) : ?>
							<p class="rses-panel-desc">
								<strong><?php esc_html_e( 'Linked elections', 'relatasoft-secure-election-suite' ); ?>:</strong>
								<?php echo esc_html( implode( '; ', $rses_linked ) ); ?>
							</p>
						<?php else : ?>
							<p class="rses-panel-desc"><?php esc_html_e( 'No elections on this site use this key yet. On the Voting site, open Public Keys to see which elections are linked; match this key label when submitting on Tallying.', 'relatasoft-secure-election-suite' ); ?></p>
						<?php endif; ?>
					</header>

					<?php if ( '' !== $rses_json ) : ?>
						<label class="rses-field-label" for="<?php echo esc_attr( $rses_ta_id ); ?>"><?php esc_html_e( 'Your share JSON (view / copy)', 'relatasoft-secure-election-suite' ); ?></label>
						<textarea
							id="<?php echo esc_attr( $rses_ta_id ); ?>"
							class="large-text code rses-share-json-view rses-code-area"
							rows="12"
							readonly
						><?php echo esc_textarea( $rses_json ); ?></textarea>
						<p class="rses-share-actions rses-key-actions">
							<button
								type="button"
								class="button button-primary rses-btn-primary rses-copy-share"
								data-rses-target="<?php echo esc_attr( $rses_ta_id ); ?>"
								data-copied-label="<?php echo esc_attr__( 'Copied!', 'relatasoft-secure-election-suite' ); ?>"
							>
								<?php esc_html_e( 'Copy Share JSON', 'relatasoft-secure-election-suite' ); ?>
							</button>
							<a class="button rses-btn-secondary" href="<?php echo esc_url( Nonce::rses_url( admin_url( 'admin-post.php?action=rses_export_key&key_id=' . $rses_key->id . '&format=json&own_share=1' ), Nonce::RSES_ACTION_KEY_EXPORT ) ); ?>">
								<?php esc_html_e( 'Download Share JSON', 'relatasoft-secure-election-suite' ); ?>
							</a>
							<a class="button rses-btn-secondary" href="<?php echo esc_url( Nonce::rses_url( admin_url( 'admin-post.php?action=rses_export_key&key_id=' . $rses_key->id . '&format=zip&own_share=1' ), Nonce::RSES_ACTION_KEY_EXPORT ) ); ?>">
								<?php esc_html_e( 'Download Share ZIP', 'relatasoft-secure-election-suite' ); ?>
							</a>
							<a class="button rses-btn-secondary" href="<?php echo esc_url( Nonce::rses_url( admin_url( 'admin-post.php?action=rses_export_key&key_id=' . $rses_key->id . '&format=json' ), Nonce::RSES_ACTION_KEY_EXPORT ) ); ?>">
								<?php esc_html_e( 'Download Public Key JSON', 'relatasoft-secure-election-suite' ); ?>
							</a>
						</p>
					<?php else : ?>
						<p><?php esc_html_e( 'Unable to decrypt your stored share for display. Try downloading the ZIP export, or contact the election administrator.', 'relatasoft-secure-election-suite' ); ?></p>
					<?php endif; ?>
				</section>
				<?php
			}

			if ( ! $rses_found ) {
				echo '<div class="rses-panel rses-panel-info"><p>' . esc_html__( 'No Shamir shares are assigned to your account yet. Ask the election administrator to generate a key and assign you as an official.', 'relatasoft-secure-election-suite' ) . '</p></div>';
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render a single key card.
	 *
	 * @param object              $key      Key row.
	 * @param array<string,mixed> $settings Settings.
	 */
	private static function rses_render_key_card( object $key, array $settings ): void {
		$rses_shares      = KeyRepository::rses_get_shares( (int) $key->id );
		$rses_attachments = json_decode( $key->attachments ?? '[]', true ) ?: array();
		$rses_linked      = KeyExportService::rses_linked_election_labels( (int) $key->id );
		?>
		<article class="rses-key-card rses-export-card">
			<header class="rses-key-card-header">
				<h3 class="rses-key-card-title">
					<?php echo esc_html( $key->key_label ); ?>
					<small>#<?php echo esc_html( (string) $key->id ); ?></small>
				</h3>
				<p class="rses-key-card-meta">
					<?php
					printf(
						/* translators: %d: key size in bits */
						esc_html__( '%d-bit ElGamal', 'relatasoft-secure-election-suite' ),
						(int) $key->key_size
					);
					?>
					·
					<?php echo (int) $key->private_key_persisted ? esc_html__( 'Private persisted', 'relatasoft-secure-election-suite' ) : esc_html__( 'Shamir shares only', 'relatasoft-secure-election-suite' ); ?>
				</p>
			</header>

			<table class="rses-key-meta-table">
				<tr><th><?php esc_html_e( 'Key Size', 'relatasoft-secure-election-suite' ); ?></th><td><?php echo esc_html( (string) $key->key_size ); ?> bits</td></tr>
				<?php if ( ! empty( $key->description ) ) : ?>
					<tr><th><?php esc_html_e( 'Description', 'relatasoft-secure-election-suite' ); ?></th><td><?php echo esc_html( (string) $key->description ); ?></td></tr>
				<?php endif; ?>
				<tr><th><?php esc_html_e( 'Linked elections', 'relatasoft-secure-election-suite' ); ?></th>
					<td>
						<?php
						if ( empty( $rses_linked ) ) {
							esc_html_e( 'None on this site (assign this key when creating an election on the Voting platform).', 'relatasoft-secure-election-suite' );
						} else {
							echo esc_html( implode( '; ', $rses_linked ) );
						}
						?>
					</td>
				</tr>
				<tr><th>p</th><td><code class="rses-bigint"><?php echo esc_html( substr( $key->public_p, 0, 64 ) . '...' ); ?></code></td></tr>
				<tr><th>q</th><td><code class="rses-bigint"><?php echo esc_html( substr( $key->public_q, 0, 64 ) . '...' ); ?></code></td></tr>
				<tr><th>g</th><td><code class="rses-bigint"><?php echo esc_html( substr( $key->public_g, 0, 64 ) . '...' ); ?></code></td></tr>
				<tr><th>y</th><td><code class="rses-bigint"><?php echo esc_html( substr( $key->public_y, 0, 64 ) . '...' ); ?></code></td></tr>
				<tr><th><?php esc_html_e( 'Shamir Threshold', 'relatasoft-secure-election-suite' ); ?></th><td><?php echo esc_html( (string) ( $key->threshold_t ?? '-' ) ); ?> / <?php echo esc_html( (string) ( $key->total_n ?? '-' ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Assigned Officials', 'relatasoft-secure-election-suite' ); ?></th>
					<td>
						<?php
						if ( empty( $rses_shares ) ) {
							esc_html_e( 'None', 'relatasoft-secure-election-suite' );
						} else {
							foreach ( $rses_shares as $rses_share ) {
								$rses_user = get_userdata( (int) $rses_share->official_user_id );
								echo esc_html(
									sprintf(
										/* translators: 1: official display name, 2: share index */
										__( '%1$s (fraction %2$d)', 'relatasoft-secure-election-suite' ),
										$rses_user ? $rses_user->display_name : '#' . $rses_share->official_user_id,
										(int) $rses_share->share_index
									)
								);
								echo '<br />';
							}
						}
						?>
					</td>
				</tr>
				<?php if ( ! empty( $rses_attachments ) ) : ?>
					<tr><th><?php esc_html_e( 'Attachments', 'relatasoft-secure-election-suite' ); ?></th>
						<td>
							<?php foreach ( $rses_attachments as $rses_att_id ) : ?>
								<a href="<?php echo esc_url( Escaper::rses_attachment_url( (int) $rses_att_id ) ); ?>" target="_blank" rel="noopener noreferrer">
									<?php echo esc_html( sprintf( __( 'Attachment #%d', 'relatasoft-secure-election-suite' ), (int) $rses_att_id ) ); ?>
								</a><br />
							<?php endforeach; ?>
						</td>
					</tr>
				<?php endif; ?>
				<tr><th><?php esc_html_e( 'Created', 'relatasoft-secure-election-suite' ); ?></th>
					<td><?php echo esc_html( $key->created_at ); ?> <?php
					$rses_creator = get_userdata( (int) $key->created_by );
					if ( $rses_creator ) {
						echo esc_html( 'by ' . $rses_creator->display_name );
					}
					?></td></tr>
			</table>

			<div class="rses-key-actions">
				<a class="button button-primary rses-btn-primary" href="<?php echo esc_url( Nonce::rses_url( admin_url( 'admin-post.php?action=rses_export_key&key_id=' . $key->id . '&format=zip' ), Nonce::RSES_ACTION_KEY_EXPORT ) ); ?>">
					<?php esc_html_e( 'Export ZIP', 'relatasoft-secure-election-suite' ); ?>
				</a>
				<a class="button rses-btn-secondary" href="<?php echo esc_url( Nonce::rses_url( admin_url( 'admin-post.php?action=rses_export_key&key_id=' . $key->id . '&format=json' ), Nonce::RSES_ACTION_KEY_EXPORT ) ); ?>">
					<?php esc_html_e( 'Export Public JSON', 'relatasoft-secure-election-suite' ); ?>
				</a>
				<?php
				$rses_own = KeyRepository::rses_get_share_for_user( (int) $key->id, get_current_user_id() );
				if ( $rses_own ) :
					?>
					<a class="button rses-btn-secondary" href="<?php echo esc_url( Nonce::rses_url( admin_url( 'admin-post.php?action=rses_export_key&key_id=' . $key->id . '&format=zip&own_share=1' ), Nonce::RSES_ACTION_KEY_EXPORT ) ); ?>">
						<?php esc_html_e( 'Export My Share', 'relatasoft-secure-election-suite' ); ?>
					</a>
				<?php endif; ?>
				<?php if ( ! empty( $settings['allow_full_private_export'] ) ) : ?>
					<a class="button button-warning" href="<?php echo esc_url( Nonce::rses_url( admin_url( 'admin-post.php?action=rses_export_key&key_id=' . $key->id . '&format=zip&full=1&rses_confirm_full=1' ), Nonce::RSES_ACTION_KEY_EXPORT ) ); ?>"
						onclick="return confirm('<?php echo esc_js( __( 'WARNING: This exports the full private key. Continue?', 'relatasoft-secure-election-suite' ) ); ?>');">
						<?php esc_html_e( 'Full Export (Admin)', 'relatasoft-secure-election-suite' ); ?>
					</a>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rses-inline-form">
					<?php Nonce::rses_field( Nonce::RSES_ACTION_KEY_EXPORT ); ?>
					<input type="hidden" name="action" value="rses_key_action" />
					<input type="hidden" name="key_id" value="<?php echo esc_attr( (string) $key->id ); ?>" />
					<input type="hidden" name="rses_key_action" value="trash" />
					<?php submit_button( __( 'Trash', 'relatasoft-secure-election-suite' ), 'delete small', 'submit', false ); ?>
				</form>
			</div>
		</article>
		<?php
	}
}
