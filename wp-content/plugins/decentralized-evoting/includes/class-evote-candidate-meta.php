<?php
/**
 * Candidate ballot number meta.
 *
 * @package DecentralizedEvoting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Brazilian numeric ballot codes on candidates.
 */
class EVote_Candidate_Meta {

	/**
	 * Register hooks.
	 */
	public static function register_hooks() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_evote_candidate', array( __CLASS__, 'save' ), 10, 2 );
	}

	/**
	 * Meta box.
	 */
	public static function add_meta_box() {
		add_meta_box(
			'evote-candidate-ballot',
			__( 'Número de urna', 'decentralized-evoting' ),
			array( __CLASS__, 'render' ),
			'evote_candidate',
			'side',
			'high'
		);
	}

	/**
	 * @param WP_Post $post Post.
	 */
	public static function render( $post ) {
		wp_nonce_field( 'evote_save_candidate_ballot', 'evote_candidate_ballot_nonce' );
		$code = get_post_meta( $post->ID, '_evote_ballot_number', true );
		?>
		<p>
			<label for="evote_ballot_number"><strong><?php esc_html_e( 'Código completo', 'decentralized-evoting' ); ?></strong></label>
			<input type="text" class="widefat" id="evote_ballot_number" name="evote_ballot_number" value="<?php echo esc_attr( $code ); ?>" pattern="\d+" maxlength="5" />
		</p>
		<p class="description"><?php esc_html_e( 'Partido (2 dígitos) + sufixo conforme o cargo.', 'decentralized-evoting' ); ?></p>
		<?php
	}

	/**
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['evote_candidate_ballot_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['evote_candidate_ballot_nonce'] ) ), 'evote_save_candidate_ballot' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$code = isset( $_POST['evote_ballot_number'] ) ? preg_replace( '/\D/', '', wp_unslash( $_POST['evote_ballot_number'] ) ) : '';
		if ( '' === $code ) {
			delete_post_meta( $post_id, '_evote_ballot_number' );
		} else {
			update_post_meta( $post_id, '_evote_ballot_number', $code );
		}
	}
}
