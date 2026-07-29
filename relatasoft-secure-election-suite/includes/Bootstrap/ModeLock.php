<?php
/**
 * Mode lock management.
 *
 * @package RelataSoft\SecureElectionSuite\Bootstrap
 */

namespace RelataSoft\SecureElectionSuite\Bootstrap;

use RelataSoft\SecureElectionSuite\Database\Repository;
use RelataSoft\SecureElectionSuite\Security\AuditLogger;
use RelataSoft\SecureElectionSuite\Security\Capability;

defined( 'ABSPATH' ) || exit;

/**
 * Manages mutually exclusive plugin modes.
 */
class ModeLock {

	public const RSES_MODE_KEY_AUTHORITY = 'key_authority';
	public const RSES_MODE_VOTING        = 'voting';
	public const RSES_MODE_TALLYING      = 'tallying';

	/**
	 * Valid modes.
	 *
	 * @var array<string,string>
	 */
	private static array $rses_valid_modes = array(
		self::RSES_MODE_KEY_AUTHORITY => 'Key Authority / ElGamal Key Manager',
		self::RSES_MODE_VOTING        => 'Voting Platform',
		self::RSES_MODE_TALLYING      => 'Tallying and Certification Platform',
	);

	/**
	 * Get valid modes.
	 *
	 * @return array<string,string>
	 */
	public static function rses_get_valid_modes(): array {
		return self::$rses_valid_modes;
	}

	/**
	 * Get current mode.
	 *
	 * @return string
	 */
	public static function rses_get_mode(): string {
		return (string) get_option( 'rses_mode', '' );
	}

	/**
	 * Check if mode is locked.
	 *
	 * @return bool
	 */
	public static function rses_is_locked(): bool {
		return '1' === get_option( 'rses_mode_locked', '0' );
	}

	/**
	 * Check if a specific mode is active.
	 *
	 * @param string $mode Mode slug.
	 * @return bool
	 */
	public static function rses_is_mode( string $mode ): bool {
		return self::rses_get_mode() === $mode;
	}

	/**
	 * Check if mode is set.
	 *
	 * @return bool
	 */
	public static function rses_has_mode(): bool {
		$mode = self::rses_get_mode();
		return ! empty( $mode ) && isset( self::$rses_valid_modes[ $mode ] );
	}

	/**
	 * Set mode (only when not locked).
	 *
	 * @param string $mode Mode slug.
	 * @return bool
	 */
	public static function rses_set_mode( string $mode ): bool {
		if ( ! Capability::rses_can_manage_election() ) {
			return false;
		}

		if ( self::rses_is_locked() ) {
			return false;
		}

		if ( ! isset( self::$rses_valid_modes[ $mode ] ) ) {
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
	 * Require a specific mode or die with message.
	 *
	 * @param string $mode Required mode.
	 * @return void
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
	 * Perform destructive reset to allow mode change.
	 *
	 * @return bool
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
	 * Get mode display label.
	 *
	 * @param string $mode Mode slug.
	 * @return string
	 */
	public static function rses_get_mode_label( string $mode ): string {
		$labels = array(
			self::RSES_MODE_KEY_AUTHORITY => __( 'Key Authority / ElGamal Key Manager', 'relatasoft-secure-election-suite' ),
			self::RSES_MODE_VOTING        => __( 'Voting Platform', 'relatasoft-secure-election-suite' ),
			self::RSES_MODE_TALLYING      => __( 'Tallying and Certification Platform', 'relatasoft-secure-election-suite' ),
		);

		return $labels[ $mode ] ?? $mode;
	}
}
