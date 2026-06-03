<?php
/**
 * Public voting shortcode and AJAX ballot submission.
 *
 * @package DecentralizedEvoting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Front-end encrypted voting (client-side ElGamal).
 */
class EVote_Voting {

	/**
	 * Register hooks.
	 */
	public static function register_hooks() {
		add_shortcode( 'evote_poll', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_action( 'wp_ajax_evote_cast_ballot', array( __CLASS__, 'ajax_cast_ballot' ) );
		add_action( 'wp_ajax_nopriv_evote_cast_ballot', array( __CLASS__, 'ajax_cast_ballot' ) );
	}

	/**
	 * Register scripts (enqueued per shortcode).
	 */
	public static function register_assets() {
		wp_register_script(
			'evote-crypto-client',
			EVOTE_PLUGIN_URL . 'assets/js/evote-crypto-client.js',
			array(),
			EVOTE_VERSION,
			true
		);
		wp_register_script(
			'evote-voter',
			EVOTE_PLUGIN_URL . 'assets/js/evote-voter.js',
			array( 'evote-crypto-client' ),
			EVOTE_VERSION,
			true
		);
		wp_register_style(
			'evote-voter',
			EVOTE_PLUGIN_URL . 'assets/css/evote-voter.css',
			array(),
			EVOTE_VERSION
		);
	}

	/**
	 * @param array<string, string> $atts Shortcode attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'id' => '0',
			),
			$atts,
			'evote_poll'
		);
		$running_id = absint( $atts['id'] );
		$config     = EVote_Running_Service::get_config( $running_id );
		if ( is_wp_error( $config ) ) {
			return '<p class="evote-error">' . esc_html( $config->get_error_message() ) . '</p>';
		}

		wp_enqueue_script( 'evote-crypto-client' );
		wp_enqueue_script( 'evote-voter' );
		wp_enqueue_style( 'evote-voter' );
		wp_localize_script( 'evote-voter', 'evoteConfig', EVote_Running_Service::client_config( $config ) );

		ob_start();
		?>
		<div class="evote-poll" data-running-id="<?php echo esc_attr( (string) $running_id ); ?>">
			<h2 class="evote-poll__title"><?php echo esc_html( $config['title'] ); ?></h2>
			<div class="evote-poll__status" id="evote-status"></div>
			<form id="evote-ballot-form" class="evote-poll__form" novalidate>
				<fieldset class="evote-poll__choices">
					<legend><?php esc_html_e( 'Ballot', 'decentralized-evoting' ); ?></legend>
					<?php
					$input_type = 'single' === $config['modality_type'] ? 'radio' : 'checkbox';
					foreach ( $config['candidates'] as $candidate ) :
						?>
						<label class="evote-poll__choice">
							<input type="<?php echo esc_attr( $input_type ); ?>" name="evote_choice" value="<?php echo esc_attr( (string) $candidate['id'] ); ?>" />
							<?php echo esc_html( $candidate['title'] ); ?>
						</label>
					<?php endforeach; ?>
				</fieldset>
				<p>
					<label for="evote-token"><strong><?php esc_html_e( 'Voting token', 'decentralized-evoting' ); ?></strong></label><br />
					<input type="password" id="evote-token" name="evote_token" class="evote-poll__token" autocomplete="off" required />
				</p>
				<button type="submit" class="evote-poll__submit button"><?php esc_html_e( 'Cast encrypted vote', 'decentralized-evoting' ); ?></button>
			</form>
			<p class="evote-poll__note"><?php esc_html_e( 'Your vote is encrypted in your browser before it is sent. The server never sees your plaintext choice.', 'decentralized-evoting' ); ?></p>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * AJAX: receive encrypted ballot(s).
	 */
	public static function ajax_cast_ballot() {
		check_ajax_referer( 'evote_cast_ballot', 'nonce' );

		$running_id = isset( $_POST['running_id'] ) ? absint( $_POST['running_id'] ) : 0;
		$token      = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$ballots_raw = isset( $_POST['ballots'] ) ? wp_unslash( $_POST['ballots'] ) : '';

		if ( '' === $token ) {
			wp_send_json_error( array( 'message' => __( 'Voting token required.', 'decentralized-evoting' ) ), 400 );
		}

		$config = EVote_Running_Service::get_config( $running_id );
		if ( is_wp_error( $config ) ) {
			wp_send_json_error( array( 'message' => $config->get_error_message() ), 400 );
		}

		$open = EVote_Running_Service::assert_poll_open( $config );
		if ( is_wp_error( $open ) ) {
			wp_send_json_error( array( 'message' => $open->get_error_message() ), 403 );
		}

		$ballots = json_decode( is_string( $ballots_raw ) ? $ballots_raw : '', true );
		if ( ! is_array( $ballots ) || empty( $ballots ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid ballot payload.', 'decentralized-evoting' ) ), 400 );
		}

		$max = (int) $config['max_choices'];
		if ( count( $ballots ) > $max ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %d: max choices */
						__( 'Too many choices (maximum %d).', 'decentralized-evoting' ),
						$max
					),
				),
				400
			);
		}

		$result = EVote_Ballot_Repository::cast_ballots( $running_id, $token, $ballots );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( array( 'message' => __( 'Your encrypted vote was recorded. Thank you.', 'decentralized-evoting' ) ) );
	}
}
