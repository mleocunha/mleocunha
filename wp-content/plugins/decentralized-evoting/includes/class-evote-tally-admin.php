<?php
/**
 * Node 3 (tally) admin — key ceremony, encrypt/decrypt helpers.
 *
 * @package DecentralizedEvoting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tally board: reconstruction, import, tally, crypto helpers.
 */
class EVote_Tally_Admin {

	const TRANSIENT_RECONSTRUCT = 'evote_reconstruct_';
	const TRANSIENT_ENCRYPT     = 'evote_tally_encrypt_';
	const TRANSIENT_DECRYPT     = 'evote_tally_decrypt_';
	const TRANSIENT_TALLY       = 'evote_tally_result_';
	const TRANSIENT_IMPORT      = 'evote_tally_import_';
	const TRANSIENT_TTL         = 300;

	/**
	 * Register hooks.
	 */
	public static function register_hooks() {
		add_action( 'admin_post_evote_reconstruct_key', array( __CLASS__, 'handle_reconstruct' ) );
		add_action( 'admin_post_evote_tally_encrypt', array( __CLASS__, 'handle_encrypt' ) );
		add_action( 'admin_post_evote_tally_decrypt', array( __CLASS__, 'handle_decrypt' ) );
		add_action( 'admin_post_evote_tally_ballot_box', array( __CLASS__, 'handle_tally_ballot_box' ) );
	}

