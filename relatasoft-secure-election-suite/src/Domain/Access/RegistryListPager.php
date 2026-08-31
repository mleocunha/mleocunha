<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Domain\Access;

/**
 * Paginação da listagem do Cadastro Eleitoral por papel.
 *
 * Objectivo: nunca carregar o cadastro inteiro na UI — só a página pedida
 * (25 / 50 / 100 / 200 linhas; default 25). Domínio puro, sem boot do sítio.
 */
final class RegistryListPager {

	/** @var list<int> */
	public const PER_PAGE_OPTIONS = array( 25, 50, 100, 200 );

	public const DEFAULT_PER_PAGE = 25;

	/**
	 * Aceitar só as densidades permitidas; qualquer outro valor → default.
	 */
	public static function normalizePerPage( int $per_page ): int {
		return in_array( $per_page, self::PER_PAGE_OPTIONS, true )
			? $per_page
			: self::DEFAULT_PER_PAGE;
	}

	/**
	 * Número total de páginas (≥ 1 mesmo com total 0, para a UI mostrar "1 / 1").
	 */
	public static function totalPages( int $total_items, int $per_page ): int {
		$per_page = self::normalizePerPage( $per_page );
		if ( $total_items <= 0 ) {
			return 1;
		}
		return (int) max( 1, (int) ceil( $total_items / $per_page ) );
	}

	/**
	 * Página pedida limitada ao intervalo [1, totalPages].
	 */
	public static function normalizePage( int $page, int $total_items, int $per_page ): int {
		$pages = self::totalPages( $total_items, $per_page );
		if ( $page < 1 ) {
			return 1;
		}
		if ( $page > $pages ) {
			return $pages;
		}
		return $page;
	}

	/**
	 * Offset 0-based para listByRole / get_users.
	 */
	public static function offset( int $page, int $total_items, int $per_page ): int {
		$per_page = self::normalizePerPage( $per_page );
		$page     = self::normalizePage( $page, $total_items, $per_page );
		return ( $page - 1 ) * $per_page;
	}

	/**
	 * Prefixo de query-arg por papel (ex.: subscriber → rses_p_subscriber).
	 */
	public static function pageQueryKey( string $role ): string {
		$role = preg_replace( '/[^a-z0-9_]/', '', strtolower( $role ) ) ?? '';
		return 'rses_p_' . $role;
	}

	/**
	 * Prefixo de query-arg de densidade por papel.
	 */
	public static function perPageQueryKey( string $role ): string {
		$role = preg_replace( '/[^a-z0-9_]/', '', strtolower( $role ) ) ?? '';
		return 'rses_pp_' . $role;
	}
}
