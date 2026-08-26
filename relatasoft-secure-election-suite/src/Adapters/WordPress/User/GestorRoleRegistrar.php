<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\User;

/**
 * Registers the custom WP role for Gestor Voto Eletrônico.
 */
final class GestorRoleRegistrar {

	public static function register(): void {
		// Role creation is idempotent on init/activation.
	}

	public static function ensureRole(): void {
		if ( get_role( WordPressCapabilityResolver::GESTOR_ROLE ) ) {
			return;
		}
		$admin = get_role( 'administrator' );
		$caps  = $admin ? $admin->capabilities : array( 'manage_options' => true, 'read' => true, 'edit_posts' => true );
		add_role(
			WordPressCapabilityResolver::GESTOR_ROLE,
			'Gestor Voto Eletrônico',
			$caps
		);
	}
}
