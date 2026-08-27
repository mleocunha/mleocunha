<?php
/**
 * Plugin activation handler.
 *
 * @package RelataSoft\SecureElectionSuite\Bootstrap
 */

namespace RelataSoft\SecureElectionSuite\Bootstrap;

use RelataSoft\SecureElectionSuite\Frontend\JourneySettings;
use RelataSoft\SecureElectionSuite\Frontend\VoterJourney;
use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Platform\PlatformUrlMask;
use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\User\GestorRoleRegistrar;

defined( 'ABSPATH' ) || exit;

/**
 * Handles plugin activation.
 */
class Activator {

	/**
	 * Activate the plugin.
	 */
	public static function activate(): void {
		self::rses_validate_dependencies();

		Migration::rses_install();

		if ( ! get_option( 'rses_mode' ) ) {
			add_option( 'rses_mode', '' );
		}

		if ( ! get_option( 'rses_mode_locked' ) ) {
			add_option( 'rses_mode_locked', '0' );
		}

		if ( ! get_option( 'rses_settings' ) ) {
			add_option( 'rses_settings', JourneySettings::rses_defaults() );
		} else {
			$rses_existing = get_option( 'rses_settings', array() );
			if ( is_array( $rses_existing ) ) {
				update_option( 'rses_settings', array_merge( JourneySettings::rses_defaults(), $rses_existing ) );
			}
		}

		if ( ModeLock::rses_is_mode( ModeLock::RSES_MODE_VOTING ) ) {
			VoterJourney::rses_provision_pages();
		}

		GestorRoleRegistrar::ensureRole();

		PlatformUrlMask::writeLoginStub();
		PlatformUrlMask::writeAdminAlias();
		PlatformUrlMask::writeHtaccessRules();

		flush_rewrite_rules();
	}

	/**
	 * Validate hard dependencies on activation.
	 *
	 * @throws \Error If critical dependencies are missing.
	 */
	private static function rses_validate_dependencies(): void {
		$rses_errors = array();

		if ( version_compare( PHP_VERSION, '8.2', '<' ) ) {
			$rses_errors[] = __( 'PHP 8.2 or higher is required.', 'relatasoft-secure-election-suite' );
		}

		if ( ! extension_loaded( 'gmp' ) ) {
			$rses_errors[] = __( 'The GMP extension is required for cryptographic operations.', 'relatasoft-secure-election-suite' );
			update_option( 'rses_gmp_missing_notice', '1' );
		}

		if ( ! extension_loaded( 'json' ) ) {
			$rses_errors[] = __( 'The JSON extension is required.', 'relatasoft-secure-election-suite' );
		}

		if ( ! function_exists( 'hash' ) || ! in_array( 'sha256', hash_algos(), true ) ) {
			$rses_errors[] = __( 'SHA-256 hash functions are required.', 'relatasoft-secure-election-suite' );
		}

		if ( ! empty( $rses_errors ) ) {
			deactivate_plugins( RSES_PLUGIN_BASENAME );
			wp_die(
				esc_html( implode( ' ', $rses_errors ) ),
				esc_html__( 'RelataSoft Secure Election Suite Activation Error', 'relatasoft-secure-election-suite' ),
				array( 'back_link' => true )
			);
		}
	}
}
