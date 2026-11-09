<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Domain\Identity;

/**
 * Ciclo de vida da conta (standalone + diretório portátil).
 *
 * Contas suspensas/bloqueadas/desativadas/excluídas não autenticam
 * e têm sessões revogadas na transição.
 */
final class AccountStatus {

	public const PENDING_VERIFICATION = 'pending_verification';
	public const ACTIVE               = 'active';
	public const SUSPENDED            = 'suspended';
	public const LOCKED               = 'locked';
	public const DISABLED             = 'disabled';
	public const PENDING_DELETION     = 'pending_deletion';
	public const DELETED              = 'deleted';

	/** @return list<string> */
	public static function all(): array {
		return array(
			self::PENDING_VERIFICATION,
			self::ACTIVE,
			self::SUSPENDED,
			self::LOCKED,
			self::DISABLED,
			self::PENDING_DELETION,
			self::DELETED,
		);
	}

	public static function isKnown( string $status ): bool {
		return in_array( $status, self::all(), true );
	}

	public static function canAuthenticate( string $status ): bool {
		return self::ACTIVE === $status;
	}

	/**
	 * Transições permitidas: from => list<to>.
	 *
	 * @return array<string,list<string>>
	 */
	public static function allowedTransitions(): array {
		return array(
			self::PENDING_VERIFICATION => array( self::ACTIVE, self::DISABLED, self::DELETED ),
			self::ACTIVE               => array( self::SUSPENDED, self::LOCKED, self::DISABLED, self::PENDING_DELETION ),
			self::SUSPENDED            => array( self::ACTIVE, self::DISABLED, self::PENDING_DELETION ),
			self::LOCKED               => array( self::ACTIVE, self::DISABLED, self::PENDING_DELETION ),
			self::DISABLED             => array( self::ACTIVE, self::PENDING_DELETION, self::DELETED ),
			self::PENDING_DELETION     => array( self::DISABLED, self::DELETED, self::ACTIVE ),
			self::DELETED              => array(),
		);
	}

	public static function canTransition( string $from, string $to ): bool {
		if ( ! self::isKnown( $from ) || ! self::isKnown( $to ) ) {
			return false;
		}
		$map = self::allowedTransitions();
		return in_array( $to, $map[ $from ] ?? array(), true );
	}

	/** Normaliza valor legado / ausente para active. */
	public static function normalize( ?string $status ): string {
		$status = is_string( $status ) ? trim( $status ) : '';
		return self::isKnown( $status ) ? $status : self::ACTIVE;
	}
}
