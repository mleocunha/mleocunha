<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RelataSoft\SecureElectionSuite\Painel\Domain\Platform\UrlMaskConfig;

/**
 * C2 / R4 — public path 404 policy without sítio boot.
 */
final class UrlMask404PolicyTest extends TestCase {

	public function test_classic_login_and_admin_404_when_stubs_ready(): void {
		$this->assertSame(
			'not_found',
			UrlMaskConfig::publicAccessDecision( '/wp-login.php', true, true )
		);
		$this->assertSame(
			'not_found',
			UrlMaskConfig::publicAccessDecision( '/wp-admin/admin.php', true, true )
		);
		$this->assertSame(
			'not_found',
			UrlMaskConfig::publicAccessDecision( '/wp-admin/', true, true )
		);
	}

	public function test_painel_entry_allowed_retired_screens_404(): void {
		$this->assertSame(
			'allow',
			UrlMaskConfig::publicAccessDecision( '/painel/admin.php?page=rses-dashboard', true, true )
		);
		$this->assertSame(
			'not_found',
			UrlMaskConfig::publicAccessDecision( '/painel/plugins.php', true, true )
		);
		$this->assertSame(
			'not_found',
			UrlMaskConfig::publicAccessDecision( '/painel/themes.php', true, true )
		);
	}

	public function test_asset_loaders_still_allowed_under_wp_admin(): void {
		$this->assertSame(
			'allow',
			UrlMaskConfig::publicAccessDecision( '/wp-admin/load-styles.php', true, true )
		);
		$this->assertSame(
			'allow',
			UrlMaskConfig::publicAccessDecision( '/wp-admin/css/dashicons.css', true, true )
		);
	}

	public function test_before_gateway_classic_admin_not_forced_404_by_policy(): void {
		// Without gateway, PlatformUrlMask skips the wp-admin 404 branch.
		$this->assertSame(
			'allow',
			UrlMaskConfig::publicAccessDecision( '/wp-admin/admin.php', false, false )
		);
		$this->assertSame(
			'allow',
			UrlMaskConfig::publicAccessDecision( '/wp-login.php', false, false )
		);
	}
}
