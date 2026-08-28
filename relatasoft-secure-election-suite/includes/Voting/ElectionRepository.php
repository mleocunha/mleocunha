<?php
/**
 * Election repository.
 *
 * @package RelataSoft\SecureElectionSuite\Voting
 */

namespace RelataSoft\SecureElectionSuite\Voting;

use RelataSoft\SecureElectionSuite\Exports\HashService;
use RelataSoft\SecureElectionSuite\Painel\Application\Persistence\PersistenceGateway;

defined( 'ABSPATH' ) || exit;

/**
 * Election database operations (delegates to A2 persistence ports).
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

		return PersistenceGateway::get()->elections->createElection( $rses_row );
	}

	/**
	 * Get election by ID.
	 *
	 * @param int $election_id Election ID.
	 * @return object|null
	 */
	public static function rses_get( int $election_id ): ?object {
		$row = PersistenceGateway::get()->elections->findElection( $election_id );
		return null === $row ? null : (object) $row;
	}

	/**
	 * List all elections.
	 *
	 * @return array<int,object>
	 */
	public static function rses_list(): array {
		return array_map(
			static fn( array $row ) => (object) $row,
			PersistenceGateway::get()->elections->listElections()
		);
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

		return PersistenceGateway::get()->elections->createRound( $rses_row );
	}

	/**
	 * Get round by ID.
	 *
	 * @param int $round_id Round ID.
	 * @return object|null
	 */
	public static function rses_get_round( int $round_id ): ?object {
		$row = PersistenceGateway::get()->elections->findRound( $round_id );
		return null === $row ? null : (object) $row;
	}

	/**
	 * Get rounds for election.
	 *
	 * @param int $election_id Election ID.
	 * @return array<int,object>
	 */
	public static function rses_get_rounds( int $election_id ): array {
		return array_map(
			static fn( array $row ) => (object) $row,
			PersistenceGateway::get()->elections->listRounds( $election_id )
		);
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

		return PersistenceGateway::get()->elections->createQuestion( $rses_row );
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

		return PersistenceGateway::get()->elections->createOption( $rses_row );
	}

	/**
	 * Get questions for round.
	 *
	 * @param int $round_id Round ID.
	 * @return array<int,object>
	 */
	public static function rses_get_questions( int $round_id ): array {
		return array_map(
			static fn( array $row ) => (object) $row,
			PersistenceGateway::get()->elections->listQuestions( $round_id )
		);
	}

	/**
	 * Get options for question.
	 *
	 * @param int $question_id Question ID.
	 * @return array<int,object>
	 */
	public static function rses_get_options( int $question_id ): array {
		return array_map(
			static fn( array $row ) => (object) $row,
			PersistenceGateway::get()->elections->listOptions( $question_id )
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
		return PersistenceGateway::get()->elections->updateElectionStatus( $election_id, $status );
	}

	/**
	 * Update round status.
	 *
	 * @param int    $round_id Round ID.
	 * @param string $status   Status.
	 * @return bool
	 */
	public static function rses_update_round_status( int $round_id, string $status ): bool {
		$opened = null;
		$closed = null;

		if ( 'open' === $status ) {
			$opened = current_time( 'mysql', true );
		} elseif ( 'closed' === $status ) {
			$closed = current_time( 'mysql', true );
		}

		return PersistenceGateway::get()->elections->updateRoundStatus( $round_id, $status, $opened, $closed );
	}
}
