<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Contracts\Session;

/**
 * Port: authenticated operator/voter session (A3).
 *
 * Not PHP $_SESSION — the current authenticated principal for cast/RSV/authorities.
 */
interface SessionPort {
	public function currentUserId(): int;

	public function isAuthenticated(): bool;

	/**
	 * Ensure the authenticated principal matches $userId (cast defense-in-depth).
	 *
	 * @throws \RuntimeException On mismatch or anonymous session.
	 */
	public function assertCurrentUser(int $userId): void;
}
