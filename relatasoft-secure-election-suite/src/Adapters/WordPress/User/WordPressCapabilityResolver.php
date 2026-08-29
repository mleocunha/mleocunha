<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\User;

use RelataSoft\SecureElectionSuite\Painel\Contracts\User\CapabilityResolver;
use RelataSoft\SecureElectionSuite\Painel\Contracts\User\UserDirectory;
use RelataSoft\SecureElectionSuite\Painel\Domain\Access\Persona;

/**
 * Maps host roles onto Painel personas and election capability rules (A3).
 */
final class WordPressCapabilityResolver implements CapabilityResolver {

	public const GESTOR_ROLE   = 've_gestor';
	public const AUDITOR_ROLE  = 've_auditor';
	public const VOTER_ROLE    = 'subscriber';
	public const ADMIN_ROLE    = 'administrator';
	public const OFFICIAL_ROLE = 'editor';

	public function __construct(
		private readonly ?UserDirectory $directory = null,
	) {}

	public function resolvePersona(?int $userId = null): Persona {
		$roles = $this->rolesOf( $userId );
		if ( empty( $roles ) && ( null === $userId || $userId < 1 ) ) {
			return Persona::Eleitor;
		}
		if ( in_array( self::GESTOR_ROLE, $roles, true ) ) {
			return Persona::Gestor;
		}
		if ( in_array( self::ADMIN_ROLE, $roles, true ) ) {
			return Persona::AdministradorEleitoral;
		}
		if ( in_array( self::AUDITOR_ROLE, $roles, true ) ) {
			return Persona::Auditor;
		}
		if ( in_array( self::OFFICIAL_ROLE, $roles, true ) ) {
			return Persona::AutoridadeEleitoral;
		}
		if ( in_array( self::VOTER_ROLE, $roles, true ) ) {
			return Persona::Eleitor;
		}
		$id = $this->resolveId( $userId );
		if ( $id > 0 && user_can( $id, 'manage_options' ) ) {
			return Persona::AdministradorEleitoral;
		}
		return Persona::Eleitor;
	}

	public function hasAdminRole(?int $userId = null): bool {
		$roles = $this->rolesOf( $userId );
		if ( in_array( self::ADMIN_ROLE, $roles, true ) || in_array( self::GESTOR_ROLE, $roles, true ) ) {
			return true;
		}
		$id = $this->resolveId( $userId );
		return $id > 0 && user_can( $id, 'manage_options' );
	}

	public function hasOfficialRole(?int $userId = null): bool {
		$roles = $this->rolesOf( $userId );
		return in_array( self::OFFICIAL_ROLE, $roles, true )
			|| in_array( self::ADMIN_ROLE, $roles, true );
	}

	public function hasVoterRole(?int $userId = null): bool {
		return in_array( self::VOTER_ROLE, $this->rolesOf( $userId ), true );
	}

	public function canManageElection(?int $userId = null): bool {
		return $this->hasAdminRole( $userId );
	}

	public function isElectionOfficial(?int $userId = null): bool {
		// Painel nav: gestor + admin + autoridade.
		$persona = $this->resolvePersona( $userId );
		return in_array(
			$persona,
			array( Persona::Gestor, Persona::AdministradorEleitoral, Persona::AutoridadeEleitoral ),
			true
		);
	}

	public function canVote(?int $userId = null): bool {
		return $this->hasVoterRole( $userId );
	}

	public function canViewAudit(?int $userId = null): bool {
		if ( $this->hasAdminRole( $userId ) ) {
			return true;
		}
		return in_array( self::AUDITOR_ROLE, $this->rolesOf( $userId ), true );
	}

	public function canExportOwnShare(?int $userId = null): bool {
		return $this->hasOfficialRole( $userId );
	}

	public function canExportAllShares(?int $userId = null): bool {
		if ( ! $this->canManageElection( $userId ) ) {
			return false;
		}
		$settings = get_option( 'rses_settings', array() );
		return ! empty( $settings['allow_full_private_export'] );
	}

	public function isCandidate(?int $userId = null): bool {
		$id = $this->resolveId( $userId );
		if ( $id < 1 ) {
			return false;
		}
		// Preserve legacy: not edit_posts, but can read.
		return user_can( $id, 'edit_posts' ) === false && user_can( $id, 'read' );
	}

	/** @return list<string> */
	private function rolesOf( ?int $userId ): array {
		$id = $this->resolveId( $userId );
		if ( $id < 1 ) {
			return array();
		}
		if ( $this->directory ) {
			$row = $this->directory->findById( $id );
			return $row ? $row['roles'] : array();
		}
		$user = get_userdata( $id );
		if ( ! $user || ! $user->exists() ) {
			return array();
		}
		return array_map( 'strval', (array) $user->roles );
	}

	private function resolveId( ?int $userId ): int {
		if ( null === $userId ) {
			if ( ! is_user_logged_in() ) {
				return 0;
			}
			return (int) get_current_user_id();
		}
		return absint( $userId );
	}
}
