<?php
/**
 * Running (election) configuration helpers.
 *
 * @package DecentralizedEvoting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read running meta, window, and ballot configuration.
 */
class EVote_Running_Service {

	/**
	 * @param int $running_id Post ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_config( $running_id ) {
		$running_id = absint( $running_id );
		$post       = get_post( $running_id );
		if ( ! $post || 'evote_running' !== $post->post_type ) {
			return new WP_Error( 'evote_invalid_running', __( 'Eleição não encontrada.', 'decentralized-evoting' ) );
		}

		$defaults = EVote_Modality_Registry::default_running_meta();
		$public_key = null;
		$public_raw = get_post_meta( $running_id, '_evote_public_key_json', true );
		if ( $public_raw ) {
			$public_key = json_decode( $public_raw, true );
			if ( ! is_array( $public_key ) ) {
				return new WP_Error( 'evote_invalid_pubkey', __( 'Chave pública JSON inválida.', 'decentralized-evoting' ) );
			}
			$valid = EVote_Json_Payloads::validate_public_key( $public_key );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
		}

		$modality = get_post_meta( $running_id, '_evote_modality_type', true ) ?: $defaults['modality_type'];
		$office   = get_post_meta( $running_id, '_evote_office_type', true ) ?: $defaults['office_type'];
		if ( ! array_key_exists( $modality, EVote_Modality_Registry::modality_options() ) ) {
			$legacy = $modality;
			$modality = 'single' === $legacy || 'multiple' === $legacy ? EVote_Modality_Registry::FPTP : EVote_Modality_Registry::FPTP;
		}

		$candidate_ids = get_post_meta( $running_id, '_evote_candidate_ids', true );
		if ( ! is_array( $candidate_ids ) ) {
			$candidate_ids = array();
		}

		$candidates = array();
		foreach ( $candidate_ids as $cid ) {
			$c = get_post( absint( $cid ) );
			if ( ! $c || 'evote_candidate' !== $c->post_type ) {
				continue;
			}
			$ballot_number = (string) get_post_meta( $c->ID, '_evote_ballot_number', true );
			$photo_url     = get_the_post_thumbnail_url( $c->ID, 'medium' ) ?: '';
			$party_logo    = '';
			$terms         = get_the_terms( $c->ID, 'evote_party' );
			if ( $terms && ! is_wp_error( $terms ) && ! empty( $terms[0] ) ) {
				$party = EVote_Party_Meta::get_party_display( $terms[0]->term_id );
				$party_logo = $party['logo_url'];
			}
			$candidates[] = array(
				'id'            => $c->ID,
				'title'         => $c->post_title,
				'ballot_number' => $ballot_number,
				'photo_url'     => $photo_url,
				'party_logo_url' => $party_logo,
			);
		}

		$qualified_codes = get_post_meta( $running_id, '_evote_qualified_ballot_codes', true );
		if ( ! is_array( $qualified_codes ) ) {
			$qualified_codes = array();
		}

		return array_merge(
			$defaults,
			array(
				'id'                      => $running_id,
				'title'                   => $post->post_title,
				'status'                  => get_post_meta( $running_id, '_evote_status', true ) ?: 'draft',
				'start'                   => get_post_meta( $running_id, '_evote_start_datetime', true ),
				'end'                     => get_post_meta( $running_id, '_evote_end_datetime', true ),
				'modality_type'           => $modality,
				'office_type'             => $office,
				'code_length'             => EVote_Modality_Registry::code_length_for_office( $office ),
				'seat_count'              => max( 1, (int) get_post_meta( $running_id, '_evote_seat_count', true ) ?: 1 ),
				'allow_blank'             => (int) get_post_meta( $running_id, '_evote_allow_blank', true ) !== 0,
				'allow_null'              => (int) get_post_meta( $running_id, '_evote_allow_null', true ) !== 0,
				'blank_timeout_seconds'   => max( 0, (int) get_post_meta( $running_id, '_evote_blank_timeout_seconds', true ) ),
				'ballotage_threshold_pct' => (float) ( get_post_meta( $running_id, '_evote_ballotage_threshold_pct', true ) ?: 50 ),
				'ballotage_advance_count' => max( 1, (int) get_post_meta( $running_id, '_evote_ballotage_advance_count', true ) ?: 2 ),
				'reuse_electors_r2'       => (int) get_post_meta( $running_id, '_evote_reuse_electors_r2', true ) !== 0,
				'parent_running_id'       => (int) get_post_meta( $running_id, '_evote_parent_running_id', true ),
				'pr_formula'                => get_post_meta( $running_id, '_evote_pr_formula', true ) ?: EVote_Modality_Registry::PR_FORMULA_BRAZILIAN,
				'pr_threshold_pct'        => (float) get_post_meta( $running_id, '_evote_pr_threshold_pct', true ),
				'pr_overhang'             => (int) get_post_meta( $running_id, '_evote_pr_overhang', true ) === 1,
				'pr_tse_party_pct'        => (float) ( get_post_meta( $running_id, '_evote_pr_tse_party_pct', true ) ?: 80 ),
				'pr_tse_candidate_pct'    => (float) ( get_post_meta( $running_id, '_evote_pr_tse_candidate_pct', true ) ?: 20 ),
				'qualified_ballot_codes'  => array_map( array( 'EVote_Ballot_Codes', 'normalize_code' ), $qualified_codes ),
				'vacancy_meta_only'       => 1,
				'tie_break'               => 'manual',
				'public_key'              => $public_key,
				'candidates'              => $candidates,
				'max_choices'             => 1,
			)
		);
	}

	/**
	 * @param array<string, mixed> $config Config.
	 * @return true|WP_Error
	 */
	public static function assert_poll_open( $config ) {
		if ( is_wp_error( $config ) ) {
			return $config;
		}
		if ( 'open' !== ( $config['status'] ?? '' ) ) {
			return new WP_Error( 'evote_not_open', __( 'Esta eleição não está aberta para votação.', 'decentralized-evoting' ) );
		}
		if ( empty( $config['public_key'] ) ) {
			return new WP_Error( 'evote_no_pubkey', __( 'Chave pública não configurada.', 'decentralized-evoting' ) );
		}
		if ( empty( $config['candidates'] ) && EVote_Modality_Registry::PR_BRAZILIAN !== ( $config['modality_type'] ?? '' ) ) {
			return new WP_Error( 'evote_no_candidates', __( 'Nenhum candidato na urna.', 'decentralized-evoting' ) );
		}

		$now = time();
		if ( ! empty( $config['start'] ) ) {
			$start = strtotime( $config['start'] . ' UTC' );
			if ( $start && $now < $start ) {
				return new WP_Error( 'evote_not_started', __( 'A votação ainda não começou.', 'decentralized-evoting' ) );
			}
		}
		if ( ! empty( $config['end'] ) ) {
			$end = strtotime( $config['end'] . ' UTC' );
			if ( $end && $now > $end ) {
				return new WP_Error( 'evote_ended', __( 'A votação já encerrou.', 'decentralized-evoting' ) );
			}
		}

		return true;
	}

