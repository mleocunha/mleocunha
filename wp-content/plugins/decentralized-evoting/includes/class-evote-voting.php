<?php
/**
 * Public voting shortcode and AJAX ballot submission (Brazil numeric ballot).
 *
 * @package DecentralizedEvoting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Front-end encrypted voting.
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
		add_action( 'wp_ajax_evote_lookup_code', array( __CLASS__, 'ajax_lookup_code' ) );
		add_action( 'wp_ajax_nopriv_evote_lookup_code', array( __CLASS__, 'ajax_lookup_code' ) );
	}

	/**
	 * Register scripts.
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
	 * @param array<string, string> $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'id' => '0' ), $atts, 'evote_poll' );
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
			<div class="evote-poll__status" id="evote-status" role="status"></div>

			<div id="evote-screen-entry" class="evote-poll__screen">
				<p class="evote-poll__hint" id="evote-hint-enter"></p>
				<div class="evote-poll__display">
					<input type="text" id="evote-code-input" class="evote-poll__code" inputmode="numeric" autocomplete="off" maxlength="5" aria-label="<?php esc_attr_e( 'Número do candidato', 'decentralized-evoting' ); ?>" />
				</div>
				<div class="evote-poll__keypad" id="evote-keypad"></div>
				<div class="evote-poll__actions">
					<button type="button" class="button evote-btn-clear" id="evote-btn-clear"><?php esc_html_e( 'Limpar', 'decentralized-evoting' ); ?></button>
					<?php if ( ! empty( $config['allow_blank'] ) ) : ?>
						<button type="button" class="button evote-btn-branco" id="evote-btn-branco"><?php esc_html_e( 'Branco', 'decentralized-evoting' ); ?></button>
					<?php endif; ?>
					<?php if ( ! empty( $config['allow_null'] ) ) : ?>
						<button type="button" class="button evote-btn-nulo" id="evote-btn-nulo"><?php esc_html_e( 'Nulo', 'decentralized-evoting' ); ?></button>
					<?php endif; ?>
				</div>
			</div>

			<div id="evote-screen-confirm" class="evote-poll__screen evote-poll__screen--hidden">
				<div class="evote-poll__confirm-card">
					<img id="evote-confirm-photo" class="evote-poll__photo" alt="" hidden />
					<img id="evote-confirm-party-logo" class="evote-poll__party-logo" alt="" hidden />
					<p id="evote-confirm-name" class="evote-poll__confirm-name"></p>
					<p id="evote-confirm-code" class="evote-poll__confirm-code"></p>
					<p id="evote-confirm-warn" class="evote-poll__warn evote-poll__warn--hidden"></p>
				</div>
				<div class="evote-poll__actions">
					<button type="button" class="button button-primary" id="evote-btn-confirm"><?php esc_html_e( 'Confirmar voto', 'decentralized-evoting' ); ?></button>
					<button type="button" class="button" id="evote-btn-back"><?php esc_html_e( 'Limpar', 'decentralized-evoting' ); ?></button>
				</div>
			</div>

			<p class="evote-poll__token-row">
				<label for="evote-token"><strong><?php esc_html_e( 'Token de votação', 'decentralized-evoting' ); ?></strong></label>
				<input type="password" id="evote-token" class="evote-poll__token" autocomplete="off" />
			</p>
			<p class="evote-poll__note"><?php esc_html_e( 'Seu voto é criptografado neste navegador. O servidor nunca vê sua escolha em texto claro.', 'decentralized-evoting' ); ?></p>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * AJAX lookup for confirmation screen.
	 */
	public static function ajax_lookup_code() {
		check_ajax_referer( 'evote_lookup_code', 'nonce' );
		$running_id = isset( $_POST['running_id'] ) ? absint( $_POST['running_id'] ) : 0;
		$code       = isset( $_POST['code'] ) ? wp_unslash( $_POST['code'] ) : '';
		$result     = EVote_Ballot_Codes::lookup( $running_id, $code );
		if ( is_wp_error( $result ) ) {
			wp_send_json_success( array( 'valid' => false, 'message' => $result->get_error_message() ) );
		}
		$config = EVote_Running_Service::get_config( $running_id );
		if ( ! EVote_Ballot_Codes::is_code_allowed( $result['code'], $config ) ) {
			wp_send_json_success(
				array(
					'valid'   => false,
					'message' => __( 'Este número não está apto neste turno.', 'decentralized-evoting' ),
				)
			);
		}
		wp_send_json_success( $result );
	}

	/**
	 * AJAX cast ballot.
	 */
	public static function ajax_cast_ballot() {
		check_ajax_referer( 'evote_cast_ballot', 'nonce' );

		$running_id  = isset( $_POST['running_id'] ) ? absint( $_POST['running_id'] ) : 0;
		$token       = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$ballots_raw = isset( $_POST['ballots'] ) ? wp_unslash( $_POST['ballots'] ) : '';

		if ( '' === $token ) {
			wp_send_json_error( array( 'message' => __( 'Token obrigatório.', 'decentralized-evoting' ) ), 400 );
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
			wp_send_json_error( array( 'message' => __( 'Cédula inválida.', 'decentralized-evoting' ) ), 400 );
		}

		$ballots = array( $ballots[0] );
		$enc     = $ballots[0]['message_encoding'] ?? '';
		if ( EVote_Homomorphic::ENC_EXP_ONE_HOT === $enc ) {
			if ( EVote_Homomorphic::MODE_EXP_ONE_HOT !== ( $config['homomorphic_mode'] ?? '' ) ) {
				wp_send_json_error( array( 'message' => __( 'Cédula homomórfica não habilitada nesta eleição.', 'decentralized-evoting' ) ), 400 );
			}
			$code = EVote_Ballot_Codes::normalize_code( $ballots[0]['selected_code'] ?? '' );
			if ( '' !== $code && ! EVote_Ballot_Codes::is_code_allowed( $code, $config ) ) {
				wp_send_json_error( array( 'message' => __( 'Número não permitido nesta eleição.', 'decentralized-evoting' ) ), 400 );
			}
		} elseif ( EVote_Modality_Registry::ENC_NUMBER === $enc ) {
			if ( EVote_Homomorphic::MODE_EXP_ONE_HOT === ( $config['homomorphic_mode'] ?? '' ) ) {
				wp_send_json_error( array( 'message' => __( 'Use a urna com apuração homomórfica ativa (recarregue a página).', 'decentralized-evoting' ) ), 400 );
			}
			$code = EVote_Ballot_Codes::normalize_code( $ballots[0]['message'] ?? '' );
			if ( ! EVote_Ballot_Codes::is_code_allowed( $code, $config ) ) {
				wp_send_json_error( array( 'message' => __( 'Número não permitido nesta eleição.', 'decentralized-evoting' ) ), 400 );
			}
		}

		$result = EVote_Ballot_Repository::cast_ballots( $running_id, $token, $ballots );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( array( 'message' => __( 'Voto criptografado registrado. Obrigado.', 'decentralized-evoting' ) ) );
	}
}
