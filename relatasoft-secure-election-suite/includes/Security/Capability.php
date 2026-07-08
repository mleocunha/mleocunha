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
	 * Election administrator (manage_options).
	 *
	 * @return bool
	 */
	public static function rses_can_manage_election(): bool {
		return current_user_can( 'manage_options' );
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
	 * Voter (subscriber, logged in).
	 *
	 * @return bool
	 */
	public static function rses_can_vote(): bool {
		return is_user_logged_in() && current_user_can( 'read' );
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
	 * Require election admin capability or die.
	 */
	public static function rses_require_admin(): void {
		if ( ! self::rses_can_manage_election() ) {
			wp_die(
				esc_html__( 'You do not have permission to perform this action.', 'relatasoft-secure-election-suite' ),
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
	 * Require voter capability or die.
	 */
	public static function rses_require_voter(): void {
		if ( ! self::rses_can_vote() ) {
			wp_die(
				esc_html__( 'You must be logged in as a voter to perform this action.', 'relatasoft-secure-election-suite' ),
				esc_html__( 'Permission Denied', 'relatasoft-secure-election-suite' ),
				array( 'response' => 403 )
			);
		}
	}
}
