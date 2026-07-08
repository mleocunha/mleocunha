<?php
/**
 * Encrypted vote repository.
 *
 * @package RelataSoft\SecureElectionSuite\Voting
 */

namespace RelataSoft\SecureElectionSuite\Voting;

use RelataSoft\SecureElectionSuite\Database\Repository;
use RelataSoft\SecureElectionSuite\Exports\HashService;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypted vote storage.
 */
class EncryptedVoteRepository {

	/**
	 * Store encrypted vote.
	 *
	 * @param array<string,mixed> $data Vote data.
	 * @return int
	 */
	public static function rses_store( array $data ): int {
		$rses_row = array(
			'election_id'            => $data['election_id'],
			'round_id'               => $data['round_id'],
			'question_id'            => $data['question_id'],
			'option_id'              => $data['option_id'] ?? null,
			'voter_user_id'          => $data['voter_user_id'],
			'ciphertext_alpha'       => $data['ciphertext_alpha'],
			'ciphertext_beta'        => $data['ciphertext_beta'],
			'encrypted_payload_json' => $data['encrypted_payload_json'] ?? null,
			'vote_hash'              => $data['vote_hash'],
			'cast_at'                => current_time( 'mysql', true ),
			'ip_hash'                => $data['ip_hash'] ?? null,
			'user_agent_hash'        => $data['user_agent_hash'] ?? null,
		);

		$rses_row['audit_hash'] = HashService::rses_hash_json( $rses_row );

		return Repository::rses_insert(
			'rses_encrypted_votes',
			$rses_row,
			array( '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Check if voter already voted on question in round.
	 *
	 * @param int $voter_user_id Voter ID.
	 * @param int $round_id      Round ID.
	 * @param int $question_id   Question ID.
	 * @return bool
	 */
	public static function rses_has_voted( int $voter_user_id, int $round_id, int $question_id ): bool {
		return Repository::rses_count(
			'rses_encrypted_votes',
			'voter_user_id = %d AND round_id = %d AND question_id = %d',
			array( $voter_user_id, $round_id, $question_id )
		) > 0;
	}

	/**
	 * Get votes for round.
	 *
	 * @param int $round_id Round ID.
	 * @return array<int,object>
	 */
	public static function rses_get_by_round( int $round_id ): array {
		return Repository::rses_get_rows(
			'rses_encrypted_votes',
			'round_id = %d',
			array( $round_id )
		);
	}

	/**
	 * Get voter receipt hash.
	 *
	 * @param int $voter_user_id Voter ID.
	 * @param int $round_id      Round ID.
	 * @return string|null
	 */
	public static function rses_get_receipt_hash( int $voter_user_id, int $round_id ): ?string {
		global $wpdb;

		$rses_table = \RelataSoft\SecureElectionSuite\Database\Schema::rses_table( 'rses_encrypted_votes' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rses_hash = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT vote_hash FROM {$rses_table} WHERE voter_user_id = %d AND round_id = %d LIMIT 1",
				$voter_user_id,
				$round_id
			)
		);

		return $rses_hash ? (string) $rses_hash : null;
	}
}
