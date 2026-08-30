<?php
declare(strict_types=1);

/**
 * A2 persistence ports — InMemory implementations (no sítio boot).
 *
 * @package RelataSoft\SecureElectionSuite\Tests\Unit\Persistence
 */

namespace RelataSoft\SecureElectionSuite\Tests\Unit\Persistence;

use PHPUnit\Framework\TestCase;
use RelataSoft\SecureElectionSuite\Painel\Application\Persistence\PersistenceGateway;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Persistence\Audit\InMemoryAuditLogRepository;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Persistence\Elections\InMemoryElectionRepository;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Persistence\Keys\InMemoryKeyRepository;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Persistence\Keys\InMemoryShareRepository;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Persistence\Tallies\InMemoryCertificationRepository;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Persistence\Tallies\InMemoryEncryptedTallyRepository;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Persistence\Tallies\InMemoryOfficialShareSubmissionRepository;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Persistence\Tallies\InMemorySignedResultsStore;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Persistence\Tallies\InMemoryTallyImportRepository;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Persistence\Votes\InMemoryEncryptedVoteRepository;

final class PersistencePortsTest extends TestCase {

	private PersistenceGateway $gw;

	protected function setUp(): void {
		PersistenceGateway::reset();
		$this->gw = PersistenceGateway::boot(
			new PersistenceGateway(
				new InMemoryKeyRepository(),
				new InMemoryShareRepository(),
				new InMemoryElectionRepository(),
				new InMemoryEncryptedVoteRepository(),
				new InMemoryEncryptedTallyRepository(),
				new InMemoryTallyImportRepository(),
				new InMemoryOfficialShareSubmissionRepository(),
				new InMemoryCertificationRepository(),
				new InMemoryAuditLogRepository(),
				new InMemorySignedResultsStore(),
			)
		);
	}

	protected function tearDown(): void {
		PersistenceGateway::reset();
	}

	public function test_keys_and_shares_lifecycle(): void {
		$keyId = $this->gw->keys->create(
			array(
				'key_label' => 'k1',
				'public_p'  => '11',
				'public_q'  => '5',
				'public_g'  => '2',
				'public_y'  => '3',
				'key_size'  => 512,
				'is_deleted'=> 0,
			)
		);
		$this->assertSame( 1, $keyId );
		$this->assertSame( 'k1', $this->gw->keys->find( $keyId )['key_label'] ?? null );

		$shareId = $this->gw->shares->create(
			array(
				'key_id'           => $keyId,
				'official_user_id' => 9,
				'share_index'      => 1,
			)
		);
		$this->assertSame( 1, $shareId );
		$this->assertCount( 1, $this->gw->shares->listByKey( $keyId ) );
		$this->assertSame( 9, $this->gw->shares->findForUser( $keyId, 9 )['official_user_id'] ?? null );

		$this->assertTrue( $this->gw->keys->updateThresholdMeta( $keyId, '97', 2, 3 ) );
		$this->assertSame( 2, $this->gw->keys->find( $keyId )['threshold_t'] ?? null );

		$this->assertTrue( $this->gw->keys->trash( $keyId ) );
		$this->assertNull( $this->gw->keys->find( $keyId ) );
		$this->assertTrue( $this->gw->keys->restore( $keyId ) );
		$this->assertNotNull( $this->gw->keys->find( $keyId ) );
		$this->assertTrue( $this->gw->keys->delete( $keyId ) );
		$this->assertNull( $this->gw->keys->find( $keyId ) );
	}

	public function test_elections_rounds_ballot(): void {
		$eid = $this->gw->elections->createElection(
			array(
				'title'         => 'Eleição',
				'voting_method' => 'approval',
				'status'        => 'draft',
			)
		);
		$rid = $this->gw->elections->createRound(
			array(
				'election_id'  => $eid,
				'round_number' => 1,
				'title'        => 'Turno 1',
			)
		);
		$this->assertSame( $rid, $this->gw->elections->findElection( $eid )['current_round_id'] ?? null );

		$qid = $this->gw->elections->createQuestion(
			array(
				'election_id'    => $eid,
				'round_id'       => $rid,
				'question_title' => 'Q?',
				'question_type'  => 'single',
				'order_index'    => 0,
			)
		);
		$oid = $this->gw->elections->createOption(
			array(
				'question_id'  => $qid,
				'option_label' => 'A',
				'order_index'  => 0,
			)
		);
		$this->assertSame( 1, $oid );
		$this->assertCount( 1, $this->gw->elections->listQuestions( $rid ) );
		$this->assertCount( 1, $this->gw->elections->listOptions( $qid ) );

		$this->assertTrue( $this->gw->elections->updateRoundStatus( $rid, 'open', '2026-01-01 00:00:00', null ) );
		$this->assertSame( 'open', $this->gw->elections->findRound( $rid )['status'] ?? null );
	}

