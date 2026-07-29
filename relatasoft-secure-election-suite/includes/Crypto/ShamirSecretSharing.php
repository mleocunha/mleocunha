<?php
/**
 * Shamir Secret Sharing implementation.
 *
 * @package RelataSoft\SecureElectionSuite\Crypto
 */

namespace RelataSoft\SecureElectionSuite\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Shamir Secret Sharing for ElGamal private exponent splitting.
 */
class ShamirSecretSharing {

	public const RSES_SHARE_VERSION = '1.0';
	public const RSES_SHARE_SCHEME  = 'ShamirSecretSharing';

	/**
	 * Split a secret into Shamir shares.
	 *
	 * @param \GMP $secret       Secret to split.
	 * @param int  $threshold    Threshold t.
	 * @param int  $total_shares Total shares n.
	 * @param \GMP $field_prime  Field prime P.
	 * @return array<int,array{x:int,y:\GMP}>
	 * @throws CryptoException If validation fails.
	 */
	public static function splitSecret( \GMP $secret, int $threshold, int $total_shares, \GMP $field_prime ): array {
		self::rses_validate_split_params( $secret, $threshold, $total_shares, $field_prime );

		$rses_coefficients = array( $secret );

		for ( $rses_i = 1; $rses_i < $threshold; ++$rses_i ) {
			$rses_coefficients[] = CryptoRandom::randomIntegerBetween( \gmp_init( 1 ), \gmp_sub( $field_prime, \gmp_init( 1 ) ) );
		}

		$rses_shares = array();

		for ( $rses_index = 1; $rses_index <= $total_shares; ++$rses_index ) {
			$rses_x = \gmp_init( $rses_index );
			$rses_y = self::evaluatePolynomial( $rses_coefficients, $rses_x, $field_prime );

			$rses_shares[] = array(
				'x' => $rses_index,
				'y' => $rses_y,
			);
		}

		return $rses_shares;
	}

	/**
	 * Reconstruct secret from shares using Lagrange interpolation at x=0.
	 *
	 * @param array<int,array{x:int,y:\GMP}> $shares      Share points.
	 * @param \GMP                           $field_prime Field prime.
	 * @return \GMP Reconstructed secret.
	 * @throws CryptoException If validation fails.
	 */
	public static function reconstructSecret( array $shares, \GMP $field_prime ): \GMP {
		$rses_points = self::rses_normalize_share_points( $shares, $field_prime );

		if ( count( $rses_points ) < 2 ) {
			throw new CryptoException( __( 'At least 2 shares required for reconstruction.', 'relatasoft-secure-election-suite' ) );
		}

		return self::lagrangeInterpolateAtZero( $rses_points, $field_prime );
	}

	/**
	 * Evaluate polynomial using Horner's method modulo field prime.
	 *
	 * @param array<int,\GMP> $coefficients Polynomial coefficients [a0, a1, ...].
	 * @param \GMP           $x            Evaluation point.
	 * @param \GMP           $field_prime  Field prime.
	 * @return \GMP
	 */
	public static function evaluatePolynomial( array $coefficients, \GMP $x, \GMP $field_prime ): \GMP {
		$rses_result = \gmp_init( 0 );
		$rses_degree = count( $coefficients ) - 1;

		for ( $rses_i = $rses_degree; $rses_i >= 0; --$rses_i ) {
			$rses_result = BigInt::modAdd(
				BigInt::modMul( $rses_result, $x, $field_prime ),
				$coefficients[ $rses_i ],
				$field_prime
			);
		}

		return $rses_result;
	}

	/**
	 * Lagrange interpolation at x = 0.
	 *
	 * @param array<int,array{x:\GMP,y:\GMP}> $points      Share points.
	 * @param \GMP                            $field_prime Field prime.
	 * @return \GMP
	 */
	public static function lagrangeInterpolateAtZero( array $points, \GMP $field_prime ): \GMP {
		$rses_secret = \gmp_init( 0 );
		$rses_zero   = \gmp_init( 0 );

		foreach ( $points as $rses_i => $rses_point_i ) {
			$rses_numerator   = \gmp_init( 1 );
			$rses_denominator = \gmp_init( 1 );

			foreach ( $points as $rses_j => $rses_point_j ) {
				if ( $rses_i === $rses_j ) {
					continue;
				}

				$rses_num_term = BigInt::modSub( $rses_zero, $rses_point_j['x'], $field_prime );
				$rses_den_term = BigInt::modSub( $rses_point_i['x'], $rses_point_j['x'], $field_prime );

				$rses_numerator   = BigInt::modMul( $rses_numerator, $rses_num_term, $field_prime );
				$rses_denominator = BigInt::modMul( $rses_denominator, $rses_den_term, $field_prime );
			}

			$rses_lambda = BigInt::modMul(
				$rses_numerator,
				BigInt::modInv( $rses_denominator, $field_prime ),
				$field_prime
			);

			$rses_term = BigInt::modMul( $rses_point_i['y'], $rses_lambda, $field_prime );
			$rses_secret = BigInt::modAdd( $rses_secret, $rses_term, $field_prime );
		}

		return $rses_secret;
	}

