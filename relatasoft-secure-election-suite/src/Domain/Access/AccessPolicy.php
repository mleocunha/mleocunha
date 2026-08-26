<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Domain\Access;

/**
 * Central permission policy for the Painel (no WordPress APIs).
 */
final class AccessPolicy {

	public const PERM_DASHBOARD_VIEW = 'dashboard.view';
	public const PERM_SETTINGS_MANAGE = 'settings.manage';
	public const PERM_MODE_MANAGE = 'mode.manage';
	public const PERM_KEYS_MANAGE = 'keys.manage';
	public const PERM_PARCELAS_OWN = 'parcelas.own';
	public const PERM_ELECTIONS_MANAGE = 'elections.manage';
	public const PERM_TALLY_MANAGE = 'tally.manage';
	public const PERM_AUDIT_VIEW = 'audit.view';
	public const PERM_SHELL_ADMIN = 'shell.admin';

	/**
	 * @return list<string>
	 */
	public function permissionsFor(Persona $persona): array {
		return match ( $persona ) {
			Persona::Gestor => array(
				self::PERM_DASHBOARD_VIEW,
				self::PERM_SETTINGS_MANAGE,
				self::PERM_MODE_MANAGE,
				self::PERM_KEYS_MANAGE,
				self::PERM_PARCELAS_OWN,
				self::PERM_ELECTIONS_MANAGE,
				self::PERM_TALLY_MANAGE,
				self::PERM_AUDIT_VIEW,
				self::PERM_SHELL_ADMIN,
			),
			Persona::AdministradorEleitoral => array(
				self::PERM_DASHBOARD_VIEW,
				self::PERM_SETTINGS_MANAGE,
				self::PERM_MODE_MANAGE,
				self::PERM_KEYS_MANAGE,
				self::PERM_PARCELAS_OWN,
				self::PERM_ELECTIONS_MANAGE,
				self::PERM_TALLY_MANAGE,
				self::PERM_AUDIT_VIEW,
				self::PERM_SHELL_ADMIN,
			),
			Persona::AutoridadeEleitoral => array(
				self::PERM_DASHBOARD_VIEW,
				self::PERM_PARCELAS_OWN,
				self::PERM_SHELL_ADMIN,
			),
			Persona::Eleitor => array(),
		};
	}

	public function can(Persona $persona, string $permission): bool {
		return in_array( $permission, $this->permissionsFor( $persona ), true );
	}
}
