<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Tests\Unit\Identity;

use PHPUnit\Framework\TestCase;
use RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\Persistence\JsonDocumentStore;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Identity\Security\CsrfTokenService;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Identity\Security\LoginRateLimiter;

final class CsrfAndRateLimitTest extends TestCase {

	public function test_csrf_roundtrip_and_tamper(): void {
		$svc   = new CsrfTokenService( 'secret-test' );
		$token = $svc->issue( 'bind:abc' );
		$this->assertTrue( $svc->validate( 'bind:abc', $token ) );
		$this->assertFalse( $svc->validate( 'bind:other', $token ) );
		$this->assertFalse( $svc->validate( 'bind:abc', $token . 'x' ) );
	}

	public function test_rate_limiter_blocks_after_failures(): void {
		$path = sys_get_temp_dir() . '/ve-rl-' . bin2hex( random_bytes( 4 ) ) . '.json';
		$store = new JsonDocumentStore( $path );
		$lim   = new LoginRateLimiter( $store );
		$key   = LoginRateLimiter::keyFor( 'admin', '127.0.0.1' );
		$this->assertFalse( $lim->isBlocked( $key ) );
		for ( $i = 0; $i < 8; $i++ ) {
			$lim->recordFailure( $key );
		}
		$this->assertTrue( $lim->isBlocked( $key ) );
		$lim->clear( $key );
		$this->assertFalse( $lim->isBlocked( $key ) );
		@unlink( $path );
	}
}