	/**
	 * Validate a share payload structure and checksum.
	 *
	 * @param array<string,mixed> $payload Share payload.
	 * @throws CryptoException If invalid.
	 */
	public static function validateSharePayload( array $payload ): void {
		$rses_required = array(
			'version',
			'scheme',
			'key_id',
			'election_round_id',
			'threshold_t',
			'total_n',
			'field_prime',
			'share_index',
			'share_value',
			'public_key',
			'checksum',
		);

		foreach ( $rses_required as $rses_key ) {
			if ( ! array_key_exists( $rses_key, $payload ) ) {
				throw new CryptoException(
					sprintf(
						/* translators: %s: field name */
						__( 'Missing required share field: %s', 'relatasoft-secure-election-suite' ),
						$rses_key
					)
				);
			}
		}

		if ( self::RSES_SHARE_SCHEME !== $payload['scheme'] ) {
			throw new CryptoException( __( 'Invalid Shamir share scheme.', 'relatasoft-secure-election-suite' ) );
		}

		if ( ! is_array( $payload['public_key'] ) ) {
			throw new CryptoException( __( 'Invalid public key in share payload.', 'relatasoft-secure-election-suite' ) );
		}

		$rses_pk_required = array( 'p', 'q', 'g', 'y' );
		foreach ( $rses_pk_required as $rses_pk_key ) {
			if ( empty( $payload['public_key'][ $rses_pk_key ] ) ) {
				throw new CryptoException( __( 'Incomplete public key in share payload.', 'relatasoft-secure-election-suite' ) );
			}
		}

		$rses_checksum        = (string) $payload['checksum'];
		$rses_expected        = self::rses_compute_share_checksum( $payload );
		if ( ! hash_equals( $rses_expected, $rses_checksum ) ) {
			throw new CryptoException( __( 'Share checksum validation failed.', 'relatasoft-secure-election-suite' ) );
		}
	}

	/**
	 * Build a share payload JSON structure.
	 *
	 * @param int                 $key_id            Key ID.
	 * @param int                 $election_round_id Election round ID.
	 * @param int                 $threshold         Threshold t.
	 * @param int                 $total             Total n.
	 * @param \GMP                $field_prime       Field prime.
	 * @param int                 $share_index       Share index.
	 * @param \GMP                $share_value       Share value.
	 * @param array<string,string> $public_key        Public key fields.
	 * @return array<string,mixed>
	 */
	public static function buildSharePayload(
		int $key_id,
		int $election_round_id,
		int $threshold,
		int $total,
		\GMP $field_prime,
		int $share_index,
		\GMP $share_value,
		array $public_key
	): array {
		$rses_payload = array(
			'version'           => self::RSES_SHARE_VERSION,
			'scheme'            => self::RSES_SHARE_SCHEME,
			'key_id'            => $key_id,
			'election_round_id' => $election_round_id,
			'threshold_t'       => $threshold,
			'total_n'           => $total,
			'field_prime'       => BigInt::toDecimalString( $field_prime ),
			'share_index'       => (string) $share_index,
			'share_value'       => BigInt::toDecimalString( $share_value ),
			'public_key'        => array(
				'p' => $public_key['p'],
				'q' => $public_key['q'],
				'g' => $public_key['g'],
				'y' => $public_key['y'],
			),
		);

		$rses_payload['checksum'] = self::rses_compute_share_checksum( $rses_payload );

		return $rses_payload;
	}

	/**
	 * Compute SHA-256 checksum over canonical share payload (excluding checksum).
	 *
	 * @param array<string,mixed> $payload Payload.
	 * @return string Hex checksum.
	 */
	public static function rses_compute_share_checksum( array $payload ): string {
		$rses_data = $payload;
		unset( $rses_data['checksum'] );
		$rses_canonical = wp_json_encode( $rses_data, JSON_UNESCAPED_SLASHES );
		return hash( 'sha256', (string) $rses_canonical );
	}

