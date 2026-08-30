<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Tests\Unit\System;

use PHPUnit\Framework\TestCase;
use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\System\BecapeService;

/**
 * C3 — becape manifest / basename / core-plugin guards (no WP boot).
 */
final class BecapeOpsGuardTest extends TestCase {

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

	public function test_safe_becape_basename(): void {
		$this->assertTrue( BecapeService::isSafeBecapeBasename( 'becape-voto-eletronico-20260101-120000.zip' ) );
		$this->assertFalse( BecapeService::isSafeBecapeBasename( '../etc/passwd' ) );
		$this->assertFalse( BecapeService::isSafeBecapeBasename( 'becape-voto-eletronico-20260101-120000.zip.bak' ) );
		$this->assertFalse( BecapeService::isSafeBecapeBasename( 'evil.zip' ) );
	}

	public function test_core_plugin_cannot_be_deleted_via_modules(): void {
		$this->assertTrue(
			BecapeService::isCorePluginBasename( 'relatasoft-secure-election-suite/relatasoft-secure-election-suite.php' )
		);
		$this->assertFalse( BecapeService::isCorePluginBasename( 'other-plugin/other.php' ) );
	}
}
