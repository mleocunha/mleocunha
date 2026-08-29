<?php
declare(strict_types=1);

/**
 * A3 identity / session / secret ports — InMemory (no sítio boot).
 *
 * @package RelataSoft\SecureElectionSuite\Tests\Unit\Identity
 */

namespace RelataSoft\SecureElectionSuite\Tests\Unit\Identity;

use PHPUnit\Framework\TestCase;
use RelataSoft\SecureElectionSuite\Painel\Application\Identity\IdentityGateway;
use RelataSoft\SecureElectionSuite\Painel\Domain\Access\Persona;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Identity\Security\InMemorySecretKeyProvider;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Identity\Session\InMemorySessionPort;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Identity\User\InMemoryCapabilityResolver;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Identity\User\InMemoryUserStore;

final class IdentityPortsTest extends TestCase {

	private IdentityGateway $gw;
	private InMemoryUserStore $store;
	private InMemoryCapabilityResolver $caps;

	protected function setUp(): void {
		IdentityGateway::reset();
		$this->store = new InMemoryUserStore();
		$this->caps  = new InMemoryCapabilityResolver( $this->store, $this->store );
		$this->gw    = IdentityGateway::boot(
			new IdentityGateway(
				$this->store,
				$this->store,
				$this->caps,
				new InMemorySessionPort( $this->store ),
				new InMemorySecretKeyProvider( 'unit-test-material' ),
			)
		);
	}

	protected function tearDown(): void {
		IdentityGateway::reset();
	}

	public function test_user_directory_create_find_and_meta(): void {
		$created = $this->gw->users->create(
			array(
				'login'       => 'eleitor1',
				'email'       => 'e1@example.com',
				'password'    => 'secret',
				'displayName' => 'Eleitor Um',
				'role'        => 'subscriber',
			)
		);
		$this->assertTrue( $created['ok'] );
		$id = $created['id'];
		$this->assertSame( 'eleitor1', $this->gw->users->findByLogin( 'eleitor1' )['login'] ?? null );
		$this->assertSame( 'e1@example.com', $this->gw->users->findByEmail( 'e1@example.com' )['email'] ?? null );

		$this->gw->users->setMeta( $id, 'rses_id_civil', '123' );
		$this->assertSame( $id, $this->gw->users->findIdByMeta( 'rses_id_civil', '123' ) );
		$this->assertCount( 1, $this->gw->users->listByRole( 'subscriber' ) );
	}

	public function test_capability_roles_for_cast_and_officials(): void {
		$voter = $this->gw->users->create(
			array(
				'login'    => 'v',
				'email'    => 'v@ex.com',
				'password' => 'x',
				'role'     => 'subscriber',
			)
		);
		$official = $this->gw->users->create(
			array(
				'login'    => 'o',
				'email'    => 'o@ex.com',
				'password' => 'x',
				'role'     => 'editor',
			)
		);
		$admin = $this->gw->users->create(
			array(
				'login'    => 'a',
				'email'    => 'a@ex.com',
				'password' => 'x',
				'role'     => 'administrator',
			)
		);
		$gestor = $this->gw->users->create(
			array(
				'login'    => 'g',
				'email'    => 'g@ex.com',
				'password' => 'x',
				'role'     => 've_gestor',
			)
		);

		$this->assertTrue( $this->gw->capabilities->hasVoterRole( $voter['id'] ) );
		$this->assertTrue( $this->gw->capabilities->hasOfficialRole( $official['id'] ) );
		$this->assertTrue( $this->gw->capabilities->hasOfficialRole( $admin['id'] ) );
		$this->assertFalse( $this->gw->capabilities->hasOfficialRole( $gestor['id'] ) );
		$this->assertTrue( $this->gw->capabilities->hasAdminRole( $gestor['id'] ) );
		$this->assertSame( Persona::AutoridadeEleitoral, $this->gw->capabilities->resolvePersona( $official['id'] ) );
	}

	public function test_session_assert_current_user(): void {
		$created = $this->gw->users->create(
			array(
				'login'    => 'cast',
				'email'    => 'c@ex.com',
				'password' => 'x',
				'role'     => 'subscriber',
			)
		);
		$this->store->setCurrentUserId( $created['id'] );
		$this->assertTrue( $this->gw->session->isAuthenticated() );
		$this->gw->session->assertCurrentUser( $created['id'] );

		$this->expectException( \RuntimeException::class );
		$this->gw->session->assertCurrentUser( $created['id'] + 99 );
	}

	public function test_secret_key_provider_stable_32_bytes(): void {
		$key = $this->gw->secrets->shareStorageKey();
		$this->assertSame( 32, strlen( $key ) );
		$this->assertSame( $key, $this->gw->secrets->shareStorageKey() );
	}

	public function test_gateway_requires_boot(): void {
		IdentityGateway::reset();
		$this->expectException( \RuntimeException::class );
		IdentityGateway::get();
	}
}
