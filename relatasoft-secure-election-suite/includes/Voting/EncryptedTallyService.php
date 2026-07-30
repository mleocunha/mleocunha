<?php
/**
 * Encrypted tally aggregation service.
 *
 * @package RelataSoft\SecureElectionSuite\Voting
 */

namespace RelataSoft\SecureElectionSuite\Voting;

use RelataSoft\SecureElectionSuite\Crypto\BigInt;
use RelataSoft\SecureElectionSuite\Crypto\ElGamal;
use RelataSoft\SecureElectionSuite\Crypto\ElGamalCiphertext;
use RelataSoft\SecureElectionSuite\Database\Repository;
use RelataSoft\SecureElectionSuite\Exports\HashService;

defined( 'ABSPATH' ) || exit;

/**
 * Aggregates encrypted votes into homomorphic tallies.
 */
class EncryptedTallyService {

	/**
	 * Compute and store encrypted tallies for a round.
	 *
	 * @param int $round_id Round ID.
	 * @return int Number of tallies created.
	 */
	public static function rses_compute_tallies( int $round_id ): int {
		$rses_votes = EncryptedVoteRepository::rses_get_by_round( $round_id );

		if ( empty( $rses_votes ) ) {
			return 0;
		}

		$rses_groups = array();

		foreach ( $rses_votes as $rses_vote ) {
			$rses_key = (int) $rses_vote->question_id . '_' . ( $rses_vote->option_id ?? 'null' );

			if ( ! isset( $rses_groups[ $rses_key ] ) ) {
				$rses_groups[ $rses_key ] = array(
					'election_id' => (int) $rses_vote->election_id,
					'question_id' => (int) $rses_vote->question_id,
					'option_id'   => $rses_vote->option_id ? (int) $rses_vote->option_id : null,
					'ciphertexts' => array(),
					'p'           => null,
				);
			}

			$rses_groups[ $rses_key ]['ciphertexts'][] = ElGamalCiphertext::fromDecimalStrings(
				$rses_vote->ciphertext_alpha,
				$rses_vote->ciphertext_beta
			);
		}

		$rses_round = ElectionRepository::rses_get_round( $round_id );
		$rses_key   = \RelataSoft\SecureElectionSuite\KeyAuthority\KeyRepository::rses_get( (int) $rses_round->key_id );
		$rses_p     = BigInt::fromDecimalString( $rses_key->public_p );

		$rses_count = 0;
		$rses_ballot_count = count( array_unique( array_map(
			static fn( $v ) => (int) $v->voter_user_id,
			$rses_votes
		) ) );

		foreach ( $rses_groups as $rses_group ) {
			$rses_agg = ElGamal::aggregate( $rses_group['ciphertexts'], $rses_p );
			$rses_arr = $rses_agg->toDecimalArray();

			$rses_row = array(
				'election_id'      => $rses_group['election_id'],
				'round_id'         => $round_id,
				'question_id'      => $rses_group['question_id'],
				'option_id'        => $rses_group['option_id'],
				'aggregate_alpha'  => $rses_arr['alpha'],
				'aggregate_beta'   => $rses_arr['beta'],
				'ballot_count'     => $rses_ballot_count,
				'max_decode_count' => $rses_ballot_count,
				'created_at'       => current_time( 'mysql', true ),
			);

			$rses_row['audit_hash'] = HashService::rses_hash_json( $rses_row );

			Repository::rses_insert(
				'rses_encrypted_tallies',
				$rses_row,
				array( '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%s' )
			);

			++$rses_count;
		}

		return $rses_count;
	}

	/**
	 * Get tallies for round.
	 *
	 * @param int $round_id Round ID.
	 * @return array<int,object>
	 */
	public static function rses_get_by_round( int $round_id ): array {
		return Repository::rses_get_rows(
			'rses_encrypted_tallies',
			'round_id = %d',
			array( $round_id )
		);
	}
}
