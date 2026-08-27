<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Application\Dashboard;

use RelataSoft\SecureElectionSuite\Painel\Application\Access\PermissionResolver;
use RelataSoft\SecureElectionSuite\Painel\Domain\Access\AccessPolicy;
use RelataSoft\SecureElectionSuite\Painel\Domain\Dashboard\ModeHomeCard;

final class DashboardHomeService {

	public function __construct(
		private readonly PermissionResolver $permissions,
		private readonly AccessPolicy $policy,
	) {}

	/**
	 * @return list<ModeHomeCard>
	 */
	public function cardsForMode(string $mode): array {
		$persona = $this->permissions->currentPersona();
		$cards   = match ( $mode ) {
			'key_authority' => array(
				new ModeHomeCard(
					'Autoridade de Chaves',
					'Gerar chaves ElGamal, dividir o segredo em parcelas e exportar o material público com segurança.',
					'Abrir Autoridade de Chaves',
					'rses-key-authority',
					AccessPolicy::PERM_KEYS_MANAGE,
				),
				new ModeHomeCard(
					'Minhas Parcelas',
					'Consultar, copiar e guardar offline a parcela que lhe foi atribuída. Nunca a compartilhar por canais inseguros.',
					'Abrir Minhas Parcelas',
					'rses-key-authority',
					AccessPolicy::PERM_PARCELAS_OWN,
				),
			),
			'voting' => array(
				new ModeHomeCard(
					'1. Importar chave pública',
					'Importar o pacote de chave pública exportado do site de geração de chaves.',
					'Gerenciar chaves públicas',
					'rses-public-keys',
					AccessPolicy::PERM_ELECTIONS_MANAGE,
				),
				new ModeHomeCard(
					'2. Configurar eleição e boletim',
					'Criar eleições, associar a chave pública, definir perguntas e abrir o escrutínio.',
					'Gerenciar eleições',
					'rses-elections',
					AccessPolicy::PERM_ELECTIONS_MANAGE,
				),
				new ModeHomeCard(
					'3. Cadastro eleitoral',
					'Importar o cadastro de eleitores e preparar o acesso à plataforma de votação.',
					'Abrir cadastro',
					'rses-electoral-roll',
					AccessPolicy::PERM_ELECTIONS_MANAGE,
				),
			),
			'tallying' => array(
				new ModeHomeCard(
					'Importar e apurar',
					'Importar o material de votação e preparar a totalização com verificação criptográfica.',
					'Abrir apuração',
					'rses-tally-import',
					AccessPolicy::PERM_TALLY_MANAGE,
				),
				new ModeHomeCard(
					'Submeter parcelas',
					'As autoridades eleitorais submetem suas parcelas para a reconstrução autorizada do segredo.',
					'Submeter parcela',
					'rses-share-submission',
					AccessPolicy::PERM_PARCELAS_OWN,
				),
				new ModeHomeCard(
					'Certificar resultados',
					'Assine o JSON de resultados e o PDF jurídico-administrativo que o acompanha.',
					'Abrir certificação',
					'rses-certification',
					AccessPolicy::PERM_TALLY_MANAGE,
				),
			),
			default => array(
				new ModeHomeCard(
					'Configurar modo do site',
					'Escolher e bloquear o modo deste site: geração de chaves, votação ou totalização.',
					'Abrir configuração de modo',
					'rses-mode-setup',
					AccessPolicy::PERM_MODE_MANAGE,
				),
			),
		};

		$out = array();
		foreach ( $cards as $card ) {
			if ( '' === $card->requiredPermission || $this->policy->can( $persona, $card->requiredPermission ) ) {
				$out[] = $card;
			}
		}
		return $out;
	}
}
