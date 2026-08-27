<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RelataSoft\SecureElectionSuite\Painel\Domain\Platform\UrlMaskConfig;

final class UrlMaskConfigTest extends TestCase {

	public function test_masks_admin_and_login_urls(): void {
		$this->assertSame(
			'https://exemplo.test/painel/admin.php?page=x',
			UrlMaskConfig::maskAdminUrl( 'https://exemplo.test/wp-admin/admin.php?page=x', 'painel' )
		);
		$this->assertSame(
			'https://exemplo.test/painel/plugins.php',
			UrlMaskConfig::maskAdminUrl( 'https://exemplo.test/wp-admin/plugins.php', 'painel' )
		);
		$this->assertSame(
			'https://exemplo.test/id.php?action=rp',
			UrlMaskConfig::maskLoginUrl( 'https://exemplo.test/wp-login.php?action=rp', 'id.php' )
		);
	}

	public function test_normalizes_paths(): void {
		$this->assertSame( 'painel', UrlMaskConfig::normalizeAdminPath( '/Painel/' ) );
		$this->assertSame( 'painel', UrlMaskConfig::normalizeAdminPath( 'wp-admin' ) );
		$this->assertSame( 'id.php', UrlMaskConfig::normalizeLoginPath( 'wp-login.php' ) );
		$this->assertSame( 'id.php', UrlMaskConfig::normalizeLoginPath( '/ID.PHP' ) );
	}

	public function test_detects_classic_paths(): void {
		$this->assertTrue( UrlMaskConfig::isWpAdminPath( '/wp-admin/admin.php' ) );
		$this->assertTrue( UrlMaskConfig::isWpLoginPath( '/wp-login.php' ) );
		$this->assertFalse( UrlMaskConfig::isWpAdminPath( '/painel/admin.php' ) );
		$this->assertFalse( UrlMaskConfig::isWpLoginPath( '/id.php' ) );
	}

	public function test_wp_admin_exemptions_for_assets_and_async(): void {
		$this->assertTrue( UrlMaskConfig::isExemptWpAdminEndpoint( '/wp-admin/admin-ajax.php' ) );
		$this->assertTrue( UrlMaskConfig::isStaticAdminAssetPath( '/wp-admin/css/dashicons.css' ) );
		$this->assertFalse( UrlMaskConfig::isExemptWpAdminEndpoint( '/wp-admin/admin.php' ) );
		$this->assertFalse( UrlMaskConfig::isExemptWpAdminEndpoint( '/wp-admin/' ) );
		$this->assertFalse( UrlMaskConfig::isStaticAdminAssetPath( '/wp-admin/plugins.php' ) );
	}

	public function test_admin_cookie_path_mirrors_wp_admin_shape(): void {
		$this->assertSame( '/painel', UrlMaskConfig::adminCookiePath( 'painel', '/' ) );
		$this->assertSame( '/blog/painel', UrlMaskConfig::adminCookiePath( 'painel', '/blog/' ) );
		$this->assertSame( '/painel', UrlMaskConfig::adminCookiePath( 'wp-admin', '' ) );
	}
}
