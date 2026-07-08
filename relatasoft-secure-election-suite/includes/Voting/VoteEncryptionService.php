<?php
/**
 * Vote encryption service.
 *
 * @package RelataSoft\SecureElectionSuite\Voting
 */

namespace RelataSoft\SecureElectionSuite\Voting;

use RelataSoft\SecureElectionSuite\Crypto\BigInt;
use RelataSoft\SecureElectionSuite\Crypto\CryptoException;
use RelataSoft\SecureElectionSuite\Crypto\ElGamal;
use RelataSoft\SecureElectionSuite\Crypto\ElGamalCiphertext;
use RelataSoft\SecureElectionSuite\Crypto\HomomorphicTally;
use RelataSoft\SecureElectionSuite\Exports\HashService;
use RelataSoft\SecureElectionSuite\KeyAuthority\KeyRepository;
use RelataSoft\SecureElectionSuite\Security\AuditLogger;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts and stores votes without plaintext storage.
 */
class VoteEncryptionService {

	/**
	 * Cast encrypted votes for a ballot submission.
	 *
	 * @param int                 $election_id Election ID.
	 * @param int                 $round_id    Round ID.
	 * @param int                 $voter_id    Voter user ID.
	 * @param array<string,mixed> $ballot_data Ballot selections (processed server-side, not stored).
	 * @return string Receipt hash.
	 * @throws CryptoException On failure.
	 */
	public static function rses_cast_ballot(
		int $election_id,
		int $round_id,
		int $voter_id,
		array $ballot_data
	): string {
		$rses_round = ElectionRepository::rses_get_round( $round_id );

		if ( ! $rses_round || (int) $rses_round->election_id !== $election_id ) {
			throw new CryptoException( __( 'Invalid election round.', 'relatasoft-secure-election-suite' ) );
		}

		if ( 'open' !== $rses_round->status ) {
			throw new CryptoException( __( 'Voting is not open for this round.', 'relatasoft-secure-election-suite' ) );
		}

		$rses_key_id = (int) $rses_round->key_id;
		$rses_key    = KeyRepository::rses_get( $rses_key_id );

		if ( ! $rses_key ) {
			throw new CryptoException( __( 'Election public key not found.', 'relatasoft-secure-election-suite' ) );
		}

		$rses_p = BigInt::fromDecimalString( $rses_key->public_p );
		$rses_q = BigInt::fromDecimalString( $rses_key->public_q );
		$rses_g = BigInt::fromDecimalString( $rses_key->public_g );
		$rses_y = BigInt::fromDecimalString( $rses_key->public_y );

		$rses_questions = ElectionRepository::rses_get_questions( $round_id );
		$rses_vote_hashes = array();

		foreach ( $rses_questions as $rses_question ) {
			$rses_qid = (int) $rses_question->id;

			if ( EncryptedVoteRepository::rses_has_voted( $voter_id, $round_id, $rses_qid ) ) {
				throw new CryptoException( __( 'You have already voted on this ballot.', 'relatasoft-secure-election-suite' ) );
			}

			$rses_options = ElectionRepository::rses_get_options( $rses_qid );
			$rses_selections = $ballot_data[ 'question_' . $rses_qid ] ?? array();

			if ( ! is_array( $rses_selections ) ) {
				$rses_selections = array( $rses_selections );
			}

			self::rses_encrypt_question_votes(
				$election_id,
				$round_id,
				$rses_qid,
				$voter_id,
				$rses_question,
				$rses_options,
				$rses_selections,
				$rses_p,
				$rses_q,
				$rses_g,
				$rses_y,
				$rses_vote_hashes
			);
		}

		$rses_receipt = HashService::rses_sha256( implode( '', $rses_vote_hashes ) );

		AuditLogger::rses_log(
			'vote_cast',
			'election',
			$election_id,
			array(
				'round_id'     => $round_id,
				'voter_id'     => $voter_id,
				'receipt_hash' => $rses_receipt,
			)
		);

		return $rses_receipt;
	}

