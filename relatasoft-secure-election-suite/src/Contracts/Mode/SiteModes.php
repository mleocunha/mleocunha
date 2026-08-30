<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Contracts\Mode;

/**
 * Modos canónicos de sítio — vocabulário único do domínio (E3 / C1 / A6).
 *
 * Regra de produto: 1 cliente = 3 sítios isolados, cada um com exactamente
 * um papel. Não existe modo “tudo-em-um”. Não há sincronização automática
 * entre sítios; material criptográfico e resultados viajam só por courier manual.
 *
 * Fonte de verdade para:
 * - Adapter #1 (`ModeLock` em `includes/Bootstrap`) — aliases e UI
 * - Adapter #2 (`EnvModeLock` / `NodeRuntime`) — modo por ambiente
 * - Navegação e gates de capacidade por modo
 */
final class SiteModes {

	/** Sítio que gera o par de chaves e as parcelas Shamir. */
	public const KEY_AUTHORITY = 'key_authority';

	/** Sítio onde os eleitores votam (urna / jornada `/voto`). */
	public const VOTING        = 'voting';

	/** Sítio que importa pacotes, apura e certifica resultados. */
	public const TALLYING      = 'tallying';

	/**
	 * Lista ordenada e fechada dos três papéis isolados.
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return array( self::KEY_AUTHORITY, self::VOTING, self::TALLYING );
	}

	/**
	 * O slug pertence ao conjunto canónico?
	 *
	 * Qualquer valor fora desta lista (ex.: `all_in_one`, string vazia) é inválido.
	 */
	public static function isValid( string $mode ): bool {
		return in_array( $mode, self::all(), true );
	}

	/**
	 * Rótulo humano em PT-BR (produto Recife) para UI e logs.
	 *
	 * Se o slug for desconhecido, devolve o próprio slug (não inventa tradução).
	 */
	public static function label( string $mode ): string {
		return match ( $mode ) {
			self::KEY_AUTHORITY => 'Autoridade de chaves',
			self::VOTING        => 'Plataforma de votação',
			self::TALLYING      => 'Apuração / certificação',
			default             => $mode,
		};
	}
}
