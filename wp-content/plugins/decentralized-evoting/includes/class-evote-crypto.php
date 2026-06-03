<?php
/**
 * Cryptographic facade — modular ElGamal and Shamir secret sharing.
 *
 * @package DecentralizedEvoting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use phpseclib3\Math\BigInteger;

/**
 * Wrapper for election cryptography (Node 1 keygen, Node 3 reconstruct).
 */
class EVote_Crypto {

	/** Minimum threshold (t). */
	const SSS_MIN_THRESHOLD = 2;

	/** Minimum total shares (n) for 2-of-3 floor. */
	const SSS_MIN_SHARES = 3;

	/** Maximum total shares (n) — evaluation points 1..n. */
	const SSS_MAX_SHARES = 99;

	/**
	 * Whether phpseclib loaded successfully.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( BigInteger::class );
	}

	/**
	 * Smoke test: BigInteger and optional ElGamal roundtrip.
	 *
	 * @param bool $deep Run encrypt/decrypt self-test (slower).
	 * @return true|WP_Error
	 */
	public static function self_test( $deep = false ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'evote_crypto_unavailable', __( 'phpseclib is not loaded.', 'decentralized-evoting' ) );
		}
		try {
			$n = new BigInteger( '255' );
			$m = $n->add( new BigInteger( '1' ) );
			if ( '256' !== (string) $m ) {
				return new WP_Error( 'evote_crypto_test_failed', __( 'BigInteger self-test failed.', 'decentralized-evoting' ) );
			}
			if ( $deep ) {
				$elgamal = EVote_Elgamal::from_rfc3526_group14();
				$pair    = $elgamal->generate_keypair();
				if ( is_wp_error( $pair ) ) {
					return $pair;
				}
				$vote = $elgamal->encode_vote_integer( 1 );
				if ( is_wp_error( $vote ) ) {
					return $vote;
				}
				$ct = $elgamal->encrypt( $vote, $pair['public'] );
				if ( is_wp_error( $ct ) ) {
					return $ct;
				}
				$plain = $elgamal->decrypt( $ct['c1'], $ct['c2'], $pair['private'] );
				if ( is_wp_error( $plain ) ) {
					return $plain;
				}
				if ( ! $plain->equals( $vote ) ) {
					return new WP_Error( 'evote_crypto_roundtrip', __( 'ElGamal encrypt/decrypt roundtrip failed.', 'decentralized-evoting' ) );
				}
			}
		} catch ( Exception $e ) {
			return new WP_Error( 'evote_crypto_exception', $e->getMessage() );
		}
		return true;
	}

	/**
	 * Validate Shamir parameters (t-of-n).
	 *
	 * @param int $threshold t.
	 * @param int $shares    n.
	 * @return true|WP_Error
	 */
	public static function validate_sss_params( $threshold, $shares ) {
		$threshold = absint( $threshold );
		$shares    = absint( $shares );

		if ( $threshold < self::SSS_MIN_THRESHOLD ) {
			return new WP_Error(
				'evote_sss_threshold_low',
				sprintf(
					/* translators: %d: minimum threshold */
					__( 'Threshold must be at least %d.', 'decentralized-evoting' ),
					self::SSS_MIN_THRESHOLD
				)
			);
		}
		if ( $shares < self::SSS_MIN_SHARES ) {
			return new WP_Error(
				'evote_sss_shares_low',
				sprintf(
					/* translators: %d: minimum shares */
					__( 'Total shares must be at least %d (minimum 2-of-3).', 'decentralized-evoting' ),
					self::SSS_MIN_SHARES
				)
			);
		}
		if ( $threshold > $shares ) {
			return new WP_Error( 'evote_sss_threshold_high', __( 'Threshold cannot exceed total shares.', 'decentralized-evoting' ) );
		}
		if ( $shares > self::SSS_MAX_SHARES ) {
			return new WP_Error(
				'evote_sss_shares_max',
				sprintf(
					/* translators: %d: maximum shares */
					__( 'Total shares cannot exceed %d.', 'decentralized-evoting' ),
					self::SSS_MAX_SHARES
				)
			);
		}
		return true;
	}

	/**
	 * Default Shamir parameters: 3-of-5.
	 *
	 * @return array{threshold: int, shares: int}
	 */
	public static function default_sss_params() {
		return array(
			'threshold' => 3,
			'shares'    => 5,
		);
	}

	/**
	 * Generate ElGamal key pair and SSS-split private key.
	 *
	 * Private key is never included in the returned package (verify internally only).
	 *
	 * @param int $threshold Minimum shares to reconstruct.
	 * @param int $shares    Total shares to generate.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function generate_key_material( $threshold, $shares ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'evote_crypto_unavailable', __( 'phpseclib is not loaded.', 'decentralized-evoting' ) );
		}

		$valid = self::validate_sss_params( $threshold, $shares );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$elgamal = EVote_Elgamal::from_rfc3526_group14();
		$params  = $elgamal->get_parameters();
		$pair    = $elgamal->generate_keypair();
		if ( is_wp_error( $pair ) ) {
			return $pair;
		}

		$private = $pair['private']->mod( $params['q'] );
		$split   = EVote_Shamir::split( $private, $threshold, $shares, $params['q'] );
		if ( is_wp_error( $split ) ) {
			return $split;
		}

		$verify = self::verify_split( $split, $private, $params['q'], $threshold );
		if ( is_wp_error( $verify ) ) {
			return $verify;
		}

		$key_id     = self::generate_key_id();
		$created_at = gmdate( 'c' );

		$public_key = array(
			'schema'     => EVote_Json_Payloads::SCHEMA_PUBLIC_KEY,
			'version'    => EVote_Json_Payloads::VERSION,
			'scheme'     => EVote_Elgamal::SCHEME,
			'group'      => 'rfc3526-group14',
			'created_at' => $created_at,
			'key_id'     => $key_id,
			'p'          => EVote_Elgamal::to_hex( $params['p'] ),
			'q'          => EVote_Elgamal::to_hex( $params['q'] ),
			'g'          => EVote_Elgamal::to_hex( $params['g'] ),
			'y'          => EVote_Elgamal::to_hex( $pair['public'] ),
			'meta'       => array(
				'threshold' => $threshold,
				'shares'    => $shares,
			),
		);

		$share_exports = array();
		foreach ( $split as $share ) {
			$share_exports[] = array(
				'schema'       => EVote_Json_Payloads::SCHEMA_SSS_SHARE,
				'version'      => EVote_Json_Payloads::VERSION,
				'scheme'       => EVote_Elgamal::SCHEME,
				'key_id'       => $key_id,
				'share_index'  => $share['share_index'],
				'threshold'    => $threshold,
				'total_shares' => $shares,
				'x'            => $share['x'],
				'value'        => $share['value'],
				'field_prime'  => EVote_Elgamal::to_hex( $params['q'] ),
			);
		}

		return array(
			'key_id'     => $key_id,
			'public_key' => $public_key,
			'shares'     => $share_exports,
			'threshold'  => $threshold,
			'total'      => $shares,
		);
	}

	/**
	 * Reconstruct private key from SSS shares (Node 3).
	 *
	 * @param array<int, array<string, mixed>> $shares Decoded share objects.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function reconstruct_private_key( array $shares ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'evote_crypto_unavailable', __( 'phpseclib is not loaded.', 'decentralized-evoting' ) );
		}
		if ( count( $shares ) < self::SSS_MIN_THRESHOLD ) {
			return new WP_Error( 'evote_sss_insufficient', __( 'Not enough shares to reconstruct.', 'decentralized-evoting' ) );
		}

		$threshold = null;
		$key_id    = null;
		$prime_hex = null;
		$points    = array();

		foreach ( $shares as $share ) {
			$check = EVote_Json_Payloads::validate_sss_share( $share );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
			if ( null === $threshold ) {
				$threshold = (int) $share['threshold'];
				$key_id    = $share['key_id'];
				$prime_hex = $share['field_prime'];
			} elseif ( (int) $share['threshold'] !== $threshold || $share['key_id'] !== $key_id ) {
				return new WP_Error( 'evote_sss_mismatch', __( 'Shares must belong to the same key ceremony.', 'decentralized-evoting' ) );
			}
			$points[] = array(
				'x'     => $share['x'],
				'value' => $share['value'],
			);
		}

		if ( count( $points ) < $threshold ) {
			return new WP_Error(
				'evote_sss_insufficient',
				sprintf(
					/* translators: 1: provided count, 2: required threshold */
					__( 'Provided %1$d shares but %2$d are required.', 'decentralized-evoting' ),
					count( $points ),
					$threshold
				)
			);
		}

		$prime = EVote_Elgamal::from_hex( $prime_hex );
		$secret = EVote_Shamir::combine( $points, $prime );
		if ( is_wp_error( $secret ) ) {
			return $secret;
		}

		return array(
			'key_id'       => $key_id,
			'private'      => EVote_Elgamal::to_hex( $secret ),
			'field_prime'  => $prime_hex,
			'threshold'    => $threshold,
			'shares_used'  => count( $points ),
		);
	}

	/**
	 * Build ElGamal instance from exported public key JSON.
	 *
	 * @param array<string, mixed> $public_key Decoded public key.
	 * @return EVote_Elgamal|WP_Error
	 */
	public static function elgamal_from_public_key( array $public_key ) {
		$valid = EVote_Json_Payloads::validate_public_key( $public_key );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$p = EVote_Elgamal::from_hex( $public_key['p'] );
		$g = EVote_Elgamal::from_hex( $public_key['g'] );
		return new EVote_Elgamal( $p, $g );
	}

	/**
	 * Resolve ElGamal parameters from public key JSON or default group.
	 *
	 * @param array<string, mixed>|null $public_key Optional public key export.
	 * @return EVote_Elgamal|WP_Error
	 */
	public static function elgamal_for_operations( $public_key = null ) {
		if ( null !== $public_key && array() !== $public_key ) {
			return self::elgamal_from_public_key( $public_key );
		}
		return EVote_Elgamal::from_rfc3526_group14();
	}

	/**
	 * Encrypt a vote integer for export / testing (Node 3 helper, mirrors future client flow).
	 *
	 * @param array<string, mixed> $public_key Decoded public key JSON.
	 * @param int                  $vote       Positive vote encoding (e.g. candidate id).
	 * @return array<string, mixed>|WP_Error
	 */
	public static function encrypt_vote( array $public_key, $vote ) {
		$elgamal = self::elgamal_from_public_key( $public_key );
		if ( is_wp_error( $elgamal ) ) {
			return $elgamal;
		}

		$y = EVote_Elgamal::from_hex( $public_key['y'] );
		$m = $elgamal->encode_vote_integer( $vote );
		if ( is_wp_error( $m ) ) {
			return $m;
		}

		$ct = $elgamal->encrypt( $m, $y );
		if ( is_wp_error( $ct ) ) {
			return $ct;
		}

		return array(
			'ballot' => array(
				'schema'            => EVote_Json_Payloads::SCHEMA_BALLOT,
				'version'           => EVote_Json_Payloads::VERSION,
				'key_id'            => $public_key['key_id'] ?? null,
				'message_encoding'  => 'vote-integer',
				'message'           => (string) absint( $vote ),
				'c1'                => EVote_Elgamal::to_hex( $ct['c1'] ),
				'c2'                => EVote_Elgamal::to_hex( $ct['c2'] ),
			),
		);
	}

	/**
	 * Decrypt an encrypted ballot with a reconstructed private key.
	 *
	 * @param string               $private_hex Private key (hex).
	 * @param array<string, mixed> $ballot      Decoded ballot JSON.
	 * @param array<string, mixed> $public_key  Optional public key for group parameters.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function decrypt_ballot( $private_hex, array $ballot, $public_key = null ) {
		$valid = EVote_Json_Payloads::validate_encrypted_ballot( $ballot );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$public_key = is_array( $public_key ) ? $public_key : null;
		$elgamal    = self::elgamal_for_operations( $public_key );
		if ( is_wp_error( $elgamal ) ) {
			return $elgamal;
		}

		$params = $elgamal->get_parameters();
		$x      = EVote_Elgamal::from_hex( $private_hex )->mod( $params['q'] );
		$c1     = EVote_Elgamal::from_hex( $ballot['c1'] );
		$c2     = EVote_Elgamal::from_hex( $ballot['c2'] );

		$plain = $elgamal->decrypt( $c1, $c2, $x );
		if ( is_wp_error( $plain ) ) {
			return $plain;
		}

		$encoding     = $ballot['message_encoding'] ?? '';
		$decoded_vote = null;
		if ( EVote_Modality_Registry::ENC_NUMBER === $encoding || 'vote-integer' === $encoding ) {
			$msg = isset( $ballot['message'] ) ? EVote_Ballot_Codes::normalize_code( $ballot['message'] ) : '';
			if ( '' !== $msg ) {
				$expected = new BigInteger( $msg );
				$decoded_vote = $plain->equals( $expected ) ? $msg : $msg;
			}
		}

		return array(
			'plaintext_hex'    => EVote_Elgamal::to_hex( $plain ),
			'vote_integer'     => $decoded_vote,
			'message_encoding' => $encoding,
			'message'          => $ballot['message'] ?? null,
			'key_id'           => $ballot['key_id'] ?? null,
		);
	}

	/**
	 * Build encrypted ballot for br-number / br-blank / br-nulo.
	 *
	 * @param array<string, mixed> $public_key Public key JSON.
	 * @param string               $encoding   Message encoding constant.
	 * @param string               $message    Payload (digits for br-number).
	 * @return array<string, mixed>|WP_Error
	 */
	public static function encrypt_ballot_payload( array $public_key, $encoding, $message = '' ) {
		$elgamal = self::elgamal_from_public_key( $public_key );
		if ( is_wp_error( $elgamal ) ) {
			return $elgamal;
		}
		$y = EVote_Elgamal::from_hex( $public_key['y'] );

		if ( EVote_Modality_Registry::ENC_BLANK === $encoding ) {
			$m = new BigInteger( '0' );
		} elseif ( EVote_Modality_Registry::ENC_NULL === $encoding ) {
			$m = new BigInteger( '1' );
		} else {
			$message = EVote_Ballot_Codes::normalize_code( $message );
			if ( '' === $message ) {
				return new WP_Error( 'evote_empty_vote', __( 'Código de voto vazio.', 'decentralized-evoting' ) );
			}
			$m = new BigInteger( $message );
		}

		$ct = $elgamal->encrypt( $m, $y );
		if ( is_wp_error( $ct ) ) {
			return $ct;
		}

		return array(
			'ballot' => array(
				'schema'           => EVote_Json_Payloads::SCHEMA_BALLOT,
				'version'          => EVote_Json_Payloads::VERSION,
				'key_id'           => $public_key['key_id'] ?? null,
				'message_encoding' => $encoding,
				'message'          => (string) $message,
				'c1'               => EVote_Elgamal::to_hex( $ct['c1'] ),
				'c2'               => EVote_Elgamal::to_hex( $ct['c2'] ),
			),
		);
	}

	/**
	 * @return string
	 */
	public static function generate_key_id() {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}
		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0x0fff ) | 0x4000,
			wp_rand( 0, 0x3fff ) | 0x8000,
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0xffff )
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $split     Share rows from Shamir::split.
	 * @param BigInteger                       $private   Original secret.
	 * @param BigInteger                       $prime     Field prime.
	 * @param int                              $threshold Required t.
	 * @return true|WP_Error
	 */
	private static function verify_split( array $split, BigInteger $private, BigInteger $prime, $threshold ) {
		$subset = array_slice( $split, 0, $threshold );
		$points = array();
		foreach ( $subset as $row ) {
			$points[] = array(
				'x'     => $row['x'],
				'value' => $row['value'],
			);
		}
		$reconstructed = EVote_Shamir::combine( $points, $prime );
		if ( is_wp_error( $reconstructed ) ) {
			return $reconstructed;
		}
		if ( ! $reconstructed->equals( $private->mod( $prime ) ) ) {
			return new WP_Error( 'evote_sss_verify_failed', __( 'Shamir split verification failed.', 'decentralized-evoting' ) );
		}
		return true;
	}
}
