<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Domain\Access;

/**
 * Electoral control-panel personas (mapped to WP roles in the adapter).
 */
enum Persona: string {
	case Gestor = 'gestor';
	case AdministradorEleitoral = 'administrador_eleitoral';
	case AutoridadeEleitoral = 'autoridade_eleitoral';
	case Eleitor = 'eleitor';

	public function labelPt(): string {
		return match ( $this ) {
			self::Gestor => 'Gestor Voto Eletrônico',
			self::AdministradorEleitoral => 'Administrador Eleitoral',
			self::AutoridadeEleitoral => 'Autoridade Eleitoral',
			self::Eleitor => 'Eleitor',
		};
	}

	public function mayEnterAdminShell(): bool {
		return $this !== self::Eleitor;
	}
}
