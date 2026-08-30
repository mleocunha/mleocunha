<?php
/**
 * Voting admin and frontend views.
 *
 * @package RelataSoft\SecureElectionSuite\Voting
 */

namespace RelataSoft\SecureElectionSuite\Voting;

use RelataSoft\SecureElectionSuite\KeyAuthority\KeyRepository;
use RelataSoft\SecureElectionSuite\Painel\Application\Identity\IdentityGateway;
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
			<?php
			$rses_booth_url = add_query_arg(
				array(
					'election_id' => $election_id,
					'round_id'    => $rses_round_id,
				),
				\RelataSoft\SecureElectionSuite\Frontend\JourneySettings::rses_page_url( 'booth_page_id' )
			);
			$rses_welcome_url = \RelataSoft\SecureElectionSuite\Frontend\JourneySettings::rses_page_url( 'welcome_page_id' );
			?>
			<section class="rses-panel rses-panel-card">
				<header class="rses-panel-header">
					<p class="rses-panel-kicker"><?php esc_html_e( 'Publicar', 'relatasoft-secure-election-suite' ); ?></p>
					<h2 class="rses-panel-title"><?php esc_html_e( 'Cabina de votação', 'relatasoft-secure-election-suite' ); ?></h2>
					<p class="rses-panel-desc">
						<?php
						printf(
							/* translators: %s: public key id */
							esc_html__( 'ID da chave pública: %s — partilhe a URL nativa da cabina quando a votação estiver aberta.', 'relatasoft-secure-election-suite' ),
							esc_html( (string) ( $rses_round->key_id ?: '—' ) )
						);
						?>
					</p>
				</header>
				<div class="rses-panel-body">
					<?php if ( '' !== $rses_booth_url ) : ?>
						<p class="rses-field-label"><?php esc_html_e( 'URL nativa (preferida)', 'relatasoft-secure-election-suite' ); ?></p>
						<div class="rses-shortcode-box">
							<code class="rses-shortcode-text"><?php echo esc_html( $rses_booth_url ); ?></code>
							<button type="button" class="button rses-btn-secondary rses-copy-shortcode" data-rses-copy="<?php echo esc_attr( $rses_booth_url ); ?>">
								<?php esc_html_e( 'Copiar URL', 'relatasoft-secure-election-suite' ); ?>
							</button>
						</div>
					<?php endif; ?>
					<?php if ( '' !== $rses_welcome_url ) : ?>
						<p class="description">
							<?php
							printf(
								/* translators: %s: welcome URL */
								esc_html__( 'Boas-vindas do eleitor: %s', 'relatasoft-secure-election-suite' ),
								esc_html( $rses_welcome_url )
							);
							?>
						</p>
					<?php endif; ?>
					<details class="rses-legacy-shortcode">
						<summary><?php esc_html_e( 'Adaptador legado (shortcode em página do sítio)', 'relatasoft-secure-election-suite' ); ?></summary>
						<div class="rses-shortcode-box">
							<code class="rses-shortcode-text"><?php echo esc_html( $rses_booth_sc ); ?></code>
							<button type="button" class="button rses-btn-secondary rses-copy-shortcode" data-rses-copy="<?php echo esc_attr( $rses_booth_sc ); ?>">
								<?php esc_html_e( 'Copiar', 'relatasoft-secure-election-suite' ); ?>
							</button>
						</div>
					</details>
					<p class="rses-inline-actions">
						<a class="button rses-btn-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=rses-shortcodes&election_id=' . $election_id . '&round_id=' . $rses_round_id ) ); ?>">
							<?php esc_html_e( 'Abrir URLs e shortcodes', 'relatasoft-secure-election-suite' ); ?>
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
	 * Render native journey URLs and optional shortcode adapters.
	 */
	public static function rses_render_shortcodes_page(): void {
		Capability::rses_require_admin();

		$rses_elections = ElectionRepository::rses_list();
		$rses_focus_eid = isset( $_GET['election_id'] ) ? absint( $_GET['election_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$rses_focus_rid = isset( $_GET['round_id'] ) ? absint( $_GET['round_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$rses_welcome   = \RelataSoft\SecureElectionSuite\Frontend\JourneySettings::rses_page_url( 'welcome_page_id' );
		$rses_booth     = \RelataSoft\SecureElectionSuite\Frontend\JourneySettings::rses_page_url( 'booth_page_id' );
		$rses_thanks    = \RelataSoft\SecureElectionSuite\Frontend\JourneySettings::rses_page_url( 'thank_you_page_id' );
		?>
		<div class="wrap rses-wrap rses-screen" <?php echo Translator::rses_html_attrs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<header class="rses-hero">
				<?php Brand::rses_render_hero_brand(); ?>

				<p class="rses-hero-kicker"><?php esc_html_e( 'Plataforma de votação', 'relatasoft-secure-election-suite' ); ?></p>
				<h1 class="rses-hero-title"><?php esc_html_e( 'URLs da jornada e shortcodes', 'relatasoft-secure-election-suite' ); ?></h1>
				<p class="rses-hero-lead"><?php
					echo esc_html(
						sprintf(
							/* translators: %s: elector role label (plural) */
							__( 'Partilhe as rotas nativas /voto com os eleitores. Só %s podem votar.', 'relatasoft-secure-election-suite' ),
							RoleLabels::rses_elector_plural()
						)
					);
				?></p>
			</header>

			<div class="rses-panel rses-panel-info">
				<p><?php esc_html_e( 'Itinerário preferido: login → /voto/ → cabina com election_id e round_id → /voto/obrigado/.', 'relatasoft-secure-election-suite' ); ?></p>
				<ol>
					<li><?php esc_html_e( 'Criar e abrir uma eleição em Eleições.', 'relatasoft-secure-election-suite' ); ?></li>
					<li><?php esc_html_e( 'Copiar a URL nativa da cabina para essa rodada.', 'relatasoft-secure-election-suite' ); ?></li>
					<li><?php esc_html_e( 'Partilhar a URL (ou a de boas-vindas) com os eleitores — sem criar página no sítio.', 'relatasoft-secure-election-suite' ); ?></li>
					<li><?php esc_html_e( 'Shortcodes em páginas do sítio são adaptadores opcionais, não o caminho principal.', 'relatasoft-secure-election-suite' ); ?></li>
				</ol>
			</div>

			<section class="rses-panel rses-panel-card">
				<header class="rses-panel-header">
					<p class="rses-panel-kicker"><?php esc_html_e( 'Nativo', 'relatasoft-secure-election-suite' ); ?></p>
					<h2 class="rses-panel-title"><?php esc_html_e( 'Rotas /voto', 'relatasoft-secure-election-suite' ); ?></h2>
					<p class="rses-panel-desc"><?php esc_html_e( 'Estas URLs não dependem de páginas ou shortcodes do sítio.', 'relatasoft-secure-election-suite' ); ?></p>
				</header>
				<div class="rses-panel-body">
					<?php
					self::rses_render_shortcode_row( __( 'Boas-vindas', 'relatasoft-secure-election-suite' ), $rses_welcome ?: '/voto/' );
					self::rses_render_shortcode_row( __( 'Cabina (base)', 'relatasoft-secure-election-suite' ), $rses_booth ?: '/voto/cabina/' );
					self::rses_render_shortcode_row( __( 'Obrigado', 'relatasoft-secure-election-suite' ), $rses_thanks ?: '/voto/obrigado/' );
					?>
				</div>
			</section>

			<section class="rses-panel rses-panel-card">
				<header class="rses-panel-header">
					<p class="rses-panel-kicker"><?php esc_html_e( 'Referência', 'relatasoft-secure-election-suite' ); ?></p>
					<h2 class="rses-panel-title"><?php esc_html_e( 'Shortcodes (adaptadores finos)', 'relatasoft-secure-election-suite' ); ?></h2>
					<p class="rses-panel-desc"><?php esc_html_e( 'Opcionais: cada shortcode aceita election_id / round_id e delega ao JourneyGateway.', 'relatasoft-secure-election-suite' ); ?></p>
				</header>
				<div class="rses-table-wrap">
					<table class="rses-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Shortcode', 'relatasoft-secure-election-suite' ); ?></th>
								<th><?php esc_html_e( 'Finalidade', 'relatasoft-secure-election-suite' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td><code>[rses_voting_booth]</code></td>
								<td><?php esc_html_e( 'Cabina encriptada (requer election_id e round_id).', 'relatasoft-secure-election-suite' ); ?></td>
							</tr>
							<tr>
								<td><code>[rses_voter_receipt]</code></td>
								<td><?php esc_html_e( 'Mostra o recibo do voto (sem escolhas em claro).', 'relatasoft-secure-election-suite' ); ?></td>
							</tr>
							<tr>
								<td><code>[rses_election_status]</code></td>
								<td><?php esc_html_e( 'Título e estado da eleição (rascunho / aberta / fechada).', 'relatasoft-secure-election-suite' ); ?></td>
							</tr>
							<tr>
								<td><code>[rses_voter_welcome]</code></td>
								<td><?php esc_html_e( 'Boas-vindas e ligações a eleições abertas.', 'relatasoft-secure-election-suite' ); ?></td>
							</tr>
							<tr>
								<td><code>[rses_voter_thank_you]</code></td>
								<td><?php esc_html_e( 'Página de agradecimento com recibo.', 'relatasoft-secure-election-suite' ); ?></td>
							</tr>
							<tr>
								<td><code>[enviar_redefinicao_senha]</code></td>
								<td><?php esc_html_e( 'Envia e-mail de redefinição de senha ao utilizador autenticado.', 'relatasoft-secure-election-suite' ); ?></td>
							</tr>
						</tbody>
					</table>
				</div>
			</section>

			<section class="rses-panel rses-panel-card">
				<header class="rses-panel-header">
					<p class="rses-panel-kicker"><?php esc_html_e( 'Gerar', 'relatasoft-secure-election-suite' ); ?></p>
					<h2 class="rses-panel-title"><?php esc_html_e( 'URLs e shortcodes por eleição', 'relatasoft-secure-election-suite' ); ?></h2>
					<p class="rses-panel-desc"><?php esc_html_e( 'URL nativa da cabina primeiro; shortcode só como fallback.', 'relatasoft-secure-election-suite' ); ?></p>
				</header>

				<div class="rses-panel-body">
					<?php if ( empty( $rses_elections ) ) : ?>
						<p class="rses-empty">
							<?php esc_html_e( 'Ainda sem eleições.', 'relatasoft-secure-election-suite' ); ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=rses-elections' ) ); ?>">
								<?php esc_html_e( 'Criar uma eleição primeiro.', 'relatasoft-secure-election-suite' ); ?>
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
										$rses_booth_sc = sprintf( '[rses_voting_booth election_id="%d" round_id="%d"]', $rses_eid, $rses_rid );
										$rses_receipt  = sprintf( '[rses_voter_receipt election_id="%d" round_id="%d"]', $rses_eid, $rses_rid );
										$rses_status   = sprintf( '[rses_election_status election_id="%d"]', $rses_eid );
										$rses_booth_u  = '' !== $rses_booth
											? add_query_arg(
												array(
													'election_id' => $rses_eid,
													'round_id'    => $rses_rid,
												),
												$rses_booth
											)
											: sprintf( '/voto/cabina/?election_id=%d&round_id=%d', $rses_eid, $rses_rid );
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
												__( 'URL nativa da cabina (preferida)', 'relatasoft-secure-election-suite' ),
												$rses_booth_u
											);
											self::rses_render_shortcode_row(
												__( 'Shortcode da cabina (opcional)', 'relatasoft-secure-election-suite' ),
												$rses_booth_sc
											);
											self::rses_render_shortcode_row(
												__( 'Recibo do eleitor', 'relatasoft-secure-election-suite' ),
												$rses_receipt
											);
											self::rses_render_shortcode_row(
												__( 'Estado da eleição', 'relatasoft-secure-election-suite' ),
												$rses_status
											);
											?>

											<p class="rses-shortcode-actions">
												<a class="button rses-btn-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=rses-elections&rses_edit=' . $rses_eid . '&round=' . $rses_rid ) ); ?>">
													<?php esc_html_e( 'Editar eleição', 'relatasoft-secure-election-suite' ); ?>
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
		$session = IdentityGateway::get()->session;
		if ( ! $session->isAuthenticated() ) {
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

		if ( EncryptedVoteRepository::rses_has_voted_round( $session->currentUserId(), $round_id ) ) {
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
		$session = IdentityGateway::get()->session;
		if ( ! $session->isAuthenticated() ) {
			echo '<div class="rses-booth" ' . Translator::rses_html_attrs() . '><div class="rses-message rses-message-warning">' . esc_html__( 'Please log in to view your receipt.', 'relatasoft-secure-election-suite' ) . '</div></div>';
			return;
		}

		$rses_hash = EncryptedVoteRepository::rses_get_receipt_hash( $session->currentUserId(), $round_id );

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
