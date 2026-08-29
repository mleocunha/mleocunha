<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Infrastructure\Identity\Session;

use RelataSoft\SecureElectionSuite\Painel\Contracts\Session\SessionPort;
use RelataSoft\SecureElectionSuite\Painel\Contracts\User\UserProvider;

final class InMemorySessionPort implements SessionPort {

	public function __construct(private readonly UserProvider $users) {}

	public function currentUserId(): int {
		return $this->users->currentUserId();
	}

	public function isAuthenticated(): bool {
		return $this->users->isAuthenticated();
	}

	public function assertCurrentUser(int $userId): void {
		$userId = abs($userId);
		if ($userId < 1 || $this->currentUserId() !== $userId) {
			throw new \RuntimeException('Session identity mismatch.');
		}
	}
}
