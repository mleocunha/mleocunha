<?php
/**
 * Encrypted tally aggregation service.
 *
 * @package RelataSoft\SecureElectionSuite\Voting
 */

namespace RelataSoft\SecureElectionSuite\Voting;

use RelataSoft\SecureElectionSuite\Crypto\BigInt;
use RelataSoft\SecureElectionSuite\Crypto\ElGamalCiphertext;
use RelataSoft\SecureElectionSuite\Exports\HashService;
use RelataSoft\SecureElectionSuite\Painel\Application\Persistence\PersistenceGateway;

defined( 'ABSPATH' ) || exit;

/**
 * Aggregates encrypted votes into homomorphic tallies.
 */
class EncryptedTallyService {

	/**
	 * Compute and store encrypted tallies for a round (streaming; low memory).
	 *
	 * @param int $round_id Round ID.
	 * @return int Number of tallies created.
	 */
	public static function rses_compute_tallies( int $round_id ): int {
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		@ini_set( 'memory_limit', '256M' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		$rses_round = ElectionRepository::rses_get_round( $round_id );
		if ( ! $rses_round ) {
			return 0;
		}

		$rses_key = \RelataSoft\SecureElectionSuite\KeyAuthority\KeyRepository::rses_get( (int) $rses_round->key_id );
		if ( ! $rses_key ) {
			return 0;
		}

		$rses_p             = BigInt::fromDecimalString( $rses_key->public_p );
		$rses_ballot_count  = EncryptedVoteRepository::rses_count_distinct_voters( $round_id );
		$rses_election_id   = (int) $rses_round->election_id;
		$rses_groups        = array();

		EncryptedVoteRepository::rses_for_each_export_row(
			$round_id,
			static function ( $rses_vote ) use ( &$rses_groups, $rses_p, $rses_election_id ) {
				$rses_qid = (int) $rses_vote->question_id;
				$rses_oid = $rses_vote->option_id ? (int) $rses_vote->option_id : null;
				$rses_key = $rses_qid . '_' . ( null === $rses_oid ? 'null' : (string) $rses_oid );

				$rses_ct = ElGamalCiphertext::fromDecimalStrings(
					(string) $rses_vote->ciphertext_alpha,
					(string) $rses_vote->ciphertext_beta
				);

				if ( ! isset( $rses_groups[ $rses_key ] ) ) {
					$rses_groups[ $rses_key ] = array(
						'election_id' => $rses_election_id,
						'question_id' => $rses_qid,
						'option_id'   => $rses_oid,
						'alpha'       => $rses_ct->getAlpha(),
						'beta'        => $rses_ct->getBeta(),
						'count'       => 1,
					);
					return;
				}

				$rses_groups[ $rses_key ]['alpha'] = BigInt::modMul( $rses_groups[ $rses_key ]['alpha'], $rses_ct->getAlpha(), $rses_p );
				$rses_groups[ $rses_key ]['beta']  = BigInt::modMul( $rses_groups[ $rses_key ]['beta'], $rses_ct->getBeta(), $rses_p );
				++$rses_groups[ $rses_key ]['count'];
			}
		);

		if ( empty( $rses_groups ) ) {
			return 0;
		}

		self::rses_delete_by_round( $round_id );

		$rses_rows = array();
		foreach ( $rses_groups as $rses_group ) {
			$rses_row = array(
				'election_id'      => $rses_group['election_id'],
				'round_id'         => $round_id,
				'question_id'      => $rses_group['question_id'],
				'option_id'        => $rses_group['option_id'],
				'aggregate_alpha'  => BigInt::toDecimalString( $rses_group['alpha'] ),
				'aggregate_beta'   => BigInt::toDecimalString( $rses_group['beta'] ),
				'ballot_count'     => max( $rses_ballot_count, (int) $rses_group['count'] ),
				'max_decode_count' => max( $rses_ballot_count, (int) $rses_group['count'] ),
				'created_at'       => current_time( 'mysql', true ),
			);

			$rses_row['audit_hash'] = HashService::rses_hash_json( $rses_row );
			$rses_rows[]            = $rses_row;
		}

		return PersistenceGateway::get()->encryptedTallies->replaceForRound( $round_id, $rses_rows );
	}

	/**
	 * Delete tallies for a round.
	 *
	 * @param int $round_id Round ID.
	 */
	public static function rses_delete_by_round( int $round_id ): void {
		PersistenceGateway::get()->encryptedTallies->deleteByRound( $round_id );
	}

	/**
	 * Get tallies for round.
	 *
	 * @param int $round_id Round ID.
	 * @return array<int,object>
	 */
	public static function rses_get_by_round( int $round_id ): array {
		return array_map(
			static fn( array $row ) => (object) $row,
			PersistenceGateway::get()->encryptedTallies->listByRound( $round_id )
		);
	}

	/**
	 * Build export-shaped tallies by streaming a compact encrypted-votes.json array file.
	 *
	 * @param string $votes_json_path Absolute path to JSON array of vote objects.
	 * @param string $public_p       Decimal prime p.
	 * @return array<int,array<string,mixed>>
	 */
	public static function rses_aggregate_from_votes_json_file( string $votes_json_path, string $public_p ): array {
		if ( '' === $public_p || ! is_readable( $votes_json_path ) ) {
			return array();
		}

		$rses_p      = BigInt::fromDecimalString( $public_p );
		$rses_groups = array();

		self::rses_for_each_json_array_object(
			$votes_json_path,
			static function ( array $rses_vote ) use ( &$rses_groups, $rses_p ) {
				if ( empty( $rses_vote['ciphertext_alpha'] ) || empty( $rses_vote['ciphertext_beta'] ) ) {
					return;
				}
				$rses_qid = (int) ( $rses_vote['question_id'] ?? 0 );
				$rses_oid = isset( $rses_vote['option_id'] ) && null !== $rses_vote['option_id'] && '' !== $rses_vote['option_id']
					? (int) $rses_vote['option_id']
					: null;
				$rses_key = $rses_qid . '_' . ( null === $rses_oid ? 'null' : (string) $rses_oid );

				$rses_ct = ElGamalCiphertext::fromDecimalStrings(
					(string) $rses_vote['ciphertext_alpha'],
					(string) $rses_vote['ciphertext_beta']
				);

				if ( ! isset( $rses_groups[ $rses_key ] ) ) {
					$rses_groups[ $rses_key ] = array(
						'question_id' => $rses_qid,
						'option_id'   => $rses_oid,
						'alpha'       => $rses_ct->getAlpha(),
						'beta'        => $rses_ct->getBeta(),
						'count'       => 1,
					);
					return;
				}

				$rses_groups[ $rses_key ]['alpha'] = BigInt::modMul( $rses_groups[ $rses_key ]['alpha'], $rses_ct->getAlpha(), $rses_p );
				$rses_groups[ $rses_key ]['beta']  = BigInt::modMul( $rses_groups[ $rses_key ]['beta'], $rses_ct->getBeta(), $rses_p );
				++$rses_groups[ $rses_key ]['count'];
			}
		);

		$rses_out = array();
		foreach ( $rses_groups as $rses_group ) {
			$rses_out[] = array(
				'question_id'     => $rses_group['question_id'],
				'option_id'       => $rses_group['option_id'],
				'aggregate_alpha' => BigInt::toDecimalString( $rses_group['alpha'] ),
				'aggregate_beta'  => BigInt::toDecimalString( $rses_group['beta'] ),
				'ballot_count'    => (int) $rses_group['count'],
			);
		}

		return $rses_out;
	}

	/**
	 * Invoke callback for each object in a top-level JSON array file.
	 *
	 * @param string                $path     Absolute path.
	 * @param callable(array):void $callback Receives decoded object arrays.
	 */
	public static function rses_for_each_json_array_object( string $path, callable $callback ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$rses_fh = fopen( $path, 'rb' );
		if ( false === $rses_fh ) {
			return;
		}

		$rses_buf = '';
		$rses_started = false;

		while ( ! feof( $rses_fh ) ) {
			$rses_chunk = fread( $rses_fh, 65536 );
			if ( ! is_string( $rses_chunk ) || '' === $rses_chunk ) {
				break;
			}
			$rses_buf .= $rses_chunk;

			if ( ! $rses_started ) {
				$rses_pos = strpos( $rses_buf, '[' );
				if ( false === $rses_pos ) {
					if ( strlen( $rses_buf ) > 16 ) {
						fclose( $rses_fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
						return;
					}
					continue;
				}
				$rses_buf     = substr( $rses_buf, $rses_pos + 1 );
				$rses_started = true;
			}

			while ( true ) {
				$rses_buf = ltrim( $rses_buf );
				if ( '' === $rses_buf ) {
					break;
				}
				if ( ']' === $rses_buf[0] ) {
					fclose( $rses_fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					return;
				}
				if ( ',' === $rses_buf[0] ) {
					$rses_buf = substr( $rses_buf, 1 );
					continue;
				}
				if ( '{' !== $rses_buf[0] ) {
					fclose( $rses_fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					return;
				}

				$rses_end = self::rses_end_of_json_value( $rses_buf, 0 );
				if ( null === $rses_end ) {
					// Need more bytes.
					break;
				}

				$rses_json = substr( $rses_buf, 0, $rses_end );
				$rses_buf  = substr( $rses_buf, $rses_end );
				$rses_obj  = json_decode( $rses_json, true );
				if ( is_array( $rses_obj ) ) {
					$callback( $rses_obj );
				}
			}

			// Keep buffer bounded.
			if ( strlen( $rses_buf ) > 8 * 1024 * 1024 ) {
				fclose( $rses_fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				return;
			}
		}

		fclose( $rses_fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	/**
	 * @param string $json  Buffer.
	 * @param int    $start Offset.
	 */
	private static function rses_end_of_json_value( string $json, int $start ): ?int {
		$rses_len = strlen( $json );
		if ( $start >= $rses_len ) {
			return null;
		}
		$rses_ch = $json[ $start ];
		if ( '{' !== $rses_ch && '[' !== $rses_ch ) {
			return null;
		}
		$rses_open   = $rses_ch;
		$rses_close  = '{' === $rses_ch ? '}' : ']';
		$rses_depth  = 0;
		$rses_in_str = false;
		$rses_esc    = false;
		for ( $rses_i = $start; $rses_i < $rses_len; $rses_i++ ) {
			$rses_c = $json[ $rses_i ];
			if ( $rses_in_str ) {
				if ( $rses_esc ) {
					$rses_esc = false;
				} elseif ( '\\' === $rses_c ) {
					$rses_esc = true;
				} elseif ( '"' === $rses_c ) {
					$rses_in_str = false;
				}
				continue;
			}
			if ( '"' === $rses_c ) {
				$rses_in_str = true;
				continue;
			}
			if ( $rses_c === $rses_open ) {
				++$rses_depth;
			} elseif ( $rses_c === $rses_close ) {
				--$rses_depth;
				if ( 0 === $rses_depth ) {
					return $rses_i + 1;
				}
			}
		}
		return null;
	}
}
