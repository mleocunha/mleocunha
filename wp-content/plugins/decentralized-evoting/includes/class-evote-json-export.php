<?php
/**
 * Build signed JSON exports for cross-node transfer.
 *
 * @package DecentralizedEvoting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ballot box export (Node 2 → Node 3).
 */
class EVote_Json_Export {

	/**
	 * Build ballot export package for a running.
	 *
	 * @param int $running_id Running post ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function ballot_box( $running_id ) {
		$config = EVote_Running_Service::get_config( $running_id );
		if ( is_wp_error( $config ) ) {
			return $config;
		}
		if ( empty( $config['public_key'] ) ) {
			return new WP_Error( 'evote_no_pubkey', __( 'Cannot export without a public key on this running.', 'decentralized-evoting' ) );
		}

		$ballots = EVote_Ballot_Repository::get_ballots_for_running( $running_id );
		$stats   = EVote_Ballot_Repository::count_stats( $running_id );

		$payload = array(
			'schema'      => EVote_Json_Payloads::SCHEMA_BALLOT_EXPORT,
			'version'     => EVote_Json_Payloads::VERSION,
			'exported_at' => gmdate( 'c' ),
			'running'     => array(
				'id'            => $config['id'],
				'title'         => $config['title'],
				'modality_type' => $config['modality_type'],
				'max_choices'   => $config['max_choices'],
			),
			'stats'       => $stats,
			'public_key'  => $config['public_key'],
			'candidates'  => $config['candidates'],
			'ballots'     => $ballots,
		);

		$payload['checksum'] = EVote_Json_Payloads::compute_checksum( $payload );

		return $payload;
	}
}
