<?php
/**
 * Homomorphic tally prototype (exponential ElGamal).
 *
 * @package DecentralizedEvoting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Multiply ciphertexts without per-ballot decryption (prototype).
 */
class EVote_Homomorphic {

	const MODE_OFF            = 'off';
	const MODE_EXP_ONE_HOT    = 'exp_one_hot';
	const MODE_EXP_REFERENDUM = 'exp_referendum';

	/** Max candidate slots for client one-hot prototype (O(n) ciphertexts per voter). */
	const MAX_ONE_HOT_SLOTS = 12;

	const ENC_EXP_ONE_HOT = 'br-exp-one-hot';
	const ENC_EXP_BIT     = 'br-exp-bit';

	/**
	 * @param array<string, mixed> $public_key Public key JSON.
	 * @param int                  $bit        0 or 1.
	 * @return array{c1: string, c2: string}|WP_Error
	 */
	public static function encrypt_bit( array $public_key, $bit ) {
		$elgamal = EVote_Crypto::elgamal_from_public_key( $public_key );
		if ( is_wp_error( $elgamal ) ) {
			return $elgamal;
		}
		$y  = EVote_Elgamal::from_hex( $public_key['y'] );
		$ct = $elgamal->encrypt_exponential( $bit ? 1 : 0, $y );
		return array(
			'c1' => EVote_Elgamal::to_hex( $ct['c1'] ),
			'c2' => EVote_Elgamal::to_hex( $ct['c2'] ),
		);
	}

