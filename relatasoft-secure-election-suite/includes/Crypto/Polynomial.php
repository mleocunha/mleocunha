<?php
/**
 * Polynomial arithmetic over a prime field (Horner eval + Lagrange at 0).
 *
 * @package RelataSoft\SecureElectionSuite\Crypto
 */

namespace RelataSoft\SecureElectionSuite\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Field polynomials used by Feldman VSS (coefficients in Z/qZ).
 */
class Polynomial {

	/**
	 * Evaluate polynomial using Horner's method modulo field prime.
	 *
	 * @param array<int,\GMP> $coefficients Coefficients [a0, a1, …].
	 * @param \GMP            $x            Evaluation point.
	 * @param \GMP            $field_prime  Field modulus.
	 */
	public static function rses_evaluate( array $coefficients, \GMP $x, \GMP $field_prime ): \GMP {
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
	 * @param array<int,array{x:\GMP,y:\GMP}> $points      Points.
	 * @param \GMP                            $field_prime Field modulus.
	 */
	public static function rses_lagrange_at_zero( array $points, \GMP $field_prime ): \GMP {
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

			$rses_term   = BigInt::modMul( $rses_point_i['y'], $rses_lambda, $field_prime );
			$rses_secret = BigInt::modAdd( $rses_secret, $rses_term, $field_prime );
		}

		return $rses_secret;
	}

	/**
	 * Reconstruct secret from share points with threshold check.
	 *
	 * @param array<int,array{x:int,y:\GMP}> $shares      Shares.
	 * @param \GMP                           $field_prime Field modulus.
	 * @param int                            $threshold   Required t.
	 * @throws CryptoException If invalid or insufficient.
	 */
	public static function rses_reconstruct_with_threshold( array $shares, \GMP $field_prime, int $threshold ): \GMP {
		if ( $threshold < 2 ) {
			throw new CryptoException( __( 'Threshold must be at least 2.', 'relatasoft-secure-election-suite' ) );
		}
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

		$rses_points = self::rses_normalize_points( array_slice( $shares, 0, $threshold ), $field_prime );
		return self::rses_lagrange_at_zero( $rses_points, $field_prime );
	}

	/**
	 * @param array<int,array{x:int,y:\GMP}> $shares      Shares.
	 * @param \GMP                           $field_prime Field.
	 * @return array<int,array{x:\GMP,y:\GMP}>
	 * @throws CryptoException If invalid.
	 */
	private static function rses_normalize_points( array $shares, \GMP $field_prime ): array {
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
}
