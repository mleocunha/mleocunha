<?php
/**
 * Capability checks for role mapping.
 *
 * @package RelataSoft\SecureElectionSuite\Security
 */

namespace RelataSoft\SecureElectionSuite\Security;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress role capability mapping.
 */
class Capability {

	/**
	 * WordPress role required to cast a ballot.
	 *
	 * Intentionally a role slug, not a capability: administrators and editors
	 * inherit `read`, so capability checks would incorrectly allow them to vote.
	 */
	public const RSES_VOTER_ROLE = 'subscriber';

	/**
	 * WordPress role required to tally and certify.
	 *
	 * Intentionally a role slug: do not rely solely on manage_options, which can
	 * be granted to non-administrator accounts by other plugins.
	 */
	public const RSES_ADMIN_ROLE = 'administrator';

	/**
	 * Whether a user has the Administrator role.
	 *
	 * @param int|null $user_id User ID, or null for the current user.
	 * @return bool
	 */
	public static function rses_user_has_admin_role( ?int $user_id = null ): bool {
		if ( null === $user_id ) {
			if ( ! is_user_logged_in() ) {
				return false;
			}
			$user_id = get_current_user_id();
		}

		$user_id = absint( $user_id );
		if ( $user_id < 1 ) {
			return false;
		}

		$user = get_userdata( $user_id );
		if ( ! $user || ! $user->exists() ) {
			return false;
		}

		$roles = array_map( 'strval', (array) $user->roles );
		return in_array( self::RSES_ADMIN_ROLE, $roles, true );
	}

	/**
	 * Election administrator: must hold the Administrator role.
	 *
	 * Used for tally import, decryption, certification, settings, and mode lock.
	 *
	 * @return bool
	 */
	public static function rses_can_manage_election(): bool {
		return self::rses_user_has_admin_role( null );
	}

	/**
	 * Whether the user may run tally decryption and certification.
	 *
	 * @return bool
	 */
	public static function rses_can_tally_and_certify(): bool {
		return self::rses_user_has_admin_role( null );
	}

	/**
	 * Election official / Shamir share holder (editor).
	 *
	 * @return bool
	 */
	public static function rses_is_election_official(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Candidate (contributor).
	 *
	 * @return bool
	 */
	public static function rses_is_candidate(): bool {
		return current_user_can( 'edit_posts' ) === false && current_user_can( 'read' );
	}

	/**
	 * Whether a user is enrolled as a voter (has the subscriber role).
	 *
	 * Dual-role accounts (e.g. editor + subscriber) may vote. Admin-only or
	 * editor-only accounts may not. Do not replace this with current_user_can('read').
	 *
	 * @param int|null $user_id User ID, or null for the current user.
	 * @return bool
	 */
	public static function rses_user_has_voter_role( ?int $user_id = null ): bool {
		if ( null === $user_id ) {
			if ( ! is_user_logged_in() ) {
				return false;
			}
			$user_id = get_current_user_id();
		}

		$user_id = absint( $user_id );
		if ( $user_id < 1 ) {
			return false;
		}

		$user = get_userdata( $user_id );
		if ( ! $user || ! $user->exists() ) {
			return false;
		}

		$roles = array_map( 'strval', (array) $user->roles );
		return in_array( self::RSES_VOTER_ROLE, $roles, true );
	}

	/**
	 * Voter eligibility: logged in and enrolled with the subscriber role.
	 *
	 * @return bool
	 */
	public static function rses_can_vote(): bool {
		return self::rses_user_has_voter_role( null );
	}

	/**
	 * Can export own Shamir share.
	 *
	 * @return bool
	 */
	public static function rses_can_export_own_share(): bool {
		return self::rses_is_election_official();
	}

	/**
	 * Can export all shares (admin only).
	 *
	 * @return bool
	 */
	public static function rses_can_export_all_shares(): bool {
		if ( ! self::rses_can_manage_election() ) {
			return false;
		}

		$rses_settings = get_option( 'rses_settings', array() );
		return ! empty( $rses_settings['allow_full_private_export'] );
	}

	/**
	 * Require Administrator role or die.
	 */
	public static function rses_require_admin(): void {
		if ( ! self::rses_can_manage_election() ) {
			wp_die(
				esc_html__( 'Only users with the Administrator role may perform this action.', 'relatasoft-secure-election-suite' ),
				esc_html__( 'Permission Denied', 'relatasoft-secure-election-suite' ),
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Require Administrator role for tally/certify operations or die.
	 */
	public static function rses_require_tally_admin(): void {
		if ( ! self::rses_can_tally_and_certify() ) {
			wp_die(
				esc_html__( 'Only users with the Administrator role may import tallies, decrypt results, or certify an election. Election officials may submit Shamir shares only.', 'relatasoft-secure-election-suite' ),
				esc_html__( 'Permission Denied', 'relatasoft-secure-election-suite' ),
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Require official capability or die.
	 */
	public static function rses_require_official(): void {
		if ( ! self::rses_is_election_official() ) {
			wp_die(
				esc_html__( 'You do not have permission to perform this action.', 'relatasoft-secure-election-suite' ),
				esc_html__( 'Permission Denied', 'relatasoft-secure-election-suite' ),
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Require enrolled voter (subscriber role) or die.
	 */
	public static function rses_require_voter(): void {
		if ( ! self::rses_can_vote() ) {
			wp_die(
				esc_html__( 'Only users enrolled with the Subscriber role may cast a ballot. Administrator and Editor accounts cannot vote unless they also have the Subscriber role.', 'relatasoft-secure-election-suite' ),
				esc_html__( 'Permission Denied', 'relatasoft-secure-election-suite' ),
				array( 'response' => 403 )
			);
		}
	}
}
