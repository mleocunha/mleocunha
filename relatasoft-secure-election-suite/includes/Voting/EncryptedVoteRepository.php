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
	 * Check if voter already cast any ballot for the round.
	 *
	 * @param int $voter_user_id Voter ID.
	 * @param int $round_id      Round ID.
	 * @return bool
	 */
	public static function rses_has_voted_round( int $voter_user_id, int $round_id ): bool {
		return Repository::rses_count(
			'rses_encrypted_votes',
			'voter_user_id = %d AND round_id = %d',
			array( $voter_user_id, $round_id )
		) > 0;
	}

	/**
	 * Get votes for round.
	 *
	 * Warning: SELECT * loads ciphertext + optional encrypted_payload_json for every
	 * row. Prefer {@see rses_for_each_export_row()} for large exports.
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
	 * Count distinct voters who cast at least one ciphertext in the round.
	 *
	 * @param int $round_id Round ID.
	 */
	public static function rses_count_distinct_voters( int $round_id ): int {
		global $wpdb;

		$rses_table = \RelataSoft\SecureElectionSuite\Database\Schema::rses_table( 'rses_encrypted_votes' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT voter_user_id) FROM {$rses_table} WHERE round_id = %d",
				$round_id
			)
		);
	}

	/**
	 * Stream export-shaped vote rows in id-ordered batches (no payload JSON).
	 *
	 * @param int                  $round_id Round ID.
	 * @param callable(object):void $callback Receives each row object.
	 * @param int                  $batch    Batch size.
	 */
	public static function rses_for_each_export_row( int $round_id, callable $callback, int $batch = 100 ): void {
		global $wpdb;

		$rses_table = \RelataSoft\SecureElectionSuite\Database\Schema::rses_table( 'rses_encrypted_votes' );
		$rses_batch = max( 1, min( 500, $batch ) );
		$rses_last  = 0;

		while ( true ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rses_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, question_id, option_id, ciphertext_alpha, ciphertext_beta, vote_hash, cast_at
					FROM {$rses_table}
					WHERE round_id = %d AND id > %d
					ORDER BY id ASC
					LIMIT %d",
					$round_id,
					$rses_last,
					$rses_batch
				)
			);

			if ( empty( $rses_rows ) ) {
				break;
			}

			foreach ( $rses_rows as $rses_row ) {
				$callback( $rses_row );
				$rses_last = (int) $rses_row->id;
			}

			if ( count( $rses_rows ) < $rses_batch ) {
				break;
			}

			// Free the batch before the next query under low memory_limit hosts.
			unset( $rses_rows );
		}
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
	 * Matches VoteEncryptionService cast receipt: sha256( concat of per-ciphertext
	 * vote_hash values in cast/insert order ). A single vote_hash row is not the
	 * elector-facing receipt when the ballot has multiple questions/options.
	 *
	 * @param int $voter_user_id Voter ID.
	 * @param int $round_id      Round ID.
	 * @return string|null
	 */
	public static function rses_get_receipt_hash( int $voter_user_id, int $round_id ): ?string {
		global $wpdb;

		$rses_table = \RelataSoft\SecureElectionSuite\Database\Schema::rses_table( 'rses_encrypted_votes' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rses_hashes = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT vote_hash FROM {$rses_table} WHERE voter_user_id = %d AND round_id = %d ORDER BY id ASC",
				$voter_user_id,
				$round_id
			)
		);

		if ( empty( $rses_hashes ) ) {
			return null;
		}

		return HashService::rses_sha256( implode( '', $rses_hashes ) );
	}
}
