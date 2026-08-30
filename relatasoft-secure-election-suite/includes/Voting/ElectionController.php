<?php
/**
 * Election controller.
 *
 * @package RelataSoft\SecureElectionSuite\Voting
 */

namespace RelataSoft\SecureElectionSuite\Voting;

use RelataSoft\SecureElectionSuite\Bootstrap\ModeLock;
use RelataSoft\SecureElectionSuite\Security\AuditLogger;
use RelataSoft\SecureElectionSuite\Security\Capability;
use RelataSoft\SecureElectionSuite\Security\Nonce;
use RelataSoft\SecureElectionSuite\Security\Sanitizer;

defined( 'ABSPATH' ) || exit;

/**
 * Election management handlers.
 */
class ElectionController {

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'admin_post_rses_save_election', array( self::class, 'rses_handle_save_election' ) );
		add_action( 'admin_post_rses_election_action', array( self::class, 'rses_handle_election_action' ) );
		add_action( 'admin_post_rses_save_round_audio', array( self::class, 'rses_handle_save_round_audio' ) );
		add_action( 'admin_post_rses_export_voting', array( self::class, 'rses_handle_export' ) );
		add_action( 'admin_post_rses_dump_open_elections', array( self::class, 'rses_handle_dump_open_elections' ) );
	}

	/**
	 * Option key for round end audio attachment ID.
	 */
	public static function rses_round_audio_option_key( int $round_id ): string {
		return 'rses_round_audio_' . absint( $round_id );
	}

	/**
	 * Get round end audio attachment ID (0 = none).
	 */
	public static function rses_get_round_end_audio_id( int $round_id ): int {
		return absint( get_option( self::rses_round_audio_option_key( $round_id ), 0 ) );
	}

	/**
	 * Public URL for round end audio, or empty string.
	 */
	public static function rses_get_round_end_audio_url( int $round_id ): string {
		$id = self::rses_get_round_end_audio_id( $round_id );
		if ( $id < 1 ) {
			return '';
		}
		$url = wp_get_attachment_url( $id );
		return is_string( $url ) ? $url : '';
	}

	/**
	 * Persist round end audio attachment.
	 */
	public static function rses_handle_save_round_audio(): void {
		Capability::rses_require_admin();
		Nonce::rses_verify_or_die( Nonce::RSES_ACTION_ELECTION_SAVE );
		ModeLock::rses_require_mode( ModeLock::RSES_MODE_VOTING );

		$election_id = Sanitizer::rses_post_id( 'election_id' );
		$round_id    = Sanitizer::rses_post_id( 'round_id' );
		$audio_id    = absint( $_POST['rses_round_end_audio_id'] ?? 0 );

		if ( $round_id > 0 ) {
			if ( $audio_id > 0 ) {
				$mime = (string) get_post_mime_type( $audio_id );
				if ( ! preg_match( '#^audio/(mpeg|wav|ogg|x-wav|wave)#i', $mime ) ) {
					// Soft reject: clear invalid types.
					$audio_id = 0;
				}
			}
			update_option( self::rses_round_audio_option_key( $round_id ), $audio_id, false );
			AuditLogger::rses_log(
				'round_end_audio_saved',
				'round',
				$round_id,
				array( 'attachment_id' => $audio_id )
			);
		}

		wp_safe_redirect( admin_url( 'admin.php?page=rses-elections&rses_edit=' . $election_id . '&round=' . $round_id ) );
		exit;
	}

	/**
	 * Save election.
	 */
	public static function rses_handle_save_election(): void {
		Capability::rses_require_admin();
		Nonce::rses_verify_or_die( Nonce::RSES_ACTION_ELECTION_SAVE );
		ModeLock::rses_require_mode( ModeLock::RSES_MODE_VOTING );

		$rses_title   = Sanitizer::rses_post_text( 'rses_election_title' );
		$rses_method  = Sanitizer::rses_text( $_POST['rses_voting_method'] ?? 'single_choice' );
		$rses_desc    = Sanitizer::rses_textarea( $_POST['rses_election_description'] ?? '' );
		$rses_key_id  = Sanitizer::rses_post_id( 'rses_key_id' );

		if ( '' === $rses_title ) {
			wp_die( esc_html__( 'Election title is required.', 'relatasoft-secure-election-suite' ) );
		}

		if ( $rses_key_id < 1 || ! \RelataSoft\SecureElectionSuite\KeyAuthority\KeyRepository::rses_get( $rses_key_id ) ) {
			wp_die( esc_html__( 'A valid imported public key is required.', 'relatasoft-secure-election-suite' ) );
		}

		$rses_election_id = ElectionRepository::rses_create(
			array(
				'title'         => $rses_title,
				'description'   => $rses_desc,
				'voting_method' => $rses_method,
				'status'        => 'draft',
			)
		);

		$rses_round_id = ElectionRepository::rses_create_round(
			array(
				'election_id'  => $rses_election_id,
				'round_number' => 1,
				'title'        => __( 'Initial Round', 'relatasoft-secure-election-suite' ),
				'key_id'       => $rses_key_id ?: null,
			)
		);

		AuditLogger::rses_log( 'election_create', 'election', $rses_election_id );

		wp_safe_redirect( admin_url( 'admin.php?page=rses-elections&rses_edit=' . $rses_election_id . '&round=' . $rses_round_id ) );
		exit;
	}

	/**
	 * Handle election actions (open/close).
	 */
	public static function rses_handle_election_action(): void {
		Capability::rses_require_admin();
		Nonce::rses_verify_or_die( Nonce::RSES_ACTION_ELECTION_SAVE );
		ModeLock::rses_require_mode( ModeLock::RSES_MODE_VOTING );

		$rses_election_id = Sanitizer::rses_post_id( 'election_id' );
		$rses_round_id    = Sanitizer::rses_post_id( 'round_id' );
		$rses_action      = Sanitizer::rses_post_text( 'rses_action' );

		switch ( $rses_action ) {
			case 'open':
				ElectionRepository::rses_update_round_status( $rses_round_id, 'open' );
				ElectionRepository::rses_update_status( $rses_election_id, 'open' );
				AuditLogger::rses_log( 'election_open', 'election', $rses_election_id );
				break;
			case 'close':
				ElectionRepository::rses_update_round_status( $rses_round_id, 'closed' );
				ElectionRepository::rses_update_status( $rses_election_id, 'closed' );
				EncryptedTallyService::rses_compute_tallies( $rses_round_id );
				AuditLogger::rses_log( 'election_close', 'election', $rses_election_id );
				break;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=rses-elections&rses_edit=' . $rses_election_id ) );
		exit;
	}

	/**
	 * Handle voting export.
	 */
	public static function rses_handle_export(): void {
		Capability::rses_require_admin();
		Nonce::rses_verify_or_die( Nonce::RSES_ACTION_VOTING_EXPORT );
		ModeLock::rses_require_mode( ModeLock::RSES_MODE_VOTING );

		$rses_election_id = Sanitizer::rses_id( $_GET['election_id'] ?? 0 );
		$rses_round_id    = Sanitizer::rses_id( $_GET['round_id'] ?? 0 );
		$rses_format      = Sanitizer::rses_text( $_GET['format'] ?? 'zip' );

		VotingExportService::rses_export( $rses_election_id, $rses_round_id, $rses_format );
	}

	/**
	 * JSON dump of open elections for automation scrapers (admin only).
	 */
	public static function rses_handle_dump_open_elections(): void {
		Capability::rses_require_admin();
		ModeLock::rses_require_mode( ModeLock::RSES_MODE_VOTING );

		$rses_payload = OpenElectionsService::rses_snapshot();

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		echo wp_json_encode( $rses_payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}
}
