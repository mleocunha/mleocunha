<?php
/**
 * Official share submission controller.
 *
 * @package RelataSoft\SecureElectionSuite\Tallying
 */

namespace RelataSoft\SecureElectionSuite\Tallying;

use RelataSoft\SecureElectionSuite\Bootstrap\ModeLock;
use RelataSoft\SecureElectionSuite\Crypto\ShareVerifyService;
use RelataSoft\SecureElectionSuite\Crypto\ThresholdPartialDecrypt;
use RelataSoft\SecureElectionSuite\Database\Repository;
use RelataSoft\SecureElectionSuite\Exports\HashService;
use RelataSoft\SecureElectionSuite\KeyAuthority\ShareEncryptionService;
use RelataSoft\SecureElectionSuite\Security\AuditLogger;
use RelataSoft\SecureElectionSuite\Security\Capability;
use RelataSoft\SecureElectionSuite\Security\Nonce;
use RelataSoft\SecureElectionSuite\Security\Sanitizer;

defined( 'ABSPATH' ) || exit;

/**
 * Handles official Feldman share → threshold partial-decrypt contributions.
 *
 * The share value is used ephemerally to build Chaum–Pedersen-proven partials
 * and is never persisted. Reconstruction of x is prohibited.
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
	 * Handle share submission → store partial-decrypt contribution only.
	 */
	public static function rses_handle_submit(): void {
		Capability::rses_require_official();
		Nonce::rses_verify_or_die( Nonce::RSES_ACTION_SHARE_SUBMIT );
		ModeLock::rses_require_mode( ModeLock::RSES_MODE_TALLYING );

		$rses_import_id  = Sanitizer::rses_post_id( 'tally_import_id' );
		$rses_key_id     = Sanitizer::rses_post_id( 'key_id' );
		$rses_round_id   = Sanitizer::rses_post_id( 'election_round_id' );
		$rses_share_json = isset( $_POST['rses_share_json'] )
			? wp_unslash( $_POST['rses_share_json'] )
			: '';

		$rses_payload = Sanitizer::rses_json( $rses_share_json );

		if ( null === $rses_payload ) {
			wp_die( esc_html__( 'Invalid share JSON.', 'relatasoft-secure-election-suite' ) );
		}

		$rses_import = TallyImportRepository::rses_get( $rses_import_id );
		if ( ! $rses_import || 'verified' !== $rses_import->status ) {
			wp_die( esc_html__( 'Import not found or not verified.', 'relatasoft-secure-election-suite' ) );
		}

		$rses_manifest = TallyImportRepository::rses_get_manifest( $rses_import );
		$rses_tallies  = is_array( $rses_manifest['encrypted_tallies'] ?? null )
			? $rses_manifest['encrypted_tallies']
			: array();
		$rses_public   = is_array( $rses_manifest['public_key'] ?? null )
			? $rses_manifest['public_key']
			: array();

		try {
			ShareVerifyService::rses_validate_for_tally( $rses_payload );

			if ( ( $rses_payload['public_key']['p'] ?? '' ) !== ( $rses_public['p'] ?? null )
				|| ( $rses_payload['public_key']['y'] ?? '' ) !== ( $rses_public['y'] ?? null ) ) {
				throw new \RelataSoft\SecureElectionSuite\Crypto\CryptoException(
					__( 'Share public key mismatch.', 'relatasoft-secure-election-suite' )
				);
			}

			// Ephemeral: share → verified partials; share value never stored.
			$rses_contribution = ThresholdPartialDecrypt::rses_build_contribution( $rses_payload, $rses_tallies );
			ThresholdPartialDecrypt::rses_validate_contribution( $rses_contribution );
		} catch ( \RelataSoft\SecureElectionSuite\Crypto\CryptoException $rses_e ) {
			wp_die( esc_html( $rses_e->getMessage() ) );
		} finally {
			unset( $rses_payload );
		}

		$rses_share_index = (int) $rses_contribution['share_index'];

		$rses_existing = Repository::rses_count(
			'rses_official_share_submissions',
			'tally_import_id = %d AND share_index = %d',
			array( $rses_import_id, $rses_share_index )
		);

		if ( $rses_existing > 0 ) {
			wp_die( esc_html__( 'Share index already submitted.', 'relatasoft-secure-election-suite' ) );
		}

		$rses_encrypted = ShareEncryptionService::rses_encrypt( wp_json_encode( $rses_contribution ) );

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
			'partial_decrypt_submit',
			'share_submission',
			$rses_submission_id,
			array(
				'share_index'     => $rses_share_index,
				'tally_import_id' => $rses_import_id,
				'scheme_id'       => ThresholdPartialDecrypt::SCHEME_ID,
				'partials'        => count( $rses_contribution['partials'] ?? array() ),
			)
		);

		wp_safe_redirect( admin_url( 'admin.php?page=rses-share-submission&rses_submitted=1&import=' . $rses_import_id ) );
		exit;
	}

	/**
	 * Handle decryption when threshold contributions are met.
	 */
	public static function rses_handle_decryption(): void {
		Capability::rses_require_tally_admin();
		Nonce::rses_verify_or_die( Nonce::RSES_ACTION_DECRYPTION );
		ModeLock::rses_require_mode( ModeLock::RSES_MODE_TALLYING );

		$rses_import_id = Sanitizer::rses_post_id( 'tally_import_id' );

		$rses_result = TallyDecryptionService::rses_decrypt_import( $rses_import_id );

		if ( ! empty( $rses_result['success'] ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=rses-certification&rses_decrypted=1&import=' . $rses_import_id ) );
			exit;
		}

		wp_die( esc_html( (string) ( $rses_result['message'] ?? __( 'Decryption failed.', 'relatasoft-secure-election-suite' ) ) ) );
	}

	/**
	 * Get submissions for an import.
	 *
	 * @param int $import_id Import ID.
	 * @return array<int,object>
	 */
	public static function rses_get_submissions( int $import_id ): array {
		return Repository::rses_get_rows(
			'rses_official_share_submissions',
			'tally_import_id = %d',
			array( $import_id ),
			'share_index ASC'
		);
	}
}
