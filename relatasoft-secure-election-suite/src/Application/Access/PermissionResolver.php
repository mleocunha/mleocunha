<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Application\Access;

use RelataSoft\SecureElectionSuite\Painel\Contracts\User\CapabilityResolver;
use RelataSoft\SecureElectionSuite\Painel\Domain\Access\AccessPolicy;
use RelataSoft\SecureElectionSuite\Painel\Domain\Access\Persona;

final class PermissionResolver {

	public function __construct(
		private readonly CapabilityResolver $capabilities,
		private readonly AccessPolicy $policy,
	) {}

	public function currentPersona(): Persona {
		return $this->capabilities->resolvePersona( null );
	}

	public function can(string $permission): bool {
		return $this->policy->can( $this->currentPersona(), $permission );
	}

	public function mayEnterAdminShell(): bool {
		return $this->currentPersona()->mayEnterAdminShell()
			&& $this->can( AccessPolicy::PERM_SHELL_ADMIN );
	}
}
