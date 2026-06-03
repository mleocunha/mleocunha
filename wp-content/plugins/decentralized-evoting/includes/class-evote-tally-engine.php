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
		$modality = $export['running']['modality_type'] ?? EVote_Modality_Registry::FPTP;

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