	/**
	 * Validate split parameters.
	 *
	 * @param \GMP $secret       Secret.
	 * @param int  $threshold    Threshold.
	 * @param int  $total_shares Total shares.
	 * @param \GMP $field_prime  Field prime.
	 * @throws CryptoException If invalid.
	 */
	private static function rses_validate_split_params( \GMP $secret, int $threshold, int $total_shares, \GMP $field_prime ): void {
		if ( $threshold < 2 ) {
			throw new CryptoException( __( 'Shamir threshold must be at least 2.', 'relatasoft-secure-election-suite' ) );
		}

		if ( $total_shares < $threshold ) {
			throw new CryptoException( __( 'Total shares must be >= threshold.', 'relatasoft-secure-election-suite' ) );
		}

		if ( \gmp_cmp( $secret, \gmp_init( 0 ) ) <= 0 ) {
			throw new CryptoException( __( 'Secret must be positive.', 'relatasoft-secure-election-suite' ) );
		}

		if ( \gmp_cmp( $field_prime, $secret ) <= 0 ) {
			throw new CryptoException( __( 'Field prime must be greater than secret.', 'relatasoft-secure-election-suite' ) );
		}

		if ( \gmp_cmp( $field_prime, \gmp_init( $total_shares ) ) <= 0 ) {
			throw new CryptoException( __( 'Field prime must be greater than total shares.', 'relatasoft-secure-election-suite' ) );
		}

		if ( ! PrimeGenerator::isProbablePrime( $field_prime ) ) {
			throw new CryptoException( __( 'Field prime must be a probable prime.', 'relatasoft-secure-election-suite' ) );
		}
	}

	/**
	 * Normalize and validate share points.
	 *
	 * @param array<int,array{x:int,y:\GMP}> $shares      Shares.
	 * @param \GMP                           $field_prime Field prime.
	 * @return array<int,array{x:\GMP,y:\GMP}>
	 */
	private static function rses_normalize_share_points( array $shares, \GMP $field_prime ): array {
		$rses_points   = array();
		$rses_seen_x   = array();
		$rses_p_minus1 = \gmp_sub( $field_prime, \gmp_init( 1 ) );

		foreach ( $shares as $rses_share ) {
			if ( ! isset( $rses_share['x'], $rses_share['y'] ) ) {
				throw new CryptoException( __( 'Invalid share structure.', 'relatasoft-secure-election-suite' ) );
			}

			$rses_x = \gmp_init( (int) $rses_share['x'] );
			$rses_y = $rses_share['y'];

			if ( \gmp_cmp( $rses_x, \gmp_init( 0 ) ) <= 0 ) {
				throw new CryptoException( __( 'Share index must be positive.', 'relatasoft-secure-election-suite' ) );
			}

			$rses_x_key = \gmp_strval( $rses_x, 10 );
			if ( isset( $rses_seen_x[ $rses_x_key ] ) ) {
				throw new CryptoException( __( 'Duplicate share index detected.', 'relatasoft-secure-election-suite' ) );
			}
			$rses_seen_x[ $rses_x_key ] = true;

			BigInt::assertInRange( $rses_y, \gmp_init( 0 ), $rses_p_minus1 );

			$rses_points[] = array(
				'x' => $rses_x,
				'y' => $rses_y,
			);
		}

		return $rses_points;
	}

	/**
	 * Reconstruct with minimum threshold validation.
	 *
	 * @param array<int,array{x:int,y:\GMP}> $shares      Shares.
	 * @param \GMP                           $field_prime Field prime.
	 * @param int                            $threshold   Required threshold.
	 * @return \GMP
	 */
	public static function reconstructWithThreshold( array $shares, \GMP $field_prime, int $threshold ): \GMP {
		if ( count( $shares ) < $threshold ) {
			throw new CryptoException(
				sprintf(
					/* translators: 1: provided shares, 2: required threshold */
					__( 'Insufficient shares: %1$d provided, %2$d required.', 'relatasoft-secure-election-suite' ),
					count( $shares ),
					$threshold
				)
			);
		}

		$rses_subset = array_slice( $shares, 0, $threshold );
		return self::reconstructSecret( $rses_subset, $field_prime );
	}
}
