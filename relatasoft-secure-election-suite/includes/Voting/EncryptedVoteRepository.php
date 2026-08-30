<?php
/**
 * Encrypted vote repository.
 *
 * @package RelataSoft\SecureElectionSuite\Voting
 */

namespace RelataSoft\SecureElectionSuite\Voting;

use RelataSoft\SecureElectionSuite\Exports\HashService;
use RelataSoft\SecureElectionSuite\Painel\Application\Persistence\PersistenceGateway;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypted vote storage (delegates to A2 persistence ports).
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

		return PersistenceGateway::get()->votes->store( $rses_row );
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
		return PersistenceGateway::get()->votes->hasVoted( $voter_user_id, $round_id, $question_id );
	}

	/**
	 * Check if voter already cast any ballot for the round.
	 *
	 * @param int $voter_user_id Voter ID.
	 * @param int $round_id      Round ID.
	 * @return bool
	 */
	public static function rses_has_voted_round( int $voter_user_id, int $round_id ): bool {
		return PersistenceGateway::get()->votes->hasVotedRound( $voter_user_id, $round_id );
	}

	/**
	 * Get votes for round.
	 *
	 * Warning: loads full rows. Prefer {@see rses_for_each_export_row()} for large exports.
	 *
	 * @param int $round_id Round ID.
	 * @return array<int,object>
	 */
	public static function rses_get_by_round( int $round_id ): array {
		$out = array();
		PersistenceGateway::get()->votes->forEachExportRow(
			$round_id,
			static function ( array $row ) use ( &$out ): void {
				$out[] = (object) $row;
			},
			500
		);
		return $out;
	}

	/**
	 * Count distinct voters who cast at least one ciphertext in the round.
	 *
	 * @param int $round_id Round ID.
	 */
	public static function rses_count_distinct_voters( int $round_id ): int {
		return PersistenceGateway::get()->votes->countDistinctVoters( $round_id );
	}

	/**
	 * Stream export-shaped vote rows in id-ordered batches (no payload JSON).
	 *
	 * @param int                   $round_id Round ID.
	 * @param callable(object):void $callback Receives each row object.
	 * @param int                   $batch    Batch size.
	 */
	public static function rses_for_each_export_row( int $round_id, callable $callback, int $batch = 100 ): void {
		PersistenceGateway::get()->votes->forEachExportRow(
			$round_id,
			static function ( array $row ) use ( $callback ): void {
				$callback( (object) $row );
			},
			$batch
		);
	}

	/**
	 * Write compact encrypted-votes.json for a round (streaming; low memory).
	 *
	 * @param int    $round_id Round ID.
	 * @param string $path     Absolute writable path.
	 * @return int Number of vote rows written.
	 */
	public static function rses_write_votes_json_file( int $round_id, string $path ): int {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$rses_fh = fopen( $path, 'wb' );
		if ( false === $rses_fh ) {
			return -1;
		}

		$rses_count = 0;
		$rses_first = true;
		fwrite( $rses_fh, '[' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

		self::rses_for_each_export_row(
			$round_id,
			static function ( $v ) use ( $rses_fh, &$rses_count, &$rses_first ) {
				$row = array(
					'id'               => (int) $v->id,
					'question_id'      => (int) $v->question_id,
					'option_id'        => $v->option_id ? (int) $v->option_id : null,
					'ciphertext_alpha' => $v->ciphertext_alpha,
					'ciphertext_beta'  => $v->ciphertext_beta,
					'vote_hash'        => $v->vote_hash,
					'cast_at'          => $v->cast_at,
				);
				$json = wp_json_encode( $row, JSON_UNESCAPED_SLASHES );
				if ( false === $json ) {
					return;
				}
				if ( ! $rses_first ) {
					fwrite( $rses_fh, ',' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
				}
				$rses_first = false;
				fwrite( $rses_fh, $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
				++$rses_count;
			}
		);

		fwrite( $rses_fh, ']' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fclose( $rses_fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $rses_count;
	}

	/**
	 * Get voter receipt hash for a round.
	 *
	 * @param int $voter_user_id Voter ID.
	 * @param int $round_id      Round ID.
	 * @return string|null
	 */
	public static function rses_get_receipt_hash( int $voter_user_id, int $round_id ): ?string {
		return PersistenceGateway::get()->votes->receiptHash( $voter_user_id, $round_id );
	}
}
