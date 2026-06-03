<?php
/**
 * Node 3 (tally) admin — import and key ceremony placeholder.
 *
 * @package DecentralizedEvoting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tally board site UI (Phase 2–3).
 */
class EVote_Tally_Admin {

	/**
	 * Register hooks.
	 */
	public static function register_hooks() {
		// Menu registered from EVote_Admin_Menu.
	}

	/**
	 * Tally page (stub).
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Tally Board', 'decentralized-evoting' ); ?></h1>
			<p><?php esc_html_e( 'Phase 3 will add encrypted ballot import, trustee share entry, private key reconstruction, and tally.', 'decentralized-evoting' ); ?></p>
			<h2><?php esc_html_e( 'Ballot import schema (preview)', 'decentralized-evoting' ); ?></h2>
			<pre class="code" style="background:#f6f7f7;padding:1em;"><?php echo esc_html( wp_json_encode( EVote_Json_Payloads::ballot_export_skeleton(), JSON_PRETTY_PRINT ) ); ?></pre>
		</div>
		<?php
	}
}
