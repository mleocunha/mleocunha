<?php
/**
 * Node 1 (generator) admin — key generation UI placeholder.
 *
 * @package DecentralizedEvoting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Authority site: ElGamal + SSS (Phase 2).
 */
class EVote_Generator_Admin {

	/**
	 * Register hooks.
	 */
	public static function register_hooks() {
		// Menu registered from EVote_Admin_Menu.
	}

	/**
	 * Key generation page (stub).
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'ElGamal Key Generation', 'decentralized-evoting' ); ?></h1>
			<p><?php esc_html_e( 'Phase 2 will add polynomial degree, SSS threshold, and secure export using phpseclib-backed math.', 'decentralized-evoting' ); ?></p>
			<h2><?php esc_html_e( 'Public key export schema (preview)', 'decentralized-evoting' ); ?></h2>
			<pre class="code" style="background:#f6f7f7;padding:1em;"><?php echo esc_html( wp_json_encode( EVote_Json_Payloads::public_key_skeleton(), JSON_PRETTY_PRINT ) ); ?></pre>
			<h2><?php esc_html_e( 'SSS share export schema (preview)', 'decentralized-evoting' ); ?></h2>
			<pre class="code" style="background:#f6f7f7;padding:1em;"><?php echo esc_html( wp_json_encode( EVote_Json_Payloads::sss_share_skeleton(), JSON_PRETTY_PRINT ) ); ?></pre>
		</div>
		<?php
	}
}
