<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RelataSoft\SecureElectionSuite\Painel\Domain\Access\AccessPolicy;
use RelataSoft\SecureElectionSuite\Painel\Domain\Access\Persona;
use RelataSoft\SecureElectionSuite\Painel\Domain\Navigation\MenuItem;
use RelataSoft\SecureElectionSuite\Painel\Domain\Navigation\NavigationRegistry;

final class NavigationRegistryTest extends TestCase {

	public function test_filters_by_permission_and_mode(): void {
		$reg = new NavigationRegistry();
		$reg->register( new MenuItem( 'home', 'Home', 'rses-dashboard', visibleForPermissions: array( AccessPolicy::PERM_DASHBOARD_VIEW ), mode: 'any' ) );
		$reg->register( new MenuItem( 'settings', 'Settings', 'rses-settings', visibleForPermissions: array( AccessPolicy::PERM_SETTINGS_MANAGE ), mode: 'any' ) );
		$reg->register( new MenuItem( 'keys', 'Keys', 'rses-key-authority', visibleForPermissions: array( AccessPolicy::PERM_KEYS_MANAGE ), mode: 'key_authority' ) );

		$policy = new AccessPolicy();
		$visible = $reg->visibleFor( Persona::AutoridadeEleitoral, $policy, 'key_authority' );
		$ids = array_map( static fn( $i ) => $i->id, $visible );
		$this->assertContains( 'home', $ids );
		$this->assertNotContains( 'settings', $ids );
		$this->assertNotContains( 'keys', $ids );
	}
}
