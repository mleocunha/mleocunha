<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Tests\Unit;

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/rses-tests/' );
}

require_once dirname( __DIR__, 2 ) . '/includes/Admin/SystemAppearancePage.php';

use RelataSoft\SecureElectionSuite\Admin\SystemAppearancePage;

final class StockThemeVisibilityTest extends TestCase {

	public function test_hides_default_twenty_themes(): void {
		$wp = new class() {
			public function get( string $key ): string {
				return match ( $key ) {
					'ThemeURI' => 'https://wordpress.org/themes/twentytwentyfour/',
					'Author'   => 'the WordPress team',
					default    => '',
				};
			}
		};
		$this->assertTrue( SystemAppearancePage::is_stock_wordpress_theme( 'twentytwentyfour', $wp ) );
		$this->assertTrue( SystemAppearancePage::is_stock_wordpress_theme( 'twentytwentyfive', $wp ) );
		$this->assertTrue( SystemAppearancePage::is_stock_wordpress_theme( 'twentyseventeen', $wp ) );
	}

	public function test_keeps_electoral_theme_visible(): void {
		$custom = new class() {
			public function get( string $key ): string {
				return match ( $key ) {
					'ThemeURI' => 'https://votoeletronico.com.br/',
					'Author'   => 'RelataSoft',
					default    => '',
				};
			}
		};
		$this->assertFalse( SystemAppearancePage::is_stock_wordpress_theme( 'voto-eletronico-tema-base', $custom ) );
	}
}
