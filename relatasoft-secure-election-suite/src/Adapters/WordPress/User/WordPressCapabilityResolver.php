<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\User;

use RelataSoft\SecureElectionSuite\Painel\Contracts\User\CapabilityResolver;
use RelataSoft\SecureElectionSuite\Painel\Domain\Access\Persona;
use RelataSoft\SecureElectionSuite\Security\Capability;

/**
 * Maps WP roles onto Painel personas while preserving RSES Capability rules.
 */
final class WordPressCapabilityResolver implements CapabilityResolver {

	public const GESTOR_ROLE = 've_gestor';

	public function resolvePersona(?int $userId = null): Persona {
		$userId = $userId ?? get_current_user_id();
		$userId = absint( $userId );
		if ( $userId < 1 ) {
			return Persona::Eleitor;
		}
		$user = get_userdata( $userId );
		if ( ! $user || ! $user->exists() ) {
			return Persona::Eleitor;
		}
		$roles = array_map( 'strval', (array) $user->roles );
		if ( in_array( self::GESTOR_ROLE, $roles, true ) ) {
			return Persona::Gestor;
		}
		if ( in_array( Capability::RSES_ADMIN_ROLE, $roles, true ) ) {
			return Persona::AdministradorEleitoral;
		}
		if ( in_array( Capability::RSES_OFFICIAL_ROLE, $roles, true ) ) {
			return Persona::AutoridadeEleitoral;
		}
		if ( in_array( Capability::RSES_VOTER_ROLE, $roles, true ) ) {
			return Persona::Eleitor;
		}
		return Persona::Eleitor;
	}

	public function canManageElection(?int $userId = null): bool {
		// Preserve RSES rule: Administrator role. Gestor is also allowed.
		$persona = $this->resolvePersona( $userId );
		return Persona::Gestor === $persona || Persona::AdministradorEleitoral === $persona;
	}

	public function isElectionOfficial(?int $userId = null): bool {
		$persona = $this->resolvePersona( $userId );
		return in_array(
			$persona,
			array( Persona::Gestor, Persona::AdministradorEleitoral, Persona::AutoridadeEleitoral ),
			true
		);
	}
}
