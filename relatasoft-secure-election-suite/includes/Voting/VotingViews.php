<?php
/**
 * Voting admin and frontend views.
 *
 * @package RelataSoft\SecureElectionSuite\Voting
 */

namespace RelataSoft\SecureElectionSuite\Voting;

use RelataSoft\SecureElectionSuite\KeyAuthority\KeyRepository;
use RelataSoft\SecureElectionSuite\Security\Capability;
use RelataSoft\SecureElectionSuite\Security\Nonce;
use RelataSoft\SecureElectionSuite\Frontend\VoterJourney;
use RelataSoft\SecureElectionSuite\I18n\RoleLabels;
use RelataSoft\SecureElectionSuite\I18n\Translator;
use RelataSoft\SecureElectionSuite\Admin\Brand;

defined( 'ABSPATH' ) || exit;

/**
 * Voting platform views.
 */
class VotingViews {

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
	 * Render elections list / editor.
	 */
	public static function rses_render_elections_list(): void {
		Capability::rses_require_admin();

		$rses_elections = ElectionRepository::rses_list();
		$rses_keys      = KeyRepository::rses_list_active();
		$rses_edit_id   = isset( $_GET['rses_edit'] ) ? absint( $_GET['rses_edit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$rses_round_id  = isset( $_GET['round'] ) ? absint( $_GET['round'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap rses-wrap rses-screen" <?php echo Translator::rses_html_attrs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<?php if ( $rses_edit_id ) : ?>
				<?php self::rses_render_election_editor( $rses_edit_id, $rses_round_id ); ?>
			<?php else : ?>
				<header class="rses-hero">
				<?php Brand::rses_render_hero_brand(); ?>

					<p class="rses-hero-kicker"><?php esc_html_e( 'Voting Platform', 'relatasoft-secure-election-suite' ); ?></p>
					<h1 class="rses-hero-title"><?php esc_html_e( 'Election Management', 'relatasoft-secure-election-suite' ); ?></h1>
					<p class="rses-hero-lead"><?php esc_html_e( 'Create elections, build ballots, open voting, and prepare exports for the Tallying site.', 'relatasoft-secure-election-suite' ); ?></p>
				</header>

				<?php if ( ! empty( $_GET['rses_mode_set'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
					<div class="rses-panel rses-panel-success">
						<p><?php esc_html_e( 'Voting mode locked. Import a public key first, then create an election.', 'relatasoft-secure-election-suite' ); ?></p>
					</div>
				<?php endif; ?>

				<?php if ( empty( $rses_keys ) ) : ?>
					<div class="rses-panel rses-panel-warning">
						<p>
							<?php esc_html_e( 'No public keys imported yet.', 'relatasoft-secure-election-suite' ); ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=rses-public-keys' ) ); ?>">
								<?php esc_html_e( 'Import a public key', 'relatasoft-secure-election-suite' ); ?>
							</a>
							<?php esc_html_e( 'before creating an election.', 'relatasoft-secure-election-suite' ); ?>
						</p>
					</div>
				<?php endif; ?>

				<section class="rses-panel rses-panel-card" id="rses-create-election">
					<header class="rses-panel-header">
						<p class="rses-panel-kicker"><?php esc_html_e( 'Create', 'relatasoft-secure-election-suite' ); ?></p>
						<h2 class="rses-panel-title"><?php esc_html_e( 'New election', 'relatasoft-secure-election-suite' ); ?></h2>
						<p class="rses-panel-desc"><?php esc_html_e( 'Choose a voting method and bind the election to an imported ElGamal public key.', 'relatasoft-secure-election-suite' ); ?></p>
					</header>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rses-form">
						<?php Nonce::rses_field( Nonce::RSES_ACTION_ELECTION_SAVE ); ?>
						<input type="hidden" name="action" value="rses_save_election" />
						<div class="rses-field-grid">
							<div class="rses-field rses-field-full">
								<label for="rses_election_title"><?php esc_html_e( 'Title', 'relatasoft-secure-election-suite' ); ?></label>
								<input type="text" name="rses_election_title" id="rses_election_title" class="regular-text" required />
							</div>
							<div class="rses-field rses-field-full">
								<label for="rses_election_description"><?php esc_html_e( 'Description', 'relatasoft-secure-election-suite' ); ?></label>
								<textarea name="rses_election_description" id="rses_election_description" rows="3"></textarea>
							</div>
							<div class="rses-field">
								<label for="rses_voting_method"><?php esc_html_e( 'Voting Method', 'relatasoft-secure-election-suite' ); ?></label>
								<select name="rses_voting_method" id="rses_voting_method">
									<?php
									$rses_methods = array(
										'yes_no'           => __( 'Yes / No', 'relatasoft-secure-election-suite' ),
										'single_choice'    => __( 'Single Choice', 'relatasoft-secure-election-suite' ),
										'multiple_choice'  => __( 'Multiple Choice', 'relatasoft-secure-election-suite' ),
										'ranked_choice'    => __( 'Ranked Choice', 'relatasoft-secure-election-suite' ),
										'numeric'          => __( 'Numeric', 'relatasoft-secure-election-suite' ),
										'fptp'             => __( 'First Past the Post', 'relatasoft-secure-election-suite' ),
										'list_voting'      => __( 'List Voting', 'relatasoft-secure-election-suite' ),
										'single_candidate' => __( 'Single Candidate', 'relatasoft-secure-election-suite' ),
										'custom'           => __( 'Custom', 'relatasoft-secure-election-suite' ),
									);
									foreach ( $rses_methods as $rses_slug => $rses_label ) :
										?>
										<option value="<?php echo esc_attr( $rses_slug ); ?>"><?php echo esc_html( $rses_label ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="rses-field">
								<label for="rses_key_id"><?php esc_html_e( 'Public Key', 'relatasoft-secure-election-suite' ); ?></label>
								<select name="rses_key_id" id="rses_key_id" required <?php disabled( empty( $rses_keys ) ); ?>>
									<option value=""><?php esc_html_e( 'Select imported public key…', 'relatasoft-secure-election-suite' ); ?></option>
									<?php foreach ( $rses_keys as $rses_key ) : ?>
										<option value="<?php echo esc_attr( (string) $rses_key->id ); ?>">
											<?php echo esc_html( sprintf( '#%d — %s (%d bits)', (int) $rses_key->id, $rses_key->key_label, (int) $rses_key->key_size ) ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
						</div>
						<p class="rses-form-actions">
							<?php
							submit_button(
								__( 'Create Election', 'relatasoft-secure-election-suite' ),
								'primary rses-btn-primary',
								'submit',
								false,
								empty( $rses_keys ) ? array( 'disabled' => 'disabled' ) : array()
							);
							?>
						</p>
					</form>
				</section>

				<section class="rses-panel rses-panel-card" id="rses-elections-list">
					<header class="rses-panel-header">
						<p class="rses-panel-kicker"><?php esc_html_e( 'Library', 'relatasoft-secure-election-suite' ); ?></p>
						<h2 class="rses-panel-title"><?php esc_html_e( 'Elections', 'relatasoft-secure-election-suite' ); ?></h2>
						<p class="rses-panel-desc"><?php esc_html_e( 'Open an election to build its ballot, publish shortcodes, and control voting windows.', 'relatasoft-secure-election-suite' ); ?></p>
					</header>

					<?php if ( empty( $rses_elections ) ) : ?>
						<div class="rses-panel-body">
							<p class="rses-empty"><?php esc_html_e( 'No elections yet.', 'relatasoft-secure-election-suite' ); ?></p>
						</div>
					<?php else : ?>
						<div class="rses-table-wrap">
							<table class="rses-table">
								<thead>
									<tr>
										<th>ID</th>
										<th><?php esc_html_e( 'Title', 'relatasoft-secure-election-suite' ); ?></th>
										<th><?php esc_html_e( 'Status', 'relatasoft-secure-election-suite' ); ?></th>
										<th><?php esc_html_e( 'Actions', 'relatasoft-secure-election-suite' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $rses_elections as $rses_e ) : ?>
										<tr>
											<td><?php echo esc_html( (string) $rses_e->id ); ?></td>
											<td><?php echo esc_html( $rses_e->title ); ?></td>
											<td><?php echo self::rses_status_pill( (string) $rses_e->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
											<td>
												<a class="rses-table-link" href="<?php echo esc_url( admin_url( 'admin.php?page=rses-elections&rses_edit=' . $rses_e->id ) ); ?>">
													<?php esc_html_e( 'Edit', 'relatasoft-secure-election-suite' ); ?>
												</a>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>

					<?php
					$rses_open_snap = OpenElectionsService::rses_snapshot();
					$rses_dump_url  = admin_url( 'admin-post.php?action=rses_dump_open_elections' );
					?>
					<script type="application/json" id="rses-open-elections-json"><?php echo wp_json_encode( $rses_open_snap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON payload for scrapers. ?></script>
					<p class="rses-panel-desc" style="margin-top:1rem;">
						<a class="rses-table-link" id="rses-dump-open-elections" data-rses-open-dump="1" href="<?php echo esc_url( $rses_dump_url ); ?>">
							<?php esc_html_e( 'Download open elections JSON', 'relatasoft-secure-election-suite' ); ?>
						</a>
					</p>
				</section>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render public key import page for Voting mode.
	 */
	public static function rses_render_public_keys_page(): void {
		Capability::rses_require_admin();

		$rses_keys = KeyRepository::rses_list_active();
		?>
		<div class="wrap rses-wrap rses-screen" <?php echo Translator::rses_html_attrs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<header class="rses-hero">
				<?php Brand::rses_render_hero_brand(); ?>

				<p class="rses-hero-kicker"><?php esc_html_e( 'Voting Platform', 'relatasoft-secure-election-suite' ); ?></p>
				<h1 class="rses-hero-title"><?php esc_html_e( 'Public Keys', 'relatasoft-secure-election-suite' ); ?></h1>
				<p class="rses-hero-lead"><?php esc_html_e( 'Import the public key JSON exported from the Key Authority site. Only public components (p, q, g, y) are stored here.', 'relatasoft-secure-election-suite' ); ?></p>
			</header>

			<?php if ( ! empty( $_GET['rses_key_imported'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="rses-panel rses-panel-success">
					<p><?php esc_html_e( 'Public key imported successfully.', 'relatasoft-secure-election-suite' ); ?></p>
				</div>
			<?php endif; ?>

			<section class="rses-panel rses-panel-card">
				<header class="rses-panel-header">
					<p class="rses-panel-kicker"><?php esc_html_e( 'Import', 'relatasoft-secure-election-suite' ); ?></p>
					<h2 class="rses-panel-title"><?php esc_html_e( 'Add public key', 'relatasoft-secure-election-suite' ); ?></h2>
					<p class="rses-panel-desc"><?php esc_html_e( 'Paste a public-key.json export or a package that includes a public_key object.', 'relatasoft-secure-election-suite' ); ?></p>
				</header>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rses-form">
					<?php Nonce::rses_field( Nonce::RSES_ACTION_KEY_IMPORT ); ?>
					<input type="hidden" name="action" value="rses_import_key" />
					<div class="rses-field-grid">
						<div class="rses-field">
							<label for="rses_import_label"><?php esc_html_e( 'Label', 'relatasoft-secure-election-suite' ); ?></label>
							<input type="text" name="rses_import_label" id="rses_import_label" class="regular-text" required />
						</div>
						<div class="rses-field rses-field-full">
							<label for="rses_import_json"><?php esc_html_e( 'Public Key JSON', 'relatasoft-secure-election-suite' ); ?></label>
							<textarea name="rses_import_json" id="rses_import_json" rows="10" class="rses-code-area" required placeholder='{"p":"...","q":"...","g":"...","y":"...","keySizeBits":2048}'></textarea>
						</div>
					</div>
					<p class="rses-form-actions">
						<?php submit_button( __( 'Import Public Key', 'relatasoft-secure-election-suite' ), 'primary rses-btn-primary', 'submit', false ); ?>
					</p>
				</form>
			</section>

			<section class="rses-panel rses-panel-card">
				<header class="rses-panel-header">
					<p class="rses-panel-kicker"><?php esc_html_e( 'Library', 'relatasoft-secure-election-suite' ); ?></p>
					<h2 class="rses-panel-title"><?php esc_html_e( 'Imported keys', 'relatasoft-secure-election-suite' ); ?></h2>
					<p class="rses-panel-desc"><?php esc_html_e( 'These keys are available when creating elections.', 'relatasoft-secure-election-suite' ); ?></p>
				</header>

				<?php if ( empty( $rses_keys ) ) : ?>
					<div class="rses-panel-body">
						<p class="rses-empty"><?php esc_html_e( 'No public keys imported.', 'relatasoft-secure-election-suite' ); ?></p>
					</div>
				<?php else : ?>
					<div class="rses-table-wrap">
						<table class="rses-table">
							<thead>
								<tr>
									<th>ID</th>
									<th><?php esc_html_e( 'Label', 'relatasoft-secure-election-suite' ); ?></th>
									<th><?php esc_html_e( 'Size', 'relatasoft-secure-election-suite' ); ?></th>
									<th>y</th>
									<th><?php esc_html_e( 'Created', 'relatasoft-secure-election-suite' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $rses_keys as $rses_key ) : ?>
									<tr>
										<td><?php echo esc_html( (string) $rses_key->id ); ?></td>
										<td><?php echo esc_html( $rses_key->key_label ); ?></td>
										<td><?php echo esc_html( (string) $rses_key->key_size ); ?> bits</td>
										<td><code class="rses-bigint"><?php echo esc_html( substr( $rses_key->public_y, 0, 40 ) . '…' ); ?></code></td>
										<td><?php echo esc_html( $rses_key->created_at ); ?></td>
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
	 * Render election editor with ballot builder.
	 *
	 * @param int $election_id Election ID.
	 * @param int $round_id    Round ID.
	 */
	private static function rses_render_election_editor( int $election_id, int $round_id ): void {
		$rses_election = ElectionRepository::rses_get( $election_id );
		$rses_round    = $round_id ? ElectionRepository::rses_get_round( $round_id ) : null;

		if ( ! $rses_round ) {
			$rses_rounds   = ElectionRepository::rses_get_rounds( $election_id );
			$rses_round    = $rses_rounds[0] ?? null;
			$rses_round_id = $rses_round ? (int) $rses_round->id : 0;
		} else {
			$rses_round_id = $round_id;
		}

		$rses_booth_sc = sprintf(
			'[rses_voting_booth election_id="%d" round_id="%d"]',
			$election_id,
			$rses_round_id
		);
		?>
		<header class="rses-hero">
				<?php Brand::rses_render_hero_brand(); ?>

			<p class="rses-hero-kicker"><?php esc_html_e( 'Election editor', 'relatasoft-secure-election-suite' ); ?></p>
			<h1 class="rses-hero-title"><?php echo esc_html( $rses_election->title ); ?></h1>
			<p class="rses-hero-lead">
				<?php esc_html_e( 'Status:', 'relatasoft-secure-election-suite' ); ?>
				<?php echo self::rses_status_pill( (string) $rses_election->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</p>
		</header>

		<p><a class="rses-back-link" href="<?php echo esc_url( admin_url( 'admin.php?page=rses-elections' ) ); ?>">&larr; <?php esc_html_e( 'Back to elections', 'relatasoft-secure-election-suite' ); ?></a></p>

		<?php if ( $rses_round ) : ?>
			<section class="rses-panel rses-panel-card">
				<header class="rses-panel-header">
					<p class="rses-panel-kicker"><?php esc_html_e( 'Publish', 'relatasoft-secure-election-suite' ); ?></p>
					<h2 class="rses-panel-title"><?php esc_html_e( 'Voting booth shortcode', 'relatasoft-secure-election-suite' ); ?></h2>
					<p class="rses-panel-desc">
						<?php
						printf(
							/* translators: %s: public key id */
							esc_html__( 'Public key ID: %s — paste the booth shortcode on a page once voting is open.', 'relatasoft-secure-election-suite' ),
							esc_html( (string) ( $rses_round->key_id ?: '—' ) )
						);
						?>
					</p>
				</header>
				<div class="rses-panel-body">
					<div class="rses-shortcode-box">
						<code class="rses-shortcode-text"><?php echo esc_html( $rses_booth_sc ); ?></code>
						<button type="button" class="button rses-btn-secondary rses-copy-shortcode" data-rses-copy="<?php echo esc_attr( $rses_booth_sc ); ?>">
							<?php esc_html_e( 'Copy', 'relatasoft-secure-election-suite' ); ?>
						</button>
					</div>
					<p class="rses-inline-actions">
						<a class="button rses-btn-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=rses-shortcodes&election_id=' . $election_id . '&round_id=' . $rses_round_id ) ); ?>">
							<?php esc_html_e( 'Open Shortcode Generator', 'relatasoft-secure-election-suite' ); ?>
						</a>
					</p>
				</div>
			</section>
		<?php endif; ?>

		<section class="rses-panel rses-panel-card">
			<header class="rses-panel-header">
				<p class="rses-panel-kicker"><?php esc_html_e( 'Control', 'relatasoft-secure-election-suite' ); ?></p>
				<h2 class="rses-panel-title"><?php esc_html_e( 'Voting window', 'relatasoft-secure-election-suite' ); ?></h2>
				<p class="rses-panel-desc"><?php esc_html_e( 'Open voting when the ballot is ready. Close & tally seals the round for export.', 'relatasoft-secure-election-suite' ); ?></p>
			</header>
			<div class="rses-panel-body">
				<div class="rses-inline-actions">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php Nonce::rses_field( Nonce::RSES_ACTION_ELECTION_SAVE ); ?>
						<input type="hidden" name="action" value="rses_election_action" />
						<input type="hidden" name="election_id" value="<?php echo esc_attr( (string) $election_id ); ?>" />
						<input type="hidden" name="round_id" value="<?php echo esc_attr( (string) $rses_round_id ); ?>" />
						<input type="hidden" name="rses_action" value="open" />
						<?php submit_button( __( 'Open Voting', 'relatasoft-secure-election-suite' ), 'primary rses-btn-primary', 'submit', false ); ?>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php Nonce::rses_field( Nonce::RSES_ACTION_ELECTION_SAVE ); ?>
						<input type="hidden" name="action" value="rses_election_action" />
						<input type="hidden" name="election_id" value="<?php echo esc_attr( (string) $election_id ); ?>" />
						<input type="hidden" name="round_id" value="<?php echo esc_attr( (string) $rses_round_id ); ?>" />
						<input type="hidden" name="rses_action" value="close" />
						<?php submit_button( __( 'Close & Tally', 'relatasoft-secure-election-suite' ), 'secondary rses-btn-secondary', 'submit', false ); ?>
					</form>
				</div>
			</div>
		</section>

		<?php if ( $rses_round ) : ?>
		<section class="rses-panel rses-panel-card">
			<header class="rses-panel-header">
				<p class="rses-panel-kicker"><?php esc_html_e( 'Áudio', 'relatasoft-secure-election-suite' ); ?></p>
				<h2 class="rses-panel-title"><?php esc_html_e( 'Áudio de fim de rodada', 'relatasoft-secure-election-suite' ); ?></h2>
				<p class="rses-panel-desc"><?php esc_html_e( 'Reproduzido automaticamente na página de agradecimento quando o eleitor conclui esta rodada (mp3/wav/ogg).', 'relatasoft-secure-election-suite' ); ?></p>
			</header>
			<?php
			$rses_audio_id  = ElectionController::rses_get_round_end_audio_id( $rses_round_id );
			$rses_audio_url = ElectionController::rses_get_round_end_audio_url( $rses_round_id );
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rses-form">
				<?php Nonce::rses_field( Nonce::RSES_ACTION_ELECTION_SAVE ); ?>
				<input type="hidden" name="action" value="rses_save_round_audio" />
				<input type="hidden" name="election_id" value="<?php echo esc_attr( (string) $election_id ); ?>" />
				<input type="hidden" name="round_id" value="<?php echo esc_attr( (string) $rses_round_id ); ?>" />
				<input type="hidden" name="rses_round_end_audio_id" id="rses_round_end_audio_id" value="<?php echo esc_attr( (string) $rses_audio_id ); ?>" />
				<div class="rses-option-media-controls">
					<span id="rses-round-audio-preview" class="rses-option-media-preview" <?php echo $rses_audio_url ? '' : 'hidden'; ?>>
						<?php if ( $rses_audio_url ) : ?>
							<audio controls preload="metadata" src="<?php echo esc_url( $rses_audio_url ); ?>"></audio>
						<?php endif; ?>
					</span>
					<button
						type="button"
						class="button rses-btn-secondary"
						id="rses-round-audio-pick"
						data-rses-title="<?php echo esc_attr__( 'Selecionar áudio de fim de rodada', 'relatasoft-secure-election-suite' ); ?>"
						data-rses-button="<?php echo esc_attr__( 'Usar este áudio', 'relatasoft-secure-election-suite' ); ?>"
					>
						<?php esc_html_e( 'Escolher áudio (mp3/wav/ogg)', 'relatasoft-secure-election-suite' ); ?>
					</button>
					<button type="button" class="button rses-btn-secondary" id="rses-round-audio-clear" <?php echo $rses_audio_id ? '' : 'hidden'; ?>>
						<?php esc_html_e( 'Remover áudio', 'relatasoft-secure-election-suite' ); ?>
					</button>
				</div>
				<p class="rses-form-actions">
					<?php submit_button( __( 'Guardar áudio', 'relatasoft-secure-election-suite' ), 'secondary rses-btn-secondary', 'submit', false ); ?>
				</p>
			</form>
		</section>
		<?php endif; ?>

		<section class="rses-panel rses-panel-card">
			<header class="rses-panel-header">
				<p class="rses-panel-kicker"><?php esc_html_e( 'Ballot', 'relatasoft-secure-election-suite' ); ?></p>
				<h2 class="rses-panel-title"><?php esc_html_e( 'Ballot builder', 'relatasoft-secure-election-suite' ); ?></h2>
				<p class="rses-panel-desc"><?php esc_html_e( 'Add questions and options. Voters will see these as choice cards on the booth.', 'relatasoft-secure-election-suite' ); ?></p>
			</header>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rses-form">
				<?php Nonce::rses_field( Nonce::RSES_ACTION_BALLOT_SAVE ); ?>
				<input type="hidden" name="action" value="rses_save_ballot" />
				<input type="hidden" name="election_id" value="<?php echo esc_attr( (string) $election_id ); ?>" />
				<input type="hidden" name="round_id" value="<?php echo esc_attr( (string) $rses_round_id ); ?>" />
				<div class="rses-field-grid">
					<div class="rses-field rses-field-full">
						<label for="rses_question_title"><?php esc_html_e( 'Question Title', 'relatasoft-secure-election-suite' ); ?></label>
						<input type="text" name="rses_question_title" id="rses_question_title" required />
					</div>
					<div class="rses-field">
						<label for="rses_question_type"><?php esc_html_e( 'Question Type', 'relatasoft-secure-election-suite' ); ?></label>
						<select name="rses_question_type" id="rses_question_type">
							<option value="yes_no">yes_no</option>
							<option value="single_choice" selected>single_choice</option>
							<option value="multiple_choice">multiple_choice</option>
							<option value="numeric">numeric</option>
							<option value="ranked_choice">ranked_choice</option>
						</select>
					</div>
					<div class="rses-field rses-field-full">
						<span class="rses-field-label"><?php esc_html_e( 'Options', 'relatasoft-secure-election-suite' ); ?></span>
						<p class="description rses-option-media-hint"><?php esc_html_e( 'Each option needs a label. Optionally attach a photo, audio clip, or video from the Media Library.', 'relatasoft-secure-election-suite' ); ?></p>
						<div class="rses-ballot-options" id="rses-ballot-options">
							<?php for ( $rses_i = 0; $rses_i < 5; ++$rses_i ) : ?>
								<div class="rses-option-row" data-rses-option-index="<?php echo esc_attr( (string) $rses_i ); ?>">
									<input
										type="text"
										name="rses_options[]"
										class="rses-option-label-input"
										placeholder="<?php esc_attr_e( 'Option label', 'relatasoft-secure-election-suite' ); ?>"
									/>
									<input type="hidden" name="rses_option_attachments[]" class="rses-option-attachment-id" value="" />
									<div class="rses-option-media-controls">
										<span class="rses-option-media-preview" hidden></span>
										<button
											type="button"
											class="button rses-btn-secondary rses-option-media-pick"
											data-rses-title="<?php echo esc_attr__( 'Select option media', 'relatasoft-secure-election-suite' ); ?>"
											data-rses-button="<?php echo esc_attr__( 'Use this media', 'relatasoft-secure-election-suite' ); ?>"
										>
											<?php esc_html_e( 'Attach photo / audio / video', 'relatasoft-secure-election-suite' ); ?>
										</button>
										<button type="button" class="button rses-btn-secondary rses-option-media-clear" hidden>
											<?php esc_html_e( 'Remove media', 'relatasoft-secure-election-suite' ); ?>
										</button>
									</div>
								</div>
							<?php endfor; ?>
						</div>
					</div>
				</div>
				<p class="rses-form-actions">
					<?php submit_button( __( 'Add Question', 'relatasoft-secure-election-suite' ), 'secondary rses-btn-secondary', 'submit', false ); ?>
				</p>
			</form>
		</section>

		<section class="rses-panel rses-panel-card">
			<header class="rses-panel-header">
				<p class="rses-panel-kicker"><?php esc_html_e( 'Preview', 'relatasoft-secure-election-suite' ); ?></p>
				<h2 class="rses-panel-title"><?php esc_html_e( 'Current ballot', 'relatasoft-secure-election-suite' ); ?></h2>
				<p class="rses-panel-desc"><?php esc_html_e( 'Questions already attached to this round.', 'relatasoft-secure-election-suite' ); ?></p>
			</header>
			<div class="rses-panel-body">
				<?php
				$rses_questions = ElectionRepository::rses_get_questions( $rses_round_id );
				if ( empty( $rses_questions ) ) :
					?>
					<p class="rses-empty"><?php esc_html_e( 'No questions yet. Add one above.', 'relatasoft-secure-election-suite' ); ?></p>
				<?php else : ?>
					<div class="rses-ballot-list">
						<?php foreach ( $rses_questions as $rses_q ) : ?>
							<div class="rses-ballot-question-preview">
								<strong><?php echo esc_html( $rses_q->question_title ); ?></strong>
								<span class="rses-q-type"><?php echo esc_html( $rses_q->question_type ); ?></span>
								<ul>
									<?php foreach ( ElectionRepository::rses_get_options( (int) $rses_q->id ) as $rses_o ) : ?>
										<li class="rses-ballot-option-preview">
											<?php
											// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- OptionMedia returns escaped HTML.
											echo OptionMedia::rses_render( $rses_o, 'admin' );
											?>
											<span class="rses-ballot-option-preview-label"><?php echo esc_html( $rses_o->option_label ); ?></span>
											<?php if ( OptionMedia::rses_has_media( $rses_o ) ) : ?>
												<?php $rses_meta = OptionMedia::rses_parse( $rses_o ); ?>
												<small class="rses-option-media-badge"><?php echo esc_html( OptionMedia::rses_type_label( $rses_meta['media_type'] ) ); ?></small>
											<?php endif; ?>
										</li>
									<?php endforeach; ?>
								</ul>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Render shortcode generator for publishing voting booths on pages/posts.
	 */
	public static function rses_render_shortcodes_page(): void {
		Capability::rses_require_admin();

		$rses_elections = ElectionRepository::rses_list();
		$rses_focus_eid = isset( $_GET['election_id'] ) ? absint( $_GET['election_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$rses_focus_rid = isset( $_GET['round_id'] ) ? absint( $_GET['round_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap rses-wrap rses-screen" <?php echo Translator::rses_html_attrs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<header class="rses-hero">
				<?php Brand::rses_render_hero_brand(); ?>

				<p class="rses-hero-kicker"><?php esc_html_e( 'Voting Platform', 'relatasoft-secure-election-suite' ); ?></p>
				<h1 class="rses-hero-title"><?php esc_html_e( 'Shortcode Generator', 'relatasoft-secure-election-suite' ); ?></h1>
				<p class="rses-hero-lead"><?php
					echo esc_html(
						sprintf(
							/* translators: %s: elector role label (plural) */
							__( 'Copy a shortcode and paste it into any page or post. Only %s may cast a ballot.', 'relatasoft-secure-election-suite' ),
							RoleLabels::rses_elector_plural()
						)
					);
				?></p>
			</header>

			<div class="rses-panel rses-panel-info">
				<p><?php esc_html_e( 'Create and open an election, copy the booth shortcode, publish a page, then share the URL with voters.', 'relatasoft-secure-election-suite' ); ?></p>
				<ol>
					<li><?php esc_html_e( 'Create and open an election under Elections.', 'relatasoft-secure-election-suite' ); ?></li>
					<li><?php esc_html_e( 'Copy the voting booth shortcode for that round.', 'relatasoft-secure-election-suite' ); ?></li>
					<li><?php esc_html_e( 'Create a page (e.g. “Vote”) and paste the shortcode.', 'relatasoft-secure-election-suite' ); ?></li>
					<li><?php esc_html_e( 'Publish the page and share the URL with voters.', 'relatasoft-secure-election-suite' ); ?></li>
				</ol>
			</div>

			<section class="rses-panel rses-panel-card">
				<header class="rses-panel-header">
					<p class="rses-panel-kicker"><?php esc_html_e( 'Reference', 'relatasoft-secure-election-suite' ); ?></p>
					<h2 class="rses-panel-title"><?php esc_html_e( 'Available shortcodes', 'relatasoft-secure-election-suite' ); ?></h2>
					<p class="rses-panel-desc"><?php esc_html_e( 'Each shortcode accepts election_id / round_id attributes as shown below.', 'relatasoft-secure-election-suite' ); ?></p>
				</header>
				<div class="rses-table-wrap">
					<table class="rses-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Shortcode', 'relatasoft-secure-election-suite' ); ?></th>
								<th><?php esc_html_e( 'Purpose', 'relatasoft-secure-election-suite' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td><code>[rses_voting_booth]</code></td>
								<td><?php esc_html_e( 'Full encrypted ballot for logged-in voters. Requires election_id and round_id.', 'relatasoft-secure-election-suite' ); ?></td>
							</tr>
							<tr>
								<td><code>[rses_voter_receipt]</code></td>
								<td><?php esc_html_e( 'Shows the voter’s receipt hash after casting (no plaintext choices).', 'relatasoft-secure-election-suite' ); ?></td>
							</tr>
							<tr>
								<td><code>[rses_election_status]</code></td>
								<td><?php esc_html_e( 'Displays election title and status (draft / open / closed).', 'relatasoft-secure-election-suite' ); ?></td>
							</tr>
							<tr>
								<td><code>[rses_voter_welcome]</code></td>
								<td><?php esc_html_e( 'Elector welcome and open-election links.', 'relatasoft-secure-election-suite' ); ?></td>
							</tr>
							<tr>
								<td><code>[rses_voter_thank_you]</code></td>
								<td><?php esc_html_e( 'Thank-you page with vote receipt hash.', 'relatasoft-secure-election-suite' ); ?></td>
							</tr>
							<tr>
								<td><code>[enviar_redefinicao_senha]</code></td>
								<td><?php esc_html_e( 'Sends a password-reset email to the currently logged-in user (plain text). Place on the welcome page for the Votador PoC password-change flow.', 'relatasoft-secure-election-suite' ); ?></td>
							</tr>
						</tbody>
					</table>
				</div>
			</section>

			<section class="rses-panel rses-panel-card">
				<header class="rses-panel-header">
					<p class="rses-panel-kicker"><?php esc_html_e( 'Generate', 'relatasoft-secure-election-suite' ); ?></p>
					<h2 class="rses-panel-title"><?php esc_html_e( 'Shortcodes by election', 'relatasoft-secure-election-suite' ); ?></h2>
					<p class="rses-panel-desc"><?php esc_html_e( 'Ready-to-copy shortcodes for every round.', 'relatasoft-secure-election-suite' ); ?></p>
				</header>

				<div class="rses-panel-body">
					<?php if ( empty( $rses_elections ) ) : ?>
						<p class="rses-empty">
							<?php esc_html_e( 'No elections yet.', 'relatasoft-secure-election-suite' ); ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=rses-elections' ) ); ?>">
								<?php esc_html_e( 'Create an election first.', 'relatasoft-secure-election-suite' ); ?>
							</a>
						</p>
					<?php else : ?>
						<div class="rses-shortcode-list">
							<?php foreach ( $rses_elections as $rses_election ) : ?>
								<?php
								$rses_rounds = ElectionRepository::rses_get_rounds( (int) $rses_election->id );
								if ( empty( $rses_rounds ) ) {
									continue;
								}
								?>
								<div class="rses-shortcode-election-card<?php echo ( $rses_focus_eid === (int) $rses_election->id ) ? ' rses-shortcode-focus' : ''; ?>">
									<h3>
										<?php echo esc_html( $rses_election->title ); ?>
										<small>#<?php echo esc_html( (string) $rses_election->id ); ?> — <?php echo esc_html( $rses_election->status ); ?></small>
									</h3>

									<?php foreach ( $rses_rounds as $rses_round ) : ?>
										<?php
										$rses_eid      = (int) $rses_election->id;
										$rses_rid      = (int) $rses_round->id;
										$rses_booth    = sprintf( '[rses_voting_booth election_id="%d" round_id="%d"]', $rses_eid, $rses_rid );
										$rses_receipt  = sprintf( '[rses_voter_receipt election_id="%d" round_id="%d"]', $rses_eid, $rses_rid );
										$rses_status   = sprintf( '[rses_election_status election_id="%d"]', $rses_eid );
										$rses_is_focus = ( $rses_focus_eid === $rses_eid && ( 0 === $rses_focus_rid || $rses_focus_rid === $rses_rid ) );
										?>
										<div class="rses-shortcode-round<?php echo $rses_is_focus ? ' rses-shortcode-focus' : ''; ?>">
											<h4>
												<?php echo esc_html( $rses_round->title ); ?>
												<small>
													#<?php echo esc_html( (string) $rses_rid ); ?> —
													<?php echo esc_html( $rses_round->status ); ?>
												</small>
											</h4>

											<?php
											self::rses_render_shortcode_row(
												__( 'Voting booth (place on a public page)', 'relatasoft-secure-election-suite' ),
												$rses_booth
											);
											self::rses_render_shortcode_row(
												__( 'Voter receipt', 'relatasoft-secure-election-suite' ),
												$rses_receipt
											);
											self::rses_render_shortcode_row(
												__( 'Election status', 'relatasoft-secure-election-suite' ),
												$rses_status
											);
											?>

											<p class="rses-shortcode-actions">
												<a class="button rses-btn-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=rses-elections&rses_edit=' . $rses_eid . '&round=' . $rses_rid ) ); ?>">
													<?php esc_html_e( 'Edit election', 'relatasoft-secure-election-suite' ); ?>
												</a>
											</p>
										</div>
									<?php endforeach; ?>
								</div>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
			</section>
		</div>
		<?php
	}

	/**
	 * Render one shortcode row with copy button.
	 *
	 * @param string $label     Label.
	 * @param string $shortcode Shortcode text.
	 */
	private static function rses_render_shortcode_row( string $label, string $shortcode ): void {
		?>
		<div class="rses-shortcode-row">
			<label><?php echo esc_html( $label ); ?></label>
			<div class="rses-shortcode-copy-wrap">
				<input type="text" class="rses-shortcode-input large-text code" readonly value="<?php echo esc_attr( $shortcode ); ?>" />
				<button type="button" class="button rses-btn-secondary rses-copy-shortcode" data-rses-copy="<?php echo esc_attr( $shortcode ); ?>">
					<?php esc_html_e( 'Copy', 'relatasoft-secure-election-suite' ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	/**
	 * Render voting export page.
	 */
	public static function rses_render_export_page(): void {
		Capability::rses_require_admin();

		$rses_elections = ElectionRepository::rses_list();
		?>
		<div class="wrap rses-wrap rses-screen" <?php echo Translator::rses_html_attrs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<header class="rses-hero">
				<?php Brand::rses_render_hero_brand(); ?>

				<p class="rses-hero-kicker"><?php esc_html_e( 'Voting Platform', 'relatasoft-secure-election-suite' ); ?></p>
				<h1 class="rses-hero-title"><?php esc_html_e( 'Voting Export', 'relatasoft-secure-election-suite' ); ?></h1>
				<p class="rses-hero-lead"><?php esc_html_e( 'Download sealed ballot packages for import on the Tallying site.', 'relatasoft-secure-election-suite' ); ?></p>
			</header>

			<section class="rses-panel rses-panel-card">
				<header class="rses-panel-header">
					<p class="rses-panel-kicker"><?php esc_html_e( 'Packages', 'relatasoft-secure-election-suite' ); ?></p>
					<h2 class="rses-panel-title"><?php esc_html_e( 'Exports by round', 'relatasoft-secure-election-suite' ); ?></h2>
					<p class="rses-panel-desc"><?php esc_html_e( 'ZIP includes ciphertext ballots and public parameters; JSON is a single-file alternative.', 'relatasoft-secure-election-suite' ); ?></p>
				</header>

				<?php if ( empty( $rses_elections ) ) : ?>
					<div class="rses-panel-body">
						<p class="rses-empty"><?php esc_html_e( 'No elections to export yet.', 'relatasoft-secure-election-suite' ); ?></p>
					</div>
				<?php else : ?>
					<div class="rses-table-wrap">
						<table class="rses-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Election', 'relatasoft-secure-election-suite' ); ?></th>
									<th><?php esc_html_e( 'Export', 'relatasoft-secure-election-suite' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $rses_elections as $rses_e ) : ?>
									<?php
									$rses_rounds = ElectionRepository::rses_get_rounds( (int) $rses_e->id );
									foreach ( $rses_rounds as $rses_r ) :
										?>
										<tr>
											<td><?php echo esc_html( $rses_e->title . ' — ' . $rses_r->title ); ?></td>
											<td>
												<div class="rses-inline-actions">
													<a class="button rses-btn-primary" href="<?php echo esc_url( Nonce::rses_url( admin_url( 'admin-post.php?action=rses_export_voting&election_id=' . $rses_e->id . '&round_id=' . $rses_r->id . '&format=zip' ), Nonce::RSES_ACTION_VOTING_EXPORT ) ); ?>">ZIP</a>
													<a class="button rses-btn-secondary" href="<?php echo esc_url( Nonce::rses_url( admin_url( 'admin-post.php?action=rses_export_voting&election_id=' . $rses_e->id . '&round_id=' . $rses_r->id . '&format=json' ), Nonce::RSES_ACTION_VOTING_EXPORT ) ); ?>">JSON</a>
												</div>
											</td>
										</tr>
									<?php endforeach; ?>
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
	 * Render voting booth shortcode.
	 *
	 * @param int $election_id Election ID.
	 * @param int $round_id    Round ID.
	 */
	public static function rses_render_voting_booth( int $election_id, int $round_id ): void {
		if ( ! is_user_logged_in() ) {
			$rses_login = VoterJourney::rses_login_url();
			echo '<div class="rses-booth" ' . Translator::rses_html_attrs() . '><div class="rses-message rses-message-warning rses-login-required">';
			echo esc_html__( 'Please log in to vote.', 'relatasoft-secure-election-suite' );
			echo ' <a class="rses-booth-login-link" href="' . esc_url( $rses_login ) . '">';
			echo esc_html__( 'Sign in', 'relatasoft-secure-election-suite' );
			echo '</a></div></div>';
			return;
		}

		if ( ! Capability::rses_can_vote() ) {
			echo '<div class="rses-booth" ' . Translator::rses_html_attrs() . '><div class="rses-message rses-message-error rses-vote-denied">';
			echo esc_html( RoleLabels::rses_message_vote_denied_booth() );
			echo '</div></div>';
			return;
		}

		if ( ! $election_id || ! $round_id ) {
			echo '<div class="rses-booth" ' . Translator::rses_html_attrs() . '><div class="rses-message rses-message-warning">' . esc_html__( 'Election not specified.', 'relatasoft-secure-election-suite' ) . '</div></div>';
			return;
		}

		if ( EncryptedVoteRepository::rses_has_voted_round( get_current_user_id(), $round_id ) ) {
			self::rses_render_voter_receipt( $election_id, $round_id );
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- BallotRenderer returns escaped HTML.
		echo BallotRenderer::rses_render( $election_id, $round_id );

		if ( isset( $_GET['rses_receipt'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$rses_flash = sanitize_text_field( wp_unslash( $_GET['rses_receipt'] ) );
			self::rses_render_receipt_card(
				$election_id,
				$round_id,
				$rses_flash,
				true
			);
		}
	}

	/**
	 * Render voter receipt shortcode.
	 *
	 * @param int $election_id Election ID.
	 * @param int $round_id    Round ID.
	 */
	public static function rses_render_voter_receipt( int $election_id, int $round_id ): void {
		if ( ! is_user_logged_in() ) {
			echo '<div class="rses-booth" ' . Translator::rses_html_attrs() . '><div class="rses-message rses-message-warning">' . esc_html__( 'Please log in to view your receipt.', 'relatasoft-secure-election-suite' ) . '</div></div>';
			return;
		}

		$rses_hash = EncryptedVoteRepository::rses_get_receipt_hash( get_current_user_id(), $round_id );

		if ( ! $rses_hash ) {
			echo '<div class="rses-booth" ' . Translator::rses_html_attrs() . '><div class="rses-message rses-message-info">' . esc_html__( 'No vote receipt found.', 'relatasoft-secure-election-suite' ) . '</div></div>';
			return;
		}

		self::rses_render_receipt_card( $election_id, $round_id, $rses_hash, false );
	}

	/**
	 * Styled receipt card (booth aesthetic).
	 *
	 * @param int    $election_id Election ID.
	 * @param int    $round_id    Round ID.
	 * @param string $hash        Receipt hash.
	 * @param bool   $just_cast   Whether this is the post-cast flash.
	 */
	private static function rses_render_receipt_card( int $election_id, int $round_id, string $hash, bool $just_cast ): void {
		$rses_election = ElectionRepository::rses_get( $election_id );
		$rses_id       = 'rses-receipt-hash-' . $round_id;
		?>
		<div
			class="rses-booth rses-booth-receipt"
			data-rses-booth="receipt"
			data-rses-election-id="<?php echo esc_attr( (string) $election_id ); ?>"
			data-rses-round-id="<?php echo esc_attr( (string) $round_id ); ?>"
			data-rses-already-voted="<?php echo $just_cast ? '0' : '1'; ?>"
			<?php echo Translator::rses_html_attrs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		>
			<header class="rses-booth-header">
				<p class="rses-booth-kicker"><?php esc_html_e( 'Vote receipt', 'relatasoft-secure-election-suite' ); ?></p>
				<?php if ( $rses_election ) : ?>
					<h2 class="rses-booth-title"><?php echo esc_html( $rses_election->title ); ?></h2>
				<?php else : ?>
					<h2 class="rses-booth-title"><?php esc_html_e( 'Your encrypted ballot was recorded', 'relatasoft-secure-election-suite' ); ?></h2>
				<?php endif; ?>
			</header>

			<div class="rses-message rses-message-success">
				<?php
				echo $just_cast
					? esc_html__( 'Vote cast successfully. Keep this receipt hash for your records.', 'relatasoft-secure-election-suite' )
					: esc_html__( 'Your ballot is already on file. This is your receipt hash — it does not reveal your choices.', 'relatasoft-secure-election-suite' );
				?>
			</div>

			<div class="rses-receipt-card">
				<p class="rses-receipt-label"><?php esc_html_e( 'Receipt hash', 'relatasoft-secure-election-suite' ); ?></p>
				<code class="rses-receipt-hash" id="<?php echo esc_attr( $rses_id ); ?>" data-rses-receipt-hash="1"><?php echo esc_html( $hash ); ?></code>
				<p class="rses-receipt-actions">
					<button
						type="button"
						class="rses-copy-receipt"
						data-rses-target="<?php echo esc_attr( $rses_id ); ?>"
						data-copied-label="<?php echo esc_attr__( 'Copied!', 'relatasoft-secure-election-suite' ); ?>"
					>
						<?php esc_html_e( 'Copy receipt', 'relatasoft-secure-election-suite' ); ?>
					</button>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render election status shortcode.
	 *
	 * @param int $election_id Election ID.
	 */
	public static function rses_render_election_status( int $election_id ): void {
		$rses_election = ElectionRepository::rses_get( $election_id );

		if ( ! $rses_election ) {
			echo '<p>' . esc_html__( 'Election not found.', 'relatasoft-secure-election-suite' ) . '</p>';
			return;
		}

		echo '<div class="rses-election-status">';
		echo '<h3>' . esc_html( $rses_election->title ) . '</h3>';
		echo '<p>' . esc_html__( 'Status:', 'relatasoft-secure-election-suite' ) . ' <strong>' . esc_html( $rses_election->status ) . '</strong></p>';
		echo '</div>';
	}
}
