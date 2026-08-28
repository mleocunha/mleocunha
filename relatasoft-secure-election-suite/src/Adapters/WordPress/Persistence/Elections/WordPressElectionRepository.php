<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Persistence\Elections;

use RelataSoft\SecureElectionSuite\Database\Repository;
use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Persistence\RowMapper;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Elections\ElectionRepository;

final class WordPressElectionRepository implements ElectionRepository {

	public function createElection(array $data): int {
		return Repository::rses_insert(
			'rses_elections',
			$data,
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	public function findElection(int $electionId): ?array {
		return RowMapper::toArray( Repository::rses_get_by_id( 'rses_elections', $electionId ) );
	}

	public function listElections(): array {
		return RowMapper::toArrays( Repository::rses_get_rows( 'rses_elections' ) );
	}

	public function updateElectionStatus(int $electionId, string $status): bool {
		return Repository::rses_update(
			'rses_elections',
			array( 'status' => $status ),
			array( 'id' => $electionId ),
			array( '%s' ),
			array( '%d' )
		);
	}

	public function createRound(array $data): int {
		$electionId = (int) ( $data['election_id'] ?? 0 );
		$roundId    = Repository::rses_insert(
			'rses_election_rounds',
			$data,
			array( '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' )
		);
		if ( $roundId ) {
			Repository::rses_update(
				'rses_elections',
				array( 'current_round_id' => $roundId ),
				array( 'id' => $electionId ),
				array( '%d' ),
				array( '%d' )
			);
		}
		return $roundId;
	}

	public function findRound(int $roundId): ?array {
		return RowMapper::toArray( Repository::rses_get_by_id( 'rses_election_rounds', $roundId ) );
	}

	public function listRounds(int $electionId): array {
		return RowMapper::toArrays(
			Repository::rses_get_rows(
				'rses_election_rounds',
				'election_id = %d',
				array( $electionId ),
				'round_number ASC'
			)
		);
	}

	public function updateRoundStatus(int $roundId, string $status, ?string $openedAt = null, ?string $closedAt = null): bool {
		$data = array( 'status' => $status );
		if ( null !== $openedAt ) {
			$data['opened_at'] = $openedAt;
		}
		if ( null !== $closedAt ) {
			$data['closed_at'] = $closedAt;
		}
		return Repository::rses_update(
			'rses_election_rounds',
			$data,
			array( 'id' => $roundId ),
			array_fill( 0, count( $data ), '%s' ),
			array( '%d' )
		);
	}

	public function createQuestion(array $data): int {
		return Repository::rses_insert(
			'rses_ballot_questions',
			$data,
			array( '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' )
		);
	}

	public function createOption(array $data): int {
		return Repository::rses_insert(
			'rses_ballot_options',
			$data,
			array( '%d', '%d', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	public function listQuestions(int $roundId): array {
		return RowMapper::toArrays(
			Repository::rses_get_rows(
				'rses_ballot_questions',
				'round_id = %d',
				array( $roundId ),
				'order_index ASC'
			)
		);
	}

	public function listOptions(int $questionId): array {
		return RowMapper::toArrays(
			Repository::rses_get_rows(
				'rses_ballot_options',
				'question_id = %d',
				array( $questionId ),
				'order_index ASC'
			)
		);
	}
}
