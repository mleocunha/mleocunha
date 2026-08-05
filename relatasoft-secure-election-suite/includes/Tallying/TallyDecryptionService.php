<?php
/**
 * Tally decryption service.
 *
 * @package RelataSoft\SecureElectionSuite\Tallying
 */

namespace RelataSoft\SecureElectionSuite\Tallying;

use RelataSoft\SecureElectionSuite\Crypto\BigInt;
use RelataSoft\SecureElectionSuite\Crypto\CryptoException;
use RelataSoft\SecureElectionSuite\Crypto\ElGamalCiphertext;
use RelataSoft\SecureElectionSuite\Crypto\ThresholdPartialDecrypt;
use RelataSoft\SecureElectionSuite\KeyAuthority\ShareEncryptionService;
use RelataSoft\SecureElectionSuite\Security\AuditLogger;
use RelataSoft\SecureElectionSuite\Security\Capability;

defined( 'ABSPATH' ) || exit;

/**
 * Combine Chaum–Pedersen-proven partial decrypts — never reconstruct x.
 */
class TallyDecryptionService {

	/**
	 * Decrypt tallies for an import when threshold contributions are available.
	 *
	 * @param int $import_id Import ID.
	 * @return array{success:bool,message:string,results?:array<string,mixed>}
	 */
	public static function rses_decrypt_import( int $import_id ): array {
		if ( ! Capability::rses_can_tally_and_certify() ) {
			AuditLogger::rses_log(
				'tally_decrypt_denied',
				'tally_import',
				$import_id,
				array( 'user_id' => get_current_user_id() )
			);
			return array(
				'success' => false,
				'message' => __( 'Only users with the Administrator role may decrypt tallies.', 'relatasoft-secure-election-suite' ),
			);
		}

		$rses_import = TallyImportRepository::rses_get( $import_id );

		if ( ! $rses_import || 'verified' !== $rses_import->status ) {
			return array(
				'success' => false,
				'message' => __( 'Import not found or not verified.', 'relatasoft-secure-election-suite' ),
			);
		}

		$rses_manifest  = TallyImportRepository::rses_get_manifest( $rses_import );
		$rses_public    = $rses_manifest['public_key'] ?? array();
		$rses_tallies   = $rses_manifest['encrypted_tallies'] ?? array();
		$rses_threshold = (int) ( $rses_manifest['round']['threshold_t'] ?? $rses_manifest['manifest']['threshold_t'] ?? 3 );

		$rses_submissions = OfficialShareSubmissionController::rses_get_submissions( $import_id );

		if ( count( $rses_submissions ) < $rses_threshold ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: 1: current shares, 2: required threshold */
					__( 'Insufficient shares: %1$d of %2$d required.', 'relatasoft-secure-election-suite' ),
					count( $rses_submissions ),
					$rses_threshold
				),
			);
		}

		try {
			$rses_p = BigInt::fromDecimalString( $rses_public['p'] );
			$rses_q = BigInt::fromDecimalString( $rses_public['q'] );
			$rses_g = BigInt::fromDecimalString( $rses_public['g'] );

			$rses_contributions = array();
			foreach ( array_slice( $rses_submissions, 0, $rses_threshold ) as $rses_sub ) {
				$rses_pkg = json_decode(
					ShareEncryptionService::rses_decrypt( $rses_sub->share_payload_encrypted ),
					true
				);
				if ( ! is_array( $rses_pkg ) ) {
					throw new CryptoException( __( 'Invalid contribution payload.', 'relatasoft-secure-election-suite' ) );
				}
				ThresholdPartialDecrypt::rses_validate_contribution( $rses_pkg );

				if ( ( $rses_pkg['public_key']['p'] ?? '' ) !== ( $rses_public['p'] ?? null )
					|| ( $rses_pkg['public_key']['y'] ?? '' ) !== ( $rses_public['y'] ?? null ) ) {
					throw new CryptoException( __( 'Contribution public key mismatch.', 'relatasoft-secure-election-suite' ) );
				}

				$rses_contributions[] = $rses_pkg;
			}

			// Index partials by (question_id, option_id).
			$rses_by_tally = array();
			foreach ( $rses_contributions as $rses_pkg ) {
				foreach ( (array) ( $rses_pkg['partials'] ?? array() ) as $rses_partial ) {
					if ( ! is_array( $rses_partial ) ) {
						continue;
					}
					$rses_key = (string) ( $rses_partial['question_id'] ?? '' ) . ':' . (string) ( $rses_partial['option_id'] ?? '' );
					$rses_by_tally[ $rses_key ][] = $rses_partial;
				}
			}

			$rses_decrypted = array();

			foreach ( $rses_tallies as $rses_tally ) {
				if ( ! is_array( $rses_tally ) ) {
					continue;
				}
				$rses_key = (string) ( $rses_tally['question_id'] ?? '' ) . ':' . (string) ( $rses_tally['option_id'] ?? '' );
				$rses_partials = $rses_by_tally[ $rses_key ] ?? array();
				if ( count( $rses_partials ) < $rses_threshold ) {
					throw new CryptoException(
						sprintf(
							/* translators: %s: question/option key */
							__( 'Missing partial decrypts for tally %s.', 'relatasoft-secure-election-suite' ),
							$rses_key
						)
					);
				}

				$rses_ct = ElGamalCiphertext::fromDecimalStrings(
					(string) $rses_tally['aggregate_alpha'],
					(string) $rses_tally['aggregate_beta']
				);

				$rses_alpha_x = ThresholdPartialDecrypt::rses_combine_partials(
					array_slice( $rses_partials, 0, $rses_threshold ),
					$rses_p,
					$rses_q
				);

				$rses_max   = (int) ( $rses_tally['ballot_count'] ?? $rses_tally['max_decode_count'] ?? 1000 );
				$rses_count = ThresholdPartialDecrypt::rses_decrypt_and_decode(
					$rses_ct,
					$rses_alpha_x,
					$rses_p,
					$rses_g,
					$rses_max
				);

				$rses_decrypted[] = array(
					'question_id' => $rses_tally['question_id'] ?? null,
					'option_id'   => $rses_tally['option_id'] ?? null,
					'count'       => $rses_count,
				);
			}

			$rses_result_data = array(
				'decrypted_results' => $rses_decrypted,
				'import_id'         => $import_id,
				'threshold'         => $rses_threshold,
				'submissions'       => count( $rses_submissions ),
				'scheme_id'         => ThresholdPartialDecrypt::SCHEME_ID,
				'private_key_reconstruction' => 'prohibited',
			);

			set_transient( 'rses_decryption_result_' . $import_id, $rses_result_data, HOUR_IN_SECONDS );

			AuditLogger::rses_log(
				'tally_decrypt_threshold_cp',
				'tally_import',
				$import_id,
				array(
					'threshold'   => $rses_threshold,
					'submissions' => count( $rses_submissions ),
					'scheme_id'   => ThresholdPartialDecrypt::SCHEME_ID,
				)
			);

			return array(
				'success' => true,
				'message' => __( 'Tally decrypted successfully via threshold partial decryption (no private key reconstruction).', 'relatasoft-secure-election-suite' ),
				'results' => $rses_result_data,
			);
		} catch ( CryptoException $rses_e ) {
			return array(
				'success' => false,
				'message' => $rses_e->getMessage(),
			);
		}
	}
}
