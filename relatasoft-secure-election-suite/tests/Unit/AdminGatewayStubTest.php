<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Platform\PlatformUrlMask;

final class AdminGatewayStubTest extends TestCase {

	public function test_wp_vars_php_misreads_painel_php_self_as_index(): void {
		$self = '/painel/admin.php';
		preg_match( '#/wp-admin/?(.*?)$#i', $self, $m );
		$pagenow = ! empty( $m[1] ) ? $m[1] : '';
		$pagenow = trim( $pagenow, '/' );
		if ( '' === $pagenow || 'index' === $pagenow || 'index.php' === $pagenow ) {
			$pagenow = 'index.php';
		}
		$this->assertSame( 'index.php', $pagenow );
	}

	public function test_stub_forces_wp_admin_php_self(): void {
		$m = new ReflectionMethod( PlatformUrlMask::class, 'stubPhp' );
		$m->setAccessible( true );
		$stub = $m->invoke( null, 'admin.php' );
		$this->assertStringContainsString( "Stub-Version: 2", $stub );
		$this->assertStringContainsString( "\$_SERVER['PHP_SELF']   = '/wp-admin/admin.php';", $stub );
		$this->assertStringContainsString( "\$_SERVER['SCRIPT_NAME'] = '/wp-admin/admin.php';", $stub );
	}
}
