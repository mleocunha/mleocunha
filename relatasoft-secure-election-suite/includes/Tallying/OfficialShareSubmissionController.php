<?php
/**
 * Official share submission controller.
 *
 * @package RelataSoft\SecureElectionSuite\Tallying
 */

namespace RelataSoft\SecureElectionSuite\Tallying;

use RelataSoft\SecureElectionSuite\Bootstrap\ModeLock;
use RelataSoft\SecureElectionSuite\Crypto\ShamirSecretSharing;
use RelataSoft\SecureElectionSuite\Database\Repository;
use RelataSoft\SecureElectionSuite\Exports\HashService;
use RelataSoft\SecureElectionSuite\KeyAuthority\KeyExportService;
use RelataSoft\SecureElectionSuite\KeyAuthority\KeyRepository;
use RelataSoft\SecureElectionSuite\KeyAuthority\ShareEncryptionService;
use RelataSoft\SecureElectionSuite\Security\AuditLogger;
use RelataSoft\SecureElectionSuite\Security\Capability;
use RelataSoft\SecureElectionSuite\Security\Nonce;
use RelataSoft\SecureElectionSuite\Security\Sanitizer;

defined( 'ABSPATH' ) || exit;

/**
 * Handles official Shamir share submissions for tallying.
 */
class OfficialShareSubmissionController {

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'admin_post_rses_submit_share', array( self::class, 'rses_handle_submit' ) );
		add_action( 'admin_post_rses_run_decryption', array( self::class, 'rses_handle_decryption' ) );
	}

	/**
	 * Handle share submission.
	 */
	public static function rses_handle_submit(): void {
		Capability::rses_require_official();
		Nonce::rses_verify_or_die( Nonce::RSES_ACTION_SHARE_SUBMIT );
		ModeLock::rses_require_mode( ModeLock::RSES_MODE_TALLYING );

		$rses_import_id = Sanitizer::rses_post_id( 'tally_import_id' );
		$rses_key_id    = Sanitizer::rses_post_id( 'key_id' );
		$rses_round_id  = Sanitizer::rses_post_id( 'election_round_id' );
		$rses_share_json = isset( $_POST['rses_share_json'] )
			? wp_unslash( $_POST['rses_share_json'] )
			: '';

		$rses_parsed = Sanitizer::rses_json( $rses_share_json );

		if ( null === $rses_parsed ) {
			wp_die( esc_html__( 'Invalid share JSON.', 'relatasoft-secure-election-suite' ) );
		}

		$rses_payload = KeyExportService::rses_unwrap_share_payload( $rses_parsed );

		try {
			ShamirSecretSharing::validateSharePayload( $rses_payload );
		} catch ( \RelataSoft\SecureElectionSuite\Crypto\CryptoException $rses_e ) {
			wp_die( esc_html( $rses_e->getMessage() ) );
		}

		$rses_share_index = (int) $rses_payload['share_index'];

		$rses_existing = Repository::rses_count(
			'rses_official_share_submissions',
			'tally_import_id = %d AND share_index = %d',
			array( $rses_import_id, $rses_share_index )
		);

		if ( $rses_existing > 0 ) {
			wp_die( esc_html__( 'Share index already submitted.', 'relatasoft-secure-election-suite' ) );
		}

		$rses_encrypted = ShareEncryptionService::rses_encrypt( wp_json_encode( $rses_payload ) );

		$rses_row = array(
			'tally_import_id'         => $rses_import_id,
			'key_id'                  => $rses_key_id,
			'election_round_id'       => $rses_round_id,
			'official_user_id'        => get_current_user_id(),
			'share_index'             => $rses_share_index,
			'share_payload_encrypted' => $rses_encrypted,
			'submitted_at'            => current_time( 'mysql', true ),
		);

		$rses_row['audit_hash'] = HashService::rses_hash_json( $rses_row );

		$rses_submission_id = Repository::rses_insert(
			'rses_official_share_submissions',
			$rses_row,
			array( '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s' )
		);

		AuditLogger::rses_log(
			'share_submit',
			'share_submission',
			$rses_submission_id,
			array(
				'share_index'    => $rses_share_index,
				'tally_import_id'=> $rses_import_id,
			)
		);

		wp_safe_redirect( admin_url( 'admin.php?page=rses-share-submission&rses_submitted=1&import=' . $rses_import_id ) );
		exit;
	}

	/**
	 * Handle decryption when threshold met.
	 */
	public static function rses_handle_decryption(): void {
		Capability::rses_require_tally_admin();
		Nonce::rses_verify_or_die( Nonce::RSES_ACTION_DECRYPTION );
		ModeLock::rses_require_mode( ModeLock::RSES_MODE_TALLYING );

		$rses_import_id = Sanitizer::rses_post_id( 'tally_import_id' );

		$rses_result = TallyDecryptionService::rses_decrypt_import( $rses_import_id );

		if ( ! $rses_result['success'] ) {
			wp_die( esc_html( $rses_result['message'] ) );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=rses-certification&rses_decrypted=1&import=' . $rses_import_id ) );
		exit;
	}

	/**
	 * Get submission count for import.
	 *
	 * @param int $import_id Import ID.
	 * @return int
	 */
	public static function rses_get_submission_count( int $import_id ): int {
		return Repository::rses_count(
			'rses_official_share_submissions',
			'tally_import_id = %d',
			array( $import_id )
		);
	}

	/**
	 * Get submissions for import.
	 *
	 * @param int $import_id Import ID.
	 * @return array<int,object>
	 */
	public static function rses_get_submissions( int $import_id ): array {
		return Repository::rses_get_rows(
			'rses_official_share_submissions',
			'tally_import_id = %d',
			array( $import_id )
		);
	}
}
