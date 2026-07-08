<?php
/**
 * Plugin deactivation handler.
 *
 * @package RelataSoft\SecureElectionSuite\Bootstrap
 */

namespace RelataSoft\SecureElectionSuite\Bootstrap;

defined( 'ABSPATH' ) || exit;

/**
 * Handles plugin deactivation.
 */
class Deactivator {

	/**
	 * Deactivate the plugin.
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();
	}
}
