<?php
/**
 * Main plugin bootstrap.
 *
 * @package DecentralizedEvoting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates node-specific modules.
 */
class EVote_Plugin {

	/**
	 * @var EVote_Plugin|null
	 */
	private static $instance = null;

	/**
	 * @return EVote_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'init' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
	}

	/**
	 * Load text domain and node modules.
	 */
	public function init() {
		load_plugin_textdomain( 'decentralized-evoting', false, dirname( plugin_basename( EVOTE_PLUGIN_FILE ) ) . '/languages' );

		if ( EVote_Node::is( EVote_Node::TYPE_POLLING ) ) {
			EVote_Post_Types::register_hooks();
			EVote_Election_Meta::register_hooks();
			EVote_Polling_Admin::register_hooks();
			EVote_Voting::register_hooks();
		}

		if ( EVote_Node::is( EVote_Node::TYPE_GENERATOR ) ) {
			EVote_Generator_Admin::register_hooks();
		}

		if ( EVote_Node::is( EVote_Node::TYPE_TALLY ) ) {
			EVote_Tally_Admin::register_hooks();
		}

		EVote_Admin_Menu::register_hooks();
	}

	/**
	 * Warn when node type is not set in wp-config.
	 */
	public function admin_notices() {
		if ( ! current_user_can( 'manage_options' ) || EVote_Node::is_configured() ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__(
				'Decentralized E-Voting: define EVOTE_NODE_TYPE in wp-config.php (generator, polling, or tally). Running in polling mode by default.',
				'decentralized-evoting'
			)
		);
	}
}
