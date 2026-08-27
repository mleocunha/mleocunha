<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Application\Navigation;

use RelataSoft\SecureElectionSuite\Painel\Application\Access\PermissionResolver;
use RelataSoft\SecureElectionSuite\Painel\Domain\Access\AccessPolicy;
use RelataSoft\SecureElectionSuite\Painel\Domain\Navigation\MenuItem;
use RelataSoft\SecureElectionSuite\Painel\Domain\Navigation\NavigationRegistry;

final class NavigationService {

	public function __construct(
		private readonly NavigationRegistry $registry,
		private readonly AccessPolicy $policy,
		private readonly PermissionResolver $permissions,
	) {}

	public function seedDefaultItems(string $mode): void {
		$this->registry->register(
			new MenuItem(
				id: 'home',
				title: 'Início',
				slug: 'rses-dashboard',
				priority: 1,
				icon: 'dashicons-privacy',
				visibleForPermissions: array( AccessPolicy::PERM_DASHBOARD_VIEW ),
				mode: 'any',
			)
		);

		$this->registry->register(
			new MenuItem(
				id: 'mode-setup',
				title: 'Configuração de Modo',
				slug: 'rses-mode-setup',
				parentId: 'home',
				priority: 5,
				visibleForPermissions: array( AccessPolicy::PERM_MODE_MANAGE ),
				mode: 'any',
			)
		);

		if ( 'key_authority' === $mode ) {
			$this->registry->register(
				new MenuItem(
					id: 'key-authority',
					title: 'Autoridade de Chaves',
					slug: 'rses-key-authority',
					parentId: 'home',
					priority: 10,
					visibleForPermissions: array( AccessPolicy::PERM_KEYS_MANAGE, AccessPolicy::PERM_PARCELAS_OWN ),
					mode: 'key_authority',
				)
			);
			$this->registry->register(
				new MenuItem(
					id: 'electoral-roll',
					title: 'Cadastro Eleitoral',
					slug: 'rses-electoral-roll',
					parentId: 'home',
					priority: 12,
					visibleForPermissions: array( AccessPolicy::PERM_KEYS_MANAGE ),
					mode: 'key_authority',
				)
			);
			$this->registry->register(
				new MenuItem(
					id: 'export-authorities',
					title: 'Exportar Autoridades Eleitorais',
					slug: 'rses-electoral-authorities',
					parentId: 'home',
					priority: 15,
					visibleForPermissions: array( AccessPolicy::PERM_KEYS_MANAGE ),
					mode: 'key_authority',
				)
			);
		}

		if ( 'voting' === $mode ) {
			foreach (
				array(
					array( 'public-keys', 'Chaves Públicas', 'rses-public-keys', 10, AccessPolicy::PERM_ELECTIONS_MANAGE ),
					array( 'elections', 'Eleições', 'rses-elections', 20, AccessPolicy::PERM_ELECTIONS_MANAGE ),
					array( 'shortcodes', 'Shortcodes', 'rses-shortcodes', 30, AccessPolicy::PERM_ELECTIONS_MANAGE ),
					array( 'redirections', 'Redirecionamentos', 'rses-redirections', 40, AccessPolicy::PERM_ELECTIONS_MANAGE ),
					array( 'electoral-roll', 'Cadastro Eleitoral', 'rses-electoral-roll', 50, AccessPolicy::PERM_ELECTIONS_MANAGE ),
					array( 'import-authorities', 'Importar Autoridades Eleitorais', 'rses-electoral-authorities', 55, AccessPolicy::PERM_ELECTIONS_MANAGE ),
					array( 'voting-export', 'Exportação', 'rses-voting-export', 60, AccessPolicy::PERM_ELECTIONS_MANAGE ),
				) as $row
			) {
				$this->registry->register(
					new MenuItem(
						id: $row[0],
						title: $row[1],
						slug: $row[2],
						parentId: 'home',
						priority: $row[3],
						visibleForPermissions: array( $row[4] ),
						mode: 'voting',
					)
				);
			}
		}

		if ( 'tallying' === $mode ) {
			foreach (
				array(
					array( 'tally-import', 'Importação / Apuração', 'rses-tally-import', 10, AccessPolicy::PERM_TALLY_MANAGE ),
					array( 'electoral-roll', 'Cadastro Eleitoral', 'rses-electoral-roll', 12, AccessPolicy::PERM_TALLY_MANAGE ),
					array( 'import-authorities', 'Importar Autoridades Eleitorais', 'rses-electoral-authorities', 15, AccessPolicy::PERM_TALLY_MANAGE ),
					array( 'share-submission', 'Submissão de Parcelas', 'rses-share-submission', 20, AccessPolicy::PERM_PARCELAS_OWN ),
					array( 'certification', 'Certificação', 'rses-certification', 30, AccessPolicy::PERM_TALLY_MANAGE ),
				) as $row
			) {
				$this->registry->register(
					new MenuItem(
						id: $row[0],
						title: $row[1],
						slug: $row[2],
						parentId: 'home',
						priority: $row[3],
						visibleForPermissions: array( $row[4] ),
						mode: 'tallying',
					)
				);
			}
		}

		$this->registry->register(
			new MenuItem(
				id: 'settings',
				title: 'Configurações',
				slug: 'rses-settings',
				parentId: 'home',
				priority: 90,
				visibleForPermissions: array( AccessPolicy::PERM_SETTINGS_MANAGE ),
				mode: 'any',
			)
		);
		$this->registry->register(
			new MenuItem(
				id: 'system-update',
				title: 'Atualizar o Sistema',
				slug: 'rses-system-update',
				parentId: 'home',
				priority: 100,
				visibleForPermissions: array( AccessPolicy::PERM_SYSTEM_MANAGE ),
				mode: 'any',
			)
		);
		$this->registry->register(
			new MenuItem(
				id: 'system-appearance',
				title: 'Identidade Visual',
				slug: 'rses-system-appearance',
				parentId: 'home',
				priority: 101,
				visibleForPermissions: array( AccessPolicy::PERM_SYSTEM_MANAGE ),
				mode: 'any',
			)
		);
		$this->registry->register(
			new MenuItem(
				id: 'system-modules',
				title: 'Módulos do Sistema',
				slug: 'rses-system-modules',
				parentId: 'home',
				priority: 102,
				visibleForPermissions: array( AccessPolicy::PERM_SYSTEM_MANAGE ),
				mode: 'any',
			)
		);
		$this->registry->register(
			new MenuItem(
				id: 'system-becape',
				title: 'Becape e Restauração',
				slug: 'rses-system-becape',
				parentId: 'home',
				priority: 103,
				visibleForPermissions: array( AccessPolicy::PERM_SYSTEM_MANAGE ),
				mode: 'any',
			)
		);
		$this->registry->register(
			new MenuItem(
				id: 'audit',
				title: 'Registro de Auditoria',
				slug: 'rses-audit-log',
				parentId: 'home',
				priority: 110,
				visibleForPermissions: array( AccessPolicy::PERM_AUDIT_VIEW ),
				mode: 'any',
			)
		);
		$this->registry->register(
			new MenuItem(
				id: 'knowledge',
				title: 'Conhecimento',
				slug: 'rses-knowledge',
				parentId: 'home',
				priority: 115,
				visibleForPermissions: array( AccessPolicy::PERM_KNOWLEDGE_VIEW ),
				mode: 'any',
			)
		);
	}

	/** @return list<\RelataSoft\SecureElectionSuite\Painel\Domain\Navigation\MenuItem> */
	public function visibleItems(string $mode): array {
		return $this->registry->visibleFor( $this->permissions->currentPersona(), $this->policy, $mode );
	}
}
