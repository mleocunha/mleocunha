<?php
/**
 * Ballot code validation and candidate lookup.
 *
 * @package DecentralizedEvoting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Brazilian numeric ballot operations.
 */
class EVote_Ballot_Codes {

	/**
	 * Build candidate index for a running.
	 *
	 * @param array<string, mixed> $config Running config.
	 * @return array<string, array<string, mixed>> Map ballot_number => candidate row.
	 */
	public static function index_for_running( $config ) {
		$index = array();
		foreach ( $config['candidates'] as $c ) {
			if ( ! empty( $c['ballot_number'] ) ) {
				$index[ $c['ballot_number'] ] = $c;
			}
		}
		return $index;
	}

	/**
	 * Normalize typed digits.
	 *
	 * @param string $raw User input.
	 * @return string Digits only.
	 */
	public static function normalize_code( $raw ) {
		return preg_replace( '/\D/', '', (string) $raw );
	}

	/**
	 * @param string               $code   Normalized code.
	 * @param array<string, mixed> $config Running config.
	 * @return true|WP_Error
	 */
	public static function validate_code_length( $code, $config ) {
		$expected = (int) ( $config['code_length'] ?? 5 );
		if ( strlen( $code ) !== $expected ) {
			return new WP_Error(
				'evote_code_length',
				sprintf(
					/* translators: %d: expected digits */
					__( 'O código deve ter %d dígitos.', 'decentralized-evoting' ),
					$expected
				)
			);
		}
		return true;
	}

	/**
	 * Lookup candidate for confirmation UI.
	 *
	 * @param int    $running_id Running ID.
	 * @param string $code       Raw or normalized code.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function lookup( $running_id, $code ) {
		$config = EVote_Running_Service::get_config( $running_id );
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$code = self::normalize_code( $code );
		$len  = self::validate_code_length( $code, $config );
		if ( is_wp_error( $len ) ) {
			return $len;
		}

		$index = self::index_for_running( $config );
		if ( ! isset( $index[ $code ] ) ) {
			return new WP_Error(
				'evote_code_unknown',
				__( 'Número não encontrado nesta eleição.', 'decentralized-evoting' ),
				array(
					'code'   => $code,
					'valid'  => false,
				)
			);
		}

		$c = $index[ $code ];
		return array(
			'valid'          => true,
			'code'           => $code,
			'candidate_id'   => $c['id'],
			'name'           => $c['title'],
			'photo_url'      => $c['photo_url'] ?? '',
			'party_logo_url' => $c['party_logo_url'] ?? '',
			'party_number'   => substr( $code, 0, 2 ),
		);
	}

	/**
	 * Whether code is allowed on this running (R2 qualified list).
	 *
	 * @param string               $code   Normalized code.
	 * @param array<string, mixed> $config Config.
	 * @return bool
	 */
	public static function is_code_allowed( $code, $config ) {
		if ( EVote_Modality_Registry::BALLOTAGE_R2 !== ( $config['modality_type'] ?? '' ) ) {
			return true;
		}
		$allowed = $config['qualified_ballot_codes'] ?? array();
		if ( empty( $allowed ) ) {
			return false;
		}
		return in_array( $code, $allowed, true );
	}

	/**
	 * Party number from full code.
	 *
	 * @param string $code Ballot code.
	 * @return string Two digits.
	 */
	public static function party_number_from_code( $code ) {
		return substr( self::normalize_code( $code ), 0, 2 );
	}
}
