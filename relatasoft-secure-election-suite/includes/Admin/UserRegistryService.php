<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Admin;

use RelataSoft\SecureElectionSuite\Bootstrap\ModeLock;
use RelataSoft\SecureElectionSuite\I18n\RoleLabels;
use RelataSoft\SecureElectionSuite\Painel\Application\Identity\IdentityGateway;
use RelataSoft\SecureElectionSuite\Painel\Domain\Access\RegistryListPager;
use RelataSoft\SecureElectionSuite\Painel\Domain\Access\UserRegistryRoles;
use RelataSoft\SecureElectionSuite\Security\Capability;

defined( 'ABSPATH' ) || exit;

/**
 * Mode-scoped user roles for the Painel registry (via IdentityGateway ports).
 */
final class UserRegistryService {

	/**
	 * Role slugs visible/creatable for the active (or given) mode.
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
			UserRegistryRoles::ROLE_ADMIN    => __( 'Administrador Eleitoral', 'relatasoft-secure-election-suite' ),
			UserRegistryRoles::ROLE_GESTOR   => __( 'Gestor pelo Cliente', 'relatasoft-secure-election-suite' ),
			UserRegistryRoles::ROLE_OFFICIAL => RoleLabels::rses_editor_singular(),
			UserRegistryRoles::ROLE_AUDITOR  => __( 'Auditor', 'relatasoft-secure-election-suite' ),
			UserRegistryRoles::ROLE_VOTER    => RoleLabels::rses_elector_singular(),
		);
	}

	/**
	 * Listagem paginada por papel (só a página pedida — sem carregar o cadastro inteiro).
	 *
	 * @param array<string,array{page?:int,per_page?:int}> $pager_state Estado por slug de papel.
	 * @return array<string, array{
	 *   users: list<array<string,mixed>>,
	 *   total: int,
	 *   page: int,
	 *   per_page: int,
	 *   total_pages: int
	 * }>
	 */
	public static function grouped_users_paged( ?string $mode = null, array $pager_state = array() ): array {
		$roles  = self::roles_for_mode( $mode );
		$labels = self::role_labels();
		$users  = IdentityGateway::get()->users;
		$out    = array();
		foreach ( $roles as $role ) {
			if ( ! isset( $labels[ $role ] ) ) {
				continue;
			}
			$want_pp = (int) ( $pager_state[ $role ]['per_page'] ?? RegistryListPager::DEFAULT_PER_PAGE );
			$want_p  = (int) ( $pager_state[ $role ]['page'] ?? 1 );
			$per     = RegistryListPager::normalizePerPage( $want_pp );
			$total   = $users->countByRole( $role );
			$page    = RegistryListPager::normalizePage( $want_p, $total, $per );
			$offset  = RegistryListPager::offset( $page, $total, $per );
			$out[ $role ] = array(
				'users'       => $total > 0 ? $users->listByRole( $role, $offset, $per ) : array(),
				'total'       => $total,
				'page'        => $page,
				'per_page'    => $per,
				'total_pages' => RegistryListPager::totalPages( $total, $per ),
			);
		}
		return $out;
	}

	/**
	 * @deprecated Prefer {@see grouped_users_paged()} — mantido para callers legados.
	 *
	 * @return array<string, list<array<string,mixed>>>
	 */
	public static function grouped_users( ?string $mode = null ): array {
		$paged = self::grouped_users_paged( $mode, array() );
		$out   = array();
		foreach ( $paged as $role => $block ) {
			$out[ $role ] = $block['users'];
		}
		return $out;
	}

	/**
	 * Ler estado de paginação a partir de $_GET (sem nonce — só navegação).
	 *
	 * @param list<string> $roles
	 * @param array<string,mixed> $request Tipicamente $_GET.
	 * @return array<string,array{page:int,per_page:int}>
	 */
	public static function pager_state_from_request( array $roles, array $request ): array {
		$state = array();
		foreach ( $roles as $role ) {
			$pk = RegistryListPager::pageQueryKey( $role );
			$pp = RegistryListPager::perPageQueryKey( $role );
			$state[ $role ] = array(
				'page'     => isset( $request[ $pk ] ) ? (int) $request[ $pk ] : 1,
				'per_page' => isset( $request[ $pp ] ) ? (int) $request[ $pp ] : RegistryListPager::DEFAULT_PER_PAGE,
			);
		}
		return $state;
	}

	/**
	 * Roles that may be assigned when creating a user in this mode.
	 *
	 * @return list<string>
	 */
	public static function creatable_roles( ?string $mode = null ): array {
		$mode  = $mode ?? ModeLock::rses_get_mode();
		$roles = array(
			UserRegistryRoles::ROLE_OFFICIAL,
			UserRegistryRoles::ROLE_AUDITOR,
		);
		if ( ModeLock::RSES_MODE_VOTING === $mode ) {
			$roles[] = UserRegistryRoles::ROLE_VOTER;
		}
		if ( Capability::rses_user_has_admin_role() ) {
			array_unshift( $roles, UserRegistryRoles::ROLE_ADMIN );
		}
		return array_values( array_unique( $roles ) );
	}

	/**
	 * Create a user with a mode-allowed role via UserDirectory.
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
		$created = IdentityGateway::get()->users->create(
			array(
				'login'       => $login,
				'email'       => $email,
				'password'    => $password,
				'displayName' => sanitize_text_field( $display_name !== '' ? $display_name : $login ),
				'role'        => $role,
			)
		);
		if ( empty( $created['ok'] ) ) {
			return new \WP_Error( 'rses_create', (string) ( $created['error'] ?? __( 'Falha ao cadastrar.', 'relatasoft-secure-election-suite' ) ) );
		}
		return (int) $created['id'];
	}
}
