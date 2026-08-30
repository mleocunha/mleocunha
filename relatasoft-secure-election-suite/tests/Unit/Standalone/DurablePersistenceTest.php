<?php
declare(strict_types=1);

/**
 * Adapter #2 — durable JSON persistence per node (survives process restart; no sync).
 *
 * @package RelataSoft\SecureElectionSuite\Tests\Unit\Standalone
 */

namespace RelataSoft\SecureElectionSuite\Tests\Unit\Standalone;

use PHPUnit\Framework\TestCase;
use RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\Bootstrap;
use RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\NodeRuntime;
use RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\Persistence\StandalonePersistenceFactory;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Mode\SiteModes;

final class DurablePersistenceTest extends TestCase {

	private string $root;

	protected function setUp(): void {
		if ( ! extension_loaded( 'gmp' ) ) {
			$this->markTestSkipped( 'GMP extension required.' );
		}
		$this->root = sys_get_temp_dir() . '/ve-durable-' . uniqid( '', true );
	}

	protected function tearDown(): void {
		$this->rmTree( $this->root );
	}

	public function test_keys_survive_process_restart(): void {
		$dir = $this->root . '/ka';
		$n1  = NodeRuntime::create( SiteModes::KEY_AUTHORITY, $dir, 'c1', true );
		$id  = $n1->persistence->keys->create(
			array(
				'key_label'  => 'duravel',
				'public_p'   => '11',
				'public_q'   => '5',
				'public_g'   => '2',
				'public_y'   => '3',
				'private_x'  => 'secret-x',
				'key_size'   => 512,
				'is_deleted' => 0,
			)
		);
		$this->assertFileExists( $n1->persistencePath() );

		// Simulate new process: new NodeRuntime on same dataDir.
		$n2 = NodeRuntime::create( SiteModes::KEY_AUTHORITY, $dir, 'c1', true );
		$row = $n2->persistence->keys->find( $id );
		$this->assertNotNull( $row );
		$this->assertSame( 'secret-x', $row['private_x'] ?? null );
		$this->assertSame( 'duravel', $row['key_label'] ?? null );
	}

	public function test_nodes_do_not_share_json_files(): void {
		$ka = NodeRuntime::create( SiteModes::KEY_AUTHORITY, $this->root . '/ka', 'c', true );
		$vt = NodeRuntime::create( SiteModes::VOTING, $this->root . '/vt', 'c', true );
		$ka->persistence->keys->create(
			array(
				'key_label'  => 'only-ka',
				'public_p'   => '1',
				'public_q'   => '1',
				'public_g'   => '1',
				'public_y'   => '1',
				'key_size'   => 512,
				'is_deleted' => 0,
			)
		);
		$this->assertFileExists( $ka->persistencePath() );
		$this->assertFileExists( $vt->persistencePath() );
		$this->assertNotSame( $ka->persistencePath(), $vt->persistencePath() );
		$this->assertCount( 0, $vt->persistence->keys->listActive() );
	}

	public function test_full_pilot_then_reload_tallying_cert(): void {
		$pilot  = Bootstrap::bootPilotWorkspace( $this->root, 'duravel', 512, true );
		$result = $pilot->run( 2 );
		$tallyDir = $this->root . '/tallying';
		$this->assertFileExists( $tallyDir . '/persistence.json' );

		$reloaded = NodeRuntime::create( SiteModes::TALLYING, $tallyDir, 'duravel', true );
		$cert     = $reloaded->persistence->certifications->findLatestReportByImport(
			// import id is not returned; list via create path — find by scanning
			1
		);
		// Pilot creates import id 1 then cert id 1 on empty store.
		$this->assertNotNull( $cert );
		$report = json_decode( (string) ( $cert['verification_report_json'] ?? '' ), true );
		$this->assertIsArray( $report );
		$this->assertSame( 2, (int) ( $report['tally'] ?? 0 ) );
		$this->assertSame( $result['certification_id'], (int) $cert['id'] );

		$kaReloaded = NodeRuntime::create( SiteModes::KEY_AUTHORITY, $this->root . '/ka', 'duravel', true );
		$kaKey      = $kaReloaded->persistence->keys->find( $result['key_id'] );
		$this->assertNotEmpty( $kaKey['private_x'] ?? null );

		$vtReloaded = NodeRuntime::create( SiteModes::VOTING, $this->root . '/voting', 'duravel', true );
		$vtKeys     = $vtReloaded->persistence->keys->listActive();
		$this->assertCount( 1, $vtKeys );
		$this->assertTrue( empty( $vtKeys[0]['private_x'] ?? null ) );
		$this->assertSame( 2, $vtReloaded->persistence->votes->countDistinctVoters( $result['round_id'] ) );
	}

	public function test_factory_writes_atomic_json(): void {
		$file = $this->root . '/persistence.json';
		$gw   = StandalonePersistenceFactory::create( $file );
		$eid  = $gw->elections->createElection(
			array(
				'title'  => 'E',
				'status' => 'open',
			)
		);
		$gw->signedResults->put( 9, array( 'pdf' => 'x' ) );

		$gw2 = StandalonePersistenceFactory::create( $file );
		$this->assertSame( 'E', $gw2->elections->findElection( $eid )['title'] ?? null );
		$this->assertSame( 'x', $gw2->signedResults->get( 9 )['pdf'] ?? null );
	}

	private function rmTree( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $dir );
	}
}
