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
			'running'     => self::export_running_meta( $config ),
			'stats'       => $stats,
			'public_key'  => $config['public_key'],
			'candidates'  => $config['candidates'],
			'ballots'     => $ballots,
		);

		$payload['checksum'] = EVote_Json_Payloads::compute_checksum( $payload );

		return $payload;
	}

	/**
	 * @param array<string, mixed> $config Full config.
	 * @return array<string, mixed>
	 */
	private static function export_running_meta( $config ) {
		$keys = array(
			'id', 'title', 'modality_type', 'office_type', 'code_length', 'seat_count',
			'allow_blank', 'allow_null', 'blank_timeout_seconds',
			'ballotage_threshold_pct', 'ballotage_advance_count', 'reuse_electors_r2', 'parent_running_id',
			'pr_formula', 'pr_threshold_pct', 'pr_overhang', 'pr_tse_party_pct', 'pr_tse_candidate_pct',
			'qualified_ballot_codes',
		);
		$out = array();
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $config ) ) {
				$out[ $key ] = $config[ $key ];
			}
		}
		return $out;
	}
}