	/**
	 * Build one-hot ballot JSON for a chosen candidate code.
	 *
	 * @param array<string, mixed> $public_key  Public key.
	 * @param array<int, array<string, mixed>> $candidates Ordered candidates with ballot_number.
	 * @param string               $chosen_code Selected code.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function build_one_hot_ballot( array $public_key, array $candidates, $chosen_code ) {
		$chosen_code = EVote_Ballot_Codes::normalize_code( $chosen_code );
		$slots       = array();
		$found       = false;
		foreach ( $candidates as $c ) {
			$code = EVote_Ballot_Codes::normalize_code( $c['ballot_number'] ?? '' );
			if ( '' === $code ) {
				continue;
			}
			$bit = ( $code === $chosen_code ) ? 1 : 0;
			if ( $bit ) {
				$found = true;
			}
			$ct = self::encrypt_bit( $public_key, $bit );
			if ( is_wp_error( $ct ) ) {
				return $ct;
			}
			$slots[] = array(
				'code' => $code,
				'bit'  => $bit,
				'c1'   => $ct['c1'],
				'c2'   => $ct['c2'],
			);
		}
		if ( ! $found && '' !== $chosen_code ) {
			return new WP_Error( 'evote_homo_code', __( 'Código não está na lista de candidatos.', 'decentralized-evoting' ) );
		}
		return array(
			'schema'           => EVote_Json_Payloads::SCHEMA_BALLOT,
			'version'          => '2',
			'key_id'           => $public_key['key_id'] ?? null,
			'message_encoding' => self::ENC_EXP_ONE_HOT,
			'selected_code'    => $chosen_code,
			'slots'            => $slots,
		);
	}

	/**
	 * Homomorphic one-hot tally: per-candidate count without decrypting individual ballots.
	 *
	 * @param array<string, mixed> $export      Ballot export.
	 * @param string               $private_hex Private key.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function tally_one_hot( array $export, $private_hex ) {
		$public_key = $export['public_key'] ?? null;
		if ( ! is_array( $public_key ) ) {
			return new WP_Error( 'evote_no_pubkey', __( 'Export sem chave pública.', 'decentralized-evoting' ) );
		}
		$elgamal = EVote_Crypto::elgamal_from_public_key( $public_key );
		if ( is_wp_error( $elgamal ) ) {
			return $elgamal;
		}
		$params = $elgamal->get_parameters();
		$x      = EVote_Elgamal::from_hex( $private_hex )->mod( $params['q'] );

		$candidates = $export['candidates'] ?? array();
		$codes      = array();
		foreach ( $candidates as $c ) {
			$code = EVote_Ballot_Codes::normalize_code( $c['ballot_number'] ?? '' );
			if ( '' !== $code ) {
				$codes[] = $code;
			}
		}
		if ( empty( $codes ) ) {
			return new WP_Error( 'evote_homo_codes', __( 'Sem códigos de candidatos.', 'decentralized-evoting' ) );
		}

		$ballots = $export['ballots'] ?? array();
		$slot_parts = array();
		foreach ( $codes as $code ) {
			$slot_parts[ $code ] = array();
		}

		$used = 0;
		foreach ( $ballots as $ballot ) {
			if ( ( $ballot['message_encoding'] ?? '' ) !== self::ENC_EXP_ONE_HOT || empty( $ballot['slots'] ) ) {
				continue;
			}
			++$used;
			foreach ( $ballot['slots'] as $slot ) {
				$code = EVote_Ballot_Codes::normalize_code( $slot['code'] ?? '' );
				if ( ! isset( $slot_parts[ $code ] ) ) {
					continue;
				}
				$slot_parts[ $code ][] = array(
					'c1' => EVote_Elgamal::from_hex( $slot['c1'] ),
					'c2' => EVote_Elgamal::from_hex( $slot['c2'] ),
				);
			}
		}

		if ( $used < 1 ) {
			return new WP_Error( 'evote_homo_ballots', __( 'Nenhuma cédula homomórfica (br-exp-one-hot) no export.', 'decentralized-evoting' ) );
		}

		$max_votes = count( $ballots ) + 1;
		$results   = array();
		$verify    = array();

		foreach ( $codes as $code ) {
			$parts = $slot_parts[ $code ];
			if ( empty( $parts ) ) {
				$results[] = array( 'ballot_number' => $code, 'votes' => 0, 'method' => 'homomorphic' );
				continue;
			}
			$agg    = $elgamal->multiply_ciphertexts( $parts );
			$g_m    = $elgamal->decrypt_exponential( $agg['c1'], $agg['c2'], $x );
			$count  = $elgamal->discrete_log_small( $g_m, $max_votes );
			if ( null === $count ) {
				return new WP_Error( 'evote_homo_dlog', sprintf( __( 'Discrete log falhou para código %s.', 'decentralized-evoting' ), $code ) );
			}
			$results[] = array( 'ballot_number' => $code, 'votes' => $count, 'method' => 'homomorphic' );
		}

		// Verification pass: decrypt-then-count on same subset.
		foreach ( $codes as $code ) {
			$verify[ $code ] = 0;
		}
		foreach ( $ballots as $ballot ) {
			if ( ( $ballot['message_encoding'] ?? '' ) !== self::ENC_EXP_ONE_HOT ) {
				continue;
			}
			foreach ( $ballot['slots'] as $slot ) {
				if ( ! empty( $slot['bit'] ) ) {
					$c = EVote_Ballot_Codes::normalize_code( $slot['code'] ?? '' );
					if ( isset( $verify[ $c ] ) ) {
						++$verify[ $c ];
					}
				}
			}
		}

		$match = true;
		foreach ( $results as $row ) {
			$c = $row['ballot_number'];
			if ( ( $verify[ $c ] ?? -1 ) !== $row['votes'] ) {
				$match = false;
				break;
			}
		}

		return array(
			'schema'        => 'evote-tally-result',
			'version'       => EVote_Json_Payloads::VERSION,
			'tallied_at'    => gmdate( 'c' ),
			'engine'        => 'homomorphic_exp_one_hot',
			'running'       => $export['running'] ?? array(),
			'ballot_count'  => count( $ballots ),
			'homo_ballots'  => $used,
			'results'       => $results,
			'verify_decrypt' => $verify,
			'verify_match'  => $match,
			'note'          => __( 'Protótipo: multiplicação de cifras (exponential ElGamal). Produção pode manter decrypt-then-count até Paillier/STV homomórfico completo.', 'decentralized-evoting' ),
		);
	}

	/**
	 * Referendum-style: each ballot encodes bit 0/1; product decrypt = g^total_yes.
	 *
	 * @param array<string, mixed> $export      Export.
	 * @param string               $private_hex Private key.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function tally_referendum( array $export, $private_hex ) {
		$public_key = $export['public_key'] ?? null;
		if ( ! is_array( $public_key ) ) {
			return new WP_Error( 'evote_no_pubkey', __( 'Export sem chave pública.', 'decentralized-evoting' ) );
		}
		$elgamal = EVote_Crypto::elgamal_from_public_key( $public_key );
		if ( is_wp_error( $elgamal ) ) {
			return $elgamal;
		}
		$x      = EVote_Elgamal::from_hex( $private_hex )->mod( $elgamal->get_parameters()['q'] );
		$parts  = array();
		$yes_dc = 0;
		foreach ( $export['ballots'] ?? array() as $ballot ) {
			if ( ( $ballot['message_encoding'] ?? '' ) !== self::ENC_EXP_BIT ) {
				continue;
			}
			$parts[] = array(
				'c1' => EVote_Elgamal::from_hex( $ballot['c1'] ),
				'c2' => EVote_Elgamal::from_hex( $ballot['c2'] ),
			);
			if ( '1' === (string) ( $ballot['message'] ?? '' ) ) {
				++$yes_dc;
			}
		}
		if ( empty( $parts ) ) {
			return new WP_Error( 'evote_homo_empty', __( 'Nenhuma cédula br-exp-bit.', 'decentralized-evoting' ) );
		}
		$agg   = $elgamal->multiply_ciphertexts( $parts );
		$g_m   = $elgamal->decrypt_exponential( $agg['c1'], $agg['c2'], $x );
		$yes_h = $elgamal->discrete_log_small( $g_m, count( $parts ) + 1 );

		return array(
			'schema'         => 'evote-tally-result',
			'engine'         => 'homomorphic_exp_referendum',
			'yes_votes_homo' => $yes_h,
			'yes_votes_decrypt' => $yes_dc,
			'verify_match'   => ( $yes_h === $yes_dc ),
			'total_ballots'  => count( $parts ),
		);
	}
}
