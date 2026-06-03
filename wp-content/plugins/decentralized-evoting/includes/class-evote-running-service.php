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
			return new WP_Error( 'evote_invalid_running', __( 'Running not found.', 'decentralized-evoting' ) );
		}

		$public_raw = get_post_meta( $running_id, '_evote_public_key_json', true );
		$public_key = null;
		if ( $public_raw ) {
			$public_key = json_decode( $public_raw, true );
			if ( ! is_array( $public_key ) ) {
				return new WP_Error( 'evote_invalid_pubkey', __( 'Stored public key JSON is invalid.', 'decentralized-evoting' ) );
			}
			$valid = EVote_Json_Payloads::validate_public_key( $public_key );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
		}

		$candidate_ids = get_post_meta( $running_id, '_evote_candidate_ids', true );
		if ( ! is_array( $candidate_ids ) ) {
			$candidate_ids = array();
		}

		$candidates = array();
		foreach ( $candidate_ids as $cid ) {
			$c = get_post( absint( $cid ) );
			if ( $c && 'evote_candidate' === $c->post_type && 'publish' === $c->post_status ) {
				$candidates[] = array(
					'id'    => $c->ID,
					'title' => $c->post_title,
				);
			}
		}

		return array(
			'id'            => $running_id,
			'title'         => $post->post_title,
			'status'        => get_post_meta( $running_id, '_evote_status', true ) ?: 'draft',
			'start'         => get_post_meta( $running_id, '_evote_start_datetime', true ),
			'end'           => get_post_meta( $running_id, '_evote_end_datetime', true ),
			'modality_type' => get_post_meta( $running_id, '_evote_modality_type', true ) ?: 'single',
			'max_choices'   => max( 1, (int) get_post_meta( $running_id, '_evote_max_choices', true ) ),
			'public_key'    => $public_key,
			'candidates'    => $candidates,
		);
	}

	/**
	 * Whether the running accepts votes right now.
	 *
	 * @param array<string, mixed> $config From get_config.
	 * @return true|WP_Error
	 */
	public static function assert_poll_open( $config ) {
		if ( is_wp_error( $config ) ) {
			return $config;
		}
		if ( 'open' !== ( $config['status'] ?? '' ) ) {
			return new WP_Error( 'evote_not_open', __( 'This election is not open for voting.', 'decentralized-evoting' ) );
		}
		if ( empty( $config['public_key'] ) ) {
			return new WP_Error( 'evote_no_pubkey', __( 'No public key configured for this election.', 'decentralized-evoting' ) );
		}
		if ( empty( $config['candidates'] ) ) {
			return new WP_Error( 'evote_no_candidates', __( 'No candidates on this ballot.', 'decentralized-evoting' ) );
		}

		$now = time();
		if ( ! empty( $config['start'] ) ) {
			$start = strtotime( $config['start'] . ' UTC' );
			if ( $start && $now < $start ) {
				return new WP_Error( 'evote_not_started', __( 'Voting has not started yet.', 'decentralized-evoting' ) );
			}
		}
		if ( ! empty( $config['end'] ) ) {
			$end = strtotime( $config['end'] . ' UTC' );
			if ( $end && $now > $end ) {
				return new WP_Error( 'evote_ended', __( 'Voting has ended.', 'decentralized-evoting' ) );
			}
		}

		return true;
	}

	/**
	 * Public config for browser (no secrets).
	 *
	 * @param array<string, mixed> $config Internal config.
	 * @return array<string, mixed>
	 */
	public static function client_config( $config ) {
		return array(
			'runningId'     => $config['id'],
			'title'         => $config['title'],
			'modalityType'  => $config['modality_type'],
			'maxChoices'    => $config['max_choices'],
			'publicKey'     => $config['public_key'],
			'candidates'    => $config['candidates'],
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'nonce'         => wp_create_nonce( 'evote_cast_ballot' ),
		);
	}
}