	public function test_votes_and_tallies(): void {
		$this->gw->votes->store(
			array(
				'round_id'         => 1,
				'question_id'      => 10,
				'option_id'        => 20,
				'voter_user_id'    => 5,
				'ciphertext_alpha' => '1',
				'ciphertext_beta'  => '2',
				'vote_hash'        => 'aa',
			)
		);
		$this->gw->votes->store(
			array(
				'round_id'         => 1,
				'question_id'      => 10,
				'option_id'        => 20,
				'voter_user_id'    => 6,
				'ciphertext_alpha' => '3',
				'ciphertext_beta'  => '4',
				'vote_hash'        => 'bb',
			)
		);

		$this->assertTrue( $this->gw->votes->hasVoted( 5, 1, 10 ) );
		$this->assertTrue( $this->gw->votes->hasVotedRound( 6, 1 ) );
		$this->assertSame( 2, $this->gw->votes->countDistinctVoters( 1 ) );
		$this->assertSame( hash( 'sha256', 'aa' ), $this->gw->votes->receiptHash( 5, 1 ) );

		$exported = array();
		$this->gw->votes->forEachExportRow(
			1,
			static function ( array $row ) use ( &$exported ): void {
				$exported[] = $row['vote_hash'];
			}
		);
		$this->assertSame( array( 'aa', 'bb' ), $exported );

		$count = $this->gw->encryptedTallies->replaceForRound(
			1,
			array(
				array(
					'election_id'     => 1,
					'question_id'     => 10,
					'option_id'       => 20,
					'aggregate_alpha' => '9',
					'aggregate_beta'  => '8',
					'ballot_count'    => 2,
					'max_decode_count'=> 2,
					'created_at'      => 'now',
					'audit_hash'      => 'h',
				),
			)
		);
		$this->assertSame( 1, $count );
		$this->assertCount( 1, $this->gw->encryptedTallies->listByRound( 1 ) );
		$this->gw->encryptedTallies->deleteByRound( 1 );
		$this->assertCount( 0, $this->gw->encryptedTallies->listByRound( 1 ) );
	}

	public function test_tally_imports_shares_certs_and_audit(): void {
		$importId = $this->gw->tallyImports->create(
			array(
				'import_manifest_json' => '{"ok":1}',
				'import_hash'          => 'h1',
				'status'               => 'pending',
			)
		);
		$this->assertSame( 'pending', $this->gw->tallyImports->find( $importId )['status'] ?? null );
		$this->assertTrue( $this->gw->tallyImports->updateStatus( $importId, 'ready' ) );
		$this->assertSame( 'ready', $this->gw->tallyImports->listSummaries()[0]['status'] ?? null );

		$subId = $this->gw->shareSubmissions->create(
			array(
				'tally_import_id'         => $importId,
				'share_index'             => 1,
				'share_payload_encrypted' => 'x',
			)
		);
		$this->assertSame( 1, $subId );
		$this->assertSame( 1, $this->gw->shareSubmissions->countByImport( $importId ) );
		$this->assertSame( 1, $this->gw->shareSubmissions->countByImportAndIndex( $importId, 1 ) );

		$certId = $this->gw->certifications->create(
			array(
				'tally_import_id'          => $importId,
				'certification_status'     => 'certified',
				'verification_report_json' => '{"r":1}',
			)
		);
		$this->assertSame( 1, $certId );
		$this->assertSame( '{"r":1}', $this->gw->certifications->findLatestReportByImport( $importId )['verification_report_json'] ?? null );

		$a1 = $this->gw->auditLog->append(
			array(
				'action'       => 'test',
				'object_type'  => 'x',
				'current_hash' => 'c1',
				'previous_hash'=> null,
			)
		);
		$a2 = $this->gw->auditLog->append(
			array(
				'action'       => 'test2',
				'object_type'  => 'x',
				'current_hash' => 'c2',
				'previous_hash'=> 'c1',
			)
		);
		$this->assertSame( 2, $a2 );
		$this->assertSame( 'c2', $this->gw->auditLog->lastHash() );
		$this->assertCount( 2, $this->gw->auditLog->listAllOrdered() );
		$this->gw->auditLog->updateHashes( $a1, null, 'c1-fixed' );
		$this->assertSame( 'c1-fixed', $this->gw->auditLog->listAllOrdered()[0]['current_hash'] ?? null );

		$this->assertSame( 1, $this->gw->shareSubmissions->deleteByImport( $importId ) );
		$this->assertSame( 1, $this->gw->certifications->deleteByImport( $importId ) );
		$this->assertTrue( $this->gw->tallyImports->delete( $importId ) );
	}

