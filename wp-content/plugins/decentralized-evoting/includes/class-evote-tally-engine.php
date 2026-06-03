<?php
/**
 * Decrypt ballots and produce tally results.
 *
 * @package DecentralizedEvoting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Batch tally for Node 3.
 */
class EVote_Tally_Engine {

	/**
	 * Tally an imported ballot export with a reconstructed private key.
	 *
	 * @param array<string, mixed> $export      Validated ballot export.
	 * @param string               $private_hex Private key hex.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function tally_export( array $export, $private_hex ) {
		$private_hex = preg_replace( '/\s+/', '', (string) $private_hex );
		if ( '' === $private_hex ) {
			return new WP_Error( 'evote_no_private', __( 'Private key is required.', 'decentralized-evoting' ) );
		}

		$public_key = $export['public_key'] ?? null;
		if ( ! is_array( $public_key ) ) {
			return new WP_Error( 'evote_no_pubkey', __( 'Export missing public key.', 'decentralized-evoting' ) );
		}

		$ballots = $export['ballots'] ?? array();
		if ( ! is_array( $ballots ) ) {
			return new WP_Error( 'evote_no_ballots', __( 'Export missing ballots array.', 'decentralized-evoting' ) );
		}

		$modality   = $export['running']['modality_type'] ?? 'single';
		$candidates = $export['candidates'] ?? array();
		$counts     = array();
		foreach ( $candidates as $c ) {
			$counts[ (string) $c['id'] ] = 0;
		}
		$counts['unknown'] = 0;
		$counts['invalid'] = 0;

		$decrypted = 0;
		$errors    = 0;

		foreach ( $ballots as $ballot ) {
			$result = EVote_Crypto::decrypt_ballot( $private_hex, $ballot, $public_key );
			if ( is_wp_error( $result ) ) {
				++$errors;
				continue;
			}
			++$decrypted;

			$vote_id = $result['vote_integer'] ?? null;
			if ( null === $vote_id ) {
				++$counts['unknown'];
				continue;
			}
			$key = (string) $vote_id;
			if ( isset( $counts[ $key ] ) ) {
				++$counts[ $key ];
			} else {
				++$counts['unknown'];
			}
		}

		$by_candidate = array();
		foreach ( $candidates as $c ) {
			$id = (string) $c['id'];
			$by_candidate[] = array(
				'candidate_id' => $c['id'],
				'title'        => $c['title'],
				'votes'        => $counts[ $id ] ?? 0,
			);
		}

		usort(
			$by_candidate,
			static function ( $a, $b ) {
				return $b['votes'] <=> $a['votes'];
			}
		);

		return array(
			'schema'       => 'evote-tally-result',
			'version'      => EVote_Json_Payloads::VERSION,
			'tallied_at'   => gmdate( 'c' ),
			'running'      => $export['running'] ?? array(),
			'modality'     => $modality,
			'ballot_count' => count( $ballots ),
			'decrypted'    => $decrypted,
			'errors'       => $errors,
			'unknown'      => $counts['unknown'],
			'results'      => $by_candidate,
		);
	}
}
