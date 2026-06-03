<?php
/**
 * Plugin Name:       Decentralized E-Voting System
 * Plugin URI:        https://github.com/mleocunha/decentralized-evoting
 * Description:       Segregated-node e-voting: key generation, polling station, and tally board. Set EVOTE_NODE_TYPE in wp-config.php.
 * Version:           0.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            mleocunha
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       decentralized-evoting
 *
 * @package DecentralizedEvoting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EVOTE_VERSION', '0.2.0' );
define( 'EVOTE_PLUGIN_FILE', __FILE__ );
define( 'EVOTE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EVOTE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once EVOTE_PLUGIN_DIR . 'includes/class-evote-autoloader.php';
EVote_Autoloader::register();

require_once EVOTE_PLUGIN_DIR . 'vendor/autoload.php';

register_activation_hook( __FILE__, array( 'EVote_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'EVote_Activator', 'deactivate' ) );

EVote_Plugin::instance();
