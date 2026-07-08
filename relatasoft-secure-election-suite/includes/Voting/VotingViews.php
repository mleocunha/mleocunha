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
		$rses_edit_id   = isset( $_GET['rses_edit'] ) ? absint( $_GET['rses_edit'] ) : 0;
		$rses_round_id  = isset( $_GET['round'] ) ? absint( $_GET['round'] ) : 0;
		?>
		<div class="wrap rses-wrap">
			<h1><?php esc_html_e( 'Election Management', 'relatasoft-secure-election-suite' ); ?></h1>

			<?php if ( $rses_edit_id ) : ?>
				<?php self::rses_render_election_editor( $rses_edit_id, $rses_round_id ); ?>
			<?php else : ?>
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
							<th><label for="rses_voting_method"><?php esc_html_e( 'Voting Method', 'relatasoft-secure-election-suite' ); ?></label></th>
							<td>
								<select name="rses_voting_method" id="rses_voting_method">
									<?php
									$rses_methods = array( 'yes_no', 'single_choice', 'multiple_choice', 'ranked_choice', 'numeric', 'fptp', 'list_voting', 'single_candidate', 'custom' );
									foreach ( $rses_methods as $rses_m ) :
										?>
										<option value="<?php echo esc_attr( $rses_m ); ?>"><?php echo esc_html( $rses_m ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th><label for="rses_key_id"><?php esc_html_e( 'Public Key ID', 'relatasoft-secure-election-suite' ); ?></label></th>
							<td><input type="number" name="rses_key_id" id="rses_key_id" min="0" /></td>
						</tr>
					</table>
					<?php submit_button( __( 'Create Election', 'relatasoft-secure-election-suite' ) ); ?>
				</form>

				<h2><?php esc_html_e( 'Elections', 'relatasoft-secure-election-suite' ); ?></h2>
				<table class="widefat striped">
					<thead><tr><th>ID</th><th><?php esc_html_e( 'Title', 'relatasoft-secure-election-suite' ); ?></th><th><?php esc_html_e( 'Status', 'relatasoft-secure-election-suite' ); ?></th><th><?php esc_html_e( 'Actions', 'relatasoft-secure-election-suite' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $rses_elections as $rses_e ) : ?>
							<tr>
								<td><?php echo esc_html( (string) $rses_e->id ); ?></td>
								<td><?php echo esc_html( $rses_e->title ); ?></td>
								<td><?php echo esc_html( $rses_e->status ); ?></td>
								<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=rses-elections&rses_edit=' . $rses_e->id ) ); ?>"><?php esc_html_e( 'Edit', 'relatasoft-secure-election-suite' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
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
			echo '<p class="rses-login-required">' . esc_html__( 'Please log in to vote.', 'relatasoft-secure-election-suite' ) . '</p>';
			return;
		}

		if ( ! Capability::rses_can_vote() ) {
			echo '<p>' . esc_html__( 'Your account does not have voting permissions.', 'relatasoft-secure-election-suite' ) . '</p>';
			return;
		}

		if ( ! $election_id || ! $round_id ) {
			echo '<p>' . esc_html__( 'Election not specified.', 'relatasoft-secure-election-suite' ) . '</p>';
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

		echo BallotRenderer::rses_render( $election_id, $round_id );

		if ( isset( $_GET['rses_receipt'] ) ) {
			echo '<div class="rses-receipt-notice"><p><strong>' . esc_html__( 'Vote cast successfully. Receipt:', 'relatasoft-secure-election-suite' ) . '</strong> <code>' . esc_html( sanitize_text_field( wp_unslash( $_GET['rses_receipt'] ) ) ) . '</code></p></div>';
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
			echo '<p>' . esc_html__( 'Please log in to view your receipt.', 'relatasoft-secure-election-suite' ) . '</p>';
			return;
		}

		$rses_hash = EncryptedVoteRepository::rses_get_receipt_hash( get_current_user_id(), $round_id );

		if ( ! $rses_hash ) {
			echo '<p>' . esc_html__( 'No vote receipt found.', 'relatasoft-secure-election-suite' ) . '</p>';
			return;
		}

		echo '<div class="rses-voter-receipt"><p><strong>' . esc_html__( 'Your Vote Receipt Hash:', 'relatasoft-secure-election-suite' ) . '</strong></p><code>' . esc_html( $rses_hash ) . '</code></div>';
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
