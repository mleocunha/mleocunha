<?php
declare(strict_types=1);

/**
 * B5 — cliente_id / cliente_nome on material packages.
 *
 * @package RelataSoft\SecureElectionSuite\Tests\Unit\Material
 */

namespace RelataSoft\SecureElectionSuite\Tests\Unit\Material;

use PHPUnit\Framework\TestCase;
use RelataSoft\SecureElectionSuite\Painel\Domain\Material\PublicKeyPackage;
use RelataSoft\SecureElectionSuite\Painel\Domain\Material\VoteMaterialPackage;

final class ClienteStampPackageTest extends TestCase {

	public function test_public_key_package_carries_cliente_fields(): void {
		$pkg = PublicKeyPackage::build(
			array(
				'p'            => '11',
				'q'            => '5',
				'g'            => '2',
				'y'            => '3',
				'cliente_id'   => 'cli-42',
				'cliente_nome' => 'Cliente Demo',
			)
		);
		$this->assertSame( 'cli-42', $pkg['cliente_id'] );
		$this->assertSame( 'Cliente Demo', $pkg['cliente_nome'] );
		$this->assertNotEmpty( $pkg['checksum'] );
		$this->assertTrue( PublicKeyPackage::validate( $pkg )['ok'] );
	}

	public function test_vote_material_package_carries_cliente_fields(): void {
		$pkg = VoteMaterialPackage::build(
			array(
				'ballots'      => array( array( 'alpha' => '1', 'beta' => '2' ) ),
				'cliente_id'   => 'cli-7',
				'cliente_nome' => 'Acme',
			)
		);
		$this->assertSame( 'cli-7', $pkg['cliente_id'] );
		$this->assertSame( 'Acme', $pkg['cliente_nome'] );
		$this->assertTrue( VoteMaterialPackage::validate( $pkg )['ok'] );
	}
}
