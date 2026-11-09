<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Tests\Unit\Identity;

use PHPUnit\Framework\TestCase;
use RelataSoft\SecureElectionSuite\Painel\Domain\Identity\AccountStatus;

final class AccountStatusTest extends TestCase {

	public function test_authenticate_only_active(): void {
		$this->assertTrue( AccountStatus::canAuthenticate( AccountStatus::ACTIVE ) );
		$this->assertFalse( AccountStatus::canAuthenticate( AccountStatus::SUSPENDED ) );
		$this->assertFalse( AccountStatus::canAuthenticate( AccountStatus::LOCKED ) );
		$this->assertFalse( AccountStatus::canAuthenticate( AccountStatus::DELETED ) );
	}

	public function test_transitions(): void {
		$this->assertTrue( AccountStatus::canTransition( AccountStatus::ACTIVE, AccountStatus::SUSPENDED ) );
		$this->assertTrue( AccountStatus::canTransition( AccountStatus::SUSPENDED, AccountStatus::ACTIVE ) );
		$this->assertFalse( AccountStatus::canTransition( AccountStatus::DELETED, AccountStatus::ACTIVE ) );
		$this->assertFalse( AccountStatus::canTransition( AccountStatus::ACTIVE, AccountStatus::DELETED ) );
	}

	public function test_normalize_legacy(): void {
		$this->assertSame( AccountStatus::ACTIVE, AccountStatus::normalize( null ) );
		$this->assertSame( AccountStatus::ACTIVE, AccountStatus::normalize( '' ) );
		$this->assertSame( AccountStatus::LOCKED, AccountStatus::normalize( AccountStatus::LOCKED ) );
	}
}
