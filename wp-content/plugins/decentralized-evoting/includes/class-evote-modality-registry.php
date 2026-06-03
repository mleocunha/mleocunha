<?php
/**
 * Election modality and office-type registry (Brazil 2026 defaults).
 *
 * @package DecentralizedEvoting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Constants and defaults for runnings.
 */
class EVote_Modality_Registry {

	const FPTP           = 'fptp';
	const BALLOTAGE_R1   = 'ballotage_r1';
	const BALLOTAGE_R2   = 'ballotage_r2';
	const PR_BRAZILIAN   = 'pr_brazilian';

	const PR_FORMULA_BRAZILIAN   = 'brazilian';
	const PR_FORMULA_DHONDT       = 'dhondt';
	const PR_FORMULA_SAINTE_LAGUE = 'sainte_lague';
	const PR_FORMULA_HARE         = 'hare';

	const OFFICE_PRESIDENT       = 'president';
	const OFFICE_GOVERNOR        = 'governor';
	const OFFICE_MAYOR           = 'mayor';
	const OFFICE_SENATOR         = 'senator';
	const OFFICE_FEDERAL_DEPUTY  = 'federal_deputy';
	const OFFICE_STATE_DEPUTY    = 'state_deputy';
	const OFFICE_CITY_COUNCILOR  = 'city_councilor';

	const ENC_NUMBER = 'br-number';
	const ENC_BLANK  = 'br-blank';
	const ENC_NULL   = 'br-nulo';

	/**
	 * @return array<string, string>
	 */
	public static function modality_options() {
		return array(
			self::FPTP         => __( 'Maioria simples (FPTP)', 'decentralized-evoting' ),
			self::BALLOTAGE_R1 => __( 'Ballotage — 1º turno', 'decentralized-evoting' ),
			self::BALLOTAGE_R2 => __( 'Ballotage — 2º turno', 'decentralized-evoting' ),
			self::PR_BRAZILIAN => __( 'Representação proporcional (lista aberta)', 'decentralized-evoting' ),
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function office_options() {
		return array(
			self::OFFICE_PRESIDENT      => __( 'Presidente', 'decentralized-evoting' ),
			self::OFFICE_GOVERNOR       => __( 'Governador', 'decentralized-evoting' ),
			self::OFFICE_MAYOR          => __( 'Prefeito', 'decentralized-evoting' ),
			self::OFFICE_SENATOR        => __( 'Senador', 'decentralized-evoting' ),
			self::OFFICE_FEDERAL_DEPUTY => __( 'Deputado federal', 'decentralized-evoting' ),
			self::OFFICE_STATE_DEPUTY   => __( 'Deputado estadual', 'decentralized-evoting' ),
			self::OFFICE_CITY_COUNCILOR => __( 'Vereador', 'decentralized-evoting' ),
		);
	}

	/**
	 * Total ballot code length (party 2 digits + suffix).
	 *
	 * @param string $office Office slug.
	 * @return int
	 */
	public static function code_length_for_office( $office ) {
		$map = array(
			self::OFFICE_PRESIDENT      => 2,
			self::OFFICE_GOVERNOR       => 2,
			self::OFFICE_MAYOR          => 2,
			self::OFFICE_SENATOR        => 3,
			self::OFFICE_FEDERAL_DEPUTY => 4,
			self::OFFICE_STATE_DEPUTY   => 5,
			self::OFFICE_CITY_COUNCILOR => 5,
		);
		return $map[ $office ] ?? 5;
	}

	/**
	 * Default modality for office (mayor/governor/president often ballotage-capable).
	 *
	 * @param string $office Office slug.
	 * @return string
	 */
	public static function default_modality_for_office( $office ) {
		if ( in_array( $office, array( self::OFFICE_FEDERAL_DEPUTY, self::OFFICE_STATE_DEPUTY, self::OFFICE_CITY_COUNCILOR ), true ) ) {
			return self::PR_BRAZILIAN;
		}
		return self::FPTP;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function default_running_meta() {
		return array(
			'modality_type'              => self::FPTP,
			'office_type'                => self::OFFICE_MAYOR,
			'seat_count'                 => 1,
			'allow_blank'                => 1,
			'allow_null'                 => 1,
			'blank_timeout_seconds'      => 120,
			'ballotage_threshold_pct'    => 50,
			'ballotage_advance_count'    => 2,
			'reuse_electors_r2'          => 1,
			'parent_running_id'          => 0,
			'pr_formula'                 => self::PR_FORMULA_BRAZILIAN,
			'pr_threshold_pct'           => 0,
			'pr_overhang'                => 0,
			'pr_tse_party_pct'           => 80,
			'pr_tse_candidate_pct'       => 20,
			'vacancy_meta_only'          => 1,
			'tie_break'                  => 'manual',
			'homomorphic_mode'           => EVote_Homomorphic::MODE_OFF,
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function homomorphic_mode_options() {
		return array(
			EVote_Homomorphic::MODE_OFF            => __( 'Desligado (decrypt-then-count)', 'decentralized-evoting' ),
			EVote_Homomorphic::MODE_EXP_ONE_HOT    => __( 'Protótipo: one-hot exponencial (FPTP/ballotage)', 'decentralized-evoting' ),
			EVote_Homomorphic::MODE_EXP_REFERENDUM => __( 'Protótipo: referendo (bit exponencial)', 'decentralized-evoting' ),
		);
	}

	/**
	 * Uses numeric Brazil UI.
	 *
	 * @param string $modality Modality slug.
	 * @return bool
	 */
	public static function uses_numeric_ballot( $modality ) {
		return in_array(
			$modality,
			array( self::FPTP, self::BALLOTAGE_R1, self::BALLOTAGE_R2, self::PR_BRAZILIAN ),
			true
		);
	}
}
