<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Persistence\Votes;

use RelataSoft\SecureElectionSuite\Database\Repository;
use RelataSoft\SecureElectionSuite\Database\Schema;
use RelataSoft\SecureElectionSuite\Exports\HashService;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Votes\EncryptedVoteRepository;

final class WordPressEncryptedVoteRepository implements EncryptedVoteRepository {

	public function store(array $data): int {
		return Repository::rses_insert(
			'rses_encrypted_votes',
			$data,
			array( '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	public function hasVoted(int $voterId, int $roundId, int $questionId): bool {
		return Repository::rses_count(
			'rses_encrypted_votes',
			'voter_user_id = %d AND round_id = %d AND question_id = %d',
			array( $voterId, $roundId, $questionId )
		) > 0;
	}

	public function hasVotedRound(int $voterId, int $roundId): bool {
		return Repository::rses_count(
			'rses_encrypted_votes',
			'voter_user_id = %d AND round_id = %d',
			array( $voterId, $roundId )
		) > 0;
	}

	public function countDistinctVoters(int $roundId): int {
		global $wpdb;
		$table = Schema::rses_table( 'rses_encrypted_votes' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT voter_user_id) FROM {$table} WHERE round_id = %d",
				$roundId
			)
		);
	}

	public function forEachExportRow(int $roundId, callable $callback, int $batch = 100): void {
		global $wpdb;
		$table = Schema::rses_table( 'rses_encrypted_votes' );
		$batch = max( 1, min( 500, $batch ) );
		$last  = 0;

		while ( true ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, question_id, option_id, ciphertext_alpha, ciphertext_beta, vote_hash, cast_at
					FROM {$table}
					WHERE round_id = %d AND id > %d
					ORDER BY id ASC
					LIMIT %d",
					$roundId,
					$last,
					$batch
				)
			);
			if ( empty( $rows ) ) {
				break;
			}
			foreach ( $rows as $row ) {
				$callback(
					array(
						'id'               => (int) $row->id,
						'question_id'      => (int) $row->question_id,
						'option_id'        => $row->option_id ? (int) $row->option_id : null,
						'ciphertext_alpha' => $row->ciphertext_alpha,
						'ciphertext_beta'  => $row->ciphertext_beta,
						'vote_hash'        => $row->vote_hash,
						'cast_at'          => $row->cast_at,
					)
				);
				$last = (int) $row->id;
			}
			if ( count( $rows ) < $batch ) {
				break;
			}
			unset( $rows );
		}
	}

	public function receiptHash(int $voterId, int $roundId): ?string {
		global $wpdb;
		$table = Schema::rses_table( 'rses_encrypted_votes' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$hashes = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT vote_hash FROM {$table} WHERE voter_user_id = %d AND round_id = %d ORDER BY id ASC",
				$voterId,
				$roundId
			)
		);
		if ( empty( $hashes ) ) {
			return null;
		}
		return HashService::rses_sha256( implode( '', $hashes ) );
	}
}
