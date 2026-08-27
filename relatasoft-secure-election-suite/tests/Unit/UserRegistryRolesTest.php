<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RelataSoft\SecureElectionSuite\Painel\Domain\Access\UserRegistryRoles;

final class UserRegistryRolesTest extends TestCase {

	public function test_key_authority_roles_exclude_voters(): void {
		$roles = UserRegistryRoles::forMode( 'key_authority' );
		$this->assertContains( UserRegistryRoles::ROLE_ADMIN, $roles );
		$this->assertContains( UserRegistryRoles::ROLE_GESTOR, $roles );
		$this->assertContains( UserRegistryRoles::ROLE_OFFICIAL, $roles );
		$this->assertFalse( UserRegistryRoles::includesVoters( 'key_authority' ) );
	}

	public function test_voting_roles_include_voters(): void {
		$roles = UserRegistryRoles::forMode( 'voting' );
		$this->assertContains( UserRegistryRoles::ROLE_ADMIN, $roles );
		$this->assertContains( UserRegistryRoles::ROLE_OFFICIAL, $roles );
		$this->assertContains( UserRegistryRoles::ROLE_VOTER, $roles );
		$this->assertTrue( UserRegistryRoles::includesVoters( 'voting' ) );
	}

	public function test_tallying_roles_exclude_voters(): void {
		$roles = UserRegistryRoles::forMode( 'tallying' );
		$this->assertContains( UserRegistryRoles::ROLE_ADMIN, $roles );
		$this->assertContains( UserRegistryRoles::ROLE_OFFICIAL, $roles );
		$this->assertFalse( UserRegistryRoles::includesVoters( 'tallying' ) );
	}
}
