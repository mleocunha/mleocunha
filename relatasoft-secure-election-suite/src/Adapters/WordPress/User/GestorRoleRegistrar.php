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
			$role = get_role( WordPressCapabilityResolver::GESTOR_ROLE );
			// Keep display name current without recreating caps.
			if ( $role && function_exists( 'wp_roles' ) ) {
				$wp_roles = wp_roles();
				if ( isset( $wp_roles->roles[ WordPressCapabilityResolver::GESTOR_ROLE ] ) ) {
					$wp_roles->roles[ WordPressCapabilityResolver::GESTOR_ROLE ]['name'] = 'Gestor pelo Cliente';
					$wp_roles->role_names[ WordPressCapabilityResolver::GESTOR_ROLE ]    = 'Gestor pelo Cliente';
				}
			}
			return;
		}
		$admin = get_role( 'administrator' );
		$caps  = $admin ? $admin->capabilities : array( 'manage_options' => true, 'read' => true, 'edit_posts' => true );
		add_role(
			WordPressCapabilityResolver::GESTOR_ROLE,
			'Gestor pelo Cliente',
			$caps
		);
	}
}
