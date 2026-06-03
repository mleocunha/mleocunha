<?php
/**
 * FPTP tally (incl. senator / mayor defaults).
 *
 * @package DecentralizedEvoting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * First-past-the-post with blank/null buckets.
 */
class EVote_Tally_Fptp {

	/**
	 * @param array<string, mixed> $export      Ballot export.
	 * @param string               $private_hex Private key.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function tally( array $export, $private_hex ) {
		$config     = $export['running'] ?? array();
		$candidates = $export['candidates'] ?? array();
		$ballots    = $export['ballots'] ?? array();
		$public_key = $export['public_key'] ?? null;

		$by_code = array();
		foreach ( $candidates as $c ) {
			if ( ! empty( $c['ballot_number'] ) ) {
				$by_code[ $c['ballot_number'] ] = $c;
			}
		}

		$counts = array( 'blank' => 0, 'null' => 0, 'unknown' => 0 );
		foreach ( array_keys( $by_code ) as $code ) {
			$counts[ $code ] = 0;
		}

		$decrypted = 0;
		$errors    = 0;

		foreach ( $ballots as $ballot ) {
			$dec = EVote_Crypto::decrypt_ballot( $private_hex, $ballot, $public_key );
			if ( is_wp_error( $dec ) ) {
				++$errors;
				continue;
			}
			++$decrypted;
			$class = EVote_Ballot_Payload::classify( $ballot, $dec );
			if ( 'blank' === $class['type'] ) {
				++$counts['blank'];
				continue;
			}
			if ( 'null' === $class['type'] ) {
				++$counts['null'];
				continue;
			}
			$code = $class['code'];
			if ( $code && isset( $counts[ $code ] ) ) {
				++$counts[ $code ];
			} else {
				++$counts['unknown'];
			}
		}

		$valid_votes = $decrypted - $counts['blank'] - $counts['null'];
		$results     = array();
		foreach ( $candidates as $c ) {
			$code = $c['ballot_number'] ?? '';
			$results[] = array(
				'candidate_id'   => $c['id'],
				'title'        => $c['title'],
				'ballot_number' => $code,
				'votes'        => $counts[ $code ] ?? 0,
			);
		}
		usort( $results, static fn( $a, $b ) => $b['votes'] <=> $a['votes'] );

		$winner = $results[0] ?? null;
		$seats  = (int) ( $config['seat_count'] ?? 1 );

		return array(
			'schema'       => EVote_Json_Payloads::SCHEMA_TALLY_RESULT,
			'version'      => EVote_Json_Payloads::VERSION,
			'tallied_at'   => gmdate( 'c' ),
			'engine'       => 'fptp',
			'running'      => $config,
			'seat_count'   => $seats,
			'ballot_count' => count( $ballots ),
			'decrypted'    => $decrypted,
			'errors'       => $errors,
			'valid_votes'  => $valid_votes,
			'blank'        => $counts['blank'],
			'null'         => $counts['null'],
			'unknown'      => $counts['unknown'],
			'results'      => $results,
			'winners'      => array_slice( $results, 0, $seats ),
			'tie_break'    => 'manual',
			'note'         => __( 'Empates: resolução manual pelo administrador.', 'decentralized-evoting' ),
		);
	}
}
