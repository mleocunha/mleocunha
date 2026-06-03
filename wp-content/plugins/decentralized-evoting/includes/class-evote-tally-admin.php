<?php
/**
 * Node 3 (tally) admin — key ceremony and reconstruction.
 *
 * @package DecentralizedEvoting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tally board: SSS reconstruction (ballot import in Phase 3).
 */
class EVote_Tally_Admin {

	/**
	 * Register hooks.
	 */
	public static function register_hooks() {
		add_action( 'admin_post_evote_reconstruct_key', array( __CLASS__, 'handle_reconstruct' ) );
	}

	/**
	 * Tally page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$result = get_transient( self::result_transient_key() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Tally Board', 'decentralized-evoting' ); ?></h1>
			<p><?php esc_html_e( 'Paste trustee share JSON files (at least t shares) to reconstruct the ElGamal private key. The reconstructed key is shown once and not stored.', 'decentralized-evoting' ); ?></p>

			<h2><?php esc_html_e( 'Key ceremony', 'decentralized-evoting' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'evote_reconstruct_key' ); ?>
				<input type="hidden" name="action" value="evote_reconstruct_key" />
				<p>
					<label for="evote_shares_json"><strong><?php esc_html_e( 'Share JSON (one array or one object per line)', 'decentralized-evoting' ); ?></strong></label>
					<textarea name="evote_shares_json" id="evote_shares_json" class="large-text code" rows="12" placeholder='[{"schema":"evote-sss-share",...}, ...]'></textarea>
				</p>
				<?php submit_button( __( 'Reconstruct private key', 'decentralized-evoting' ) ); ?>
			</form>

			<?php if ( is_array( $result ) ) : ?>
				<div class="notice notice-success">
					<p><?php esc_html_e( 'Reconstruction succeeded. Copy the private key now — it will not be shown again after you leave this page.', 'decentralized-evoting' ); ?></p>
				</div>
				<pre class="code" style="background:#f6f7f7;padding:1em;"><?php echo esc_html( wp_json_encode( $result, JSON_PRETTY_PRINT ) ); ?></pre>
			<?php endif; ?>

			<hr />
			<h2><?php esc_html_e( 'Ballot import (Phase 3)', 'decentralized-evoting' ); ?></h2>
			<pre class="code" style="background:#f6f7f7;padding:1em;"><?php echo esc_html( wp_json_encode( EVote_Json_Payloads::ballot_export_skeleton(), JSON_PRETTY_PRINT ) ); ?></pre>
		</div>
		<?php
	}

	/**
	 * Handle share paste and reconstruction.
	 */
	public static function handle_reconstruct() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'decentralized-evoting' ) );
		}
		check_admin_referer( 'evote_reconstruct_key' );

		$raw = isset( $_POST['evote_shares_json'] ) ? wp_unslash( $_POST['evote_shares_json'] ) : '';
		$shares = self::parse_shares_input( $raw );
		if ( is_wp_error( $shares ) ) {
			wp_die( esc_html( $shares->get_error_message() ) );
		}

		$result = EVote_Crypto::reconstruct_private_key( $shares );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}

		set_transient( self::result_transient_key(), $result, 120 );
		wp_safe_redirect( admin_url( 'admin.php?page=evote-tally' ) );
		exit;
	}

	/**
	 * @param string $raw User input.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private static function parse_shares_input( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return new WP_Error( 'evote_empty', __( 'No share data provided.', 'decentralized-evoting' ) );
		}

		$decoded = json_decode( $raw, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error( 'evote_json', __( 'Invalid JSON.', 'decentralized-evoting' ) );
		}

		if ( isset( $decoded['schema'] ) ) {
			return array( $decoded );
		}
		if ( is_array( $decoded ) && isset( $decoded[0] ) ) {
			return $decoded;
		}

		return new WP_Error( 'evote_json_format', __( 'Provide a JSON array of shares or a single share object.', 'decentralized-evoting' ) );
	}

	/**
	 * @return string
	 */
	private static function result_transient_key() {
		return 'evote_reconstruct_' . get_current_user_id();
	}
}
