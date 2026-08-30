<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Contracts\Mode;

/**
 * Canonical site modes — 1 cliente = 3 sítios isolados (E3 / A6).
 */
final class SiteModes {

	public const KEY_AUTHORITY = 'key_authority';
	public const VOTING        = 'voting';
	public const TALLYING      = 'tallying';

	/**
	 * @return list<string>
	 */
	public static function all(): array {
		return array( self::KEY_AUTHORITY, self::VOTING, self::TALLYING );
	}

	public static function isValid( string $mode ): bool {
		return in_array( $mode, self::all(), true );
	}

	/** Human label (PT-BR produto). */
	public static function label( string $mode ): string {
		return match ( $mode ) {
			self::KEY_AUTHORITY => 'Autoridade de chaves',
			self::VOTING        => 'Plataforma de votação',
			self::TALLYING      => 'Apuração / certificação',
			default             => $mode,
		};
	}
}
