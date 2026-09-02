<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Domain\ElectoralRoll;

/**
 * Formato .rsv (RelataSoft Separated Values) do Cadastro Eleitoral.
 *
 * Separadores (contrato de produto):
 * - `:` separa campos
 * - `;` separa itens de série (ex.: vários e-mails ou celulares)
 * - `,` é texto livre (ex.: endereço), nunca separador de coluna
 *
 * Independente de WordPress — unit-testável em PHPUnit puro.
 */
final class RsvFormat {

	public const FIELD_SEP = ':';
	public const SERIES_SEP = ';';
	public const EXTENSION = 'rsv';

	/** @var list<string> */
	public const HEADERS = array(
		'login',
		'numerodeidentificacaocivil',
		'numerodeidentificacaoeleitoral',
		'regiaoeleitoralampla',
		'regiaoeleitoralespecifica',
		'nomecompleto',
		'celular',
		'email',
		'endereco',
		'papel',
		'senha',
	);

	/**
	 * Papéis canónicos PT-BR (arquivo modelo por língua traduz os metadados).
	 *
	 * @var array<string,string> papel_rsv => slug WP interno
	 */
	public const ROLE_MAP_PT_BR = array(
		'eleitor'        => 'subscriber',
		'auditor'        => 've_auditor',
		'autoridade'     => 'editor',
		'administrador'  => 'administrator',
		'gestor'         => 've_gestor',
		// Legado WooCommerce / WP.
		'subscriber'     => 'subscriber',
		'customer'       => 've_auditor',
		'editor'         => 'editor',
		'administrator'  => 'administrator',
	);

	/**
	 * Serializa a linha de metadados (cabeçalho).
	 */
	public static function headerLine(): string {
		return implode( self::FIELD_SEP, self::HEADERS );
	}

	/**
	 * Parte uma linha .rsv em campos (exatamente count(HEADERS)).
	 *
	 * @return list<string>|null Null se o número de campos for inválido.
	 */
	public static function parseLine( string $line ): ?array {
		$line = self::stripBom( rtrim( $line, "\r\n" ) );
		if ( '' === $line ) {
			return null;
		}
		$parts = explode( self::FIELD_SEP, $line );
		if ( count( $parts ) !== count( self::HEADERS ) ) {
			return null;
		}
		return array_values( $parts );
	}

	/**
	 * @param list<string> $fields
	 */
	public static function serializeLine( array $fields ): string {
		$padded = array();
		foreach ( self::HEADERS as $i => $_h ) {
			$padded[] = (string) ( $fields[ $i ] ?? '' );
		}
		return implode( self::FIELD_SEP, $padded );
	}

	/**
	 * @param list<string> $fields
	 * @return array<string,string>
	 */
	public static function associate( array $fields ): array {
		$out = array();
		foreach ( self::HEADERS as $i => $key ) {
			$out[ $key ] = (string) ( $fields[ $i ] ?? '' );
		}
		return $out;
	}

	/**
	 * @return list<string>
	 */
	public static function splitSeries( string $value ): array {
		if ( '' === $value ) {
			return array();
		}
		$parts = explode( self::SERIES_SEP, $value );
		$out   = array();
		foreach ( $parts as $p ) {
			$p = trim( $p );
			if ( '' !== $p ) {
				$out[] = $p;
			}
		}
		return $out;
	}

	/**
	 * @param list<string> $items
	 */
	public static function joinSeries( array $items ): string {
		$clean = array();
		foreach ( $items as $item ) {
			$item = trim( (string) $item );
			if ( '' !== $item ) {
				$clean[] = $item;
			}
		}
		return implode( self::SERIES_SEP, $clean );
	}

	/**
	 * Resolve papel canónico → slug WP.
	 */
	public static function mapRole( string $papel, string $locale = 'pt_BR' ): ?string {
		$papel = strtolower( trim( $papel ) );
		$map   = self::ROLE_MAP_PT_BR;
		// Futuro: mapas por locale (en_US, pt_PT, …).
		unset( $locale );
		return $map[ $papel ] ?? null;
	}

	/**
	 * Resolve slug WP → papel canónico PT-BR (exportação .rsv).
	 *
	 * @return string Papel canónico ou string vazia se desconhecido.
	 */
	public static function reverseRole( string $wp_role ): string {
		$wp_role = strtolower( trim( $wp_role ) );
		$map     = array(
			'subscriber'    => 'eleitor',
			've_auditor'    => 'auditor',
			'editor'        => 'autoridade',
			'administrator' => 'administrador',
			've_gestor'     => 'gestor',
		);
		return $map[ $wp_role ] ?? '';
	}

	/**
	 * Tamanho de chunk adaptativo: consulta teto PHP e limita eficiência.
	 *
	 * @param int $php_ceiling_bytes min(post_max, upload_max) ou 0 se desconhecido
	 */
	public static function adaptiveChunkBytes( int $php_ceiling_bytes ): int {
		$min = 64 * 1024;       // 64 KiB piso
		$max = 1024 * 1024;     // 1 MiB teto de eficiência (mesmo se o servidor aceitar mais)
		$ideal = 256 * 1024;    // 256 KiB alvo
		if ( $php_ceiling_bytes > 0 ) {
			// Deixa folga para multipart overhead (~25%).
			$safe = (int) floor( $php_ceiling_bytes * 0.7 );
			$ideal = min( $ideal, max( $min, $safe ) );
		}
		return max( $min, min( $max, $ideal ) );
	}

	/**
	 * Max RSV upload size in bytes (4 GiB product ceiling).
	 */
	public static function maxUploadBytes(): int {
		return 4 * 1024 * 1024 * 1024; // 4 GiB oficial.
	}

	private static function stripBom( string $s ): string {
		if ( str_starts_with( $s, "\xEF\xBB\xBF" ) ) {
			return substr( $s, 3 );
		}
		return $s;
	}
}
