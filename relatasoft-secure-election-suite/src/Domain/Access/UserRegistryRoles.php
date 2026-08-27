<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Domain\Access;

/**
 * Which WP role slugs belong in the user registry for each operation mode.
 */
final class UserRegistryRoles {

	public const ROLE_ADMIN    = 'administrator';
	public const ROLE_GESTOR   = 've_gestor';
	public const ROLE_OFFICIAL = 'editor';
	public const ROLE_VOTER    = 'subscriber';

	/**
	 * @return list<string>
	 */
	public static function forMode( string $mode ): array {
		$core = array( self::ROLE_ADMIN, self::ROLE_GESTOR, self::ROLE_OFFICIAL );
		if ( 'voting' === $mode ) {
			$core[] = self::ROLE_VOTER;
		}
		return array_values( array_unique( $core ) );
	}

	public static function includesVoters( string $mode ): bool {
		return in_array( self::ROLE_VOTER, self::forMode( $mode ), true );
	}
}