	/**
	 * Tally page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$reconstruct     = get_transient( self::transient_key( self::TRANSIENT_RECONSTRUCT ) );
		$encrypt_out     = get_transient( self::transient_key( self::TRANSIENT_ENCRYPT ) );
		$decrypt_out     = get_transient( self::transient_key( self::TRANSIENT_DECRYPT ) );
		$tally_result    = get_transient( self::transient_key( self::TRANSIENT_TALLY ) );
		$import_meta     = get_transient( self::transient_key( self::TRANSIENT_IMPORT ) );
		$private_prefill = is_array( $reconstruct ) && ! empty( $reconstruct['private'] ) ? $reconstruct['private'] : '';
		$error           = isset( $_GET['evote_error'] ) ? sanitize_text_field( wp_unslash( $_GET['evote_error'] ) ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Tally Board', 'decentralized-evoting' ); ?></h1>
			<?php if ( $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'Import the ballot box from the polling station, reconstruct the private key, and run the tally.', 'decentralized-evoting' ); ?></p>

			<h2><?php esc_html_e( 'Import ballot box & tally', 'decentralized-evoting' ); ?></h2>
			<?php if ( is_array( $import_meta ) ) : ?>
				<p class="description">
					<?php
					printf(
						/* translators: 1: running title, 2: ballot count */
						esc_html__( 'Loaded export: %1$s — %2$d ballots (checksum verified).', 'decentralized-evoting' ),
						esc_html( $import_meta['title'] ?? '' ),
						(int) ( $import_meta['ballot_count'] ?? 0 )
					);
					?>
				</p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'evote_tally_ballot_box' ); ?>
				<input type="hidden" name="action" value="evote_tally_ballot_box" />
				<p>
					<label for="evote_ballot_export"><strong><?php esc_html_e( 'Ballot export JSON (from Node 2)', 'decentralized-evoting' ); ?></strong></label>
					<textarea name="evote_ballot_export" id="evote_ballot_export" class="large-text code" rows="12"></textarea>
				</p>
				<p>
					<label for="evote_tally_private"><strong><?php esc_html_e( 'Private key (hex)', 'decentralized-evoting' ); ?></strong></label>
					<textarea name="evote_private_hex" id="evote_tally_private" class="large-text code" rows="3"><?php echo esc_textarea( $private_prefill ); ?></textarea>
				</p>
				<?php submit_button( __( 'Verify import & run tally', 'decentralized-evoting' ), 'primary' ); ?>
			</form>

			<?php if ( is_array( $tally_result ) ) : ?>
				<h3><?php esc_html_e( 'Tally results', 'decentralized-evoting' ); ?></h3>
				<?php if ( isset( $tally_result['verify_match'] ) ) : ?>
					<p class="description">
						<?php
						echo $tally_result['verify_match']
							? esc_html__( 'Verificação homomórfica: contagens batem com decrypt-then-count nos slots.', 'decentralized-evoting' )
							: esc_html__( 'Verificação homomórfica: divergência — revise o export ou a chave privada.', 'decentralized-evoting' );
						?>
					</p>
				<?php endif; ?>
				<pre class="code" style="background:#f6f7f7;padding:1em;max-height:400px;overflow:auto;"><?php echo esc_html( wp_json_encode( $tally_result, JSON_PRETTY_PRINT ) ); ?></pre>
			<?php endif; ?>

			<hr />

			<h2><?php esc_html_e( 'Key ceremony', 'decentralized-evoting' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'evote_reconstruct_key' ); ?>
				<input type="hidden" name="action" value="evote_reconstruct_key" />
				<p>
					<label for="evote_shares_json"><strong><?php esc_html_e( 'Share JSON', 'decentralized-evoting' ); ?></strong></label>
					<textarea name="evote_shares_json" id="evote_shares_json" class="large-text code" rows="10" placeholder='[{"schema":"evote-sss-share",...}, ...]'></textarea>
				</p>
				<?php submit_button( __( 'Reconstruct private key', 'decentralized-evoting' ) ); ?>
			</form>

			<?php if ( is_array( $reconstruct ) ) : ?>
				<div class="notice notice-success inline"><p><?php esc_html_e( 'Private key reconstructed (shown below for copy/paste into decrypt helper). Not stored permanently.', 'decentralized-evoting' ); ?></p></div>
				<pre class="code" style="background:#f6f7f7;padding:1em;max-height:200px;overflow:auto;"><?php echo esc_html( wp_json_encode( $reconstruct, JSON_PRETTY_PRINT ) ); ?></pre>
			<?php endif; ?>

			<hr />

			<h2><?php esc_html_e( 'Encrypt helper (test ballot)', 'decentralized-evoting' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Build an encrypted ballot JSON from the election public key and a vote integer (e.g. candidate ID). Use this to test decryption before processing a real ballot box.', 'decentralized-evoting' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'evote_tally_encrypt' ); ?>
				<input type="hidden" name="action" value="evote_tally_encrypt" />
				<p>
					<label for="evote_encrypt_pubkey"><strong><?php esc_html_e( 'Public key JSON', 'decentralized-evoting' ); ?></strong></label>
					<textarea name="evote_public_key_json" id="evote_encrypt_pubkey" class="large-text code" rows="8" placeholder='{"schema":"evote-public-key",...}'></textarea>
				</p>
				<p>
					<label for="evote_vote_integer"><strong><?php esc_html_e( 'Vote integer', 'decentralized-evoting' ); ?></strong></label>
					<input type="number" name="evote_vote_integer" id="evote_vote_integer" min="1" value="1" class="small-text" />
					<span class="description"><?php esc_html_e( 'Encoded as the ElGamal message (same as future client-side encryption).', 'decentralized-evoting' ); ?></span>
				</p>
				<?php submit_button( __( 'Encrypt', 'decentralized-evoting' ), 'secondary' ); ?>
			</form>

			<?php if ( is_array( $encrypt_out ) ) : ?>
				<h3><?php esc_html_e( 'Ciphertext output', 'decentralized-evoting' ); ?></h3>
				<pre class="code" style="background:#f6f7f7;padding:1em;max-height:320px;overflow:auto;"><?php echo esc_html( wp_json_encode( $encrypt_out, JSON_PRETTY_PRINT ) ); ?></pre>
			<?php endif; ?>

			<hr />

			<h2><?php esc_html_e( 'Decrypt helper', 'decentralized-evoting' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Decrypt a single encrypted ballot with the reconstructed private key. Public key JSON is optional (defaults to RFC 3526 Group 14).', 'decentralized-evoting' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'evote_tally_decrypt' ); ?>
				<input type="hidden" name="action" value="evote_tally_decrypt" />
				<p>
					<label for="evote_decrypt_private"><strong><?php esc_html_e( 'Private key (hex)', 'decentralized-evoting' ); ?></strong></label>
					<textarea name="evote_private_hex" id="evote_decrypt_private" class="large-text code" rows="3" placeholder="<?php esc_attr_e( 'From reconstruction above', 'decentralized-evoting' ); ?>"><?php echo esc_textarea( $private_prefill ); ?></textarea>
				</p>
				<p>
					<label for="evote_decrypt_pubkey"><strong><?php esc_html_e( 'Public key JSON (optional)', 'decentralized-evoting' ); ?></strong></label>
					<textarea name="evote_public_key_json" id="evote_decrypt_pubkey" class="large-text code" rows="6" placeholder="<?php esc_attr_e( 'Leave empty if using RFC 3526 Group 14', 'decentralized-evoting' ); ?>"></textarea>
				</p>
				<p>
					<label for="evote_ballot_json"><strong><?php esc_html_e( 'Encrypted ballot JSON', 'decentralized-evoting' ); ?></strong></label>
					<textarea name="evote_ballot_json" id="evote_ballot_json" class="large-text code" rows="8" placeholder='{"schema":"evote-encrypted-ballot","c1":"...","c2":"..."}'></textarea>
				</p>
				<?php submit_button( __( 'Decrypt', 'decentralized-evoting' ), 'secondary' ); ?>
			</form>

			<?php if ( is_array( $decrypt_out ) ) : ?>
				<h3><?php esc_html_e( 'Plaintext output', 'decentralized-evoting' ); ?></h3>
				<pre class="code" style="background:#f6f7f7;padding:1em;"><?php echo esc_html( wp_json_encode( $decrypt_out, JSON_PRETTY_PRINT ) ); ?></pre>
				<?php if ( ! empty( $decrypt_out['vote_integer'] ) ) : ?>
					<p><strong><?php esc_html_e( 'Decoded vote:', 'decentralized-evoting' ); ?></strong> <code><?php echo esc_html( (string) $decrypt_out['vote_integer'] ); ?></code></p>
				<?php endif; ?>
			<?php endif; ?>

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

		$raw    = isset( $_POST['evote_shares_json'] ) ? wp_unslash( $_POST['evote_shares_json'] ) : '';
		$shares = self::parse_shares_input( $raw );
		if ( is_wp_error( $shares ) ) {
			self::redirect_with_error( $shares->get_error_message() );
		}

		$result = EVote_Crypto::reconstruct_private_key( $shares );
		if ( is_wp_error( $result ) ) {
			self::redirect_with_error( $result->get_error_message() );
		}

		set_transient( self::transient_key( self::TRANSIENT_RECONSTRUCT ), $result, self::TRANSIENT_TTL );
		wp_safe_redirect( admin_url( 'admin.php?page=evote-tally' ) );
		exit;
	}

	/**
	 * Encrypt helper handler.
	 */
	public static function handle_encrypt() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'decentralized-evoting' ) );
		}
		check_admin_referer( 'evote_tally_encrypt' );

		$public_key = self::parse_json_field( 'evote_public_key_json' );
		if ( is_wp_error( $public_key ) ) {
			self::redirect_with_error( $public_key->get_error_message() );
		}

		$vote = isset( $_POST['evote_vote_integer'] ) ? absint( $_POST['evote_vote_integer'] ) : 0;
		if ( $vote < 1 ) {
			self::redirect_with_error( __( 'Vote integer must be at least 1.', 'decentralized-evoting' ) );
		}

		$result = EVote_Crypto::encrypt_vote( $public_key, $vote );
		if ( is_wp_error( $result ) ) {
			self::redirect_with_error( $result->get_error_message() );
		}

		set_transient( self::transient_key( self::TRANSIENT_ENCRYPT ), $result, self::TRANSIENT_TTL );
		wp_safe_redirect( admin_url( 'admin.php?page=evote-tally' ) );
		exit;
	}

	/**
	 * Decrypt helper handler.
	 */
	public static function handle_decrypt() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'decentralized-evoting' ) );
		}
		check_admin_referer( 'evote_tally_decrypt' );

		$private = isset( $_POST['evote_private_hex'] ) ? sanitize_text_field( wp_unslash( $_POST['evote_private_hex'] ) ) : '';
		$private = preg_replace( '/\s+/', '', $private );
		if ( '' === $private ) {
			self::redirect_with_error( __( 'Private key hex is required.', 'decentralized-evoting' ) );
		}

		$ballot = self::parse_json_field( 'evote_ballot_json' );
		if ( is_wp_error( $ballot ) ) {
			self::redirect_with_error( $ballot->get_error_message() );
		}

		$public_key = null;
		$pub_raw    = isset( $_POST['evote_public_key_json'] ) ? trim( wp_unslash( $_POST['evote_public_key_json'] ) ) : '';
		if ( '' !== $pub_raw ) {
			$public_key = json_decode( $pub_raw, true );
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $public_key ) ) {
				self::redirect_with_error( __( 'Invalid public key JSON.', 'decentralized-evoting' ) );
			}
		}

		$result = EVote_Crypto::decrypt_ballot( $private, $ballot, $public_key );
		if ( is_wp_error( $result ) ) {
			self::redirect_with_error( $result->get_error_message() );
		}

		set_transient( self::transient_key( self::TRANSIENT_DECRYPT ), $result, self::TRANSIENT_TTL );
		wp_safe_redirect( admin_url( 'admin.php?page=evote-tally' ) );
		exit;
	}

	/**
	 * Import ballot export and run tally.
	 */
	public static function handle_tally_ballot_box() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'decentralized-evoting' ) );
		}
		check_admin_referer( 'evote_tally_ballot_box' );

		$raw = isset( $_POST['evote_ballot_export'] ) ? wp_unslash( $_POST['evote_ballot_export'] ) : '';
		$data = EVote_Json_Import::parse( $raw );
		if ( is_wp_error( $data ) ) {
			self::redirect_with_error( $data->get_error_message() );
		}

		$export = EVote_Json_Import::ballot_box( $data );
		if ( is_wp_error( $export ) ) {
			self::redirect_with_error( $export->get_error_message() );
		}

		$private = isset( $_POST['evote_private_hex'] ) ? sanitize_text_field( wp_unslash( $_POST['evote_private_hex'] ) ) : '';
		$private = preg_replace( '/\s+/', '', $private );

		$tally = EVote_Tally_Engine::tally_export( $export, $private );
		if ( is_wp_error( $tally ) ) {
			self::redirect_with_error( $tally->get_error_message() );
		}

		set_transient(
			self::transient_key( self::TRANSIENT_IMPORT ),
			array(
				'title'        => $export['running']['title'] ?? '',
				'ballot_count' => count( $export['ballots'] ?? array() ),
			),
			self::TRANSIENT_TTL
		);
		set_transient( self::transient_key( self::TRANSIENT_TALLY ), $tally, self::TRANSIENT_TTL );

		wp_safe_redirect( admin_url( 'admin.php?page=evote-tally' ) );
		exit;
	}

	/**
	 * @param string $prefix Transient prefix.
	 * @return string
	 */
	private static function transient_key( $prefix ) {
		return $prefix . get_current_user_id();
	}

	/**
	 * @param string $message Error text.
	 */
	private static function redirect_with_error( $message ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'evote-tally',
					'evote_error' => rawurlencode( $message ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * @param string $field POST field name.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function parse_json_field( $field ) {
		$raw = isset( $_POST[ $field ] ) ? trim( wp_unslash( $_POST[ $field ] ) ) : '';
		if ( '' === $raw ) {
			return new WP_Error( 'evote_empty', __( 'JSON input is required.', 'decentralized-evoting' ) );
		}
		$decoded = json_decode( $raw, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return new WP_Error( 'evote_json', __( 'Invalid JSON.', 'decentralized-evoting' ) );
		}
		return $decoded;
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
}
