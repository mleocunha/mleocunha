<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\Persistence;

use RelataSoft\SecureElectionSuite\Painel\Application\Persistence\PersistenceGateway;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Audit\AuditLogRepository;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Elections\ElectionRepository;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Keys\KeyRepository;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Keys\ShareRepository;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Tallies\CertificationRepository;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Tallies\EncryptedTallyRepository;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Tallies\OfficialShareSubmissionRepository;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Tallies\SignedResultsStore;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Tallies\TallyImportRepository;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Votes\EncryptedVoteRepository;

/**
 * Builds a {@see PersistenceGateway} backed by one JSON file per standalone node.
 */
final class StandalonePersistenceFactory {

	public static function create( string $persistenceFile ): PersistenceGateway {
		$store = new JsonDocumentStore( $persistenceFile );

		return new PersistenceGateway(
			new FileJsonKeyRepository( $store ),
			new FileJsonShareRepository( $store ),
			new FileJsonElectionRepository( $store ),
			new FileJsonEncryptedVoteRepository( $store ),
			new FileJsonEncryptedTallyRepository( $store ),
			new FileJsonTallyImportRepository( $store ),
			new FileJsonOfficialShareSubmissionRepository( $store ),
			new FileJsonCertificationRepository( $store ),
			new FileJsonAuditLogRepository( $store ),
			new FileJsonSignedResultsStore( $store ),
		);
	}
}

final class FileJsonKeyRepository implements KeyRepository {

	public function __construct( private readonly JsonDocumentStore $store ) {}

	public function create( array $data ): int {
		$data['is_deleted'] = (int) ( $data['is_deleted'] ?? 0 );
		return $this->store->insert( 'keys', $data );
	}

	public function find( int $keyId ): ?array {
		$row = $this->store->find( 'keys', $keyId );
		if ( null === $row || (int) ( $row['is_deleted'] ?? 0 ) === 1 ) {
			return null;
		}
		return $row;
	}

