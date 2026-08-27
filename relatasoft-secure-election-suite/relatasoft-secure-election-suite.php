<?php
/**
 * Plugin Name:       Voto Eletrônico by RelataSoft
 * Plugin URI:        https://relatasoft.com/secure-election-suite
 * Description:       Painel de Controle Eleitoral — gestão democrática, auditável e criptograficamente garantida (RSES international codebase).
 * Version:           1.0.35
 * Requires at least: 6.0
 * Requires PHP:      8.2
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

define( 'RSES_VERSION', '1.0.35' );
define( 'RSES_PLUGIN_FILE', __FILE__ );
define( 'RSES_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RSES_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'RSES_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * PSR-4 style autoloader for RelataSoft\SecureElectionSuite namespace.
 *
 * Loads legacy modules from includes/ and the Painel module from src/.
 *
 * @param string $class_name Fully qualified class name.
 */
function rses_autoload( string $class_name ): void {
	$prefix = 'RelataSoft\\SecureElectionSuite\\';
	if ( strncmp( $prefix, $class_name, strlen( $prefix ) ) !== 0 ) {
		return;
	}

	$relative_class = substr( $class_name, strlen( $prefix ) );

	// Painel module: RelataSoft\SecureElectionSuite\Painel\... → src/...
	if ( strncmp( 'Painel\\', $relative_class, 7 ) === 0 ) {
		$painel_relative = substr( $relative_class, 7 );
		$file            = RSES_PLUGIN_DIR . 'src/' . str_replace( '\\', '/', $painel_relative ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
		return;
	}

	$file = RSES_PLUGIN_DIR . 'includes/' . str_replace( '\\', '/', $relative_class ) . '.php';
	if ( is_readable( $file ) ) {
		require_once $file;
	}
}

spl_autoload_register( 'rses_autoload' );

register_activation_hook( __FILE__, array( 'RelataSoft\\SecureElectionSuite\\Bootstrap\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'RelataSoft\\SecureElectionSuite\\Bootstrap\\Deactivator', 'deactivate' ) );

// Materialize /painel gateway as early as this plugin loads (no symlinks).
if ( class_exists( 'RelataSoft\\SecureElectionSuite\\Painel\\Adapters\\WordPress\\Platform\\PlatformUrlMask', false )
	|| true
) {
	// Autoload the class, then ensure gateway + mu-plugin copy.
	$rses_mask = 'RelataSoft\\SecureElectionSuite\\Painel\\Adapters\\WordPress\\Platform\\PlatformUrlMask';
	if ( class_exists( $rses_mask ) ) {
		$rses_mask::writeAdminGateway();
		$rses_mask::writeLoginStub();
		$rses_mask::installMuPlugin();
	}
}

RelataSoft\SecureElectionSuite\Bootstrap\Plugin::instance()->run();
