<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Contracts\User;

interface UserProvider {
	public function currentUserId(): int;

	public function isAuthenticated(): bool;

	/** @return list<string> */
	public function currentRoles(): array;
}
