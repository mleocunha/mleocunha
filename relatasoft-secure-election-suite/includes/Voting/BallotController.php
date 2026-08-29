<?php
/**
 * Ballot controller.
 *
 * @package RelataSoft\SecureElectionSuite\Voting
 */

namespace RelataSoft\SecureElectionSuite\Voting;

use RelataSoft\SecureElectionSuite\Bootstrap\ModeLock;
use RelataSoft\SecureElectionSuite\Crypto\CryptoException;
use RelataSoft\SecureElectionSuite\Painel\Application\Identity\IdentityGateway;
use RelataSoft\SecureElectionSuite\Security\AuditLogger;
use RelataSoft\SecureElectionSuite\Security\Capability;
use RelataSoft\SecureElectionSuite\Security\Nonce;
use RelataSoft\SecureElectionSuite\I18n\RoleLabels;
use RelataSoft\SecureElectionSuite\Frontend\VoterJourney;
use RelataSoft\SecureElectionSuite\Security\Sanitizer;

defined( 'ABSPATH' ) || exit;

/**
 * Ballot builder and vote casting handlers.
 */
class BallotController {

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'admin_post_rses_save_ballot', array( self::class, 'rses_handle_save_ballot' ) );
		add_action( 'admin_post_rses_cast_vote', array( self::class, 'rses_handle_cast_vote' ) );
		add_action( 'wp_ajax_rses_cast_vote', array( self::class, 'rses_ajax_cast_vote' ) );
		add_action( 'wp_ajax_nopriv_rses_cast_vote', array( self::class, 'rses_ajax_login_required' ) );
	}

	/**
	 * Save ballot questions/options.
	 */
	public static function rses_handle_save_ballot(): void {
		Capability::rses_require_admin();
		Nonce::rses_verify_or_die( Nonce::RSES_ACTION_BALLOT_SAVE );
		ModeLock::rses_require_mode( ModeLock::RSES_MODE_VOTING );

		$rses_election_id = Sanitizer::rses_post_id( 'election_id' );
		$rses_round_id    = Sanitizer::rses_post_id( 'round_id' );
		$rses_q_title     = Sanitizer::rses_post_text( 'rses_question_title' );
		$rses_q_type      = Sanitizer::rses_text( $_POST['rses_question_type'] ?? 'single_choice' );
		$rses_options     = isset( $_POST['rses_options'] ) && is_array( $_POST['rses_options'] )
			? array_map( array( Sanitizer::class, 'rses_text' ), wp_unslash( $_POST['rses_options'] ) )
			: array();
		$rses_attachments = isset( $_POST['rses_option_attachments'] ) && is_array( $_POST['rses_option_attachments'] )
			? array_map( 'absint', wp_unslash( $_POST['rses_option_attachments'] ) )
			: array();

		$rses_qid = ElectionRepository::rses_create_question(
			array(
				'election_id'    => $rses_election_id,
				'round_id'       => $rses_round_id,
				'question_title' => $rses_q_title,
				'question_type'  => $rses_q_type,
				'min_choices'    => 'multiple_choice' === $rses_q_type ? 1 : ( 'yes_no' === $rses_q_type ? 1 : 1 ),
				'max_choices'    => 'multiple_choice' === $rses_q_type ? count( $rses_options ) : 1,
			)
		);

		foreach ( $rses_options as $rses_idx => $rses_label ) {
			if ( '' === $rses_label ) {
				continue;
			}

			$rses_attachment_id = isset( $rses_attachments[ $rses_idx ] )
				? OptionMedia::rses_sanitize_attachment_id( (int) $rses_attachments[ $rses_idx ] )
				: 0;

			ElectionRepository::rses_create_option(
				array(
					'question_id'    => $rses_qid,
					'option_label'   => $rses_label,
					'order_index'    => $rses_idx,
					'attachment_id'  => $rses_attachment_id,
				)
			);
		}

		AuditLogger::rses_log( 'ballot_save', 'question', $rses_qid );

		wp_safe_redirect( admin_url( 'admin.php?page=rses-elections&rses_edit=' . $rses_election_id . '&round=' . $rses_round_id ) );
		exit;
	}

	/**
	 * Handle vote cast via admin-post.
	 */
	public static function rses_handle_cast_vote(): void {
		Capability::rses_require_voter();
		Nonce::rses_verify_or_die( Nonce::RSES_ACTION_VOTE_CAST );
		ModeLock::rses_require_mode( ModeLock::RSES_MODE_VOTING );

		$rses_election_id = Sanitizer::rses_post_id( 'election_id' );
		$rses_round_id    = Sanitizer::rses_post_id( 'round_id' );
		$rses_ballot      = isset( $_POST['rses_ballot'] ) && is_array( $_POST['rses_ballot'] )
			? wp_unslash( $_POST['rses_ballot'] )
			: array();

		try {
			$rses_receipt = VoteEncryptionService::rses_cast_ballot(
				$rses_election_id,
				$rses_round_id,
				IdentityGateway::get()->session->currentUserId(),
				$rses_ballot
			);

			$rses_thank_you = VoterJourney::rses_thank_you_redirect_url( $rses_receipt, $rses_election_id, $rses_round_id );
			if ( '' !== $rses_thank_you ) {
				wp_safe_redirect( $rses_thank_you );
				exit;
			}

			wp_safe_redirect(
				add_query_arg(
					array(
						'rses_receipt' => $rses_receipt,
						'election_id'  => $rses_election_id,
						'round_id'     => $rses_round_id,
					),
					wp_get_referer() ?: home_url()
				)
			);
			exit;
		} catch ( CryptoException $rses_e ) {
			wp_die( esc_html( $rses_e->getMessage() ) );
		}
	}

	/**
	 * AJAX vote cast handler.
	 */
	public static function rses_ajax_cast_vote(): void {
		check_ajax_referer( 'rses_vote_cast', 'nonce' );

		if ( ! Capability::rses_can_vote() ) {
			wp_send_json_error(
				array(
					'message' => RoleLabels::rses_message_vote_denied_full(),
				),
				403
			);
		}

		ModeLock::rses_require_mode( ModeLock::RSES_MODE_VOTING );

		$rses_election_id = Sanitizer::rses_id( $_POST['election_id'] ?? 0 );
		$rses_round_id    = Sanitizer::rses_id( $_POST['round_id'] ?? 0 );
		$rses_ballot      = isset( $_POST['ballot'] ) && is_array( $_POST['ballot'] )
			? wp_unslash( $_POST['ballot'] )
			: array();

		try {
			$rses_receipt = VoteEncryptionService::rses_cast_ballot(
				$rses_election_id,
				$rses_round_id,
				IdentityGateway::get()->session->currentUserId(),
				$rses_ballot
			);

			$rses_thank_you = VoterJourney::rses_thank_you_redirect_url( $rses_receipt, $rses_election_id, $rses_round_id );

			wp_send_json_success(
				array(
					'receipt'      => $rses_receipt,
					'redirect_url' => $rses_thank_you,
				)
			);
		} catch ( CryptoException $rses_e ) {
			wp_send_json_error( array( 'message' => $rses_e->getMessage() ) );
		}
	}

	/**
	 * AJAX handler for non-logged-in users.
	 */
	public static function rses_ajax_login_required(): void {
		wp_send_json_error( array( 'message' => __( 'You must be logged in to vote.', 'relatasoft-secure-election-suite' ) ) );
	}
}
