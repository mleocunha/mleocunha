<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Infrastructure\Identity\User;

use RelataSoft\SecureElectionSuite\Painel\Contracts\User\CapabilityResolver;
use RelataSoft\SecureElectionSuite\Painel\Contracts\User\UserDirectory;
use RelataSoft\SecureElectionSuite\Painel\Contracts\User\UserProvider;
use RelataSoft\SecureElectionSuite\Painel\Domain\Access\Persona;

final class InMemoryCapabilityResolver implements CapabilityResolver {

	public const VOTER_ROLE    = 'subscriber';
	public const ADMIN_ROLE    = 'administrator';
	public const OFFICIAL_ROLE = 'editor';
	public const GESTOR_ROLE   = 've_gestor';
	public const AUDITOR_ROLE  = 've_auditor';

	private bool $allowFullPrivateExport = false;

	public function __construct(
		private readonly UserProvider $current,
		private readonly UserDirectory $directory,
	) {}

	public function setAllowFullPrivateExport(bool $allow): void {
		$this->allowFullPrivateExport = $allow;
	}

	public function resolvePersona(?int $userId = null): Persona {
		$roles = $this->rolesOf($userId);
		if (in_array(self::GESTOR_ROLE, $roles, true)) {
			return Persona::Gestor;
		}
		if (in_array(self::ADMIN_ROLE, $roles, true)) {
			return Persona::AdministradorEleitoral;
		}
		if (in_array(self::AUDITOR_ROLE, $roles, true)) {
			return Persona::Auditor;
		}
		if (in_array(self::OFFICIAL_ROLE, $roles, true)) {
			return Persona::AutoridadeEleitoral;
		}
		return Persona::Eleitor;
	}

	public function hasAdminRole(?int $userId = null): bool {
		$roles = $this->rolesOf($userId);
		return in_array(self::ADMIN_ROLE, $roles, true) || in_array(self::GESTOR_ROLE, $roles, true);
	}

	public function hasOfficialRole(?int $userId = null): bool {
		$roles = $this->rolesOf($userId);
		return in_array(self::OFFICIAL_ROLE, $roles, true) || in_array(self::ADMIN_ROLE, $roles, true);
	}

	public function hasVoterRole(?int $userId = null): bool {
		return in_array(self::VOTER_ROLE, $this->rolesOf($userId), true);
	}

	public function canManageElection(?int $userId = null): bool {
		$persona = $this->resolvePersona($userId);
		return Persona::Gestor === $persona || Persona::AdministradorEleitoral === $persona;
	}

	public function isElectionOfficial(?int $userId = null): bool {
		$persona = $this->resolvePersona($userId);
		return in_array(
			$persona,
			array(Persona::Gestor, Persona::AdministradorEleitoral, Persona::AutoridadeEleitoral),
			true
		);
	}

	public function canVote(?int $userId = null): bool {
		return $this->hasVoterRole($userId);
	}

	public function canViewAudit(?int $userId = null): bool {
		if ($this->hasAdminRole($userId)) {
			return true;
		}
		return in_array(self::AUDITOR_ROLE, $this->rolesOf($userId), true);
	}

	public function canExportOwnShare(?int $userId = null): bool {
		return $this->hasOfficialRole($userId);
	}

	public function canExportAllShares(?int $userId = null): bool {
		return $this->canManageElection($userId) && $this->allowFullPrivateExport;
	}

	public function isCandidate(?int $userId = null): bool {
		$roles = $this->rolesOf($userId);
		return in_array(self::VOTER_ROLE, $roles, true)
			&& !in_array(self::OFFICIAL_ROLE, $roles, true)
			&& !in_array(self::ADMIN_ROLE, $roles, true);
	}

	/** @return list<string> */
	private function rolesOf(?int $userId): array {
		if (null === $userId) {
			return $this->current->currentRoles();
		}
		$row = $this->directory->findById($userId);
		return $row ? $row['roles'] : array();
	}
}
