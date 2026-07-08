<?php
/**
 * AJAX router.
 *
 * @package RelataSoft\SecureElectionSuite\Ajax
 */

namespace RelataSoft\SecureElectionSuite\Ajax;

defined( 'ABSPATH' ) || exit;

/**
 * Central AJAX registration (vote casting handled in BallotController).
 */
class AjaxRouter {

	/**
	 * Register AJAX hooks.
	 */
	public static function register(): void {
		add_action( 'wp_ajax_rses_crypto_self_test_status', array( self::class, 'rses_crypto_status' ) );
	}

	/**
	 * Return GMP availability status.
	 */
	public static function rses_crypto_status(): void {
		check_ajax_referer( 'rses_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}

		wp_send_json_success(
			array(
				'gmp' => extension_loaded( 'gmp' ),
				'zip' => class_exists( 'ZipArchive' ),
			)
		);
	}
}
