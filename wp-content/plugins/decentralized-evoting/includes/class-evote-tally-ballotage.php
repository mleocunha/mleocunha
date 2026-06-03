<?php
/**
 * Ballotage round 1 / round 2 tally.
 *
 * @package DecentralizedEvoting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Majority threshold and runoff advancement.
 */
class EVote_Tally_Ballotage {

	/**
	 * @param array<string, mixed> $export      Export.
	 * @param string               $private_hex Private key.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function tally( array $export, $private_hex ) {
		$config     = $export['running'] ?? array();
		$modality   = $config['modality_type'] ?? EVote_Modality_Registry::BALLOTAGE_R1;
		$fptp       = EVote_Tally_Fptp::tally( $export, $private_hex );
		if ( is_wp_error( $fptp ) ) {
			return $fptp;
		}

		$threshold_pct = (float) ( $config['ballotage_threshold_pct'] ?? 50 );
		$advance_n     = (int) ( $config['ballotage_advance_count'] ?? 2 );
		$valid         = (int) $fptp['valid_votes'];
		$results       = $fptp['results'];

		$outright_winner = null;
		if ( $valid > 0 && EVote_Modality_Registry::BALLOTAGE_R1 === $modality ) {
			$needed = ceil( $valid * ( $threshold_pct / 100 ) );
			if ( ! empty( $results[0] ) && $results[0]['votes'] >= $needed ) {
				$outright_winner = $results[0];
			}
		}

		$advance = array_slice( $results, 0, $advance_n );

		$fptp['engine']            = 'ballotage';
		$fptp['modality']          = $modality;
		$fptp['threshold_percent'] = $threshold_pct;
		$fptp['votes_needed']      = $valid > 0 ? (int) ceil( $valid * ( $threshold_pct / 100 ) ) : 0;
		$fptp['outright_winner']   = $outright_winner;
		$fptp['advance_to_round2'] = $outright_winner ? array() : $advance;
		$fptp['admin_prompt']      = $outright_winner
			? __( 'Vencedor no 1º turno. Não é necessário 2º turno.', 'decentralized-evoting' )
			: sprintf(
				/* translators: %d: number of candidates */
				__( 'Crie manualmente o 2º turno com os %d candidatos mais votados. Use os mesmos números de urna.', 'decentralized-evoting' ),
				count( $advance )
			);

		if ( EVote_Modality_Registry::BALLOTAGE_R2 === $modality ) {
			$fptp['admin_prompt'] = __( '2º turno: apenas votos válidos para candidatos qualificados.', 'decentralized-evoting' );
		}

		return $fptp;
	}
}
