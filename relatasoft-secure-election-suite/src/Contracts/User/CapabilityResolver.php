<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Contracts\User;

use RelataSoft\SecureElectionSuite\Painel\Domain\Access\Persona;

/**
 * Port: election capability / persona resolution (A3).
 *
 * Role-slug rules for cast/RSV/authorities live here so domain never calls host caps APIs.
 */
interface CapabilityResolver {
	public function resolvePersona(?int $userId = null): Persona;

	/** Administrator / gestor / manage_options — election admin. */
	public function hasAdminRole(?int $userId = null): bool;

	/** Editor or Administrator — Shamir official (not gestor-only). */
	public function hasOfficialRole(?int $userId = null): bool;

	/** Subscriber enrolled as voter. */
	public function hasVoterRole(?int $userId = null): bool;

	public function canManageElection(?int $userId = null): bool;

	public function isElectionOfficial(?int $userId = null): bool;

	public function canVote(?int $userId = null): bool;

	public function canViewAudit(?int $userId = null): bool;

	public function canExportOwnShare(?int $userId = null): bool;

	public function canExportAllShares(?int $userId = null): bool;

	public function isCandidate(?int $userId = null): bool;
}