	public function test_list_methods_and_tally_maintenance_ports(): void {
		$keyId = $this->gw->keys->create(
			array(
				'key_label'  => 'k2',
				'public_p'   => '11',
				'public_q'   => '5',
				'public_g'   => '2',
				'public_y'   => '3',
				'key_size'   => 512,
				'is_deleted' => 0,
			)
		);
		$this->assertCount( 1, $this->gw->keys->listActive() );

		$eid = $this->gw->elections->createElection(
			array(
				'title'         => 'E2',
				'voting_method' => 'approval',
				'status'        => 'draft',
			)
		);
		$this->assertCount( 1, $this->gw->elections->listElections() );
		$this->assertTrue( $this->gw->elections->updateElectionStatus( $eid, 'open' ) );
		$rid = $this->gw->elections->createRound(
			array(
				'election_id'  => $eid,
				'round_number' => 1,
				'title'        => 'R1',
			)
		);
		$this->assertCount( 1, $this->gw->elections->listRounds( $eid ) );

		$importId = $this->gw->tallyImports->create(
			array(
				'import_manifest_json' => '{"election":{"title":"X"},"round":{"title":"Y"}}',
				'import_hash'          => 'h2',
				'status'               => 'pending',
				'election_title'       => '',
			)
		);
		$need = $this->gw->tallyImports->listIdsNeedingSummary( 10, 100000 );
		$this->assertContains( $importId, $need );
		$this->assertTrue(
			$this->gw->tallyImports->updateSummary(
				$importId,
				array(
					'election_title' => 'X',
					'round_title'    => 'Y',
					'ballot_count'   => 1,
				)
			)
		);

		$big = $this->gw->tallyImports->create(
			array(
				'import_manifest_json' => str_repeat( 'x', 50 ),
				'import_hash'          => 'h3',
				'status'               => 'pending',
			)
		);
		$purged = $this->gw->tallyImports->purgeOversizedManifests( '{"purged":true}', 10 );
		$this->assertGreaterThanOrEqual( 1, $purged );
		$this->assertSame( 'rejected', $this->gw->tallyImports->find( $big )['status'] ?? null );

		$this->gw->shareSubmissions->create(
			array(
				'tally_import_id'         => $importId,
				'share_index'             => 1,
				'share_payload_encrypted' => 'x',
			)
		);
		$this->assertCount( 1, $this->gw->shareSubmissions->listByImport( $importId ) );
		$this->gw->auditLog->append(
			array(
				'action'        => 'm2.audit',
				'object_type'   => 'test',
				'current_hash'  => 'm2hash',
				'previous_hash' => null,
			)
		);
		$this->assertNotEmpty( $this->gw->auditLog->listRecent( 5 ) );

		$this->gw->signedResults->put( $importId, array( 'package' => array( 'v' => 1 ) ) );
		$this->assertSame( 1, $this->gw->signedResults->get( $importId )['package']['v'] ?? null );
		$this->gw->signedResults->delete( $importId );
		$this->assertNull( $this->gw->signedResults->get( $importId ) );

		unset( $keyId, $rid );
	}

	public function test_gateway_requires_boot(): void {
		PersistenceGateway::reset();
		$this->expectException( \RuntimeException::class );
		PersistenceGateway::get();
	}
}
