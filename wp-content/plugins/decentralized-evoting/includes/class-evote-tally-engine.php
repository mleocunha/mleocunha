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
 * Routes tally to modality-specific engines.
 */
class EVote_Tally_Engine {

	/**
	 * @param array<string, mixed> $export      Validated ballot export.
	 * @param string               $private_hex Private key hex.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function tally_export( array $export, $private_hex ) {
		$homo = $export['running']['homomorphic_mode'] ?? EVote_Homomorphic::MODE_OFF;
		$modality = $export['running']['modality_type'] ?? EVote_Modality_Registry::FPTP;

		if ( EVote_Homomorphic::MODE_EXP_ONE_HOT === $homo ) {
			if ( in_array( $modality, array( EVote_Modality_Registry::FPTP, EVote_Modality_Registry::BALLOTAGE_R1, EVote_Modality_Registry::BALLOTAGE_R2 ), true ) ) {
				return EVote_Homomorphic::tally_one_hot( $export, $private_hex );
			}
		}
		if ( EVote_Homomorphic::MODE_EXP_REFERENDUM === $homo ) {
			return EVote_Homomorphic::tally_referendum( $export, $private_hex );
		}

		switch ( $modality ) {
			case EVote_Modality_Registry::PR_BRAZILIAN:
				return EVote_Tally_Brazilian_Pr::tally( $export, $private_hex );
			case EVote_Modality_Registry::BALLOTAGE_R1:
			case EVote_Modality_Registry::BALLOTAGE_R2:
				return EVote_Tally_Ballotage::tally( $export, $private_hex );
			case EVote_Modality_Registry::FPTP:
			default:
				return EVote_Tally_Fptp::tally( $export, $private_hex );
		}
	}
}
