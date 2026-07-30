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
use RelataSoft\SecureElectionSuite\Crypto\HomomorphicTally;
use RelataSoft\SecureElectionSuite\Crypto\ShamirSecretSharing;
use RelataSoft\SecureElectionSuite\KeyAuthority\ShareEncryptionService;
use RelataSoft\SecureElectionSuite\Security\AuditLogger;
use RelataSoft\SecureElectionSuite\Security\Capability;

defined( 'ABSPATH' ) || exit;

/**
 * In-memory Shamir reconstruction and tally decryption.
 */
class TallyDecryptionService {

	/**
	 * Decrypt tallies for an import when threshold shares are available.
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
			$rses_y = BigInt::fromDecimalString( $rses_public['y'] );

			$rses_share_points = array();
			$rses_field_prime  = null;

			foreach ( array_slice( $rses_submissions, 0, $rses_threshold ) as $rses_sub ) {
				$rses_payload = json_decode(
					ShareEncryptionService::rses_decrypt( $rses_sub->share_payload_encrypted ),
					true
				);

				if ( ! is_array( $rses_payload ) ) {
					throw new CryptoException( __( 'Invalid share payload.', 'relatasoft-secure-election-suite' ) );
				}

				ShamirSecretSharing::validateSharePayload( $rses_payload );

				if ( $rses_payload['public_key']['p'] !== $rses_public['p']
					|| $rses_payload['public_key']['y'] !== $rses_public['y'] ) {
					throw new CryptoException( __( 'Share public key mismatch.', 'relatasoft-secure-election-suite' ) );
				}

				$rses_field_prime = BigInt::fromDecimalString( $rses_payload['field_prime'] );

				$rses_share_points[] = array(
					'x' => (int) $rses_payload['share_index'],
					'y' => BigInt::fromDecimalString( $rses_payload['share_value'] ),
				);
			}

			$rses_x = ShamirSecretSharing::reconstructWithThreshold(
				$rses_share_points,
				$rses_field_prime,
				$rses_threshold
			);

			$rses_y_check = BigInt::modPow( $rses_g, $rses_x, $rses_p );
			if ( \gmp_cmp( $rses_y_check, $rses_y ) !== 0 ) {
				throw new CryptoException( __( 'Reconstructed key failed validation.', 'relatasoft-secure-election-suite' ) );
			}

			$rses_decrypted = array();

			foreach ( $rses_tallies as $rses_tally ) {
				$rses_ct = ElGamalCiphertext::fromDecimalStrings(
					$rses_tally['aggregate_alpha'],
					$rses_tally['aggregate_beta']
				);

				$rses_max = (int) ( $rses_tally['ballot_count'] ?? $rses_tally['max_decode_count'] ?? 1000 );

				$rses_count = HomomorphicTally::decryptAndDecode(
					$rses_ct,
					$rses_p,
					$rses_q,
					$rses_g,
					$rses_x,
					$rses_max
				);

				$rses_decrypted[] = array(
					'question_id' => $rses_tally['question_id'] ?? null,
					'option_id'   => $rses_tally['option_id'] ?? null,
					'count'       => $rses_count,
				);
			}

			unset( $rses_x );

			$rses_result_data = array(
				'decrypted_results' => $rses_decrypted,
				'import_id'         => $import_id,
				'threshold'         => $rses_threshold,
				'submissions'       => count( $rses_submissions ),
			);

			set_transient( 'rses_decryption_result_' . $import_id, $rses_result_data, HOUR_IN_SECONDS );

			return array(
				'success' => true,
				'message' => __( 'Tally decrypted successfully.', 'relatasoft-secure-election-suite' ),
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
