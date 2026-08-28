<?php
declare(strict_types=1);
/**
 * Prime number generation for ElGamal and Shamir.
 *
 * Portable Domain crypto (A1) — no WordPress runtime required.
 *
 * @package RelataSoft\SecureElectionSuite\Painel\Domain\Crypto
 */

namespace RelataSoft\SecureElectionSuite\Painel\Domain\Crypto;


/**
 * Probable and safe prime generation.
 */
class PrimeGenerator {

	private const RSES_MAX_SAFE_PRIME_ATTEMPTS = 10000;
	private const RSES_MAX_GENERATOR_ATTEMPTS  = 10000;
	private const RSES_PROB_PRIME_REPS         = 25;

	/**
	 * Generate a probable prime with given bit length.
	 *
	 * @param int $bits Bit length.
	 * @return \GMP
	 * @throws CryptoException If generation fails.
	 */
	public static function generateProbablePrime( int $bits ): \GMP {
		if ( $bits < 2 ) {
			throw new CryptoException( 'Prime bit length must be at least 2.' );
		}

		$rses_loops = 0;

		while ( $rses_loops < self::RSES_MAX_SAFE_PRIME_ATTEMPTS ) {
			++$rses_loops;

			$rses_candidate = self::rses_random_odd_of_bit_length( $bits );

			if ( self::isProbablePrime( $rses_candidate ) ) {
				return $rses_candidate;
			}
		}

		throw new CryptoException( 'Failed to generate probable prime.' );
	}

	/**
	 * Generate a safe prime p = 2q + 1 where q is also prime.
	 *
	 * @param int $bits Bit length for p.
	 * @return array{0:\GMP,1:\GMP} [p, q]
	 * @throws CryptoException If generation fails.
	 */
	public static function generateSafePrime( int $bits ): array {
		if ( $bits < 3 ) {
			throw new CryptoException( 'Safe prime bit length must be at least 3.' );
		}

		$rses_q_bits = $bits - 1;
		$rses_loops  = 0;

		while ( $rses_loops < self::RSES_MAX_SAFE_PRIME_ATTEMPTS ) {
			++$rses_loops;

			$rses_q = self::generateProbablePrime( $rses_q_bits );
			$rses_p = \gmp_add( \gmp_mul( \gmp_init( 2 ), $rses_q ), \gmp_init( 1 ) );

			if ( BigInt::bitLength( $rses_p ) !== $bits ) {
				continue;
			}

			if ( self::isProbablePrime( $rses_p ) && self::isProbablePrime( $rses_q ) ) {
				return array( $rses_p, $rses_q );
			}
		}

		throw new CryptoException( 'Failed to generate safe prime.' );
	}

	/**
	 * Test if n is a probable prime.
	 *
	 * @param \GMP $n Candidate.
	 * @return bool
	 */
	public static function isProbablePrime( \GMP $n ): bool {
		return \gmp_prob_prime( $n, self::RSES_PROB_PRIME_REPS ) > 0;
	}

	/**
	 * Try one Sophie-Germain candidate q for a safe prime p = 2q + 1.
	 *
	 * Used by checkpointed key generation so the same attempt can be resumed.
	 *
	 * @param \GMP $q_candidate Odd candidate for q (bit length bits-1).
	 * @param int  $bits        Desired bit length of p.
	 * @return array{0:\GMP,1:\GMP}|null [p, q] or null if rejected.
	 */
	public static function trySafePrimeFromQ( \GMP $q_candidate, int $bits ): ?array {
		if ( $bits < 3 ) {
			return null;
		}

		if ( ! self::isProbablePrime( $q_candidate ) ) {
			return null;
		}

		$rses_p = \gmp_add( \gmp_mul( \gmp_init( 2 ), $q_candidate ), \gmp_init( 1 ) );
		if ( BigInt::bitLength( $rses_p ) !== $bits ) {
			return null;
		}

		if ( ! self::isProbablePrime( $rses_p ) ) {
			return null;
		}

		return array( $rses_p, $q_candidate );
	}

