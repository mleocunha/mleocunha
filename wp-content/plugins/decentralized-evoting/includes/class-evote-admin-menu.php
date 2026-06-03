<?php
/**
 * Top-level admin menu and node dashboard.
 *
 * @package DecentralizedEvoting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers shared admin pages per node.
 */
class EVote_Admin_Menu {

	/**
	 * Register hooks.
	 */
	public static function register_hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
	}

	/**
	 * Node dashboard under Settings or standalone.
	 */
	public static function register_menu() {
		add_menu_page(
			__( 'E-Voting System', 'decentralized-evoting' ),
			__( 'E-Voting', 'decentralized-evoting' ),
			'manage_options',
			'evote-dashboard',
			array( __CLASS__, 'render_dashboard' ),
			'dashicons-shield',
			3
		);

		if ( EVote_Node::is( EVote_Node::TYPE_POLLING ) ) {
			add_submenu_page(
				'evote-dashboard',
				__( 'Polling Tools', 'decentralized-evoting' ),
				__( 'Polling Tools', 'decentralized-evoting' ),
				'manage_options',
				'evote-export',
				array( 'EVote_Polling_Admin', 'render_export_page' )
			);
		}

		if ( EVote_Node::is( EVote_Node::TYPE_GENERATOR ) ) {
			add_submenu_page(
				'evote-dashboard',
				__( 'Key Generation', 'decentralized-evoting' ),
				__( 'Key Generation', 'decentralized-evoting' ),
				'manage_options',
				'evote-generator',
				array( 'EVote_Generator_Admin', 'render_page' )
			);
		}

		if ( EVote_Node::is( EVote_Node::TYPE_TALLY ) ) {
			add_submenu_page(
				'evote-dashboard',
				__( 'Tally', 'decentralized-evoting' ),
				__( 'Tally', 'decentralized-evoting' ),
				'manage_options',
				'evote-tally',
				array( 'EVote_Tally_Admin', 'render_page' )
			);
		}
	}

	/**
	 * Dashboard home.
	 */
	public static function render_dashboard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$configured = EVote_Node::is_configured();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Decentralized E-Voting', 'decentralized-evoting' ); ?></h1>
			<table class="widefat striped" style="max-width:640px;">
				<tbody>
					<tr>
						<th><?php esc_html_e( 'Node type', 'decentralized-evoting' ); ?></th>
						<td><code><?php echo esc_html( EVote_Node::get_type() ); ?></code> — <?php echo esc_html( EVote_Node::get_label() ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'wp-config constant', 'decentralized-evoting' ); ?></th>
						<td>
							<?php if ( $configured ) : ?>
								<span style="color:green;"><?php esc_html_e( 'EVOTE_NODE_TYPE is set.', 'decentralized-evoting' ); ?></span>
							<?php else : ?>
								<span style="color:#b45309;"><?php esc_html_e( 'Add define( \'EVOTE_NODE_TYPE\', \'generator\' | \'polling\' | \'tally\' );', 'decentralized-evoting' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Plugin version', 'decentralized-evoting' ); ?></th>
						<td><?php echo esc_html( EVOTE_VERSION ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'phpseclib', 'decentralized-evoting' ); ?></th>
						<td>
							<?php
							$test = EVote_Crypto::self_test();
							if ( is_wp_error( $test ) ) {
								echo '<span style="color:#b91c1c;">' . esc_html( $test->get_error_message() ) . '</span>';
							} else {
								esc_html_e( 'Loaded and operational.', 'decentralized-evoting' );
							}
							?>
						</td>
					</tr>
				</tbody>
			</table>
			<?php if ( EVote_Node::is( EVote_Node::TYPE_POLLING ) ) : ?>
				<p><?php esc_html_e( 'Manage runnings, candidates, and modalities from the E-Voting menu. Electors and encrypted ballots use custom database tables.', 'decentralized-evoting' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

}
