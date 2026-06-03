<?php
/**
 * Party taxonomy term meta (2-digit code, logo).
 *
 * @package DecentralizedEvoting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Party number and logo for ballot confirmation screen.
 */
class EVote_Party_Meta {

	/**
	 * Register hooks.
	 */
	public static function register_hooks() {
		add_action( 'evote_party_add_form_fields', array( __CLASS__, 'add_fields' ) );
		add_action( 'evote_party_edit_form_fields', array( __CLASS__, 'edit_fields' ), 10, 2 );
		add_action( 'created_evote_party', array( __CLASS__, 'save' ) );
		add_action( 'edited_evote_party', array( __CLASS__, 'save' ) );
	}

	/**
	 * @param string $taxonomy Taxonomy.
	 */
	public static function add_fields( $taxonomy ) {
		?>
		<div class="form-field">
			<label for="evote_party_number"><?php esc_html_e( 'Número do partido (2 dígitos)', 'decentralized-evoting' ); ?></label>
			<input type="text" name="evote_party_number" id="evote_party_number" maxlength="2" pattern="\d{2}" />
		</div>
		<div class="form-field">
			<label for="evote_party_logo_url"><?php esc_html_e( 'URL do logo', 'decentralized-evoting' ); ?></label>
			<input type="url" name="evote_party_logo_url" id="evote_party_logo_url" class="regular-text" />
		</div>
		<?php
	}

	/**
	 * @param WP_Term $term     Term.
	 * @param string  $taxonomy Taxonomy.
	 */
	public static function edit_fields( $term, $taxonomy ) {
		$number = get_term_meta( $term->term_id, '_evote_party_number', true );
		$logo   = get_term_meta( $term->term_id, '_evote_party_logo_url', true );
		?>
		<tr class="form-field">
			<th scope="row"><label for="evote_party_number"><?php esc_html_e( 'Número (2 dígitos)', 'decentralized-evoting' ); ?></label></th>
			<td><input type="text" name="evote_party_number" id="evote_party_number" value="<?php echo esc_attr( $number ); ?>" maxlength="2" pattern="\d{2}" /></td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="evote_party_logo_url"><?php esc_html_e( 'URL do logo', 'decentralized-evoting' ); ?></label></th>
			<td><input type="url" name="evote_party_logo_url" id="evote_party_logo_url" class="regular-text" value="<?php echo esc_attr( $logo ); ?>" /></td>
		</tr>
		<?php
	}

	/**
	 * @param int $term_id Term ID.
	 */
	public static function save( $term_id ) {
		if ( isset( $_POST['evote_party_number'] ) ) {
			$num = preg_replace( '/\D/', '', wp_unslash( $_POST['evote_party_number'] ) );
			$num = str_pad( substr( $num, 0, 2 ), 2, '0', STR_PAD_LEFT );
			update_term_meta( $term_id, '_evote_party_number', $num );
		}
		if ( isset( $_POST['evote_party_logo_url'] ) ) {
			update_term_meta( $term_id, '_evote_party_logo_url', esc_url_raw( wp_unslash( $_POST['evote_party_logo_url'] ) ) );
		}
	}

	/**
	 * @param int $term_id Party term ID.
	 * @return array{number: string, logo_url: string}
	 */
	public static function get_party_display( $term_id ) {
		return array(
			'number'   => (string) get_term_meta( $term_id, '_evote_party_number', true ),
			'logo_url' => (string) get_term_meta( $term_id, '_evote_party_logo_url', true ),
		);
	}
}