	/**
	 * Find generator g of subgroup of order q for safe prime p = 2q + 1.
	 *
	 * @param \GMP $p Safe prime.
	 * @param \GMP $q Subgroup order.
	 * @return \GMP
	 * @throws CryptoException If generator not found.
	 */
	public static function findGeneratorForSafePrime( \GMP $p, \GMP $q ): \GMP {
		$rses_two   = \gmp_init( 2 );
		$rses_p_min = \gmp_sub( $p, $rses_two );
		$rses_loops = 0;

		while ( $rses_loops < self::RSES_MAX_GENERATOR_ATTEMPTS ) {
			++$rses_loops;

			$rses_h = CryptoRandom::randomIntegerBetween( $rses_two, $rses_p_min );
			$rses_g = BigInt::modPow( $rses_h, $rses_two, $p );

			if ( \gmp_cmp( $rses_g, \gmp_init( 1 ) ) > 0 ) {
				$rses_check = BigInt::modPow( $rses_g, $q, $p );
				if ( \gmp_cmp( $rses_check, \gmp_init( 1 ) ) === 0 ) {
					return $rses_g;
				}
			}
		}

		throw new CryptoException( 'Failed to find subgroup generator.' );
	}

	/**
	 * Generate a prime greater than secret.
	 *
	 * @param \GMP $secret    Secret value.
	 * @param int  $extra_bits Extra bits beyond secret length.
	 * @return \GMP
	 * @throws CryptoException If generation fails.
	 */
	public static function generatePrimeGreaterThan( \GMP $secret, int $extra_bits = 128 ): \GMP {
		$rses_secret_bits = BigInt::bitLength( $secret );
		$rses_target_bits = $rses_secret_bits + $extra_bits;
		$rses_loops       = 0;

		while ( $rses_loops < self::RSES_MAX_SAFE_PRIME_ATTEMPTS ) {
			++$rses_loops;

			$rses_prime = self::generateProbablePrime( $rses_target_bits );

			if ( \gmp_cmp( $rses_prime, $secret ) > 0 ) {
				return $rses_prime;
			}
		}

		throw new CryptoException( 'Failed to generate prime greater than secret.' );
	}

	/**
	 * Generate random odd integer of specified bit length.
	 *
	 * @param int $bits Bit length.
	 * @return \GMP
	 */
	private static function rses_random_odd_of_bit_length( int $bits ): \GMP {
		$rses_byte_length = (int) ceil( $bits / 8 );
		$rses_bytes       = CryptoRandom::randomBytes( $rses_byte_length );
		$rses_hex         = bin2hex( $rses_bytes );
		$rses_candidate   = \gmp_init( $rses_hex, 16 );

		$rses_max = \gmp_sub( \gmp_pow( \gmp_init( 2 ), $bits ), \gmp_init( 1 ) );

		if ( \gmp_cmp( $rses_candidate, $rses_max ) > 0 ) {
			$rses_candidate = \gmp_mod( $rses_candidate, \gmp_add( $rses_max, \gmp_init( 1 ) ) );
		}

		$rses_min = \gmp_pow( \gmp_init( 2 ), $bits - 1 );

		if ( \gmp_cmp( $rses_candidate, $rses_min ) < 0 ) {
			$rses_candidate = \gmp_add( $rses_candidate, $rses_min );
		}

		if ( \gmp_cmp( $rses_candidate, $rses_max ) > 0 ) {
			$rses_candidate = \gmp_mod( $rses_candidate, \gmp_add( $rses_max, \gmp_init( 1 ) ) );
			if ( \gmp_cmp( $rses_candidate, $rses_min ) < 0 ) {
				$rses_candidate = \gmp_add( $rses_candidate, $rses_min );
			}
		}

		if ( 0 === \gmp_cmp( \gmp_mod( $rses_candidate, \gmp_init( 2 ) ), \gmp_init( 0 ) ) ) {
			$rses_candidate = \gmp_add( $rses_candidate, \gmp_init( 1 ) );
		}

		if ( \gmp_cmp( $rses_candidate, $rses_max ) > 0 ) {
			$rses_candidate = \gmp_sub( $rses_candidate, \gmp_init( 2 ) );
		}

		return $rses_candidate;
	}
}
