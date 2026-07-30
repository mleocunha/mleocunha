<?php
/**
 * Track elector (and user) login events for security monitoring.
 *
 * @package RelataSoft\SecureElectionSuite\Security
 */

namespace RelataSoft\SecureElectionSuite\Security;

use RelataSoft\SecureElectionSuite\Voting\EncryptedVoteRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Persists last/first login timestamps via usermeta.
 */
class LoginTracker {

	public const META_LAST_LOGIN  = 'rses_last_login_at';
	public const META_FIRST_LOGIN = 'rses_first_login_at';
	public const META_LOGIN_COUNT = 'rses_login_count';

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'wp_login', array( self::class, 'rses_on_login' ), 10, 2 );
	}

	/**
	 * Record a successful login.
	 *
	 * @param string   $user_login Username.
	 * @param \WP_User $user       User object.
	 */
	public static function rses_on_login( string $user_login, \WP_User $user ): void {
		unset( $user_login );

		$user_id = (int) $user->ID;
		if ( $user_id < 1 ) {
			return;
		}

		$now = current_time( 'mysql', true );

		if ( ! get_user_meta( $user_id, self::META_FIRST_LOGIN, true ) ) {
			update_user_meta( $user_id, self::META_FIRST_LOGIN, $now );
		}

		update_user_meta( $user_id, self::META_LAST_LOGIN, $now );

		$count = absint( get_user_meta( $user_id, self::META_LOGIN_COUNT, true ) );
		update_user_meta( $user_id, self::META_LOGIN_COUNT, $count + 1 );

		if ( Capability::rses_user_has_voter_role( $user_id ) ) {
			AuditLogger::rses_log(
				'elector_login',
				'user',
				$user_id,
				array(
					'command'    => 'wp_login',
					'user_login' => $user->user_login,
				)
			);
		}
	}

	/**
	 * GMT mysql datetime of last login, or empty.
	 */
	public static function rses_last_login_at( int $user_id ): string {
		$val = get_user_meta( $user_id, self::META_LAST_LOGIN, true );
		return is_string( $val ) ? $val : '';
	}

	/**
	 * Whether the user has an active WordPress session token.
	 */
	public static function rses_has_active_session( int $user_id ): bool {
		if ( $user_id < 1 || ! class_exists( '\WP_Session_Tokens' ) ) {
			return false;
		}

		$manager  = \WP_Session_Tokens::get_instance( $user_id );
		$sessions = $manager->get_all();
		if ( ! is_array( $sessions ) || empty( $sessions ) ) {
			return false;
		}

		$now = time();
		foreach ( $sessions as $session ) {
			if ( is_array( $session ) && isset( $session['expiration'] ) && (int) $session['expiration'] > $now ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Elector user IDs (subscriber role).
	 *
	 * @return list<int>
	 */
	public static function rses_elector_ids(): array {
		$users = get_users(
			array(
				'role'   => Capability::RSES_VOTER_ROLE,
				'fields' => 'ID',
				'number' => 0,
			)
		);

		$ids = array();
		foreach ( (array) $users as $id ) {
			$ids[] = absint( $id );
		}

		return array_values( array_filter( $ids ) );
	}

	/**
	 * Electors who have logged in at least once.
	 *
	 * @return list<int>
	 */
	public static function rses_electors_logged_in_ever(): array {
		$ids = array();
		foreach ( self::rses_elector_ids() as $user_id ) {
			if ( '' !== self::rses_last_login_at( $user_id ) ) {
				$ids[] = $user_id;
			}
		}
		return $ids;
	}

	/**
	 * Electors whose last (or first) login is on/after a GMT mysql datetime.
	 *
	 * @return list<int>
	 */
	public static function rses_electors_logged_in_since( string $since_gmt ): array {
		$since_gmt = trim( $since_gmt );
		if ( '' === $since_gmt ) {
			return array();
		}

		$ids = array();
		foreach ( self::rses_elector_ids() as $user_id ) {
			$last = self::rses_last_login_at( $user_id );
			if ( '' !== $last && $last >= $since_gmt ) {
				$ids[] = $user_id;
			}
		}
		return $ids;
	}

	/**
	 * Metrics for the security monitor.
	 *
	 * @param int $round_id Selected election round (0 = none).
	 * @return array{
	 *   electors_total:int,
	 *   electors_logged_in:int,
	 *   electors_since_open:int,
	 *   electors_since_open_voted:int,
	 *   electors_since_open_online:int,
	 *   opened_at:?string,
	 *   election_id:int,
	 *   round_id:int
	 * }
	 */
	public static function rses_metrics( int $round_id = 0 ): array {
		$logged_in = self::rses_electors_logged_in_ever();
		$opened_at = null;
		$election_id = 0;

		$since_ids = array();
		$voted     = 0;
		$online    = 0;

		if ( $round_id > 0 ) {
			$round = \RelataSoft\SecureElectionSuite\Voting\ElectionRepository::rses_get_round( $round_id );
			if ( $round ) {
				$election_id = absint( $round->election_id ?? 0 );
				$opened_at   = ! empty( $round->opened_at ) ? (string) $round->opened_at : null;
				if ( $opened_at ) {
					$since_ids = self::rses_electors_logged_in_since( $opened_at );
					$voters    = EncryptedVoteRepository::rses_unique_voter_ids( $round_id );
					$voted_set = array_flip( $voters );
					foreach ( $since_ids as $uid ) {
						if ( isset( $voted_set[ $uid ] ) ) {
							++$voted;
						}
						if ( self::rses_has_active_session( $uid ) ) {
							++$online;
						}
					}
				}
			}
		}

		return array(
			'electors_total'              => count( self::rses_elector_ids() ),
			'electors_logged_in'          => count( $logged_in ),
			'electors_since_open'         => count( $since_ids ),
			'electors_since_open_voted'   => $voted,
			'electors_since_open_online'  => $online,
			'opened_at'                   => $opened_at,
			'election_id'                 => $election_id,
			'round_id'                    => $round_id,
		);
	}
}
