<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RelataSoft\SecureElectionSuite\Bootstrap\ModeLock;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Mode\SiteModes;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/rses-tests/' );
}

require_once dirname( __DIR__, 2 ) . '/includes/Bootstrap/ModeLock.php';

/**
 * C1 — ModeLock vocabulary aligns with SiteModes (E3 isolation).
 */
final class ModeIsolationTest extends TestCase {

	public function test_mode_lock_slugs_match_site_modes(): void {
		$this->assertSame( SiteModes::all(), array_keys( ModeLock::rses_get_valid_modes() ) );
		foreach ( SiteModes::all() as $slug ) {
			$this->assertSame( SiteModes::label( $slug ), ModeLock::rses_get_mode_label( $slug ) );
		}
	}

	public function test_constants_alias_site_modes(): void {
		$this->assertSame( SiteModes::KEY_AUTHORITY, ModeLock::RSES_MODE_KEY_AUTHORITY );
		$this->assertSame( SiteModes::VOTING, ModeLock::RSES_MODE_VOTING );
		$this->assertSame( SiteModes::TALLYING, ModeLock::RSES_MODE_TALLYING );
	}

	public function test_exactly_three_isolated_roles(): void {
		$this->assertCount( 3, SiteModes::all() );
		$this->assertFalse( SiteModes::isValid( 'all_in_one' ) );
		$this->assertFalse( SiteModes::isValid( '' ) );
	}
}
