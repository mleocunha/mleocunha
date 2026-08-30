<?php
/**
 * Tranca de modo do sítio (Adapter #1 / facade WordPress).
 *
 * @package RelataSoft\SecureElectionSuite\Bootstrap
 */

namespace RelataSoft\SecureElectionSuite\Bootstrap;

use RelataSoft\SecureElectionSuite\Database\Repository;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Mode\SiteModes;
use RelataSoft\SecureElectionSuite\Security\AuditLogger;
use RelataSoft\SecureElectionSuite\Security\Capability;

defined( 'ABSPATH' ) || exit;

/**
 * Garante mutual exclusivity de modos no Adapter #1 (C1 / E3).
 *
 * Um sítio só pode ser autoridade de chaves, votação ou apuração.
 * Depois de escolhido, o modo fica trancado (`rses_mode_locked=1`).
 * Trocar de modo exige reset destrutivo (apaga dados eleitorais deste sítio).
 *
 * Vocabulário (slugs e rótulos) vem sempre de {@see SiteModes} — esta classe
 * é a fachada WP (options, capabilities, audit) e não redefine o domínio.
 */
class ModeLock {

	/** Alias estável para código legado / includes que ainda referem as constantes RSES_*. */
	public const RSES_MODE_KEY_AUTHORITY = SiteModes::KEY_AUTHORITY;
	public const RSES_MODE_VOTING        = SiteModes::VOTING;
	public const RSES_MODE_TALLYING      = SiteModes::TALLYING;

	/**
	 * Mapa slug → rótulo PT-BR dos modos válidos (delegado a {@see SiteModes}).
	 *
	 * @return array<string,string>
	 */
	public static function rses_get_valid_modes(): array {
		$out = array();
		foreach ( SiteModes::all() as $slug ) {
			$out[ $slug ] = SiteModes::label( $slug );
		}
		return $out;
	}

	/**
	 * Modo actualmente gravado em opções do sítio (string vazia se ainda não definido).
	 */
	public static function rses_get_mode(): string {
		return (string) get_option( 'rses_mode', '' );
	}

	/**
	 * O modo já foi escolhido e trancado? (impede nova escolha sem reset).
	 */
	public static function rses_is_locked(): bool {
		return '1' === get_option( 'rses_mode_locked', '0' );
	}

	/**
	 * O sítio está neste modo exacto?
	 *
	 * @param string $mode Slug canónico ({@see SiteModes}).
	 */
	public static function rses_is_mode( string $mode ): bool {
		return self::rses_get_mode() === $mode;
	}

	/**
	 * Há um modo válido definido (não vazio e pertencente a {@see SiteModes::all()})?
	 */
	public static function rses_has_mode(): bool {
		$mode = self::rses_get_mode();
		return '' !== $mode && SiteModes::isValid( $mode );
	}

	/**
	 * Definir e trancar o modo (só se ainda não estiver trancado).
	 *
	 * Requisitos: capacidade de gerir eleição, modo ainda livre, slug válido.
	 * Em sucesso grava `rses_mode`, tranca, e regista auditoria `mode_set`.
	 *
	 * @param string $mode Slug canónico.
	 * @return bool false se sem permissão, já trancado, ou slug inválido.
	 */
	public static function rses_set_mode( string $mode ): bool {
		if ( ! Capability::rses_can_manage_election() ) {
			return false;
		}

		if ( self::rses_is_locked() ) {
			return false;
		}

		if ( ! SiteModes::isValid( $mode ) ) {
			return false;
		}

		update_option( 'rses_mode', $mode );
		update_option( 'rses_mode_locked', '1' );

		AuditLogger::rses_log(
			'mode_set',
			'mode',
			null,
			array(
				'mode' => $mode,
			)
		);

		return true;
	}

	/**
	 * Abortar o pedido com 403 se o sítio não estiver no modo exigido.
	 *
	 * Usado por ecrãs/AJAX que só fazem sentido num dos três papéis.
	 *
	 * @param string $mode Modo obrigatório para a acção.
	 */
	public static function rses_require_mode( string $mode ): void {
		if ( ! self::rses_is_mode( $mode ) ) {
			wp_die(
				esc_html__( 'This action is not available in the current plugin mode.', 'relatasoft-secure-election-suite' ),
				esc_html__( 'Mode Restriction', 'relatasoft-secure-election-suite' ),
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Reset destrutivo: limpar dados eleitorais e destrancar o modo.
	 *
	 * Apaga tabelas da suíte e opções de modo; permite escolher outro papel.
	 * Não sincroniza nem avisa os outros dois sítios do cliente (isolamento E3).
	 *
	 * @return bool false sem permissão de gestão.
	 */
	public static function rses_destructive_reset(): bool {
		if ( ! Capability::rses_can_manage_election() ) {
			return false;
		}

		Repository::rses_truncate_all_tables();

		delete_option( 'rses_mode' );
		update_option( 'rses_mode_locked', '0' );

		AuditLogger::rses_log(
			'destructive_reset',
			'system',
			null,
			array(
				'message' => 'All election data, keys, shares, and audit logs removed.',
			)
		);

		return true;
	}

	/**
	 * Rótulo de apresentação do modo (PT-BR via {@see SiteModes::label}).
	 *
	 * Se o slug for inválido, devolve o slug em bruto (útil em debug).
	 *
	 * @param string $mode Slug a rotular.
	 */
	public static function rses_get_mode_label( string $mode ): string {
		return SiteModes::isValid( $mode ) ? SiteModes::label( $mode ) : $mode;
	}
}
