<?php
/**
 * Plugin Name:       RelataSoft Secure Election Suite
 * Plugin URI:        https://relatasoft.com/secure-election-suite
 * Description:       Production-oriented secure election platform with ElGamal encryption and Shamir Secret Sharing.
 * Version:           1.0.25
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            RelataSoft
 * Author URI:        https://relatasoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       relatasoft-secure-election-suite
 * Domain Path:       /languages
 *
 * @package RelataSoft\SecureElectionSuite
 */

defined( 'ABSPATH' ) || exit;

define( 'RSES_VERSION', '1.0.25' );
define( 'RSES_PLUGIN_FILE', __FILE__ );
define( 'RSES_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RSES_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'RSES_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * PSR-4 style autoloader for RelataSoft\SecureElectionSuite namespace.
 *
 * @param string $class_name Fully qualified class name.
 */
function rses_autoload( string $class_name ): void {
	$prefix   = 'RelataSoft\\SecureElectionSuite\\';
	$base_dir = RSES_PLUGIN_DIR . 'includes/';

	if ( strncmp( $prefix, $class_name, strlen( $prefix ) ) !== 0 ) {
		return;
	}

	$relative_class = substr( $class_name, strlen( $prefix ) );
	$file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

	if ( is_readable( $file ) ) {
		require_once $file;
	}
}

spl_autoload_register( 'rses_autoload' );

register_activation_hook( __FILE__, array( 'RelataSoft\\SecureElectionSuite\\Bootstrap\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'RelataSoft\\SecureElectionSuite\\Bootstrap\\Deactivator', 'deactivate' ) );

RelataSoft\SecureElectionSuite\Bootstrap\Plugin::instance()->run();
