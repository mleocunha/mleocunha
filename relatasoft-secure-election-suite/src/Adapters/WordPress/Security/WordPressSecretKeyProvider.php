<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Security;

use RelataSoft\SecureElectionSuite\Painel\Contracts\Security\SecretKeyProvider;

/**
 * Adapter #1: derive share storage key from host secrets (stays inside the adapter).
 */
final class WordPressSecretKeyProvider implements SecretKeyProvider {

	public function shareStorageKey(): string {
		$material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' )
			. ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '' )
			. ( defined( 'LOGGED_IN_KEY' ) ? LOGGED_IN_KEY : '' );

		return hash( 'sha256', $material . 'rses_share_encryption', true );
	}
}
