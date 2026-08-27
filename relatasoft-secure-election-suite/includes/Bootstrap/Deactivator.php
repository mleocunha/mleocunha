<?php
/**
 * Plugin deactivation handler.
 *
 * @package RelataSoft\SecureElectionSuite\Bootstrap
 */

namespace RelataSoft\SecureElectionSuite\Bootstrap;

use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Platform\PlatformUrlMask;

defined( 'ABSPATH' ) || exit;

/**
 * Handles plugin deactivation.
 */
class Deactivator {

	/**
	 * Deactivate the plugin.
	 */
	public static function deactivate(): void {
		PlatformUrlMask::removeLoginStub();
		PlatformUrlMask::clearHtaccessRules();
		flush_rewrite_rules();
	}
}
