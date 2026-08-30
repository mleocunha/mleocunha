<?php
/**
 * Shamir share assignment service.
 *
 * @package RelataSoft\SecureElectionSuite\KeyAuthority
 */

namespace RelataSoft\SecureElectionSuite\KeyAuthority;

use RelataSoft\SecureElectionSuite\Crypto\BigInt;
use RelataSoft\SecureElectionSuite\Crypto\CryptoException;
use RelataSoft\SecureElectionSuite\Crypto\ElGamalKeyPair;
use RelataSoft\SecureElectionSuite\Crypto\PrimeGenerator;
use RelataSoft\SecureElectionSuite\Crypto\ShamirSecretSharing;
use RelataSoft\SecureElectionSuite\Exports\HashService;
use RelataSoft\SecureElectionSuite\Painel\Application\Persistence\PersistenceGateway;

defined( 'ABSPATH' ) || exit;

/**
 * Assigns Shamir shares to election officials.
 */
class ShareAssignmentService {

	/**
	 * Split private key and assign shares to officials.
	 *
	 * @param ElGamalKeyPair $keypair          Key pair with private x.
	 * @param int            $key_id           Key database ID.
	 * @param int            $election_round_id Election round ID.
	 * @param int            $threshold        Threshold t.
	 * @param int            $total            Total n.
	 * @param array<int,int> $official_user_ids Official user IDs (must have count = n).
	 * @return array<int,int> Share IDs created.
	 * @throws CryptoException On failure.
	 */
	public static function rses_assign_shares(
		ElGamalKeyPair $keypair,
		int $key_id,
		int $election_round_id,
		int $threshold,
		int $total,
		array $official_user_ids
	): array {
		if ( count( $official_user_ids ) !== $total ) {
			throw new CryptoException( __( 'Number of officials must equal total shares.', 'relatasoft-secure-election-suite' ) );
		}

		$rses_x           = $keypair->getPrivateGmp();
		$rses_field_prime = PrimeGenerator::generatePrimeGreaterThan( $rses_x, 128 );

		$rses_shares = ShamirSecretSharing::splitSecret( $rses_x, $threshold, $total, $rses_field_prime );

		$rses_public_key = array(
			'p' => $keypair->getP(),
			'q' => $keypair->getQ(),
			'g' => $keypair->getG(),
			'y' => $keypair->getY(),
		);

		$rses_share_ids = array();
		$rses_now       = current_time( 'mysql', true );

		foreach ( $rses_shares as $rses_idx => $rses_share ) {
			$rses_payload = ShamirSecretSharing::buildSharePayload(
				$key_id,
				$election_round_id,
				$threshold,
				$total,
				$rses_field_prime,
				$rses_share['x'],
				$rses_share['y'],
				$rses_public_key
			);

			$rses_encrypted = ShareEncryptionService::rses_encrypt( wp_json_encode( $rses_payload ) );

			$rses_row = array(
				'key_id'                  => $key_id,
				'election_round_id'       => $election_round_id,
				'official_user_id'        => $official_user_ids[ $rses_idx ],
				'share_index'             => $rses_share['x'],
				'share_payload_encrypted' => $rses_encrypted,
				'threshold_t'             => $threshold,
				'total_n'                 => $total,
				'field_prime'             => BigInt::toDecimalString( $rses_field_prime ),
				'status'                  => 'assigned',
				'created_at'              => $rses_now,
			);

			$rses_row['audit_hash'] = HashService::rses_hash_json( $rses_row );

			$rses_share_ids[] = PersistenceGateway::get()->shares->create( $rses_row );
		}

		PersistenceGateway::get()->keys->updateThresholdMeta(
			$key_id,
			BigInt::toDecimalString( $rses_field_prime ),
			$threshold,
			$total
		);

		return $rses_share_ids;
	}
}
