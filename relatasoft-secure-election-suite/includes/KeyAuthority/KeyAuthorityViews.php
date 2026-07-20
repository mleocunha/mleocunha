<?php
/**
 * Key Authority admin views.
 *
 * @package RelataSoft\SecureElectionSuite\KeyAuthority
 */

namespace RelataSoft\SecureElectionSuite\KeyAuthority;

use RelataSoft\SecureElectionSuite\Security\Capability;
use RelataSoft\SecureElectionSuite\Security\Escaper;
use RelataSoft\SecureElectionSuite\Security\Nonce;

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
		<div class="wrap rses-wrap">
			<h1><?php esc_html_e( 'Key Authority / ElGamal Key Manager', 'relatasoft-secure-election-suite' ); ?></h1>

			<?php if ( ! empty( $_GET['rses_mode_set'] ) || ! empty( $_GET['rses_key_created'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible">
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

			<div class="rses-notice rses-notice-warning">
				<p><?php esc_html_e( 'Private keys are split into Shamir Secret Sharing shares immediately. Full private keys are not persisted by default.', 'relatasoft-secure-election-suite' ); ?></p>
			</div>

			<?php if ( empty( $rses_officials ) ) : ?>
				<div class="rses-notice rses-notice-warning">
					<p><?php esc_html_e( 'No editor accounts found. Create WordPress users with the Editor role before assigning Shamir shares.', 'relatasoft-secure-election-suite' ); ?></p>
				</div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Generate New Key', 'relatasoft-secure-election-suite' ); ?></h2>

			<div id="rses-keygen-progress" class="rses-keygen-progress" hidden>
				<div class="rses-notice rses-notice-info">
					<p id="rses-keygen-message"><?php esc_html_e( 'Preparing…', 'relatasoft-secure-election-suite' ); ?></p>
					<div class="rses-progress-bar rses-keygen-bar">
						<div id="rses-keygen-bar-fill" class="rses-progress-fill" style="width:0%"></div>
					</div>
					<p>
						<span id="rses-keygen-percent">0%</span>
						— <span id="rses-keygen-stage"></span>
						— <span id="rses-keygen-attempts"></span>
					</p>
					<p>
						<button type="button" class="button" id="rses-keygen-cancel"><?php esc_html_e( 'Cancel', 'relatasoft-secure-election-suite' ); ?></button>
					</p>
				</div>
			</div>

			<form method="post" action="#" id="rses-keygen-form" class="rses-form">
				<?php Nonce::rses_field( Nonce::RSES_ACTION_KEY_GENERATE ); ?>
				<input type="hidden" name="action" value="rses_generate_key" />

				<table class="form-table">
					<tr>
						<th><label for="rses_key_label"><?php esc_html_e( 'Key Label', 'relatasoft-secure-election-suite' ); ?></label></th>
						<td><input type="text" name="rses_key_label" id="rses_key_label" class="regular-text" required /></td>
					</tr>
					<tr>
						<th><label for="rses_key_size"><?php esc_html_e( 'Key Size (bits)', 'relatasoft-secure-election-suite' ); ?></label></th>
						<td>
							<select name="rses_key_size" id="rses_key_size">
								<option value="512"><?php esc_html_e( '512 (local testing only)', 'relatasoft-secure-election-suite' ); ?></option>
								<option value="1024"><?php esc_html_e( '1024 (development)', 'relatasoft-secure-election-suite' ); ?></option>
								<option value="2048" selected><?php esc_html_e( '2048 (recommended minimum)', 'relatasoft-secure-election-suite' ); ?></option>
								<option value="3072"><?php esc_html_e( '3072 (stronger — chunked)', 'relatasoft-secure-election-suite' ); ?></option>
								<option value="4096"><?php esc_html_e( '4096 (strongest — chunked)', 'relatasoft-secure-election-suite' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Generation runs in ≤25s AJAX chunks with a live progress bar so PHP time limits are not exceeded.', 'relatasoft-secure-election-suite' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="rses_threshold_t"><?php esc_html_e( 'Shamir Threshold (t)', 'relatasoft-secure-election-suite' ); ?></label></th>
						<td>
							<input type="number" name="rses_threshold_t" id="rses_threshold_t" value="3" min="2" />
							<p class="description"><?php esc_html_e( 'Total shares (n) is set automatically from the number of officials you select.', 'relatasoft-secure-election-suite' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Assign Officials', 'relatasoft-secure-election-suite' ); ?></th>
						<td>
							<?php if ( empty( $rses_officials ) ) : ?>
								<em><?php esc_html_e( 'No officials available.', 'relatasoft-secure-election-suite' ); ?></em>
							<?php else : ?>
								<?php foreach ( $rses_officials as $rses_user ) : ?>
									<label>
										<input type="checkbox" name="rses_officials[]" value="<?php echo esc_attr( (string) $rses_user->ID ); ?>" />
										<?php echo esc_html( $rses_user->display_name . ' (' . $rses_user->user_login . ')' ); ?>
									</label><br />
								<?php endforeach; ?>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><label for="rses_key_description"><?php esc_html_e( 'Description', 'relatasoft-secure-election-suite' ); ?></label></th>
						<td><textarea name="rses_key_description" id="rses_key_description" rows="3" class="large-text"></textarea></td>
					</tr>
					<tr>
						<th><label for="rses_attachment_id"><?php esc_html_e( 'Media Attachment ID', 'relatasoft-secure-election-suite' ); ?></label></th>
						<td><input type="number" name="rses_attachment_id" id="rses_attachment_id" min="0" /></td>
					</tr>
				</table>

				<?php submit_button( __( 'Generate Key & Assign Shares', 'relatasoft-secure-election-suite' ), 'primary', 'rses_keygen_submit', false ); ?>
			</form>

			<h2><?php esc_html_e( 'Import Public Key', 'relatasoft-secure-election-suite' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php Nonce::rses_field( Nonce::RSES_ACTION_KEY_IMPORT ); ?>
				<input type="hidden" name="action" value="rses_import_key" />
				<p>
					<label for="rses_import_label"><?php esc_html_e( 'Label', 'relatasoft-secure-election-suite' ); ?></label>
					<input type="text" name="rses_import_label" id="rses_import_label" class="regular-text" />
				</p>
				<p>
					<label for="rses_import_json"><?php esc_html_e( 'Public Key JSON', 'relatasoft-secure-election-suite' ); ?></label><br />
					<textarea name="rses_import_json" id="rses_import_json" rows="6" class="large-text code" required></textarea>
				</p>
				<?php submit_button( __( 'Import Key', 'relatasoft-secure-election-suite' ), 'secondary' ); ?>
			</form>

			<h2><?php esc_html_e( 'Key Cards', 'relatasoft-secure-election-suite' ); ?></h2>
			<?php if ( empty( $rses_keys ) ) : ?>
				<p><?php esc_html_e( 'No keys generated yet.', 'relatasoft-secure-election-suite' ); ?></p>
			<?php else : ?>
				<?php foreach ( $rses_keys as $rses_key ) : ?>
					<?php self::rses_render_key_card( $rses_key, $rses_settings ); ?>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Official-only view: export own Shamir share.
	 */
	private static function rses_render_official_shares(): void {
		$rses_user_id = get_current_user_id();
		$rses_keys    = KeyRepository::rses_list_active();
		?>
		<div class="wrap rses-wrap">
			<h1><?php esc_html_e( 'My Shamir Shares', 'relatasoft-secure-election-suite' ); ?></h1>
			<p><?php esc_html_e( 'Export only your assigned Shamir Secret Sharing share. Share values must be stored offline securely.', 'relatasoft-secure-election-suite' ); ?></p>

			<?php
			$rses_found = false;
			foreach ( $rses_keys as $rses_key ) {
				$rses_share = KeyRepository::rses_get_share_for_user( (int) $rses_key->id, $rses_user_id );
				if ( ! $rses_share ) {
					continue;
				}
				$rses_found = true;
				?>
				<div class="rses-key-card">
					<h3><?php echo esc_html( $rses_key->key_label ); ?> <small>#<?php echo esc_html( (string) $rses_key->id ); ?></small></h3>
					<p>
						<?php
						printf(
							/* translators: %d: share index */
							esc_html__( 'Your share index: %d', 'relatasoft-secure-election-suite' ),
							(int) $rses_share->share_index
						);
						?>
					</p>
					<p>
						<a class="button button-primary" href="<?php echo esc_url( Nonce::rses_url( admin_url( 'admin-post.php?action=rses_export_key&key_id=' . $rses_key->id . '&format=zip&own_share=1' ), Nonce::RSES_ACTION_KEY_EXPORT ) ); ?>">
							<?php esc_html_e( 'Export My Share (ZIP)', 'relatasoft-secure-election-suite' ); ?>
						</a>
						<a class="button" href="<?php echo esc_url( Nonce::rses_url( admin_url( 'admin-post.php?action=rses_export_key&key_id=' . $rses_key->id . '&format=json' ), Nonce::RSES_ACTION_KEY_EXPORT ) ); ?>">
							<?php esc_html_e( 'Export Public Key JSON', 'relatasoft-secure-election-suite' ); ?>
						</a>
					</p>
				</div>
				<?php
			}

			if ( ! $rses_found ) {
				echo '<p>' . esc_html__( 'No Shamir shares are assigned to your account yet.', 'relatasoft-secure-election-suite' ) . '</p>';
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
		$rses_shares = KeyRepository::rses_get_shares( (int) $key->id );
		$rses_attachments = json_decode( $key->attachments ?? '[]', true ) ?: array();
		?>
		<div class="rses-key-card">
			<h3><?php echo esc_html( $key->key_label ); ?> <small>#<?php echo esc_html( (string) $key->id ); ?></small></h3>
			<table class="widefat">
				<tr><th><?php esc_html_e( 'Key Size', 'relatasoft-secure-election-suite' ); ?></th><td><?php echo esc_html( (string) $key->key_size ); ?> bits</td></tr>
				<tr><th>p</th><td><code class="rses-bigint"><?php echo esc_html( substr( $key->public_p, 0, 64 ) . '...' ); ?></code></td></tr>
				<tr><th>q</th><td><code class="rses-bigint"><?php echo esc_html( substr( $key->public_q, 0, 64 ) . '...' ); ?></code></td></tr>
				<tr><th>g</th><td><code class="rses-bigint"><?php echo esc_html( substr( $key->public_g, 0, 64 ) . '...' ); ?></code></td></tr>
				<tr><th>y</th><td><code class="rses-bigint"><?php echo esc_html( substr( $key->public_y, 0, 64 ) . '...' ); ?></code></td></tr>
				<tr><th><?php esc_html_e( 'Private Key Status', 'relatasoft-secure-election-suite' ); ?></th>
					<td><?php echo (int) $key->private_key_persisted ? esc_html__( 'Persisted (not recommended)', 'relatasoft-secure-election-suite' ) : esc_html__( 'Not persisted (Shamir shares only)', 'relatasoft-secure-election-suite' ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Shamir Threshold', 'relatasoft-secure-election-suite' ); ?></th><td><?php echo esc_html( (string) ( $key->threshold_t ?? '-' ) ); ?> / <?php echo esc_html( (string) ( $key->total_n ?? '-' ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Assigned Officials', 'relatasoft-secure-election-suite' ); ?></th>
					<td>
						<?php
						foreach ( $rses_shares as $rses_share ) {
							$rses_user = get_userdata( (int) $rses_share->official_user_id );
							echo esc_html( ( $rses_user ? $rses_user->display_name : '#' . $rses_share->official_user_id ) . ' (share ' . $rses_share->share_index . ')' );
							echo '<br />';
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

			<p class="rses-key-actions">
				<a class="button" href="<?php echo esc_url( Nonce::rses_url( admin_url( 'admin-post.php?action=rses_export_key&key_id=' . $key->id . '&format=zip' ), Nonce::RSES_ACTION_KEY_EXPORT ) ); ?>">
					<?php esc_html_e( 'Export ZIP', 'relatasoft-secure-election-suite' ); ?>
				</a>
				<a class="button" href="<?php echo esc_url( Nonce::rses_url( admin_url( 'admin-post.php?action=rses_export_key&key_id=' . $key->id . '&format=json' ), Nonce::RSES_ACTION_KEY_EXPORT ) ); ?>">
					<?php esc_html_e( 'Export Public JSON', 'relatasoft-secure-election-suite' ); ?>
				</a>
				<?php
				$rses_own = KeyRepository::rses_get_share_for_user( (int) $key->id, get_current_user_id() );
				if ( $rses_own ) :
					?>
					<a class="button" href="<?php echo esc_url( Nonce::rses_url( admin_url( 'admin-post.php?action=rses_export_key&key_id=' . $key->id . '&format=zip&own_share=1' ), Nonce::RSES_ACTION_KEY_EXPORT ) ); ?>">
						<?php esc_html_e( 'Export My Share', 'relatasoft-secure-election-suite' ); ?>
					</a>
				<?php endif; ?>
				<?php if ( ! empty( $settings['allow_full_private_export'] ) ) : ?>
					<a class="button button-warning" href="<?php echo esc_url( Nonce::rses_url( admin_url( 'admin-post.php?action=rses_export_key&key_id=' . $key->id . '&format=zip&full=1&rses_confirm_full=1' ), Nonce::RSES_ACTION_KEY_EXPORT ) ); ?>"
						onclick="return confirm('<?php echo esc_js( __( 'WARNING: This exports the full private key. Continue?', 'relatasoft-secure-election-suite' ) ); ?>');">
						<?php esc_html_e( 'Full Export (Admin)', 'relatasoft-secure-election-suite' ); ?>
					</a>
				<?php endif; ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
				<?php Nonce::rses_field( Nonce::RSES_ACTION_KEY_EXPORT ); ?>
				<input type="hidden" name="action" value="rses_key_action" />
				<input type="hidden" name="key_id" value="<?php echo esc_attr( (string) $key->id ); ?>" />
				<input type="hidden" name="rses_key_action" value="trash" />
				<?php submit_button( __( 'Trash', 'relatasoft-secure-election-suite' ), 'delete small', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}
}
