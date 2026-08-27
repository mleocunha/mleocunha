<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Admin;

use RelataSoft\SecureElectionSuite\Bootstrap\ModeLock;
use RelataSoft\SecureElectionSuite\I18n\RoleLabels;
use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\User\WordPressCapabilityResolver;
use RelataSoft\SecureElectionSuite\Painel\Domain\Access\UserRegistryRoles;
use RelataSoft\SecureElectionSuite\Security\Capability;

defined( 'ABSPATH' ) || exit;

/**
 * Mode-scoped user roles for the Painel registry.
 */
final class UserRegistryService {

	/**
	 * WP role slugs visible/creatable for the active (or given) mode.
	 *
	 * @return list<string>
	 */
	public static function roles_for_mode( ?string $mode = null ): array {
		$mode = $mode ?? ModeLock::rses_get_mode();
		return UserRegistryRoles::forMode( is_string( $mode ) ? $mode : '' );
	}

	/**
	 * Human labels for roles in the registry.
	 *
	 * @return array<string,string> role => label
	 */
	public static function role_labels(): array {
		return array(
			Capability::RSES_ADMIN_ROLE     => __( 'Administrador Eleitoral', 'relatasoft-secure-election-suite' ),
			WordPressCapabilityResolver::GESTOR_ROLE => __( 'Gestor Voto Eletrônico', 'relatasoft-secure-election-suite' ),
			Capability::RSES_OFFICIAL_ROLE  => RoleLabels::rses_editor_singular(),
			Capability::RSES_VOTER_ROLE     => RoleLabels::rses_elector_singular(),
		);
	}

	/**
	 * Grouped users for the registry UI.
	 *
	 * @return array<string, list<\WP_User>>
	 */
	public static function grouped_users( ?string $mode = null ): array {
		$roles  = self::roles_for_mode( $mode );
		$labels = self::role_labels();
		$out    = array();
		foreach ( $roles as $role ) {
			if ( ! isset( $labels[ $role ] ) ) {
				continue;
			}
			$users = get_users(
				array(
					'role'    => $role,
					'orderby' => 'display_name',
					'order'   => 'ASC',
					'number'  => 500,
				)
			);
			$out[ $role ] = is_array( $users ) ? $users : array();
		}
		return $out;
	}

	/**
	 * Roles that may be assigned when creating a user in this mode.
	 * Admins/gestor are listed but create defaults to autoridade / eleitor.
	 *
	 * @return list<string>
	 */
	public static function creatable_roles( ?string $mode = null ): array {
		$mode = $mode ?? ModeLock::rses_get_mode();
		$roles = array( Capability::RSES_OFFICIAL_ROLE );
		if ( ModeLock::RSES_MODE_VOTING === $mode ) {
			$roles[] = Capability::RSES_VOTER_ROLE;
		}
		// Gestor/admin may also provision another electoral administrator.
		if ( Capability::rses_user_has_admin_role() ) {
			array_unshift( $roles, Capability::RSES_ADMIN_ROLE );
		}
		return array_values( array_unique( $roles ) );
	}

	/**
	 * Create a WP user with a mode-allowed role.
	 *
	 * @return int|\WP_Error
	 */
	public static function create_user( string $login, string $email, string $display_name, string $password, string $role ) {
		$allowed = self::creatable_roles();
		if ( ! in_array( $role, $allowed, true ) ) {
			return new \WP_Error( 'rses_role', __( 'Esse papel não é permitido neste modo.', 'relatasoft-secure-election-suite' ) );
		}
		$login = sanitize_user( $login, true );
		$email = sanitize_email( $email );
		if ( '' === $login || ! is_email( $email ) ) {
			return new \WP_Error( 'rses_fields', __( 'Login e e-mail válidos são obrigatórios.', 'relatasoft-secure-election-suite' ) );
		}
		if ( strlen( $password ) < 8 ) {
			return new \WP_Error( 'rses_pass', __( 'A senha deve ter pelo menos 8 caracteres.', 'relatasoft-secure-election-suite' ) );
		}
		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => $password,
				'display_name' => sanitize_text_field( $display_name !== '' ? $display_name : $login ),
				'role'         => $role,
			)
		);
		return $user_id;
	}
}
