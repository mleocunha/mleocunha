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
use RelataSoft\SecureElectionSuite\Database\Schema;
use RelataSoft\SecureElectionSuite\Exports\HashService;
use RelataSoft\SecureElectionSuite\KeyAuthority\KeyExportService;
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
 *
 * Each fraction is bound to one verified tally import (one election/round
 * package). Key labels may collide across voting servers; matching uses the
 * imported public key (p/q/g/y).
 */
class OfficialShareSubmissionController {

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'admin_post_rses_submit_share', array( self::class, 'rses_handle_submit' ) );
		add_action( 'admin_post_rses_clear_shares', array( self::class, 'rses_handle_clear' ) );
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

		$rses_import = TallyImportRepository::rses_get( $rses_import_id );
		if ( ! $rses_import || 'verified' !== $rses_import->status ) {
			wp_die( esc_html__( 'This election package is not available for fraction submission. Import and verify the voting results first.', 'relatasoft-secure-election-suite' ) );
		}

		$rses_manifest = TallyImportRepository::rses_get_manifest( $rses_import );
		if ( empty( $rses_manifest['public_key']['y'] ) ) {
			wp_die( esc_html__( 'This import has no public key. Re-import the voting package for this election.', 'relatasoft-secure-election-suite' ) );
		}

		$rses_tallies = is_array( $rses_manifest['encrypted_tallies'] ?? null )
			? $rses_manifest['encrypted_tallies']
			: array();

		$rses_parsed = Sanitizer::rses_json( $rses_share_json );

		if ( null === $rses_parsed ) {
			wp_die( esc_html__( 'Invalid share JSON.', 'relatasoft-secure-election-suite' ) );
		}

		$rses_payload = KeyExportService::rses_unwrap_share_payload( $rses_parsed );

		try {
			ShareVerifyService::rses_validate_for_tally( $rses_payload );

			if ( ! TallyImportRepository::rses_share_matches_import_public_key( $rses_payload, $rses_manifest ) ) {
				$rses_expected = TallyImportRepository::rses_key_identity( $rses_import, $rses_manifest );
				$rses_got_fp   = TallyImportRepository::rses_public_key_fingerprint(
					is_array( $rses_payload['public_key'] ?? null ) ? $rses_payload['public_key'] : array()
				);
				wp_die(
					esc_html(
						sprintf(
							/* translators: 1: election title, 2: expected fingerprint, 3: submitted fingerprint */
							__( 'This Shamir fraction does not belong to “%1$s”. Expected public-key fingerprint %2$s, but the JSON has %3$s. Paste the fraction for this imported election (key labels may be identical across servers — match by fingerprint / source site).', 'relatasoft-secure-election-suite' ),
							TallyImportRepository::rses_display_election_title( $rses_import ),
							$rses_expected['fingerprint'] ?: '—',
							$rses_got_fp ?: '—'
						)
					)
				);
			}

			$rses_user_id = get_current_user_id();
			if ( self::rses_official_has_submission( $rses_import_id, $rses_user_id ) ) {
				wp_die(
					esc_html(
						sprintf(
							/* translators: %s: election title */
							__( 'You already submitted a Shamir fraction for “%s”. Each official submits one fraction per imported election.', 'relatasoft-secure-election-suite' ),
							TallyImportRepository::rses_display_election_title( $rses_import )
						)
					)
				);
			}

			// Ephemeral: share → verified partials; share value never stored.
			$rses_contribution = ThresholdPartialDecrypt::rses_build_contribution( $rses_payload, $rses_tallies );
			ThresholdPartialDecrypt::rses_validate_contribution( $rses_contribution );
		} catch ( \RelataSoft\SecureElectionSuite\Crypto\CryptoException $rses_e ) {
			wp_die( esc_html( $rses_e->getMessage() ) );
		} finally {
			unset( $rses_payload, $rses_parsed );
		}

		$rses_share_index = (int) $rses_contribution['share_index'];

		$rses_existing = Repository::rses_count(
			'rses_official_share_submissions',
			'tally_import_id = %d AND share_index = %d',
			array( $rses_import_id, $rses_share_index )
		);

		if ( $rses_existing > 0 ) {
			wp_die( esc_html__( 'Share index already submitted for this election.', 'relatasoft-secure-election-suite' ) );
		}

		// Prefer identity from the imported package (local voting key id), not KA key_id.
		if ( $rses_key_id < 1 ) {
			$rses_key_id = (int) ( $rses_manifest['round']['key_id'] ?? $rses_manifest['manifest']['key_id'] ?? 0 );
		}
		if ( $rses_round_id < 1 ) {
			$rses_round_id = (int) ( $rses_manifest['round']['id'] ?? $rses_manifest['manifest']['round_id'] ?? 0 );
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
				'fingerprint'     => TallyImportRepository::rses_public_key_fingerprint(
					is_array( $rses_manifest['public_key'] ) ? $rses_manifest['public_key'] : array()
				),
			)
		);

		wp_safe_redirect( admin_url( 'admin.php?page=rses-share-submission&rses_submitted=1&import=' . $rses_import_id ) );
		exit;
	}

	/**
	 * Admin: clear all submitted contributions for one imported election.
	 */
	public static function rses_handle_clear(): void {
		Capability::rses_require_tally_admin();
		Nonce::rses_verify_or_die( Nonce::RSES_ACTION_SHARE_CLEAR );
		ModeLock::rses_require_mode( ModeLock::RSES_MODE_TALLYING );

		$rses_import_id = Sanitizer::rses_post_id( 'tally_import_id' );
		$rses_import    = TallyImportRepository::rses_get( $rses_import_id );

		if ( ! $rses_import ) {
			wp_die( esc_html__( 'Import not found.', 'relatasoft-secure-election-suite' ) );
		}

		$rses_deleted = self::rses_clear_submissions_for_import( $rses_import_id );

		AuditLogger::rses_log(
			'share_submissions_cleared',
			'tally_import',
			$rses_import_id,
			array(
				'deleted'        => $rses_deleted,
				'election_title' => TallyImportRepository::rses_display_election_title( $rses_import ),
				'cleared_by'     => get_current_user_id(),
			)
		);

		wp_safe_redirect(
			admin_url(
				'admin.php?page=rses-share-submission&rses_shares_cleared=1&import=' . $rses_import_id . '&count=' . (int) $rses_deleted
			)
		);
		exit;
	}

	/**
	 * Delete all official share submissions for an import and drop decryption cache.
	 *
	 * @param int $import_id Import ID.
	 * @return int Number of rows deleted.
	 */
	public static function rses_clear_submissions_for_import( int $import_id ): int {
		global $wpdb;

		if ( $import_id < 1 ) {
			return 0;
		}

		$rses_before = self::rses_get_submission_count( $import_id );
		$rses_table  = Schema::rses_table( 'rses_official_share_submissions' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$rses_table,
			array( 'tally_import_id' => $import_id ),
			array( '%d' )
		);

		delete_transient( 'rses_decryption_result_' . $import_id );
		delete_transient( 'rses_certification_' . $import_id );

		return $rses_before;
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
	 * Whether an official already submitted a contribution for this import/election.
	 *
	 * @param int $import_id Import ID.
	 * @param int $user_id   Official user ID.
	 */
	public static function rses_official_has_submission( int $import_id, int $user_id ): bool {
		if ( $import_id < 1 || $user_id < 1 ) {
			return false;
		}
		return Repository::rses_count(
			'rses_official_share_submissions',
			'tally_import_id = %d AND official_user_id = %d',
			array( $import_id, $user_id )
		) > 0;
	}

	/**
	 * Get the current official's submission for an import, if any.
	 *
	 * @param int $import_id Import ID.
	 * @param int $user_id   Official user ID.
	 * @return object|null
	 */
	public static function rses_get_official_submission( int $import_id, int $user_id ): ?object {
		$rses_rows = Repository::rses_get_rows(
			'rses_official_share_submissions',
			'tally_import_id = %d AND official_user_id = %d',
			array( $import_id, $user_id ),
			'id DESC'
		);
		return $rses_rows[0] ?? null;
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
