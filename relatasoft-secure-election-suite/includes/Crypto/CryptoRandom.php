<?php
/**
 * Cryptographically secure random number generation.
 *
 * @package RelataSoft\SecureElectionSuite\Crypto
 */

namespace RelataSoft\SecureElectionSuite\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Secure random utilities using random_bytes().
 */
class CryptoRandom {

	private const RSES_MAX_REJECTION_LOOPS = 10000;

	/**
	 * Generate cryptographically secure random bytes.
	 *
	 * @param int $length Byte length.
	 * @return string
	 * @throws CryptoException If generation fails.
	 */
	public static function randomBytes( int $length ): string {
		if ( $length < 1 ) {
			throw new CryptoException( __( 'Random byte length must be positive.', 'relatasoft-secure-election-suite' ) );
		}

		try {
			return random_bytes( $length );
		} catch ( \Exception $rses_e ) {
			throw new CryptoException(
				__( 'Failed to generate secure random bytes.', 'relatasoft-secure-election-suite' ),
				0,
				$rses_e
			);
		}
	}

	/**
	 * Generate random integer in [minInclusive, maxInclusive] using rejection sampling.
	 *
	 * @param \GMP $min_inclusive Minimum inclusive.
	 * @param \GMP $max_inclusive Maximum inclusive.
	 * @return \GMP
	 * @throws CryptoException If generation fails.
	 */
	public static function randomIntegerBetween( \GMP $min_inclusive, \GMP $max_inclusive ): \GMP {
		if ( \gmp_cmp( $min_inclusive, $max_inclusive ) > 0 ) {
			throw new CryptoException( __( 'Invalid random range: min exceeds max.', 'relatasoft-secure-election-suite' ) );
		}

		$rses_range = \gmp_add( \gmp_sub( $max_inclusive, $min_inclusive ), \gmp_init( 1 ) );
		$rses_bit_length = BigInt::bitLength( $rses_range );
		$rses_byte_length = (int) ceil( $rses_bit_length / 8 );

		if ( $rses_byte_length < 1 ) {
			$rses_byte_length = 1;
		}

		$rses_loops = 0;

		while ( $rses_loops < self::RSES_MAX_REJECTION_LOOPS ) {
			++$rses_loops;

			$rses_bytes     = self::randomBytes( $rses_byte_length );
			$rses_hex       = bin2hex( $rses_bytes );
			$rses_candidate = \gmp_init( $rses_hex, 16 );

			if ( \gmp_cmp( $rses_candidate, $rses_range ) < 0 ) {
				return \gmp_add( $rses_candidate, $min_inclusive );
			}
		}

		throw new CryptoException( __( 'Failed to generate random integer within range.', 'relatasoft-secure-election-suite' ) );
	}

	/**
	 * Generate random non-zero value modulo m.
	 *
	 * @param \GMP $modulus Modulus.
	 * @return \GMP
	 * @throws CryptoException If generation fails.
	 */
	public static function randomNonZeroModulo( \GMP $modulus ): \GMP {
		if ( \gmp_cmp( $modulus, \gmp_init( 2 ) ) < 0 ) {
			throw new CryptoException( __( 'Modulus must be at least 2.', 'relatasoft-secure-election-suite' ) );
		}

		$rses_max = \gmp_sub( $modulus, \gmp_init( 1 ) );
		$rses_loops = 0;

		while ( $rses_loops < self::RSES_MAX_REJECTION_LOOPS ) {
			++$rses_loops;

			$rses_value = self::randomIntegerBetween( \gmp_init( 1 ), $rses_max );

			if ( \gmp_cmp( $rses_value, \gmp_init( 0 ) ) !== 0 ) {
				return $rses_value;
			}
		}

		throw new CryptoException( __( 'Failed to generate non-zero random modulo value.', 'relatasoft-secure-election-suite' ) );
	}
}
