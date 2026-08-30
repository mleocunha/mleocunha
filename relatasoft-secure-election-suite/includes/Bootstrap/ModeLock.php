<?php
/**
 * Mode lock management.
 *
 * @package RelataSoft\SecureElectionSuite\Bootstrap
 */

namespace RelataSoft\SecureElectionSuite\Bootstrap;

use RelataSoft\SecureElectionSuite\Database\Repository;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Mode\SiteModes;
use RelataSoft\SecureElectionSuite\Security\AuditLogger;
use RelataSoft\SecureElectionSuite\Security\Capability;

defined( 'ABSPATH' ) || exit;

/**
 * Manages mutually exclusive plugin modes (1 sítio = 1 modo; E3 / C1).
 */
class ModeLock {

	public const RSES_MODE_KEY_AUTHORITY = SiteModes::KEY_AUTHORITY;
	public const RSES_MODE_VOTING        = SiteModes::VOTING;
	public const RSES_MODE_TALLYING      = SiteModes::TALLYING;

	/**
	 * Get valid modes (slug => label PT-BR).
	 *
	 * @return array<string,string>
	 */
	public static function rses_get_valid_modes(): array {
		$out = array();
		foreach ( SiteModes::all() as $slug ) {
			$out[ $slug ] = SiteModes::label( $slug );
		}
		return $out;
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
		return '' !== $mode && SiteModes::isValid( $mode );
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

		if ( ! SiteModes::isValid( $mode ) ) {
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
		return SiteModes::isValid( $mode ) ? SiteModes::label( $mode ) : $mode;
	}
}
