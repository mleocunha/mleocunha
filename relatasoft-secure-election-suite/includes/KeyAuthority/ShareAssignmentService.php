<?php
/**
 * Shamir / Feldman share assignment service.
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
use RelataSoft\SecureElectionSuite\Crypto\PrimeGenerator;
use RelataSoft\SecureElectionSuite\Crypto\ShamirSecretSharing;
use RelataSoft\SecureElectionSuite\Database\Repository;
use RelataSoft\SecureElectionSuite\Exports\HashService;

defined( 'ABSPATH' ) || exit;

/**
 * Assigns threshold shares to election officials.
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

		$scheme = CryptoSchemeRegistry::rses_active_generation_scheme();
		if ( ! CryptoSchemeRegistry::rses_may_generate( $scheme ) ) {
			throw new CryptoException( __( 'Active crypto scheme is not allowed to generate new shares.', 'relatasoft-secure-election-suite' ) );
		}

		if ( CryptoSchemeRegistry::SCHEME_MODP_ELGAMAL_FELDMAN_V1 === $scheme ) {
			return self::rses_assign_feldman( $keypair, $key_id, $election_round_id, $threshold, $total, $official_user_ids );
		}

		return self::rses_assign_legacy_shamir( $keypair, $key_id, $election_round_id, $threshold, $total, $official_user_ids );
	}

	/**
	 * @param array<int,int> $official_user_ids Officials.
	 * @return array<int,int>
	 */
	private static function rses_assign_feldman(
		ElGamalKeyPair $keypair,
		int $key_id,
		int $election_round_id,
		int $threshold,
		int $total,
		array $official_user_ids
	): array {
		$x = $keypair->getPrivateGmp();
		$p = BigInt::fromDecimalString( $keypair->getP() );
		$q = BigInt::fromDecimalString( $keypair->getQ() );
		$g = BigInt::fromDecimalString( $keypair->getG() );

		$split = FeldmanVss::rses_split_with_commitments( $x, $threshold, $total, $p, $q, $g );
		$commitments_dec = FeldmanVss::rses_commitments_to_decimal( $split['commitments'] );
		$ceremony_id     = 'cer-' . wp_generate_password( 20, false, false );
		$created_at      = gmdate( 'c' );

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

		$key_row = KeyRepository::rses_get( $key_id );
		$transcript = CeremonyTranscript::rses_build(
			array(
				'scheme_id'         => FeldmanVss::SCHEME_ID,
				'ceremony_id'       => $ceremony_id,
				'key_id'            => $key_id,
				'key_label'         => $key_row ? (string) $key_row->key_label : '',
				'election_round_id' => $election_round_id,
				'threshold_t'       => $threshold,
				'participant_count' => $total,
				'created_at'        => $created_at,
				'public_key'        => $public_key,
				'commitments'       => $commitments_dec,
				'participants'      => $participants,
			)
		);

		$field_q = BigInt::toDecimalString( $q );
		Repository::rses_update(
			'rses_keys',
			array(
				'field_prime'              => $field_q,
				'threshold_t'              => $threshold,
				'total_n'                  => $total,
				'scheme_id'                => FeldmanVss::SCHEME_ID,
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
					'created_at'             => $created_at,
					'participants'           => $participants,
					'keySizeBits'            => (int) $keypair->getKeySizeBits(),
				)
			);

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

	/**
	 * Legacy baseline path (kept for archive / rollback).
	 *
	 * @param array<int,int> $official_user_ids Officials.
	 * @return array<int,int>
	 */
	private static function rses_assign_legacy_shamir(
		ElGamalKeyPair $keypair,
		int $key_id,
		int $election_round_id,
		int $threshold,
		int $total,
		array $official_user_ids
	): array {
		$x           = $keypair->getPrivateGmp();
		$field_prime = PrimeGenerator::generatePrimeGreaterThan( $x, 128 );
		$shares      = ShamirSecretSharing::splitSecret( $x, $threshold, $total, $field_prime );

		$public_key = array(
			'p' => $keypair->getP(),
			'q' => $keypair->getQ(),
			'g' => $keypair->getG(),
			'y' => $keypair->getY(),
		);

		$share_ids = array();
		$now       = current_time( 'mysql', true );

		foreach ( $shares as $idx => $share ) {
			$payload = ShamirSecretSharing::buildSharePayload(
				$key_id,
				$election_round_id,
				$threshold,
				$total,
				$field_prime,
				$share['x'],
				$share['y'],
				$public_key
			);

			$encrypted = ShareEncryptionService::rses_encrypt( (string) wp_json_encode( $payload ) );

			$row = array(
				'key_id'                  => $key_id,
				'election_round_id'       => $election_round_id,
				'official_user_id'        => $official_user_ids[ $idx ],
				'share_index'             => $share['x'],
				'share_payload_encrypted' => $encrypted,
				'threshold_t'             => $threshold,
				'total_n'                 => $total,
				'field_prime'             => BigInt::toDecimalString( $field_prime ),
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

		Repository::rses_update(
			'rses_keys',
			array(
				'field_prime'     => BigInt::toDecimalString( $field_prime ),
				'threshold_t'     => $threshold,
				'total_n'         => $total,
				'scheme_id'       => CryptoSchemeRegistry::SCHEME_MODP_ELGAMAL_SHAMIR_V1,
				'ceremony_status' => CeremonyTranscript::CEREMONY_STATUS_ACTIVE,
			),
			array( 'id' => $key_id ),
			array( '%s', '%d', '%d', '%s', '%s' ),
			array( '%d' )
		);

		return $share_ids;
	}
}
