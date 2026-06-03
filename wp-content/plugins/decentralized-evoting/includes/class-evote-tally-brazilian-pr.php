<?php
/**
 * Brazilian open-list PR (quota + highest averages for remainders).
 *
 * @package DecentralizedEvoting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TSE-style hybrid seat allocation (v1).
 */
class EVote_Tally_Brazilian_Pr {

	/**
	 * @param array<string, mixed> $export      Export.
	 * @param string               $private_hex Private key.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function tally( array $export, $private_hex ) {
		$config     = $export['running'] ?? array();
		$seats      = max( 1, (int) ( $config['seat_count'] ?? 1 ) );
		$ballots    = $export['ballots'] ?? array();
		$public_key = $export['public_key'] ?? null;
		$formula    = $config['pr_formula'] ?? EVote_Modality_Registry::PR_FORMULA_BRAZILIAN;

		if ( EVote_Modality_Registry::PR_FORMULA_BRAZILIAN !== $formula ) {
			return new WP_Error( 'evote_pr_formula', __( 'Fórmula PR ainda não implementada nesta versão.', 'decentralized-evoting' ) );
		}

		$party_votes     = array();
		$candidate_votes = array();
		$blank = 0;
		$null  = 0;
		$decrypted = 0;

		foreach ( $ballots as $ballot ) {
			$dec = EVote_Crypto::decrypt_ballot( $private_hex, $ballot, $public_key );
			if ( is_wp_error( $dec ) ) {
				continue;
			}
			++$decrypted;
			$class = EVote_Ballot_Payload::classify( $ballot, $dec );
			if ( 'blank' === $class['type'] ) {
				++$blank;
				continue;
			}
			if ( 'null' === $class['type'] ) {
				++$null;
				continue;
			}
			$code = $class['code'];
			if ( ! $code ) {
				++$null;
				continue;
			}
			$party = EVote_Ballot_Codes::party_number_from_code( $code );
			if ( ! isset( $party_votes[ $party ] ) ) {
				$party_votes[ $party ] = 0;
			}
			$party_votes[ $party ]++;
			if ( ! isset( $candidate_votes[ $code ] ) ) {
				$candidate_votes[ $code ] = 0;
			}
			$candidate_votes[ $code ]++;
		}

		$valid_votes = array_sum( $party_votes );
		if ( $valid_votes < 1 ) {
			return new WP_Error( 'evote_no_valid', __( 'Nenhum voto válido para apuração proporcional.', 'decentralized-evoting' ) );
		}

		$threshold_pct = (float) ( $config['pr_threshold_pct'] ?? 0 );
		if ( $threshold_pct > 0 ) {
			$min_votes = ceil( $valid_votes * ( $threshold_pct / 100 ) );
			foreach ( array_keys( $party_votes ) as $party ) {
				if ( $party_votes[ $party ] < $min_votes ) {
					unset( $party_votes[ $party ] );
				}
			}
		}

		$electoral_quota = (int) floor( $valid_votes / $seats );
		if ( $electoral_quota < 1 ) {
			$electoral_quota = 1;
		}

		$party_seats = array();
		$allocated   = 0;
		foreach ( $party_votes as $party => $votes ) {
			$q = (int) floor( $votes / $electoral_quota );
			$party_seats[ $party ] = $q;
			$allocated += $q;
		}

		$remainder = $seats - $allocated;
		$tse_party = (float) ( $config['pr_tse_party_pct'] ?? 80 ) / 100;
		$tse_cand  = (float) ( $config['pr_tse_candidate_pct'] ?? 20 ) / 100;

		while ( $remainder > 0 && ! empty( $party_votes ) ) {
			$best_party = null;
			$best_avg   = -1;
			foreach ( $party_votes as $party => $votes ) {
				$won = $party_seats[ $party ] ?? 0;
				$avg = $votes / ( $won + 1 );
				if ( $remainder === $seats - $allocated && $won > 0 && $avg < $votes * $tse_party ) {
					continue;
				}
				if ( $avg > $best_avg ) {
					$best_avg   = $avg;
					$best_party = $party;
				}
			}
			if ( null === $best_party ) {
				foreach ( $party_votes as $party => $votes ) {
					$won = $party_seats[ $party ] ?? 0;
					$avg = $votes / ( $won + 1 );
					if ( $avg > $best_avg ) {
						$best_avg   = $avg;
						$best_party = $party;
					}
				}
			}
			if ( null === $best_party ) {
				break;
			}
			$party_seats[ $best_party ] = ( $party_seats[ $best_party ] ?? 0 ) + 1;
			--$remainder;
		}

		$elected = array();
		foreach ( $party_seats as $party => $seat_count ) {
			$cands = array();
			foreach ( $candidate_votes as $code => $cv ) {
				if ( EVote_Ballot_Codes::party_number_from_code( $code ) === $party ) {
					$cands[ $code ] = $cv;
				}
			}
			arsort( $cands );
			$pick = array_slice( array_keys( $cands ), 0, $seat_count );
			foreach ( $pick as $code ) {
				$elected[] = array(
					'ballot_number' => $code,
					'party'         => $party,
					'votes'         => $candidate_votes[ $code ] ?? 0,
				);
			}
		}

		return array(
			'schema'           => EVote_Json_Payloads::SCHEMA_TALLY_RESULT,
			'version'          => EVote_Json_Payloads::VERSION,
			'tallied_at'       => gmdate( 'c' ),
			'engine'           => 'pr_brazilian',
			'running'          => $config,
			'seat_count'       => $seats,
			'electoral_quota'  => $electoral_quota,
			'valid_votes'      => $valid_votes,
			'blank'            => $blank,
			'null'             => $null,
			'party_votes'      => $party_votes,
			'party_seats'      => $party_seats,
			'candidate_votes'  => $candidate_votes,
			'elected'          => $elected,
			'vacancy_meta_only' => true,
		);
	}
}
