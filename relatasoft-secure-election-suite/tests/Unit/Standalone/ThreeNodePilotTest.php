<?php
declare(strict_types=1);

/**
 * A6 / M6 — Adapter #2 three isolated nodes + manual courier (no host / no sync).
 *
 * @package RelataSoft\SecureElectionSuite\Tests\Unit\Standalone
 */

namespace RelataSoft\SecureElectionSuite\Tests\Unit\Standalone;

use PHPUnit\Framework\TestCase;
use RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\Bootstrap;
use RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\EnvModeLock;
use RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\NodeRuntime;
use RelataSoft\SecureElectionSuite\Painel\Application\Standalone\ThreeNodePilot;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Mode\SiteModes;
use RelataSoft\SecureElectionSuite\Painel\Domain\Material\PublicKeyPackage;
use RelataSoft\SecureElectionSuite\Painel\Domain\Material\VoteMaterialPackage;

final class ThreeNodePilotTest extends TestCase {

	private string $root;

	protected function setUp(): void {
		if ( ! extension_loaded( 'gmp' ) ) {
			$this->markTestSkipped( 'GMP extension required.' );
		}
		$this->root = sys_get_temp_dir() . '/ve-a6-' . uniqid( '', true );
	}

	protected function tearDown(): void {
		$this->rmTree( $this->root );
	}

	public function test_mode_lock_rejects_switch(): void {
		$lock = new EnvModeLock();
		$lock->lock( SiteModes::VOTING );
		$this->assertTrue( $lock->isLocked() );
		$this->expectException( \RuntimeException::class );
		$lock->lock( SiteModes::TALLYING );
	}

	public function test_nodes_do_not_share_persistence(): void {
		$ka = NodeRuntime::create( SiteModes::KEY_AUTHORITY, $this->root . '/ka' );
		$vt = NodeRuntime::create( SiteModes::VOTING, $this->root . '/vt' );

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

		$this->assertCount( 1, $ka->persistence->keys->listActive() );
		$this->assertCount( 0, $vt->persistence->keys->listActive() );
		$this->assertNotSame( $ka->persistence, $vt->persistence );
	}

	public function test_full_pilot_without_legacy_host(): void {
		$pilot  = Bootstrap::bootPilotWorkspace( $this->root, 'cliente-demo', 512 );
		$result = $pilot->run( 2 );

		$this->assertSame( 2, $result['tally'] );
		$this->assertGreaterThan( 0, $result['certification_id'] );
		$this->assertContains( ThreeNodePilot::PUBLIC_KEY_FILE, $result['courier_files'] );
		$this->assertContains( ThreeNodePilot::VOTE_MATERIAL_FILE, $result['courier_files'] );
		$this->assertContains( 'parcela-1.json', $result['courier_files'] );
		$this->assertContains( 'parcela-2.json', $result['courier_files'] );
		$this->assertContains( 'parcela-3.json', $result['courier_files'] );

		$iso = $pilot->isolationSnapshot();
		// KA has key with private; voting imported public only; tallying has no key repo rows by design.
		$this->assertSame( 1, $iso['ka_keys'] );
		$this->assertSame( 1, $iso['voting_keys'] );
		$this->assertSame( 0, $iso['tallying_keys'] );

		$kaKey = $pilot->keyAuthority->persistence->keys->find( $result['key_id'] );
		$this->assertNotEmpty( $kaKey['private_x'] ?? null );

		$vtKeys = $pilot->voting->persistence->keys->listActive();
		$this->assertArrayNotHasKey( 'private_x', $vtKeys[0] );
		$this->assertTrue( empty( $vtKeys[0]['private_x'] ?? null ) );

		$pub = PublicKeyPackage::fromJson(
			(string) file_get_contents( $pilot->courier->path( ThreeNodePilot::PUBLIC_KEY_FILE ) )
		);
		$this->assertNotNull( $pub );
		$votes = VoteMaterialPackage::fromJson(
			(string) file_get_contents( $pilot->courier->path( ThreeNodePilot::VOTE_MATERIAL_FILE ) )
		);
		$this->assertNotNull( $votes );
		$this->assertCount( 2, $votes['ballots'] );
	}

	public function test_wrong_mode_blocks_keygen(): void {
		$pilot = Bootstrap::bootPilotWorkspace( $this->root );
		$this->expectException( \RuntimeException::class );
		$pilot->voting->requireMode( SiteModes::KEY_AUTHORITY );
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
