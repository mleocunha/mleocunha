<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Contracts\User;

use RelataSoft\SecureElectionSuite\Painel\Domain\Access\Persona;

interface CapabilityResolver {
	public function resolvePersona(?int $userId = null): Persona;

	public function canManageElection(?int $userId = null): bool;

	public function isElectionOfficial(?int $userId = null): bool;
}
