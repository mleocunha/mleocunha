<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Tests\Unit\Identity;

use PHPUnit\Framework\TestCase;
use RelataSoft\SecureElectionSuite\Painel\Domain\Identity\PasswordPolicy;

final class PasswordPolicyTest extends TestCase {

	public function test_rejects_short_and_common(): void {
		$this->assertFalse( PasswordPolicy::validate( 'curta' )['ok'] );
		$this->assertFalse( PasswordPolicy::validate( 'password123' )['ok'] );
		$this->assertFalse( PasswordPolicy::validate( 'admin', 'admin' )['ok'] );
		$this->assertFalse( PasswordPolicy::validate( 'xxadminyy123', 'admin' )['ok'] );
	}

	public function test_hash_and_verify_roundtrip(): void {
		$hash = PasswordPolicy::hash( 'SenhaForte99!' );
		$this->assertStringStartsWith( '$', $hash );
		$this->assertTrue( PasswordPolicy::verify( $hash, 'SenhaForte99!' ) );
		$this->assertFalse( PasswordPolicy::verify( $hash, 'outra' ) );
		$this->assertFalse( PasswordPolicy::verify( 'plain:SenhaForte99!', 'SenhaForte99!' ) );
	}

	public function test_rejects_overlong(): void {
		$this->assertFalse( PasswordPolicy::validate( str_repeat( 'a', PasswordPolicy::MAX_LENGTH + 1 ) )['ok'] );
	}
}
