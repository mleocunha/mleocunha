<?php
/**
 * Force logout and login ban controls.
 *
 * @package RelataSoft\SecureElectionSuite\Security
 */

namespace RelataSoft\SecureElectionSuite\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Session destruction and login barring.
 */
class AccessControl {

	public const META_BANNED     = 'rses_login_banned';
	public const META_BANNED_AT  = 'rses_login_banned_at';
	public const META_BANNED_BY  = 'rses_login_banned_by';

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_filter( 'authenticate', array( self::class, 'rses_block_banned_authenticate' ), 30, 3 );
		add_filter( 'wp_authenticate_user', array( self::class, 'rses_block_banned_user' ), 30, 2 );
	}

	/**
	 * Whether the user is barred from new logins.
	 */
	public static function rses_is_banned( int $user_id ): bool {
		return '1' === (string) get_user_meta( $user_id, self::META_BANNED, true );
	}

	/**
	 * Ban a user from new logins.
	 *
	 * @param string $command Origin command label for the audit log.
	 */
	public static function rses_ban_user( int $user_id, string $command = 'ban_user' ): bool {
		$user_id = absint( $user_id );
		if ( $user_id < 1 ) {
			return false;
		}

		update_user_meta( $user_id, self::META_BANNED, '1' );
		update_user_meta( $user_id, self::META_BANNED_AT, current_time( 'mysql', true ) );
		update_user_meta( $user_id, self::META_BANNED_BY, get_current_user_id() );

		AuditLogger::rses_log(
			'user_ban',
			'user',
			$user_id,
			array(
				'command'       => $command,
				'ordered_by'    => get_current_user_id(),
				'target_login'  => self::rses_login_name( $user_id ),
			)
		);

		return true;
	}

	/**
	 * Lift a login ban.
	 */
	public static function rses_unban_user( int $user_id, string $command = 'unban_user' ): bool {
		$user_id = absint( $user_id );
		if ( $user_id < 1 ) {
			return false;
		}

		delete_user_meta( $user_id, self::META_BANNED );
		delete_user_meta( $user_id, self::META_BANNED_AT );
		delete_user_meta( $user_id, self::META_BANNED_BY );

		AuditLogger::rses_log(
			'user_unban',
			'user',
			$user_id,
			array(
				'command'      => $command,
				'ordered_by'   => get_current_user_id(),
				'target_login' => self::rses_login_name( $user_id ),
			)
		);

		return true;
	}

	/**
	 * Destroy all sessions for a user (force logout).
	 *
	 * @param bool   $also_ban Also bar new logins.
	 * @param string $command  Origin command for audit.
	 */
	public static function rses_force_logout( int $user_id, bool $also_ban = false, string $command = 'force_logout' ): bool {
		$user_id = absint( $user_id );
		if ( $user_id < 1 ) {
			return false;
		}

		if ( class_exists( '\WP_Session_Tokens' ) ) {
			\WP_Session_Tokens::get_instance( $user_id )->destroy_all();
		}

		if ( get_current_user_id() === $user_id ) {
			wp_clear_auth_cookie();
		}

		AuditLogger::rses_log(
			'force_logout',
			'user',
			$user_id,
			array(
				'command'      => $command,
				'ordered_by'   => get_current_user_id(),
				'target_login' => self::rses_login_name( $user_id ),
				'also_ban'     => $also_ban,
			)
		);

		if ( $also_ban ) {
			self::rses_ban_user( $user_id, $command . '+ban' );
		}

		return true;
	}

	/**
	 * Block banned users during authentication.
	 *
	 * @param null|\WP_User|\WP_Error $user     User or error.
	 * @param string                  $username Username.
	 * @param string                  $password Password.
	 * @return null|\WP_User|\WP_Error
	 */
	public static function rses_block_banned_authenticate( $user, string $username, string $password ) {
		unset( $password );

		if ( $user instanceof \WP_User && self::rses_is_banned( (int) $user->ID ) ) {
			return new \WP_Error(
				'rses_login_banned',
				__( 'This account is barred from signing in by election security controls.', 'relatasoft-secure-election-suite' )
			);
		}

		if ( ! ( $user instanceof \WP_User ) && '' !== $username ) {
			$lookup = get_user_by( 'login', $username );
			if ( ! $lookup ) {
				$lookup = get_user_by( 'email', $username );
			}
			if ( $lookup instanceof \WP_User && self::rses_is_banned( (int) $lookup->ID ) ) {
				return new \WP_Error(
					'rses_login_banned',
					__( 'This account is barred from signing in by election security controls.', 'relatasoft-secure-election-suite' )
				);
			}
		}

		return $user;
	}

	/**
	 * Secondary ban gate after credentials validated.
	 *
	 * @param \WP_User|\WP_Error $user User.
	 * @param string             $password Password.
	 * @return \WP_User|\WP_Error
	 */
	public static function rses_block_banned_user( $user, $password ) {
		unset( $password );

		if ( $user instanceof \WP_User && self::rses_is_banned( (int) $user->ID ) ) {
			return new \WP_Error(
				'rses_login_banned',
				__( 'This account is barred from signing in by election security controls.', 'relatasoft-secure-election-suite' )
			);
		}

		return $user;
	}

	/**
	 * Login name helper.
	 */
	private static function rses_login_name( int $user_id ): string {
		$user = get_userdata( $user_id );
		return ( $user && $user->exists() ) ? (string) $user->user_login : '';
	}
}
