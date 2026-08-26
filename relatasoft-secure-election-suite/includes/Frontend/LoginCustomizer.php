<?php
/**
 * @deprecated 1.0.28 Login branding moved to Painel\Adapters\WordPress\Branding\WordPressLoginBranding.
 *
 * Kept temporarily so older references do not fatal; no longer registered by Plugin::run().
 *
 * @package RelataSoft\SecureElectionSuite\Frontend
 */

namespace RelataSoft\SecureElectionSuite\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * @deprecated 1.0.28
 */
class LoginCustomizer {

	/**
	 * No-op — branding is handled by the Painel module.
	 */
	public static function register(): void {
		// Intentionally empty.
	}
}
