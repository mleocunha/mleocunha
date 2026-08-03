<?php
/**
 * Election repository.
 *
 * @package RelataSoft\SecureElectionSuite\Voting
 */

namespace RelataSoft\SecureElectionSuite\Voting;

use RelataSoft\SecureElectionSuite\Database\Repository;
use RelataSoft\SecureElectionSuite\Database\Schema;
use RelataSoft\SecureElectionSuite\Exports\HashService;

defined( 'ABSPATH' ) || exit;

/**
 * Election database operations.
 */
class ElectionRepository {

	/**
	 * Create election.
	 *
	 * @param array<string,mixed> $data Election data.
	 * @return int
	 */
	public static function rses_create( array $data ): int {
		$rses_row = array(
			'title'         => $data['title'],
			'description'   => $data['description'] ?? null,
			'status'        => $data['status'] ?? 'draft',
			'voting_method' => $data['voting_method'],
			'created_by'    => get_current_user_id(),
			'created_at'    => current_time( 'mysql', true ),
			'opens_at'      => $data['opens_at'] ?? null,
			'closes_at'     => $data['closes_at'] ?? null,
			'settings_json' => isset( $data['settings'] ) ? wp_json_encode( $data['settings'] ) : null,
		);

		$rses_row['audit_hash'] = HashService::rses_hash_json( $rses_row );

		return Repository::rses_insert(
			'rses_elections',
			$rses_row,
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Get election by ID.
	 *
	 * @param int $election_id Election ID.
	 * @return object|null
	 */
	public static function rses_get( int $election_id ): ?object {
		return Repository::rses_get_by_id( 'rses_elections', $election_id );
	}

	/**
	 * List all elections.
	 *
	 * @return array<int,object>
	 */
	public static function rses_list(): array {
		return Repository::rses_get_rows( 'rses_elections' );
	}

	/**
	 * Create election round.
	 *
	 * @param array<string,mixed> $data Round data.
	 * @return int
	 */
	public static function rses_create_round( array $data ): int {
		$rses_row = array(
			'election_id'  => $data['election_id'],
			'round_number' => $data['round_number'],
			'round_type'   => $data['round_type'] ?? 'initial',
			'title'        => $data['title'],
			'status'       => $data['status'] ?? 'draft',
			'key_id'       => $data['key_id'] ?? null,
			'threshold_t'  => $data['threshold_t'] ?? null,
			'total_n'      => $data['total_n'] ?? null,
			'created_at'   => current_time( 'mysql', true ),
		);

		$rses_row['audit_hash'] = HashService::rses_hash_json( $rses_row );

		$rses_round_id = Repository::rses_insert(
			'rses_election_rounds',
			$rses_row,
			array( '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' )
		);

		if ( $rses_round_id ) {
			Repository::rses_update(
				'rses_elections',
				array( 'current_round_id' => $rses_round_id ),
				array( 'id' => $data['election_id'] ),
				array( '%d' ),
				array( '%d' )
			);
		}

		return $rses_round_id;
	}

	/**
	 * Get round by ID.
	 *
	 * @param int $round_id Round ID.
	 * @return object|null
	 */
	public static function rses_get_round( int $round_id ): ?object {
		return Repository::rses_get_by_id( 'rses_election_rounds', $round_id );
	}

	/**
	 * Get rounds for election.
	 *
	 * @param int $election_id Election ID.
	 * @return array<int,object>
	 */
	public static function rses_get_rounds( int $election_id ): array {
		return Repository::rses_get_rows(
			'rses_election_rounds',
			'election_id = %d',
			array( $election_id ),
			'round_number ASC'
		);
	}

	/**
	 * List elections/rounds that use a given public key.
	 *
	 * @param int $key_id Key ID.
	 * @return array<int,object>
	 */
	public static function rses_list_usage_by_key( int $key_id ): array {
		global $wpdb;

		if ( $key_id < 1 ) {
			return array();
		}

		$rses_rounds     = Schema::rses_table( 'rses_election_rounds' );
		$rses_elections  = Schema::rses_table( 'rses_elections' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rses_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.id AS election_id, e.title AS election_title, e.status AS election_status,
					r.id AS round_id, r.title AS round_title, r.round_number, r.status AS round_status, r.key_id
				FROM {$rses_rounds} r
				INNER JOIN {$rses_elections} e ON e.id = r.election_id
				WHERE r.key_id = %d
				ORDER BY e.id DESC, r.round_number ASC",
				$key_id
			)
		);

		return is_array( $rses_rows ) ? $rses_rows : array();
	}

	/**
	 * Create ballot question.
	 *
	 * @param array<string,mixed> $data Question data.
	 * @return int
	 */
	public static function rses_create_question( array $data ): int {
		$rses_row = array(
			'election_id'          => $data['election_id'],
			'round_id'             => $data['round_id'],
			'question_title'       => $data['question_title'],
			'question_description' => $data['question_description'] ?? null,
			'question_type'        => $data['question_type'],
			'min_choices'          => $data['min_choices'] ?? 0,
			'max_choices'          => $data['max_choices'] ?? 1,
			'order_index'          => $data['order_index'] ?? 0,
			'settings_json'        => isset( $data['settings'] ) ? wp_json_encode( $data['settings'] ) : null,
		);

		$rses_row['audit_hash'] = HashService::rses_hash_json( $rses_row );

		return Repository::rses_insert(
			'rses_ballot_questions',
			$rses_row,
			array( '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' )
		);
	}

	/**
	 * Create ballot option.
	 *
	 * @param array<string,mixed> $data Option data.
	 * @return int
	 */
	public static function rses_create_option( array $data ): int {
		$rses_metadata = array();
		if ( ! empty( $data['metadata'] ) && is_array( $data['metadata'] ) ) {
			$rses_metadata = $data['metadata'];
		} elseif ( ! empty( $data['attachment_id'] ) ) {
			$rses_metadata = OptionMedia::rses_metadata_from_attachment( (int) $data['attachment_id'] );
		}

		$rses_row = array(
			'question_id'       => $data['question_id'],
			'candidate_user_id' => $data['candidate_user_id'] ?? null,
			'option_label'      => $data['option_label'],
			'option_value'      => $data['option_value'] ?? null,
			'order_index'       => $data['order_index'] ?? 0,
			'metadata_json'     => ! empty( $rses_metadata ) ? wp_json_encode( $rses_metadata ) : null,
		);

		$rses_row['audit_hash'] = HashService::rses_hash_json(
			array(
				'question_id'       => $rses_row['question_id'],
				'candidate_user_id' => $rses_row['candidate_user_id'],
				'option_label'      => $rses_row['option_label'],
				'option_value'      => $rses_row['option_value'],
				'order_index'       => $rses_row['order_index'],
				'metadata_json'     => $rses_row['metadata_json'],
			)
		);

		return Repository::rses_insert(
			'rses_ballot_options',
			$rses_row,
			array( '%d', '%d', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * Get questions for round.
	 *
	 * @param int $round_id Round ID.
	 * @return array<int,object>
	 */
	public static function rses_get_questions( int $round_id ): array {
		return Repository::rses_get_rows(
			'rses_ballot_questions',
			'round_id = %d',
			array( $round_id ),
			'order_index ASC'
		);
	}

	/**
	 * Get options for question.
	 *
	 * @param int $question_id Question ID.
	 * @return array<int,object>
	 */
	public static function rses_get_options( int $question_id ): array {
		return Repository::rses_get_rows(
			'rses_ballot_options',
			'question_id = %d',
			array( $question_id ),
			'order_index ASC'
		);
	}

	/**
	 * Update election status.
	 *
	 * @param int    $election_id Election ID.
	 * @param string $status      Status.
	 * @return bool
	 */
	public static function rses_update_status( int $election_id, string $status ): bool {
		return Repository::rses_update(
			'rses_elections',
			array( 'status' => $status ),
			array( 'id' => $election_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Update round status.
	 *
	 * @param int    $round_id Round ID.
	 * @param string $status   Status.
	 * @return bool
	 */
	public static function rses_update_round_status( int $round_id, string $status ): bool {
		$rses_data = array( 'status' => $status );

		if ( 'open' === $status ) {
			$rses_data['opened_at'] = current_time( 'mysql', true );
		} elseif ( 'closed' === $status ) {
			$rses_data['closed_at'] = current_time( 'mysql', true );
		}

		return Repository::rses_update(
			'rses_election_rounds',
			$rses_data,
			array( 'id' => $round_id ),
			array_fill( 0, count( $rses_data ), '%s' ),
			array( '%d' )
		);
	}
}