	/**
	 * @param array<string, mixed> $config Config.
	 * @return array<string, mixed>
	 */
	public static function client_config( $config ) {
		$index = array();
		foreach ( $config['candidates'] as $c ) {
			if ( ! empty( $c['ballot_number'] ) ) {
				$index[ $c['ballot_number'] ] = array(
					'id'            => $c['id'],
					'title'         => $c['title'],
					'photo_url'     => $c['photo_url'],
					'party_logo_url' => $c['party_logo_url'],
				);
			}
		}

		return array(
			'runningId'            => $config['id'],
			'title'                => $config['title'],
			'modalityType'         => $config['modality_type'],
			'officeType'           => $config['office_type'],
			'codeLength'           => $config['code_length'],
			'seatCount'            => $config['seat_count'],
			'allowBlank'           => (bool) $config['allow_blank'],
			'allowNull'            => (bool) $config['allow_null'],
			'blankTimeoutSeconds'  => (int) $config['blank_timeout_seconds'],
			'publicKey'            => $config['public_key'],
			'candidateIndex'       => $index,
			'qualifiedCodes'       => $config['qualified_ballot_codes'],
			'ajaxUrl'              => admin_url( 'admin-ajax.php' ),
			'nonce'                => wp_create_nonce( 'evote_cast_ballot' ),
			'lookupNonce'          => wp_create_nonce( 'evote_lookup_code' ),
			'i18n'                 => array(
				'confirm'       => __( 'Confirmar voto', 'decentralized-evoting' ),
				'clear'         => __( 'Limpar', 'decentralized-evoting' ),
				'branco'        => __( 'Branco', 'decentralized-evoting' ),
				'nulo'          => __( 'Nulo', 'decentralized-evoting' ),
				'invalidWarn'   => __( 'Número inválido. Se confirmar, o voto será registrado como nulo (contado como branco).', 'decentralized-evoting' ),
				'timeoutBlank'  => __( 'Tempo esgotado — voto em branco registrado.', 'decentralized-evoting' ),
				'success'       => __( 'Voto criptografado registrado. Obrigado.', 'decentralized-evoting' ),
				'enterCode'     => __( 'Digite o número do candidato', 'decentralized-evoting' ),
			),
		);
	}
}
