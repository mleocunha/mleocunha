<?php
/**
 * Meta boxes for running (election) configuration.
 *
 * @package DecentralizedEvoting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI for election boundaries and imported public key.
 */
class EVote_Election_Meta {

	/**
	 * Register hooks.
	 */
	public static function register_hooks() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post_evote_running', array( __CLASS__, 'save_running_meta' ), 10, 2 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_modality_meta_boxes' ) );
		add_action( 'save_post_evote_modality', array( __CLASS__, 'save_modality_meta' ), 10, 2 );
	}

	/**
	 * Running meta boxes.
	 */
	public static function add_meta_boxes() {
		add_meta_box(
			'evote-running-schedule',
			__( 'Polling Schedule', 'decentralized-evoting' ),
			array( __CLASS__, 'render_schedule_box' ),
			'evote_running',
			'side',
			'high'
		);

		add_meta_box(
			'evote-running-crypto',
			__( 'Cryptography & Modality', 'decentralized-evoting' ),
			array( __CLASS__, 'render_crypto_box' ),
			'evote_running',
			'normal',
			'high'
		);

		add_meta_box(
			'evote-running-ballot',
			__( 'Ballot Configuration', 'decentralized-evoting' ),
			array( __CLASS__, 'render_ballot_box' ),
			'evote_running',
			'normal',
			'default'
		);
	}

	/**
	 * Modality template meta boxes.
	 */
	public static function add_modality_meta_boxes() {
		add_meta_box(
			'evote-modality-settings',
			__( 'Modality Rules', 'decentralized-evoting' ),
			array( __CLASS__, 'render_modality_settings_box' ),
			'evote_modality',
			'normal',
			'high'
		);
	}

	/**
	 * @param WP_Post $post Post object.
	 */
	public static function render_schedule_box( $post ) {
		wp_nonce_field( 'evote_save_running', 'evote_running_nonce' );
		$start = get_post_meta( $post->ID, '_evote_start_datetime', true );
		$end   = get_post_meta( $post->ID, '_evote_end_datetime', true );
		$status = get_post_meta( $post->ID, '_evote_status', true ) ?: 'draft';
		?>
		<p>
			<label for="evote_start_datetime"><strong><?php esc_html_e( 'Opens', 'decentralized-evoting' ); ?></strong></label><br />
			<input type="datetime-local" id="evote_start_datetime" name="evote_start_datetime" class="widefat" value="<?php echo esc_attr( self::format_datetime_local( $start ) ); ?>" />
		</p>
		<p>
			<label for="evote_end_datetime"><strong><?php esc_html_e( 'Closes', 'decentralized-evoting' ); ?></strong></label><br />
			<input type="datetime-local" id="evote_end_datetime" name="evote_end_datetime" class="widefat" value="<?php echo esc_attr( self::format_datetime_local( $end ) ); ?>" />
		</p>
		<p>
			<label for="evote_status"><strong><?php esc_html_e( 'Status', 'decentralized-evoting' ); ?></strong></label><br />
			<select id="evote_status" name="evote_status" class="widefat">
				<?php foreach ( self::status_options() as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $status, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php
	}

	/**
	 * @param WP_Post $post Post object.
	 */
	public static function render_crypto_box( $post ) {
		$public_key_json = get_post_meta( $post->ID, '_evote_public_key_json', true );
		$modality_type   = get_post_meta( $post->ID, '_evote_modality_type', true ) ?: 'single';
		$modality_id     = (int) get_post_meta( $post->ID, '_evote_modality_id', true );
		$max_choices     = (int) get_post_meta( $post->ID, '_evote_max_choices', true );
		if ( $max_choices < 1 ) {
			$max_choices = 1;
		}

		$modalities = get_posts(
			array(
				'post_type'      => 'evote_modality',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'post_status'    => 'publish',
			)
		);
		?>
		<p>
			<label for="evote_public_key_json"><strong><?php esc_html_e( 'ElGamal public key (JSON from Node 1)', 'decentralized-evoting' ); ?></strong></label>
			<textarea id="evote_public_key_json" name="evote_public_key_json" class="large-text code" rows="8" placeholder='{"schema":"evote-public-key","version":"1",...}'><?php echo esc_textarea( $public_key_json ); ?></textarea>
		</p>
		<p>
			<label for="evote_modality_type"><strong><?php esc_html_e( 'Voting modality', 'decentralized-evoting' ); ?></strong></label><br />
			<select id="evote_modality_type" name="evote_modality_type">
				<?php foreach ( self::modality_type_options() as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $modality_type, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="evote_modality_id"><strong><?php esc_html_e( 'Linked modality template (optional)', 'decentralized-evoting' ); ?></strong></label><br />
			<select id="evote_modality_id" name="evote_modality_id" class="widefat">
				<option value="0"><?php esc_html_e( '— None —', 'decentralized-evoting' ); ?></option>
				<?php foreach ( $modalities as $modality ) : ?>
					<option value="<?php echo esc_attr( (string) $modality->ID ); ?>" <?php selected( $modality_id, $modality->ID ); ?>><?php echo esc_html( $modality->post_title ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="evote_max_choices"><strong><?php esc_html_e( 'Maximum choices', 'decentralized-evoting' ); ?></strong></label><br />
			<input type="number" id="evote_max_choices" name="evote_max_choices" min="1" value="<?php echo esc_attr( (string) $max_choices ); ?>" />
		</p>
		<?php
	}

	/**
	 * @param WP_Post $post Post object.
	 */
	public static function render_ballot_box( $post ) {
		$candidate_ids = get_post_meta( $post->ID, '_evote_candidate_ids', true );
		if ( ! is_array( $candidate_ids ) ) {
			$candidate_ids = array();
		}

		$candidates = get_posts(
			array(
				'post_type'      => 'evote_candidate',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'post_status'    => array( 'publish', 'draft' ),
			)
		);
		?>
		<p><?php esc_html_e( 'Select candidates that appear on this running\'s ballot.', 'decentralized-evoting' ); ?></p>
		<ul style="max-height:240px;overflow:auto;margin:0;">
			<?php foreach ( $candidates as $candidate ) : ?>
				<li>
					<label>
						<input type="checkbox" name="evote_candidate_ids[]" value="<?php echo esc_attr( (string) $candidate->ID ); ?>" <?php checked( in_array( $candidate->ID, $candidate_ids, true ) ); ?> />
						<?php echo esc_html( $candidate->post_title ); ?>
					</label>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * @param WP_Post $post Post object.
	 */
	public static function render_modality_settings_box( $post ) {
		wp_nonce_field( 'evote_save_modality', 'evote_modality_nonce' );
		$type          = get_post_meta( $post->ID, '_evote_modality_type', true ) ?: 'single';
		$max_choices   = (int) get_post_meta( $post->ID, '_evote_max_choices', true );
		$allow_abstain = (bool) get_post_meta( $post->ID, '_evote_allow_abstain', true );
		$ranked_levels = (int) get_post_meta( $post->ID, '_evote_ranked_levels', true );
		if ( $max_choices < 1 ) {
			$max_choices = 1;
		}
		?>
		<p>
			<label for="evote_modality_type_tpl"><strong><?php esc_html_e( 'Type', 'decentralized-evoting' ); ?></strong></label><br />
			<select id="evote_modality_type_tpl" name="evote_modality_type">
				<?php foreach ( self::modality_type_options() as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $type, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="evote_max_choices_tpl"><strong><?php esc_html_e( 'Max choices', 'decentralized-evoting' ); ?></strong></label><br />
			<input type="number" id="evote_max_choices_tpl" name="evote_max_choices" min="1" value="<?php echo esc_attr( (string) $max_choices ); ?>" />
		</p>
		<p>
			<label>
				<input type="checkbox" name="evote_allow_abstain" value="1" <?php checked( $allow_abstain ); ?> />
				<?php esc_html_e( 'Allow abstain', 'decentralized-evoting' ); ?>
			</label>
		</p>
		<p>
			<label for="evote_ranked_levels"><strong><?php esc_html_e( 'Ranked preference levels (if ranked)', 'decentralized-evoting' ); ?></strong></label><br />
			<input type="number" id="evote_ranked_levels" name="evote_ranked_levels" min="0" value="<?php echo esc_attr( (string) $ranked_levels ); ?>" />
		</p>
		<?php
	}

	/**
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_running_meta( $post_id, $post ) {
		if ( ! isset( $_POST['evote_running_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['evote_running_nonce'] ) ), 'evote_save_running' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$start = isset( $_POST['evote_start_datetime'] ) ? self::parse_datetime_local( sanitize_text_field( wp_unslash( $_POST['evote_start_datetime'] ) ) ) : '';
		$end   = isset( $_POST['evote_end_datetime'] ) ? self::parse_datetime_local( sanitize_text_field( wp_unslash( $_POST['evote_end_datetime'] ) ) ) : '';
		update_post_meta( $post_id, '_evote_start_datetime', $start );
		update_post_meta( $post_id, '_evote_end_datetime', $end );

		$status = isset( $_POST['evote_status'] ) ? sanitize_key( wp_unslash( $_POST['evote_status'] ) ) : 'draft';
		if ( ! array_key_exists( $status, self::status_options() ) ) {
			$status = 'draft';
		}
		update_post_meta( $post_id, '_evote_status', $status );

		$public_key_raw = isset( $_POST['evote_public_key_json'] ) ? wp_unslash( $_POST['evote_public_key_json'] ) : '';
		$public_key_raw = is_string( $public_key_raw ) ? trim( $public_key_raw ) : '';
		if ( '' === $public_key_raw ) {
			delete_post_meta( $post_id, '_evote_public_key_json' );
		} else {
			$decoded = json_decode( $public_key_raw, true );
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
				return;
			}
			update_post_meta( $post_id, '_evote_public_key_json', wp_json_encode( $decoded ) );
		}

		$modality_type = isset( $_POST['evote_modality_type'] ) ? sanitize_key( wp_unslash( $_POST['evote_modality_type'] ) ) : 'single';
		if ( ! array_key_exists( $modality_type, self::modality_type_options() ) ) {
			$modality_type = 'single';
		}
		update_post_meta( $post_id, '_evote_modality_type', $modality_type );

		$modality_id = isset( $_POST['evote_modality_id'] ) ? absint( $_POST['evote_modality_id'] ) : 0;
		update_post_meta( $post_id, '_evote_modality_id', $modality_id );

		$max_choices = isset( $_POST['evote_max_choices'] ) ? max( 1, absint( $_POST['evote_max_choices'] ) ) : 1;
		update_post_meta( $post_id, '_evote_max_choices', $max_choices );

		$candidate_ids = array();
		if ( isset( $_POST['evote_candidate_ids'] ) && is_array( $_POST['evote_candidate_ids'] ) ) {
			$candidate_ids = array_map( 'absint', wp_unslash( $_POST['evote_candidate_ids'] ) );
			$candidate_ids = array_values( array_filter( $candidate_ids ) );
		}
		update_post_meta( $post_id, '_evote_candidate_ids', $candidate_ids );
	}

	/**
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_modality_meta( $post_id, $post ) {
		if ( ! isset( $_POST['evote_modality_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['evote_modality_nonce'] ) ), 'evote_save_modality' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$type = isset( $_POST['evote_modality_type'] ) ? sanitize_key( wp_unslash( $_POST['evote_modality_type'] ) ) : 'single';
		if ( ! array_key_exists( $type, self::modality_type_options() ) ) {
			$type = 'single';
		}
		update_post_meta( $post_id, '_evote_modality_type', $type );
		update_post_meta( $post_id, '_evote_max_choices', isset( $_POST['evote_max_choices'] ) ? max( 1, absint( $_POST['evote_max_choices'] ) ) : 1 );
		update_post_meta( $post_id, '_evote_allow_abstain', ! empty( $_POST['evote_allow_abstain'] ) ? 1 : 0 );
		update_post_meta( $post_id, '_evote_ranked_levels', isset( $_POST['evote_ranked_levels'] ) ? absint( $_POST['evote_ranked_levels'] ) : 0 );
	}

	/**
	 * @return array<string, string>
	 */
	public static function status_options() {
		return array(
			'draft'  => __( 'Draft', 'decentralized-evoting' ),
			'ready'  => __( 'Ready', 'decentralized-evoting' ),
			'open'   => __( 'Open', 'decentralized-evoting' ),
			'closed' => __( 'Closed', 'decentralized-evoting' ),
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function modality_type_options() {
		return array(
			'single'   => __( 'Single choice', 'decentralized-evoting' ),
			'multiple' => __( 'Multiple choice', 'decentralized-evoting' ),
			'ranked'   => __( 'Ranked / preferential', 'decentralized-evoting' ),
		);
	}

	/**
	 * @param string $stored MySQL datetime or empty.
	 * @return string
	 */
	private static function format_datetime_local( $stored ) {
		if ( empty( $stored ) ) {
			return '';
		}
		$ts = strtotime( $stored );
		return $ts ? gmdate( 'Y-m-d\TH:i', $ts ) : '';
	}

	/**
	 * @param string $input datetime-local value.
	 * @return string MySQL datetime or empty.
	 */
	private static function parse_datetime_local( $input ) {
		if ( '' === $input ) {
			return '';
		}
		$ts = strtotime( $input );
		return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : '';
	}
}
