<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RelataSoft\SecureElectionSuite\Painel\Domain\Authorities\AuthoritiesDirectorySync;
use RelataSoft\SecureElectionSuite\Painel\Domain\Authorities\AuthoritiesPackage;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Identity\User\InMemoryUserStore;

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

	public function test_import_persists_public_sss_index(): void {
		$dir = new InMemoryUserStore();
		$pkg = AuthoritiesPackage::build(
			array(
				'source_mode' => 'key_authority',
				'authorities' => array(
					array(
						'user_login'     => 'autel01',
						'user_email'     => 'autel01@example.gov.br',
						'display_name'   => 'Autoridade 01',
						'role'           => 'editor',
						'share_index'    => 1,
						'source_key_id'  => 5,
						'threshold_t'    => 2,
						'total_n'        => 4,
						'public_sss'     => array(
							'share_index'   => 1,
							'source_key_id' => 5,
							'threshold_t'   => 2,
							'total_n'       => 4,
							'field_prime'   => '17',
							'key_label'     => 'teste',
							'key_size'      => 512,
							'public_key'    => array(
								'p' => '23',
								'q' => '11',
								'g' => '5',
								'y' => '7',
							),
						),
					),
				),
			)
		);

		$res = AuthoritiesDirectorySync::importPackage( $dir, $pkg );
		$this->assertSame( 1, $res['created'] );
		$this->assertSame( array(), $res['errors'] );

		$user = $dir->findByLogin( 'autel01' );
		$this->assertNotNull( $user );
		$sss = AuthoritiesDirectorySync::readPublicSss( $dir, (int) $user['id'] );
		$this->assertNotNull( $sss );
		$this->assertSame( 1, (int) $sss['share_index'] );
		$this->assertSame( 5, (int) $sss['source_key_id'] );
		$this->assertArrayNotHasKey( 'share_value', $sss );
	}
}
