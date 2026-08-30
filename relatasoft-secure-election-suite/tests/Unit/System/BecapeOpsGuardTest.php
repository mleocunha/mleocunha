<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Tests\Unit\System;

use PHPUnit\Framework\TestCase;
use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\System\BecapeService;

/**
 * C3 — guards de becape / módulos (funções puras, sem boot WordPress).
 *
 * Cobre manifesto de restauração, basename seguro dos ZIPs e a proibição
 * de apagar o plugin núcleo via ecrã Módulos do Sistema.
 */
final class BecapeOpsGuardTest extends TestCase {

	/**
	 * Manifesto válido exige format ve-becape-v1 + created_utc string;
	 * format errado ou mapa vazio são rejeitados.
	 */
	public function test_valid_manifest_format(): void {
		$this->assertTrue(
			BecapeService::isValidManifest(
				array(
					'format'      => BecapeService::MANIFEST_FORMAT,
					'created_utc' => '2026-01-01T00:00:00+00:00',
				)
			)
		);
		$this->assertFalse( BecapeService::isValidManifest( array( 'format' => 'other' ) ) );
		$this->assertFalse( BecapeService::isValidManifest( array() ) );
	}

	/**
	 * Só o padrão canónico de ZIP é aceite; traversal, extensão extra e
	 * nomes arbitrários falham.
	 */
	public function test_safe_becape_basename(): void {
		$this->assertTrue( BecapeService::isSafeBecapeBasename( 'becape-voto-eletronico-20260101-120000.zip' ) );
		$this->assertFalse( BecapeService::isSafeBecapeBasename( '../etc/passwd' ) );
		$this->assertFalse( BecapeService::isSafeBecapeBasename( 'becape-voto-eletronico-20260101-120000.zip.bak' ) );
		$this->assertFalse( BecapeService::isSafeBecapeBasename( 'evil.zip' ) );
	}

	/**
	 * O basename do núcleo da suíte é detectado; outros plugins não.
	 * SystemModulesPage::handle_delete usa isto para bloquear remoção.
	 */
	public function test_core_plugin_cannot_be_deleted_via_modules(): void {
		$this->assertTrue(
			BecapeService::isCorePluginBasename( 'relatasoft-secure-election-suite/relatasoft-secure-election-suite.php' )
		);
		$this->assertFalse( BecapeService::isCorePluginBasename( 'other-plugin/other.php' ) );
	}
}
