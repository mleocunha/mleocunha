<?php
/**
 * Feldman VSS share assignment service.
 *
 * @package RelataSoft\SecureElectionSuite\KeyAuthority
 */

namespace RelataSoft\SecureElectionSuite\KeyAuthority;

use RelataSoft\SecureElectionSuite\Crypto\BigInt;
use RelataSoft\SecureElectionSuite\Crypto\CeremonyTranscript;
use RelataSoft\SecureElectionSuite\Crypto\CryptoException;
use RelataSoft\SecureElectionSuite\Crypto\CryptoSchemeRegistry;
use RelataSoft\SecureElectionSuite\Crypto\ElGamalKeyPair;
use RelataSoft\SecureElectionSuite\Crypto\FeldmanVss;
use RelataSoft\SecureElectionSuite\Database\Repository;
use RelataSoft\SecureElectionSuite\Exports\HashService;

defined( 'ABSPATH' ) || exit;

/**
 * Assigns Feldman VSS shares to election officials.
 */
class ShareAssignmentService {

	/**
	 * Split private key with Feldman VSS and assign shares to officials.
	 *
	 * @param ElGamalKeyPair $keypair           Key pair with private x.
	 * @param int            $key_id            Key database ID.
	 * @param int            $election_round_id Election round ID.
	 * @param int            $threshold         Threshold t.
	 * @param int            $total             Total n.
	 * @param array<int,int> $official_user_ids Official user IDs (count = n).
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

		$scheme = CryptoSchemeRegistry::rses_active_generation_scheme();
		if ( ! CryptoSchemeRegistry::rses_may_generate( $scheme ) ) {
			throw new CryptoException( __( 'Active generation scheme is not allowed.', 'relatasoft-secure-election-suite' ) );
		}

		$x = $keypair->getPrivateGmp();
		$p = BigInt::fromDecimalString( $keypair->getP() );
		$q = BigInt::fromDecimalString( $keypair->getQ() );
		$g = BigInt::fromDecimalString( $keypair->getG() );

		$split             = FeldmanVss::rses_split_with_commitments( $x, $threshold, $total, $p, $q, $g );
		$commitments_dec   = FeldmanVss::rses_commitments_to_decimal( $split['commitments'] );
		$ceremony_id       = 'cer-' . wp_generate_password( 20, false, false );
		$created_at        = gmdate( 'c' );

		$participants = array();
		foreach ( $official_user_ids as $i => $uid ) {
			$participants[] = array(
				'participant_id'   => (int) $uid,
				'share_index'      => (int) $split['shares'][ $i ]['x'],
				'official_user_id' => (int) $uid,
			);
		}

		$public_key = array(
			'p'           => $keypair->getP(),
			'q'           => $keypair->getQ(),
			'g'           => $keypair->getG(),
			'y'           => $keypair->getY(),
			'keySizeBits' => (int) $keypair->getKeySizeBits(),
		);

		$key_row    = KeyRepository::rses_get( $key_id );
		$transcript = CeremonyTranscript::rses_build(
			array(
				'scheme_id'                  => $scheme,
				'ceremony_id'                => $ceremony_id,
				'key_id'                     => $key_id,
				'key_label'                  => $key_row ? (string) $key_row->key_label : '',
				'election_round_id'          => $election_round_id,
				'threshold_t'                => $threshold,
				'participant_count'          => $total,
				'created_at'                 => $created_at,
				'public_key'                 => $public_key,
				'commitments'                => $commitments_dec,
				'participants'               => $participants,
				'private_key_reconstruction' => 'prohibited',
				'security_generation'        => 'target-modular',
			)
		);

		$field_q = BigInt::toDecimalString( $q );
		Repository::rses_update(
			'rses_keys',
			array(
				'field_prime'              => $field_q,
				'threshold_t'              => $threshold,
				'total_n'                  => $total,
				'scheme_id'                => $scheme,
				'ceremony_id'              => $ceremony_id,
				'commitments_json'         => wp_json_encode( $commitments_dec ),
				'ceremony_transcript_json' => wp_json_encode( $transcript ),
				'public_transcript_hash'   => (string) $transcript['public_transcript_hash'],
				'ceremony_status'          => CeremonyTranscript::CEREMONY_STATUS_ACTIVE,
			),
			array( 'id' => $key_id ),
			array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		$share_ids = array();
		$now       = current_time( 'mysql', true );

		foreach ( $split['shares'] as $idx => $share ) {
			$payload = FeldmanVss::rses_build_share_payload(
				array(
					'ceremony_id'            => $ceremony_id,
					'key_id'                 => $key_id,
					'election_round_id'      => $election_round_id,
					'threshold_t'            => $threshold,
					'total_n'                => $total,
					'participant_id'         => (int) $official_user_ids[ $idx ],
					'field_prime'            => $field_q,
					'share_index'            => $share['x'],
					'share_value'            => BigInt::toDecimalString( $share['y'] ),
					'public_key'             => $public_key,
					'commitments'            => $commitments_dec,
					'public_transcript_hash' => (string) $transcript['public_transcript_hash'],
				)
			);
			// Mark generation scheme (threshold-cp) while keeping FeldmanVSS share math/verify.
			$payload['scheme_id'] = $scheme;
			$payload['checksum']  = FeldmanVss::rses_compute_payload_checksum( $payload );

			$encrypted = ShareEncryptionService::rses_encrypt( (string) wp_json_encode( $payload ) );

			$row = array(
				'key_id'                  => $key_id,
				'election_round_id'       => $election_round_id,
				'official_user_id'        => $official_user_ids[ $idx ],
				'share_index'             => $share['x'],
				'share_payload_encrypted' => $encrypted,
				'threshold_t'             => $threshold,
				'total_n'                 => $total,
				'field_prime'             => $field_q,
				'status'                  => 'assigned',
				'created_at'              => $now,
			);
			$row['audit_hash'] = HashService::rses_hash_json( $row );

			$share_ids[] = Repository::rses_insert(
				'rses_shares',
				$row,
				array( '%d', '%d', '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
			);
		}

		return $share_ids;
	}
}
