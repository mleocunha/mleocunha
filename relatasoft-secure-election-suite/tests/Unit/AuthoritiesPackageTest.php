<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RelataSoft\SecureElectionSuite\Painel\Domain\Authorities\AuthoritiesPackage;

final class AuthoritiesPackageTest extends TestCase {

	public function test_roundtrip_checksum_and_json(): void {
		$pkg = AuthoritiesPackage::build(
			array(
				'exported_at'    => '2026-08-27T00:00:00+00:00',
				'source_site'    => 'https://chave.example/',
				'source_mode'    => 'key_authority',
				'plugin_version' => '1.0.31',
				'authorities'    => array(
					array(
						'user_login'   => 'maria',
						'user_email'   => 'maria@example.gov.br',
						'display_name' => 'Maria Silva',
						'role'         => 'editor',
						'user_pass'    => '$P$Bexamplehash',
					),
				),
			)
		);

		$this->assertSame( AuthoritiesPackage::FORMAT, $pkg['format'] );
		$this->assertNotSame( '', $pkg['checksum'] );
		$this->assertTrue( AuthoritiesPackage::validate( $pkg )['ok'] );

		$json = AuthoritiesPackage::toJson( $pkg );
		$back = AuthoritiesPackage::fromJson( $json );
		$this->assertIsArray( $back );
		$this->assertSame( $pkg['checksum'], $back['checksum'] );
		$this->assertSame( 'maria', $back['authorities'][0]['user_login'] );
	}

	public function test_rejects_tampered_checksum(): void {
		$pkg = AuthoritiesPackage::build(
			array(
				'authorities' => array(
					array(
						'user_login' => 'a',
						'user_email' => 'a@example.com',
					),
				),
			)
		);
		$pkg['authorities'][0]['user_login'] = 'hacked';
		$this->assertFalse( AuthoritiesPackage::validate( $pkg )['ok'] );
	}
}
