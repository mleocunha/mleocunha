<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\User;

use RelataSoft\SecureElectionSuite\Painel\Contracts\User\UserProvider;

final class WordPressUserProvider implements UserProvider {

	public function currentUserId(): int {
		return (int) get_current_user_id();
	}

	public function currentRoles(): array {
		$user = wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return array();
		}
		return array_map( 'strval', (array) $user->roles );
	}
}