	/**
	 * Encrypt votes for a single question.
	 *
	 * @param int                 $election_id   Election ID.
	 * @param int                 $round_id      Round ID.
	 * @param int                 $question_id   Question ID.
	 * @param int                 $voter_id      Voter ID.
	 * @param object              $question      Question row.
	 * @param array<int,object>   $options       Options.
	 * @param array<int,mixed>    $selections    Selections.
	 * @param \GMP                $p             Prime.
	 * @param \GMP                $q             Subgroup order.
	 * @param \GMP                $g             Generator.
	 * @param \GMP                $y             Public key.
	 * @param array<int,string>   $vote_hashes   Vote hash accumulator.
	 */
	private static function rses_encrypt_question_votes(
		int $election_id,
		int $round_id,
		int $question_id,
		int $voter_id,
		object $question,
		array $options,
		array $selections,
		\GMP $p,
		\GMP $q,
		\GMP $g,
		\GMP $y,
		array &$vote_hashes
	): void {
		$rses_type = $question->question_type;

		if ( 'numeric' === $rses_type ) {
			$rses_value = absint( $selections[0] ?? 0 );
			$rses_ct    = HomomorphicTally::encryptCount( $rses_value, $p, $q, $g, $y );
			$rses_arr   = $rses_ct->toDecimalArray();

			$rses_hash = HashService::rses_sha256( $rses_arr['alpha'] . $rses_arr['beta'] );
			$vote_hashes[] = $rses_hash;

			EncryptedVoteRepository::rses_store(
				array(
					'election_id'      => $election_id,
					'round_id'         => $round_id,
					'question_id'      => $question_id,
					'option_id'        => null,
					'voter_user_id'    => $voter_id,
					'ciphertext_alpha' => $rses_arr['alpha'],
					'ciphertext_beta'  => $rses_arr['beta'],
					'vote_hash'        => $rses_hash,
					'ip_hash'          => self::rses_hash_client_ip(),
					'user_agent_hash'  => self::rses_hash_user_agent(),
				)
			);
			return;
		}

		$rses_selected_ids = array_map( 'absint', $selections );
		$rses_selected_count = count( array_filter( $rses_selected_ids ) );

		if ( $rses_selected_count < (int) $question->min_choices || $rses_selected_count > (int) $question->max_choices ) {
			throw new CryptoException( __( 'Invalid number of selections.', 'relatasoft-secure-election-suite' ) );
		}

		foreach ( $options as $rses_option ) {
			$rses_oid    = (int) $rses_option->id;
			$rses_count  = in_array( $rses_oid, $rses_selected_ids, true ) ? 1 : 0;
			$rses_ct     = HomomorphicTally::encryptCount( $rses_count, $p, $q, $g, $y );
			$rses_arr    = $rses_ct->toDecimalArray();
			$rses_hash   = HashService::rses_sha256( $rses_arr['alpha'] . $rses_arr['beta'] );
			$vote_hashes[] = $rses_hash;

			EncryptedVoteRepository::rses_store(
				array(
					'election_id'      => $election_id,
					'round_id'         => $round_id,
					'question_id'      => $question_id,
					'option_id'        => $rses_oid,
					'voter_user_id'    => $voter_id,
					'ciphertext_alpha' => $rses_arr['alpha'],
					'ciphertext_beta'  => $rses_arr['beta'],
					'vote_hash'        => $rses_hash,
					'ip_hash'          => self::rses_hash_client_ip(),
					'user_agent_hash'  => self::rses_hash_user_agent(),
				)
			);
		}
	}

	/**
	 * Hash client IP for audit (not plaintext).
	 *
	 * @return string|null
	 */
	private static function rses_hash_client_ip(): ?string {
		$rses_ip = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';
		return $rses_ip ? hash( 'sha256', $rses_ip ) : null;
	}

	/**
	 * Hash user agent.
	 *
	 * @return string|null
	 */
	private static function rses_hash_user_agent(): ?string {
		$rses_ua = isset( $_SERVER['HTTP_USER_AGENT'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
			: '';
		return $rses_ua ? hash( 'sha256', $rses_ua ) : null;
	}
}
