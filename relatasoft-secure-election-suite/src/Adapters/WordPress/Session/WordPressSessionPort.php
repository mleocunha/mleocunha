<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Session;

use RelataSoft\SecureElectionSuite\Painel\Contracts\Session\SessionPort;

final class WordPressSessionPort implements SessionPort {

	public function currentUserId(): int {
		return (int) get_current_user_id();
	}

	public function isAuthenticated(): bool {
		return is_user_logged_in();
	}

	public function assertCurrentUser(int $userId): void {
		$userId = absint( $userId );
		if ( $userId < 1 || $this->currentUserId() !== $userId ) {
			throw new \RuntimeException( 'Session identity mismatch.' );
		}
	}
}
