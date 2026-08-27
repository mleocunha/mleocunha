<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RelataSoft\SecureElectionSuite\Painel\Domain\Access\AccessPolicy;
use RelataSoft\SecureElectionSuite\Painel\Domain\Access\Persona;

final class AccessPolicyTest extends TestCase {

	public function test_eleitor_cannot_enter_shell_permissions(): void {
		$policy = new AccessPolicy();
		$this->assertFalse( $policy->can( Persona::Eleitor, AccessPolicy::PERM_SHELL_ADMIN ) );
		$this->assertFalse( Persona::Eleitor->mayEnterAdminShell() );
	}

	public function test_autoridade_can_view_dashboard_and_parcelas_only(): void {
		$policy = new AccessPolicy();
		$this->assertTrue( $policy->can( Persona::AutoridadeEleitoral, AccessPolicy::PERM_DASHBOARD_VIEW ) );
		$this->assertTrue( $policy->can( Persona::AutoridadeEleitoral, AccessPolicy::PERM_PARCELAS_OWN ) );
		$this->assertFalse( $policy->can( Persona::AutoridadeEleitoral, AccessPolicy::PERM_SETTINGS_MANAGE ) );
	}

	public function test_gestor_has_full_permissions(): void {
		$policy = new AccessPolicy();
		foreach ( $policy->permissionsFor( Persona::Gestor ) as $perm ) {
			$this->assertTrue( $policy->can( Persona::Gestor, $perm ) );
		}
		$this->assertTrue( $policy->can( Persona::Gestor, AccessPolicy::PERM_SYSTEM_MANAGE ) );
		$this->assertTrue( $policy->can( Persona::AdministradorEleitoral, AccessPolicy::PERM_SYSTEM_MANAGE ) );
		$this->assertFalse( $policy->can( Persona::AutoridadeEleitoral, AccessPolicy::PERM_SYSTEM_MANAGE ) );
	}
}
