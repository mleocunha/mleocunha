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

defined( 'ABSPATH' ) || exit;

/**
 * Voting platform views.
 */
class VotingViews {

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
		<div class="wrap rses-wrap">
			<h1><?php esc_html_e( 'Election Management', 'relatasoft-secure-election-suite' ); ?></h1>

			<?php if ( ! empty( $_GET['rses_mode_set'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Voting mode locked. Import a public key first, then create an election.', 'relatasoft-secure-election-suite' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $rses_edit_id ) : ?>
				<?php self::rses_render_election_editor( $rses_edit_id, $rses_round_id ); ?>
			<?php else : ?>
				<?php if ( empty( $rses_keys ) ) : ?>
					<div class="rses-notice rses-notice-warning">
						<p>
							<?php esc_html_e( 'No public keys imported yet.', 'relatasoft-secure-election-suite' ); ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=rses-public-keys' ) ); ?>">
								<?php esc_html_e( 'Import a public key', 'relatasoft-secure-election-suite' ); ?>
							</a>
							<?php esc_html_e( 'before creating an election.', 'relatasoft-secure-election-suite' ); ?>
						</p>
					</div>
				<?php endif; ?>

				<h2><?php esc_html_e( 'Create Election', 'relatasoft-secure-election-suite' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php Nonce::rses_field( Nonce::RSES_ACTION_ELECTION_SAVE ); ?>
					<input type="hidden" name="action" value="rses_save_election" />
					<table class="form-table">
						<tr>
							<th><label for="rses_election_title"><?php esc_html_e( 'Title', 'relatasoft-secure-election-suite' ); ?></label></th>
							<td><input type="text" name="rses_election_title" id="rses_election_title" class="regular-text" required /></td>
						</tr>
						<tr>
							<th><label for="rses_election_description"><?php esc_html_e( 'Description', 'relatasoft-secure-election-suite' ); ?></label></th>
							<td><textarea name="rses_election_description" id="rses_election_description" class="large-text" rows="3"></textarea></td>
						</tr>
						<tr>
							<th><label for="rses_voting_method"><?php esc_html_e( 'Voting Method', 'relatasoft-secure-election-suite' ); ?></label></th>
							<td>
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
							</td>
						</tr>
						<tr>
							<th><label for="rses_key_id"><?php esc_html_e( 'Public Key', 'relatasoft-secure-election-suite' ); ?></label></th>
							<td>
								<select name="rses_key_id" id="rses_key_id" required <?php disabled( empty( $rses_keys ) ); ?>>
									<option value=""><?php esc_html_e( 'Select imported public key…', 'relatasoft-secure-election-suite' ); ?></option>
									<?php foreach ( $rses_keys as $rses_key ) : ?>
										<option value="<?php echo esc_attr( (string) $rses_key->id ); ?>">
											<?php echo esc_html( sprintf( '#%d — %s (%d bits)', (int) $rses_key->id, $rses_key->key_label, (int) $rses_key->key_size ) ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Create Election', 'relatasoft-secure-election-suite' ), 'primary', 'submit', true, empty( $rses_keys ) ? array( 'disabled' => 'disabled' ) : array() ); ?>
				</form>

				<h2><?php esc_html_e( 'Elections', 'relatasoft-secure-election-suite' ); ?></h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th>ID</th>
							<th><?php esc_html_e( 'Title', 'relatasoft-secure-election-suite' ); ?></th>
							<th><?php esc_html_e( 'Status', 'relatasoft-secure-election-suite' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'relatasoft-secure-election-suite' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $rses_elections ) ) : ?>
							<tr><td colspan="4"><?php esc_html_e( 'No elections yet.', 'relatasoft-secure-election-suite' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $rses_elections as $rses_e ) : ?>
								<tr>
									<td><?php echo esc_html( (string) $rses_e->id ); ?></td>
									<td><?php echo esc_html( $rses_e->title ); ?></td>
									<td><?php echo esc_html( $rses_e->status ); ?></td>
									<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=rses-elections&rses_edit=' . $rses_e->id ) ); ?>"><?php esc_html_e( 'Edit', 'relatasoft-secure-election-suite' ); ?></a></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
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
		<div class="wrap rses-wrap">
			<h1><?php esc_html_e( 'Public Keys', 'relatasoft-secure-election-suite' ); ?></h1>
			<p><?php esc_html_e( 'Import the public key JSON exported from the Key Authority site. Only public components (p, q, g, y) are stored here.', 'relatasoft-secure-election-suite' ); ?></p>

			<?php if ( ! empty( $_GET['rses_key_imported'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Public key imported successfully.', 'relatasoft-secure-election-suite' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php Nonce::rses_field( Nonce::RSES_ACTION_KEY_IMPORT ); ?>
				<input type="hidden" name="action" value="rses_import_key" />
				<table class="form-table">
					<tr>
						<th><label for="rses_import_label"><?php esc_html_e( 'Label', 'relatasoft-secure-election-suite' ); ?></label></th>
						<td><input type="text" name="rses_import_label" id="rses_import_label" class="regular-text" required /></td>
					</tr>
					<tr>
						<th><label for="rses_import_json"><?php esc_html_e( 'Public Key JSON', 'relatasoft-secure-election-suite' ); ?></label></th>
						<td>
							<textarea name="rses_import_json" id="rses_import_json" rows="10" class="large-text code" required placeholder='{"p":"...","q":"...","g":"...","y":"...","keySizeBits":2048}'></textarea>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Import Public Key', 'relatasoft-secure-election-suite' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Imported Keys', 'relatasoft-secure-election-suite' ); ?></h2>
			<table class="widefat striped">
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
					<?php if ( empty( $rses_keys ) ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'No public keys imported.', 'relatasoft-secure-election-suite' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $rses_keys as $rses_key ) : ?>
							<tr>
								<td><?php echo esc_html( (string) $rses_key->id ); ?></td>
								<td><?php echo esc_html( $rses_key->key_label ); ?></td>
								<td><?php echo esc_html( (string) $rses_key->key_size ); ?> bits</td>
								<td><code class="rses-bigint"><?php echo esc_html( substr( $rses_key->public_y, 0, 40 ) . '…' ); ?></code></td>
								<td><?php echo esc_html( $rses_key->created_at ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
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
			$rses_rounds = ElectionRepository::rses_get_rounds( $election_id );
			$rses_round  = $rses_rounds[0] ?? null;
			$rses_round_id = $rses_round ? (int) $rses_round->id : 0;
		} else {
			$rses_round_id = $round_id;
		}
		?>
		<h2><?php echo esc_html( $rses_election->title ); ?></h2>
		<p><?php esc_html_e( 'Status:', 'relatasoft-secure-election-suite' ); ?> <strong><?php echo esc_html( $rses_election->status ); ?></strong></p>

		<?php if ( $rses_round ) : ?>
			<div class="rses-notice rses-notice-info">
				<p>
					<strong><?php esc_html_e( 'Voting booth shortcode:', 'relatasoft-secure-election-suite' ); ?></strong>
					<code class="rses-shortcode-text">[rses_voting_booth election_id="<?php echo esc_attr( (string) $election_id ); ?>" round_id="<?php echo esc_attr( (string) $rses_round_id ); ?>"]</code>
					<button type="button" class="button button-small rses-copy-shortcode" data-rses-copy="[rses_voting_booth election_id=&quot;<?php echo esc_attr( (string) $election_id ); ?>&quot; round_id=&quot;<?php echo esc_attr( (string) $rses_round_id ); ?>&quot;]">
						<?php esc_html_e( 'Copy', 'relatasoft-secure-election-suite' ); ?>
					</button>
				</p>
				<p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=rses-shortcodes&election_id=' . $election_id . '&round_id=' . $rses_round_id ) ); ?>">
						<?php esc_html_e( 'Open Shortcode Generator →', 'relatasoft-secure-election-suite' ); ?>
					</a>
				</p>
				<p>
					<?php
					printf(
						/* translators: %s: public key id */
						esc_html__( 'Public key ID: %s', 'relatasoft-secure-election-suite' ),
						esc_html( (string) ( $rses_round->key_id ?: '—' ) )
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
			<?php Nonce::rses_field( Nonce::RSES_ACTION_ELECTION_SAVE ); ?>
			<input type="hidden" name="action" value="rses_election_action" />
			<input type="hidden" name="election_id" value="<?php echo esc_attr( (string) $election_id ); ?>" />
			<input type="hidden" name="round_id" value="<?php echo esc_attr( (string) $rses_round_id ); ?>" />
			<input type="hidden" name="rses_action" value="open" />
			<?php submit_button( __( 'Open Voting', 'relatasoft-secure-election-suite' ), 'primary', 'submit', false ); ?>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
			<?php Nonce::rses_field( Nonce::RSES_ACTION_ELECTION_SAVE ); ?>
			<input type="hidden" name="action" value="rses_election_action" />
			<input type="hidden" name="election_id" value="<?php echo esc_attr( (string) $election_id ); ?>" />
			<input type="hidden" name="round_id" value="<?php echo esc_attr( (string) $rses_round_id ); ?>" />
			<input type="hidden" name="rses_action" value="close" />
			<?php submit_button( __( 'Close & Tally', 'relatasoft-secure-election-suite' ), 'secondary', 'submit', false ); ?>
		</form>

		<h3><?php esc_html_e( 'Ballot Builder', 'relatasoft-secure-election-suite' ); ?></h3>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php Nonce::rses_field( Nonce::RSES_ACTION_BALLOT_SAVE ); ?>
			<input type="hidden" name="action" value="rses_save_ballot" />
			<input type="hidden" name="election_id" value="<?php echo esc_attr( (string) $election_id ); ?>" />
			<input type="hidden" name="round_id" value="<?php echo esc_attr( (string) $rses_round_id ); ?>" />
			<p>
				<label><?php esc_html_e( 'Question Title', 'relatasoft-secure-election-suite' ); ?></label>
				<input type="text" name="rses_question_title" class="regular-text" required />
			</p>
			<p>
				<label><?php esc_html_e( 'Question Type', 'relatasoft-secure-election-suite' ); ?></label>
				<select name="rses_question_type">
					<option value="yes_no">yes_no</option>
					<option value="single_choice" selected>single_choice</option>
					<option value="multiple_choice">multiple_choice</option>
					<option value="numeric">numeric</option>
					<option value="ranked_choice">ranked_choice</option>
				</select>
			</p>
			<p><?php esc_html_e( 'Options (one per line in fields below)', 'relatasoft-secure-election-suite' ); ?></p>
			<?php for ( $rses_i = 0; $rses_i < 5; ++$rses_i ) : ?>
				<input type="text" name="rses_options[]" placeholder="<?php esc_attr_e( 'Option label', 'relatasoft-secure-election-suite' ); ?>" class="regular-text" /><br />
			<?php endfor; ?>
			<?php submit_button( __( 'Add Question', 'relatasoft-secure-election-suite' ), 'secondary' ); ?>
		</form>

		<h3><?php esc_html_e( 'Current Ballot', 'relatasoft-secure-election-suite' ); ?></h3>
		<?php
		$rses_questions = ElectionRepository::rses_get_questions( $rses_round_id );
		foreach ( $rses_questions as $rses_q ) :
			?>
			<div class="rses-ballot-question-preview">
				<strong><?php echo esc_html( $rses_q->question_title ); ?></strong> (<?php echo esc_html( $rses_q->question_type ); ?>)
				<ul>
					<?php foreach ( ElectionRepository::rses_get_options( (int) $rses_q->id ) as $rses_o ) : ?>
						<li><?php echo esc_html( $rses_o->option_label ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endforeach; ?>

		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=rses-elections' ) ); ?>">&larr; <?php esc_html_e( 'Back to list', 'relatasoft-secure-election-suite' ); ?></a></p>
		<?php
	}

	/**
	 * Render shortcode generator for publishing voting booths on pages/posts.
	 */
	public static function rses_render_shortcodes_page(): void {
		Capability::rses_require_admin();

		$rses_elections   = ElectionRepository::rses_list();
		$rses_focus_eid   = isset( $_GET['election_id'] ) ? absint( $_GET['election_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$rses_focus_rid   = isset( $_GET['round_id'] ) ? absint( $_GET['round_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap rses-wrap">
			<h1><?php esc_html_e( 'Shortcode Generator', 'relatasoft-secure-election-suite' ); ?></h1>

			<div class="rses-notice rses-notice-info">
				<p><?php esc_html_e( 'Copy a shortcode below and paste it into any WordPress page or post (block editor → Shortcode block, or classic editor). Only WordPress users enrolled with the Subscriber role may cast a ballot (Administrator/Editor alone cannot; dual-role Subscriber+Editor may).', 'relatasoft-secure-election-suite' ); ?></p>
				<ol>
					<li><?php esc_html_e( 'Create and open an election under Elections.', 'relatasoft-secure-election-suite' ); ?></li>
					<li><?php esc_html_e( 'Copy the voting booth shortcode for that round.', 'relatasoft-secure-election-suite' ); ?></li>
					<li><?php esc_html_e( 'Create a page (e.g. “Vote”) and paste the shortcode.', 'relatasoft-secure-election-suite' ); ?></li>
					<li><?php esc_html_e( 'Publish the page and share the URL with voters.', 'relatasoft-secure-election-suite' ); ?></li>
				</ol>
			</div>

			<h2><?php esc_html_e( 'Available shortcodes', 'relatasoft-secure-election-suite' ); ?></h2>
			<table class="widefat striped">
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
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Generated shortcodes by election', 'relatasoft-secure-election-suite' ); ?></h2>

			<?php if ( empty( $rses_elections ) ) : ?>
				<p>
					<?php esc_html_e( 'No elections yet.', 'relatasoft-secure-election-suite' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=rses-elections' ) ); ?>">
						<?php esc_html_e( 'Create an election first.', 'relatasoft-secure-election-suite' ); ?>
					</a>
				</p>
			<?php else : ?>
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
							$rses_eid = (int) $rses_election->id;
							$rses_rid = (int) $rses_round->id;

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
									<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=rses-elections&rses_edit=' . $rses_eid . '&round=' . $rses_rid ) ); ?>">
										<?php esc_html_e( 'Edit election', 'relatasoft-secure-election-suite' ); ?>
									</a>
									<a class="button" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=page' ) ); ?>" target="_blank" rel="noopener noreferrer">
										<?php esc_html_e( 'Create new page', 'relatasoft-secure-election-suite' ); ?>
									</a>
								</p>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
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
				<button type="button" class="button rses-copy-shortcode" data-rses-copy="<?php echo esc_attr( $shortcode ); ?>">
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
		<div class="wrap rses-wrap">
			<h1><?php esc_html_e( 'Voting Export', 'relatasoft-secure-election-suite' ); ?></h1>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Election', 'relatasoft-secure-election-suite' ); ?></th><th><?php esc_html_e( 'Export', 'relatasoft-secure-election-suite' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $rses_elections as $rses_e ) : ?>
						<?php
						$rses_rounds = ElectionRepository::rses_get_rounds( (int) $rses_e->id );
						foreach ( $rses_rounds as $rses_r ) :
							?>
							<tr>
								<td><?php echo esc_html( $rses_e->title . ' - ' . $rses_r->title ); ?></td>
								<td>
									<a class="button" href="<?php echo esc_url( Nonce::rses_url( admin_url( 'admin-post.php?action=rses_export_voting&election_id=' . $rses_e->id . '&round_id=' . $rses_r->id . '&format=zip' ), Nonce::RSES_ACTION_VOTING_EXPORT ) ); ?>">ZIP</a>
									<a class="button" href="<?php echo esc_url( Nonce::rses_url( admin_url( 'admin-post.php?action=rses_export_voting&election_id=' . $rses_e->id . '&round_id=' . $rses_r->id . '&format=json' ), Nonce::RSES_ACTION_VOTING_EXPORT ) ); ?>">JSON</a>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endforeach; ?>
				</tbody>
			</table>
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
			echo '<div class="rses-booth"><div class="rses-message rses-message-warning rses-login-required">' . esc_html__( 'Please log in to vote.', 'relatasoft-secure-election-suite' ) . '</div></div>';
			return;
		}

		if ( ! Capability::rses_can_vote() ) {
			echo '<div class="rses-booth"><div class="rses-message rses-message-error rses-vote-denied">' . esc_html__( 'Only users enrolled with the Subscriber role may cast a ballot. Sign in with a Subscriber account (Administrator and Editor accounts are not eligible unless they also have the Subscriber role).', 'relatasoft-secure-election-suite' ) . '</div></div>';
			return;
		}

		if ( ! $election_id || ! $round_id ) {
			echo '<div class="rses-booth"><div class="rses-message rses-message-warning">' . esc_html__( 'Election not specified.', 'relatasoft-secure-election-suite' ) . '</div></div>';
			return;
		}

		$rses_votes = EncryptedVoteRepository::rses_get_by_round( $round_id );
		$rses_voted = false;
		foreach ( $rses_votes as $rses_v ) {
			if ( (int) $rses_v->voter_user_id === get_current_user_id() ) {
				$rses_voted = true;
				break;
			}
		}
		if ( $rses_voted ) {
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
			echo '<div class="rses-booth"><div class="rses-message rses-message-warning">' . esc_html__( 'Please log in to view your receipt.', 'relatasoft-secure-election-suite' ) . '</div></div>';
			return;
		}

		$rses_hash = EncryptedVoteRepository::rses_get_receipt_hash( get_current_user_id(), $round_id );

		if ( ! $rses_hash ) {
			echo '<div class="rses-booth"><div class="rses-message rses-message-info">' . esc_html__( 'No vote receipt found.', 'relatasoft-secure-election-suite' ) . '</div></div>';
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
		<div class="rses-booth rses-booth-receipt" data-rses-booth="receipt">
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
				<code class="rses-receipt-hash" id="<?php echo esc_attr( $rses_id ); ?>"><?php echo esc_html( $hash ); ?></code>
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
