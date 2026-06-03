<?php
/**
 * Brazil election rules meta box for runnings.
 *
 * @package DecentralizedEvoting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin fields for modalities, PR, ballotage, blank/null.
 */
class EVote_Running_Rules_Meta {

	/**
	 * Register meta box.
	 */
	public static function register() {
		add_meta_box(
			'evote-running-brazil-rules',
			__( 'Regras eleitorais (Brasil)', 'decentralized-evoting' ),
			array( __CLASS__, 'render' ),
			'evote_running',
			'normal',
			'default'
		);
	}

	/**
	 * @param WP_Post $post Post.
	 */
	public static function render( $post ) {
		$d = EVote_Modality_Registry::default_running_meta();
		$modality   = get_post_meta( $post->ID, '_evote_modality_type', true ) ?: EVote_Modality_Registry::default_modality_for_office( get_post_meta( $post->ID, '_evote_office_type', true ) ?: $d['office_type'] );
		$office     = get_post_meta( $post->ID, '_evote_office_type', true ) ?: $d['office_type'];
		$seats      = (int) get_post_meta( $post->ID, '_evote_seat_count', true ) ?: 1;
		$allow_blank = (int) get_post_meta( $post->ID, '_evote_allow_blank', true ) !== 0;
		$allow_null  = (int) get_post_meta( $post->ID, '_evote_allow_null', true ) !== 0;
		$timeout     = (int) get_post_meta( $post->ID, '_evote_blank_timeout_seconds', true ) ?: 120;
		$thresh      = (float) get_post_meta( $post->ID, '_evote_ballotage_threshold_pct', true ) ?: 50;
		$advance     = (int) get_post_meta( $post->ID, '_evote_ballotage_advance_count', true ) ?: 2;
		$parent      = (int) get_post_meta( $post->ID, '_evote_parent_running_id', true );
		$reuse       = (int) get_post_meta( $post->ID, '_evote_reuse_electors_r2', true ) !== 0;
		$pr_formula  = get_post_meta( $post->ID, '_evote_pr_formula', true ) ?: EVote_Modality_Registry::PR_FORMULA_BRAZILIAN;
		$pr_thresh   = (float) get_post_meta( $post->ID, '_evote_pr_threshold_pct', true );
		$pr_overhang = (int) get_post_meta( $post->ID, '_evote_pr_overhang', true ) === 1;
		$pr_tse_p    = (float) get_post_meta( $post->ID, '_evote_pr_tse_party_pct', true ) ?: 80;
		$pr_tse_c    = (float) get_post_meta( $post->ID, '_evote_pr_tse_candidate_pct', true ) ?: 20;
		$qualified   = get_post_meta( $post->ID, '_evote_qualified_ballot_codes', true );
		if ( ! is_array( $qualified ) ) {
			$qualified = array();
		}
		$homo_mode = get_post_meta( $post->ID, '_evote_homomorphic_mode', true ) ?: EVote_Homomorphic::MODE_OFF;
		$runnings = get_posts( array( 'post_type' => 'evote_running', 'posts_per_page' => -1, 'post_status' => 'any', 'exclude' => array( $post->ID ) ) );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="evote_modality_type"><?php esc_html_e( 'Sistema', 'decentralized-evoting' ); ?></label></th>
				<td>
					<select name="evote_modality_type" id="evote_modality_type">
						<?php foreach ( EVote_Modality_Registry::modality_options() as $val => $label ) : ?>
							<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $modality, $val ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="evote_office_type"><?php esc_html_e( 'Cargo', 'decentralized-evoting' ); ?></label></th>
				<td>
					<select name="evote_office_type" id="evote_office_type">
						<?php foreach ( EVote_Modality_Registry::office_options() as $val => $label ) : ?>
							<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $office, $val ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Define o comprimento do código (2 a 5 dígitos).', 'decentralized-evoting' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="evote_seat_count"><?php esc_html_e( 'Vagas', 'decentralized-evoting' ); ?></label></th>
				<td><input type="number" name="evote_seat_count" id="evote_seat_count" min="1" value="<?php echo esc_attr( (string) $seats ); ?>" /></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Votos especiais', 'decentralized-evoting' ); ?></th>
				<td>
					<label><input type="checkbox" name="evote_allow_blank" value="1" <?php checked( $allow_blank ); ?> /> <?php esc_html_e( 'Permitir branco', 'decentralized-evoting' ); ?></label><br />
					<label><input type="checkbox" name="evote_allow_null" value="1" <?php checked( $allow_null ); ?> /> <?php esc_html_e( 'Permitir nulo (número inválido confirmado)', 'decentralized-evoting' ); ?></label>
				</td>
			</tr>
			<tr>
				<th><label for="evote_blank_timeout_seconds"><?php esc_html_e( 'Timeout → branco (segundos)', 'decentralized-evoting' ); ?></label></th>
				<td><input type="number" name="evote_blank_timeout_seconds" id="evote_blank_timeout_seconds" min="0" value="<?php echo esc_attr( (string) $timeout ); ?>" /></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Ballotage', 'decentralized-evoting' ); ?></th>
				<td>
					<label><?php esc_html_e( '% para vitória no 1º turno', 'decentralized-evoting' ); ?>
						<input type="number" name="evote_ballotage_threshold_pct" step="0.1" min="0" max="100" value="<?php echo esc_attr( (string) $thresh ); ?>" />
					</label><br />
					<label><?php esc_html_e( 'Classificados para 2º turno', 'decentralized-evoting' ); ?>
						<input type="number" name="evote_ballotage_advance_count" min="1" value="<?php echo esc_attr( (string) $advance ); ?>" />
					</label><br />
					<label><?php esc_html_e( 'Running do 1º turno (se for 2º turno)', 'decentralized-evoting' ); ?>
						<select name="evote_parent_running_id">
							<option value="0">—</option>
							<?php foreach ( $runnings as $r ) : ?>
								<option value="<?php echo esc_attr( (string) $r->ID ); ?>" <?php selected( $parent, $r->ID ); ?>><?php echo esc_html( $r->post_title ); ?></option>
							<?php endforeach; ?>
						</select>
					</label><br />
					<label><input type="checkbox" name="evote_reuse_electors_r2" value="1" <?php checked( $reuse ); ?> /> <?php esc_html_e( 'Reutilizar eleitores/tokens no 2º turno', 'decentralized-evoting' ); ?></label>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( '2º turno — números qualificados', 'decentralized-evoting' ); ?></th>
				<td>
					<textarea name="evote_qualified_ballot_codes" class="large-text code" rows="4" placeholder="13123&#10;45234"><?php echo esc_textarea( implode( "\n", $qualified ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Um código por linha. Somente estes números são válidos no 2º turno.', 'decentralized-evoting' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Proporcional', 'decentralized-evoting' ); ?></th>
				<td>
					<select name="evote_pr_formula">
						<option value="brazilian" <?php selected( $pr_formula, 'brazilian' ); ?>><?php esc_html_e( 'Brasileiro (quota + médias)', 'decentralized-evoting' ); ?></option>
						<option value="dhondt" <?php selected( $pr_formula, 'dhondt' ); ?>><?php esc_html_e( 'D’Hondt', 'decentralized-evoting' ); ?></option>
						<option value="sainte_lague" <?php selected( $pr_formula, 'sainte_lague' ); ?>><?php esc_html_e( 'Sainte-Laguë', 'decentralized-evoting' ); ?></option>
						<option value="hare" <?php selected( $pr_formula, 'hare' ); ?>><?php esc_html_e( 'Hare (maior resto)', 'decentralized-evoting' ); ?></option>
					</select><br />
					<label><?php esc_html_e( 'Cláusula de barreira %', 'decentralized-evoting' ); ?> <input type="number" name="evote_pr_threshold_pct" step="0.1" min="0" max="100" value="<?php echo esc_attr( (string) $pr_thresh ); ?>" /></label><br />
					<label><input type="checkbox" name="evote_pr_overhang" value="1" <?php checked( $pr_overhang ); ?> /> <?php esc_html_e( 'Overhang', 'decentralized-evoting' ); ?></label><br />
					<label><?php esc_html_e( 'TSE sobras — % mín. partido', 'decentralized-evoting' ); ?> <input type="number" name="evote_pr_tse_party_pct" min="0" max="100" value="<?php echo esc_attr( (string) $pr_tse_p ); ?>" /></label>
					<label><?php esc_html_e( '% mín. candidato', 'decentralized-evoting' ); ?> <input type="number" name="evote_pr_tse_candidate_pct" min="0" max="100" value="<?php echo esc_attr( (string) $pr_tse_c ); ?>" /></label>
				</td>
			</tr>
			<tr>
				<th><label for="evote_homomorphic_mode"><?php esc_html_e( 'Apuração homomórfica (protótipo)', 'decentralized-evoting' ); ?></label></th>
				<td>
					<select name="evote_homomorphic_mode" id="evote_homomorphic_mode">
						<?php foreach ( EVote_Modality_Registry::homomorphic_mode_options() as $val => $label ) : ?>
							<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $homo_mode, $val ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						<?php
						printf(
							/* translators: %d: max candidates */
							esc_html__( 'One-hot exponencial: até %d candidatos com número na urna; a apuração multiplica cifras e faz um discrete log por candidato. PR continua decrypt-then-count.', 'decentralized-evoting' ),
							(int) EVote_Homomorphic::MAX_ONE_HOT_SLOTS
						);
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Vacâncias (v1)', 'decentralized-evoting' ); ?></th>
				<td><p class="description"><?php esc_html_e( 'Documentação e metadados apenas nesta versão.', 'decentralized-evoting' ); ?></p></td>
			</tr>
		</table>
		<?php
	}

	/**
	 * @param int $post_id Post ID.
	 */
	public static function save( $post_id ) {
		$modality = isset( $_POST['evote_modality_type'] ) ? sanitize_key( wp_unslash( $_POST['evote_modality_type'] ) ) : EVote_Modality_Registry::FPTP;
		if ( ! array_key_exists( $modality, EVote_Modality_Registry::modality_options() ) ) {
			$modality = EVote_Modality_Registry::FPTP;
		}
		update_post_meta( $post_id, '_evote_modality_type', $modality );

		$office = isset( $_POST['evote_office_type'] ) ? sanitize_key( wp_unslash( $_POST['evote_office_type'] ) ) : EVote_Modality_Registry::OFFICE_MAYOR;
		if ( ! array_key_exists( $office, EVote_Modality_Registry::office_options() ) ) {
			$office = EVote_Modality_Registry::OFFICE_MAYOR;
		}
		update_post_meta( $post_id, '_evote_office_type', $office );
		update_post_meta( $post_id, '_evote_seat_count', max( 1, absint( $_POST['evote_seat_count'] ?? 1 ) ) );
		update_post_meta( $post_id, '_evote_allow_blank', ! empty( $_POST['evote_allow_blank'] ) ? 1 : 0 );
		update_post_meta( $post_id, '_evote_allow_null', ! empty( $_POST['evote_allow_null'] ) ? 1 : 0 );
		update_post_meta( $post_id, '_evote_blank_timeout_seconds', absint( $_POST['evote_blank_timeout_seconds'] ?? 120 ) );
		update_post_meta( $post_id, '_evote_ballotage_threshold_pct', (float) ( $_POST['evote_ballotage_threshold_pct'] ?? 50 ) );
		update_post_meta( $post_id, '_evote_ballotage_advance_count', max( 1, absint( $_POST['evote_ballotage_advance_count'] ?? 2 ) ) );
		update_post_meta( $post_id, '_evote_parent_running_id', absint( $_POST['evote_parent_running_id'] ?? 0 ) );
		update_post_meta( $post_id, '_evote_reuse_electors_r2', ! empty( $_POST['evote_reuse_electors_r2'] ) ? 1 : 0 );

		$codes = array();
		if ( ! empty( $_POST['evote_qualified_ballot_codes'] ) ) {
			$lines = preg_split( '/\r\n|\r|\n/', wp_unslash( $_POST['evote_qualified_ballot_codes'] ) );
			foreach ( $lines as $line ) {
				$c = EVote_Ballot_Codes::normalize_code( $line );
				if ( '' !== $c ) {
					$codes[] = $c;
				}
			}
		}
		update_post_meta( $post_id, '_evote_qualified_ballot_codes', $codes );

		$formula = sanitize_key( wp_unslash( $_POST['evote_pr_formula'] ?? 'brazilian' ) );
		update_post_meta( $post_id, '_evote_pr_formula', $formula );
		update_post_meta( $post_id, '_evote_pr_threshold_pct', (float) ( $_POST['evote_pr_threshold_pct'] ?? 0 ) );
		update_post_meta( $post_id, '_evote_pr_overhang', ! empty( $_POST['evote_pr_overhang'] ) ? 1 : 0 );
		update_post_meta( $post_id, '_evote_pr_tse_party_pct', (float) ( $_POST['evote_pr_tse_party_pct'] ?? 80 ) );
		update_post_meta( $post_id, '_evote_pr_tse_candidate_pct', (float) ( $_POST['evote_pr_tse_candidate_pct'] ?? 20 ) );

		$homo = isset( $_POST['evote_homomorphic_mode'] ) ? sanitize_key( wp_unslash( $_POST['evote_homomorphic_mode'] ) ) : EVote_Homomorphic::MODE_OFF;
		if ( ! array_key_exists( $homo, EVote_Modality_Registry::homomorphic_mode_options() ) ) {
			$homo = EVote_Homomorphic::MODE_OFF;
		}
		update_post_meta( $post_id, '_evote_homomorphic_mode', $homo );
	}
}