	public function listActive(): array {
		$out = array();
		foreach ( $this->store->all( 'keys' ) as $row ) {
			if ( (int) ( $row['is_deleted'] ?? 0 ) === 0 ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	public function trash( int $keyId ): bool {
		$ok = false;
		$this->store->mutateAuto(
			'keys',
			static function ( array $t ) use ( $keyId, &$ok ): array {
				if ( ! isset( $t['rows'][ $keyId ] ) ) {
					return $t;
				}
				$t['rows'][ $keyId ]['is_deleted'] = 1;
				$t['rows'][ $keyId ]['deleted_at'] = $t['rows'][ $keyId ]['deleted_at'] ?? gmdate( 'Y-m-d H:i:s' );
				$ok = true;
				return $t;
			}
		);
		return $ok;
	}

	public function restore( int $keyId ): bool {
		$ok = false;
		$this->store->mutateAuto(
			'keys',
			static function ( array $t ) use ( $keyId, &$ok ): array {
				if ( ! isset( $t['rows'][ $keyId ] ) ) {
					return $t;
				}
				$t['rows'][ $keyId ]['is_deleted'] = 0;
				$t['rows'][ $keyId ]['deleted_at'] = null;
				$ok = true;
				return $t;
			}
		);
		return $ok;
	}

	public function delete( int $keyId ): bool {
		$ok = false;
		$this->store->mutateAuto(
			'keys',
			static function ( array $t ) use ( $keyId, &$ok ): array {
				if ( ! isset( $t['rows'][ $keyId ] ) ) {
					return $t;
				}
				unset( $t['rows'][ $keyId ] );
				$ok = true;
				return $t;
			}
		);
		return $ok;
	}

	public function updateThresholdMeta( int $keyId, string $fieldPrime, int $thresholdT, int $totalN ): bool {
		$ok = false;
		$this->store->mutateAuto(
			'keys',
			static function ( array $t ) use ( $keyId, $fieldPrime, $thresholdT, $totalN, &$ok ): array {
				if ( ! isset( $t['rows'][ $keyId ] ) ) {
					return $t;
				}
				$t['rows'][ $keyId ]['field_prime'] = $fieldPrime;
				$t['rows'][ $keyId ]['threshold_t'] = $thresholdT;
				$t['rows'][ $keyId ]['total_n']     = $totalN;
				$ok = true;
				return $t;
			}
		);
		return $ok;
	}
}

final class FileJsonShareRepository implements ShareRepository {

	public function __construct( private readonly JsonDocumentStore $store ) {}

	public function create( array $data ): int {
		return $this->store->insert( 'shares', $data );
	}

	public function listByKey( int $keyId ): array {
		$out = array();
		foreach ( $this->store->all( 'shares' ) as $row ) {
			if ( (int) ( $row['key_id'] ?? 0 ) === $keyId ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	public function findForUser( int $keyId, int $userId ): ?array {
		foreach ( $this->store->all( 'shares' ) as $row ) {
			if ( (int) ( $row['key_id'] ?? 0 ) === $keyId && (int) ( $row['official_user_id'] ?? 0 ) === $userId ) {
				return $row;
			}
		}
		return null;
	}
}

final class FileJsonEncryptedVoteRepository implements EncryptedVoteRepository {

	public function __construct( private readonly JsonDocumentStore $store ) {}

	public function store( array $data ): int {
		return $this->store->insert( 'votes', $data );
	}

	public function hasVoted( int $voterId, int $roundId, int $questionId ): bool {
		foreach ( $this->store->all( 'votes' ) as $row ) {
			if (
				(int) ( $row['voter_user_id'] ?? 0 ) === $voterId
				&& (int) ( $row['round_id'] ?? 0 ) === $roundId
				&& (int) ( $row['question_id'] ?? 0 ) === $questionId
			) {
				return true;
			}
		}
		return false;
	}

	public function hasVotedRound( int $voterId, int $roundId ): bool {
		foreach ( $this->store->all( 'votes' ) as $row ) {
			if ( (int) ( $row['voter_user_id'] ?? 0 ) === $voterId && (int) ( $row['round_id'] ?? 0 ) === $roundId ) {
				return true;
			}
		}
		return false;
	}

	public function countDistinctVoters( int $roundId ): int {
		$seen = array();
		foreach ( $this->store->all( 'votes' ) as $row ) {
			if ( (int) ( $row['round_id'] ?? 0 ) === $roundId ) {
				$seen[ (int) ( $row['voter_user_id'] ?? 0 ) ] = true;
			}
		}
		return count( $seen );
	}

	public function forEachExportRow( int $roundId, callable $callback, int $batch = 100 ): void {
		unset( $batch );
		$rows = array();
		foreach ( $this->store->all( 'votes' ) as $row ) {
			if ( (int) ( $row['round_id'] ?? 0 ) === $roundId ) {
				$rows[] = $row;
			}
		}
		usort( $rows, static fn( $a, $b ) => ( (int) $a['id'] ) <=> ( (int) $b['id'] ) );
		foreach ( $rows as $row ) {
			$callback(
				array(
					'id'               => (int) $row['id'],
					'question_id'      => (int) ( $row['question_id'] ?? 0 ),
					'option_id'        => isset( $row['option_id'] ) ? (int) $row['option_id'] : null,
					'ciphertext_alpha' => $row['ciphertext_alpha'] ?? null,
					'ciphertext_beta'  => $row['ciphertext_beta'] ?? null,
					'vote_hash'        => $row['vote_hash'] ?? null,
					'cast_at'          => $row['cast_at'] ?? null,
				)
			);
		}
	}

	public function receiptHash( int $voterId, int $roundId ): ?string {
		$hashes = array();
		foreach ( $this->store->all( 'votes' ) as $row ) {
			if ( (int) ( $row['voter_user_id'] ?? 0 ) === $voterId && (int) ( $row['round_id'] ?? 0 ) === $roundId ) {
				$hashes[] = array( 'id' => (int) $row['id'], 'hash' => (string) ( $row['vote_hash'] ?? '' ) );
			}
		}
		if ( empty( $hashes ) ) {
			return null;
		}
		usort( $hashes, static fn( $a, $b ) => $a['id'] <=> $b['id'] );
		$concat = '';
		foreach ( $hashes as $h ) {
			$concat .= $h['hash'];
		}
		return hash( 'sha256', $concat );
	}
}

final class FileJsonEncryptedTallyRepository implements EncryptedTallyRepository {

	public function __construct( private readonly JsonDocumentStore $store ) {}

	public function replaceForRound( int $roundId, array $rows ): int {
		$this->deleteByRound( $roundId );
		$count = 0;
		foreach ( $rows as $row ) {
			$row['round_id'] = $roundId;
			$this->store->insert( 'encrypted_tallies', $row );
			++$count;
		}
		return $count;
	}

	public function listByRound( int $roundId ): array {
		$out = array();
		foreach ( $this->store->all( 'encrypted_tallies' ) as $row ) {
			if ( (int) ( $row['round_id'] ?? 0 ) === $roundId ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	public function deleteByRound( int $roundId ): void {
		$this->store->mutateAuto(
			'encrypted_tallies',
			static function ( array $t ) use ( $roundId ): array {
				foreach ( array_keys( $t['rows'] ) as $id ) {
					if ( (int) ( $t['rows'][ $id ]['round_id'] ?? 0 ) === $roundId ) {
						unset( $t['rows'][ $id ] );
					}
				}
				return $t;
			}
		);
	}
}

final class FileJsonTallyImportRepository implements TallyImportRepository {

	public function __construct( private readonly JsonDocumentStore $store ) {}

	public function create( array $data ): int {
		return $this->store->insert( 'tally_imports', $data );
	}

	public function find( int $importId ): ?array {
		return $this->store->find( 'tally_imports', $importId );
	}

	public function listSummaries(): array {
		$out = array();
		foreach ( $this->store->all( 'tally_imports' ) as $row ) {
			$copy     = $row;
			$manifest = (string) ( $copy['import_manifest_json'] ?? '' );
			$copy['manifest_bytes'] = strlen( $manifest );
			unset( $copy['import_manifest_json'] );
			$out[] = $copy;
		}
		usort( $out, static fn( $a, $b ) => ( (int) $b['id'] ) <=> ( (int) $a['id'] ) );
		return $out;
	}

	public function updateStatus( int $importId, string $status ): bool {
		$ok = false;
		$this->store->mutateAuto(
			'tally_imports',
			static function ( array $t ) use ( $importId, $status, &$ok ): array {
				if ( ! isset( $t['rows'][ $importId ] ) ) {
					return $t;
				}
				$t['rows'][ $importId ]['status'] = $status;
				$ok = true;
				return $t;
			}
		);
		return $ok;
	}

	public function updateSummary( int $importId, array $summary ): bool {
		$ok = false;
		$this->store->mutateAuto(
			'tally_imports',
			static function ( array $t ) use ( $importId, $summary, &$ok ): array {
				if ( ! isset( $t['rows'][ $importId ] ) ) {
					return $t;
				}
				foreach ( $summary as $k => $v ) {
					$t['rows'][ $importId ][ $k ] = $v;
				}
				$ok = true;
				return $t;
			}
		);
		return $ok;
	}

	public function delete( int $importId ): bool {
		$ok = false;
		$this->store->mutateAuto(
			'tally_imports',
			static function ( array $t ) use ( $importId, &$ok ): array {
				if ( ! isset( $t['rows'][ $importId ] ) ) {
					return $t;
				}
				unset( $t['rows'][ $importId ] );
				$ok = true;
				return $t;
			}
		);
		return $ok;
	}

	public function listIdsNeedingSummary( int $limit, int $maxManifestBytes ): array {
		$ids  = array();
		$rows = $this->store->all( 'tally_imports' );
		usort( $rows, static fn( $a, $b ) => ( (int) $b['id'] ) <=> ( (int) $a['id'] ) );
		foreach ( $rows as $row ) {
			$title    = (string) ( $row['election_title'] ?? '' );
			$manifest = (string) ( $row['import_manifest_json'] ?? '' );
			if ( '' !== $title ) {
				continue;
			}
			if ( strlen( $manifest ) > $maxManifestBytes ) {
				continue;
			}
			$ids[] = (int) $row['id'];
			if ( count( $ids ) >= $limit ) {
				break;
			}
		}
		return $ids;
	}

	public function purgeOversizedManifests( string $stubJson, int $maxBytes ): int {
		$n = 0;
		$this->store->mutateAuto(
			'tally_imports',
			static function ( array $t ) use ( $stubJson, $maxBytes, &$n ): array {
				foreach ( $t['rows'] as $id => $row ) {
					$manifest = (string) ( $row['import_manifest_json'] ?? '' );
					if ( strlen( $manifest ) > $maxBytes ) {
						$t['rows'][ $id ]['import_manifest_json'] = $stubJson;
						$t['rows'][ $id ]['status']               = 'rejected';
						++$n;
					}
				}
				return $t;
			}
		);
		return $n;
	}
}

final class FileJsonOfficialShareSubmissionRepository implements OfficialShareSubmissionRepository {

	public function __construct( private readonly JsonDocumentStore $store ) {}

	public function create( array $data ): int {
		return $this->store->insert( 'share_submissions', $data );
	}

	public function countByImport( int $importId ): int {
		$n = 0;
		foreach ( $this->store->all( 'share_submissions' ) as $row ) {
			if ( (int) ( $row['tally_import_id'] ?? 0 ) === $importId ) {
				++$n;
			}
		}
		return $n;
	}

	public function countByImportAndIndex( int $importId, int $shareIndex ): int {
		$n = 0;
		foreach ( $this->store->all( 'share_submissions' ) as $row ) {
			if ( (int) ( $row['tally_import_id'] ?? 0 ) === $importId && (int) ( $row['share_index'] ?? 0 ) === $shareIndex ) {
				++$n;
			}
		}
		return $n;
	}

	public function listByImport( int $importId ): array {
		$out = array();
		foreach ( $this->store->all( 'share_submissions' ) as $row ) {
			if ( (int) ( $row['tally_import_id'] ?? 0 ) === $importId ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	public function deleteByImport( int $importId ): int {
		$n = 0;
		$this->store->mutateAuto(
			'share_submissions',
			static function ( array $t ) use ( $importId, &$n ): array {
				foreach ( array_keys( $t['rows'] ) as $id ) {
					if ( (int) ( $t['rows'][ $id ]['tally_import_id'] ?? 0 ) === $importId ) {
						unset( $t['rows'][ $id ] );
						++$n;
					}
				}
				return $t;
			}
		);
		return $n;
	}
}

final class FileJsonCertificationRepository implements CertificationRepository {

	public function __construct( private readonly JsonDocumentStore $store ) {}

	public function create( array $data ): int {
		return $this->store->insert( 'certifications', $data );
	}

	public function findLatestReportByImport( int $importId ): ?array {
		$best = null;
		foreach ( $this->store->all( 'certifications' ) as $row ) {
			if ( (int) ( $row['tally_import_id'] ?? 0 ) !== $importId ) {
				continue;
			}
			if ( null === $best || (int) $row['id'] > (int) $best['id'] ) {
				$best = $row;
			}
		}
		return $best;
	}

	public function deleteByImport( int $importId ): int {
		$n = 0;
		$this->store->mutateAuto(
			'certifications',
			static function ( array $t ) use ( $importId, &$n ): array {
				foreach ( array_keys( $t['rows'] ) as $id ) {
					if ( (int) ( $t['rows'][ $id ]['tally_import_id'] ?? 0 ) === $importId ) {
						unset( $t['rows'][ $id ] );
						++$n;
					}
				}
				return $t;
			}
		);
		return $n;
	}
}

final class FileJsonAuditLogRepository implements AuditLogRepository {

	public function __construct( private readonly JsonDocumentStore $store ) {}

	public function append( array $entry ): int {
		return $this->store->insert( 'audit_log', $entry );
	}

	public function lastHash(): ?string {
		$best = null;
		foreach ( $this->store->all( 'audit_log' ) as $row ) {
			if ( null === $best || (int) $row['id'] > (int) $best['id'] ) {
				$best = $row;
			}
		}
		if ( null === $best ) {
			return null;
		}
		$hash = $best['current_hash'] ?? null;
		return null === $hash || '' === $hash ? null : (string) $hash;
	}

	public function listRecent( int $limit = 100 ): array {
		$rows = $this->store->all( 'audit_log' );
		usort( $rows, static fn( $a, $b ) => ( (int) $b['id'] ) <=> ( (int) $a['id'] ) );
		return array_slice( $rows, 0, max( 0, $limit ) );
	}

	public function listAllOrdered(): array {
		$rows = $this->store->all( 'audit_log' );
		usort( $rows, static fn( $a, $b ) => ( (int) $a['id'] ) <=> ( (int) $b['id'] ) );
		return $rows;
	}

	public function updateHashes( int $id, ?string $previous, string $current ): void {
		$this->store->mutateAuto(
			'audit_log',
			static function ( array $t ) use ( $id, $previous, $current ): array {
				if ( ! isset( $t['rows'][ $id ] ) ) {
					return $t;
				}
				$t['rows'][ $id ]['previous_hash'] = $previous;
				$t['rows'][ $id ]['current_hash']  = $current;
				return $t;
			}
		);
	}
}

final class FileJsonSignedResultsStore implements SignedResultsStore {

	public function __construct( private readonly JsonDocumentStore $store ) {}

	public function get( int $importId ): ?array {
		$blob = $this->store->blob( 'signed_results' );
		$row  = $blob[ (string) $importId ] ?? null;
		return is_array( $row ) ? $row : null;
	}

	public function put( int $importId, array $meta ): void {
		$blob                       = $this->store->blob( 'signed_results' );
		$blob[ (string) $importId ] = $meta;
		$this->store->writeBlob( 'signed_results', $blob );
	}

	public function delete( int $importId ): void {
		$blob = $this->store->blob( 'signed_results' );
		unset( $blob[ (string) $importId ] );
		$this->store->writeBlob( 'signed_results', $blob );
	}
}

final class FileJsonElectionRepository implements ElectionRepository {

	public function __construct( private readonly JsonDocumentStore $store ) {}

	/** @return array<string,mixed> */
	private function state(): array {
		$b = $this->store->blob( 'elections' );
		return array(
			'electionId' => (int) ( $b['electionId'] ?? 1 ),
			'roundId'    => (int) ( $b['roundId'] ?? 1 ),
			'questionId' => (int) ( $b['questionId'] ?? 1 ),
			'optionId'   => (int) ( $b['optionId'] ?? 1 ),
			'elections'  => $this->intKeyed( (array) ( $b['elections'] ?? array() ) ),
			'rounds'     => $this->intKeyed( (array) ( $b['rounds'] ?? array() ) ),
			'questions'  => $this->intKeyed( (array) ( $b['questions'] ?? array() ) ),
			'options'    => $this->intKeyed( (array) ( $b['options'] ?? array() ) ),
		);
	}

	/**
	 * @param array<string|int,mixed> $map
	 * @return array<int,array<string,mixed>>
	 */
	private function intKeyed( array $map ): array {
		$out = array();
		foreach ( $map as $id => $row ) {
			if ( is_array( $row ) ) {
				$out[ (int) $id ] = $row;
			}
		}
		return $out;
	}

	/** @param array<string,mixed> $state */
	private function save( array $state ): void {
		$encode = static function ( array $rows ): array {
			$out = array();
			foreach ( $rows as $id => $row ) {
				$out[ (string) (int) $id ] = $row;
			}
			return $out;
		};
		$this->store->writeBlob(
			'elections',
			array(
				'electionId' => $state['electionId'],
				'roundId'    => $state['roundId'],
				'questionId' => $state['questionId'],
				'optionId'   => $state['optionId'],
				'elections'  => $encode( $state['elections'] ),
				'rounds'     => $encode( $state['rounds'] ),
				'questions'  => $encode( $state['questions'] ),
				'options'    => $encode( $state['options'] ),
			)
		);
	}

	public function createElection( array $data ): int {
		$s              = $this->state();
		$id             = $s['electionId']++;
		$data['id']     = $id;
		$s['elections'][ $id ] = $data;
		$this->save( $s );
		return $id;
	}

	public function findElection( int $electionId ): ?array {
		return $this->state()['elections'][ $electionId ] ?? null;
	}

	public function listElections(): array {
		return array_values( $this->state()['elections'] );
	}

	public function updateElectionStatus( int $electionId, string $status ): bool {
		$s = $this->state();
		if ( ! isset( $s['elections'][ $electionId ] ) ) {
			return false;
		}
		$s['elections'][ $electionId ]['status'] = $status;
		$this->save( $s );
		return true;
	}

	public function createRound( array $data ): int {
		$s          = $this->state();
		$id         = $s['roundId']++;
		$data['id'] = $id;
		$s['rounds'][ $id ] = $data;
		$electionId = (int) ( $data['election_id'] ?? 0 );
		if ( isset( $s['elections'][ $electionId ] ) ) {
			$s['elections'][ $electionId ]['current_round_id'] = $id;
		}
		$this->save( $s );
		return $id;
	}

	public function findRound( int $roundId ): ?array {
		return $this->state()['rounds'][ $roundId ] ?? null;
	}

	public function listRounds( int $electionId ): array {
		$out = array();
		foreach ( $this->state()['rounds'] as $row ) {
			if ( (int) ( $row['election_id'] ?? 0 ) === $electionId ) {
				$out[] = $row;
			}
		}
		usort( $out, static fn( $a, $b ) => ( (int) ( $a['round_number'] ?? 0 ) ) <=> ( (int) ( $b['round_number'] ?? 0 ) ) );
		return $out;
	}

	public function updateRoundStatus( int $roundId, string $status, ?string $openedAt = null, ?string $closedAt = null ): bool {
		$s = $this->state();
		if ( ! isset( $s['rounds'][ $roundId ] ) ) {
			return false;
		}
		$s['rounds'][ $roundId ]['status'] = $status;
		if ( null !== $openedAt ) {
			$s['rounds'][ $roundId ]['opened_at'] = $openedAt;
		}
		if ( null !== $closedAt ) {
			$s['rounds'][ $roundId ]['closed_at'] = $closedAt;
		}
		$this->save( $s );
		return true;
	}

	public function createQuestion( array $data ): int {
		$s          = $this->state();
		$id         = $s['questionId']++;
		$data['id'] = $id;
		$s['questions'][ $id ] = $data;
		$this->save( $s );
		return $id;
	}

	public function createOption( array $data ): int {
		$s          = $this->state();
		$id         = $s['optionId']++;
		$data['id'] = $id;
		$s['options'][ $id ] = $data;
		$this->save( $s );
		return $id;
	}

	public function listQuestions( int $roundId ): array {
		$out = array();
		foreach ( $this->state()['questions'] as $row ) {
			if ( (int) ( $row['round_id'] ?? 0 ) === $roundId ) {
				$out[] = $row;
			}
		}
		usort( $out, static fn( $a, $b ) => ( (int) ( $a['order_index'] ?? 0 ) ) <=> ( (int) ( $b['order_index'] ?? 0 ) ) );
		return $out;
	}

	public function listOptions( int $questionId ): array {
		$out = array();
		foreach ( $this->state()['options'] as $row ) {
			if ( (int) ( $row['question_id'] ?? 0 ) === $questionId ) {
				$out[] = $row;
			}
		}
		usort( $out, static fn( $a, $b ) => ( (int) ( $a['order_index'] ?? 0 ) ) <=> ( (int) ( $b['order_index'] ?? 0 ) ) );
		return $out;
	}
}
