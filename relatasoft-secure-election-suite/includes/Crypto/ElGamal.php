<?php
/**
 * ElGamal encryption, decryption, and aggregation.
 *
 * @package RelataSoft\SecureElectionSuite\Crypto
 */

namespace RelataSoft\SecureElectionSuite\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * ElGamal cryptographic operations.
 */
class ElGamal {

	/**
	 * Generate an ElGamal key pair.
	 *
	 * @param int $bits Key size in bits (minimum 512).
	 * @return ElGamalKeyPair
	 * @throws CryptoException If generation or validation fails.
	 */
	public static function generateKeyPair( int $bits ): ElGamalKeyPair {
		if ( $bits < 512 ) {
			throw new CryptoException( __( 'ElGamal key size must be at least 512 bits.', 'relatasoft-secure-election-suite' ) );
		}

		list( $rses_p, $rses_q ) = PrimeGenerator::generateSafePrime( $bits );
		$rses_g = PrimeGenerator::findGeneratorForSafePrime( $rses_p, $rses_q );

		$rses_two = \gmp_init( 2 );
		$rses_x   = CryptoRandom::randomIntegerBetween( $rses_two, \gmp_sub( $rses_q, $rses_two ) );
		$rses_y   = BigInt::modPow( $rses_g, $rses_x, $rses_p );

		self::rses_validate_key_pair( $rses_p, $rses_q, $rses_g, $rses_x, $rses_y );

		return new ElGamalKeyPair(
			BigInt::toDecimalString( $rses_p ),
			BigInt::toDecimalString( $rses_q ),
			BigInt::toDecimalString( $rses_g ),
			BigInt::toDecimalString( $rses_x ),
			BigInt::toDecimalString( $rses_y ),
			$bits,
			gmdate( 'Y-m-d H:i:s' )
		);
	}

	/**
	 * Encrypt a message.
	 *
	 * @param \GMP $message Message element mod p.
	 * @param \GMP $p       Prime p.
	 * @param \GMP $q       Subgroup order q.
	 * @param \GMP $g       Generator g.
	 * @param \GMP $y       Public key y.
	 * @return ElGamalCiphertext
	 * @throws CryptoException If validation fails.
	 */
	public static function encrypt( \GMP $message, \GMP $p, \GMP $q, \GMP $g, \GMP $y ): ElGamalCiphertext {
		$rses_one = \gmp_init( 1 );

		if ( \gmp_cmp( $message, $rses_one ) < 0 || \gmp_cmp( $message, $p ) >= 0 ) {
			throw new CryptoException( __( 'Message must be in range [1, p-1].', 'relatasoft-secure-election-suite' ) );
		}

		$rses_two = \gmp_init( 2 );
		$rses_r   = CryptoRandom::randomIntegerBetween( $rses_two, \gmp_sub( $q, $rses_two ) );

		$rses_alpha = BigInt::modPow( $g, $rses_r, $p );
		$rses_s     = BigInt::modPow( $y, $rses_r, $p );
		$rses_beta  = BigInt::modMul( $message, $rses_s, $p );

		self::rses_validate_ciphertext( $rses_alpha, $rses_beta, $p );

		return new ElGamalCiphertext( $rses_alpha, $rses_beta );
	}

	/**
	 * Decrypt a ciphertext.
	 *
	 * @param ElGamalCiphertext $ciphertext Ciphertext.
	 * @param \GMP              $p          Prime p.
	 * @param \GMP              $x          Private exponent.
	 * @return \GMP Decrypted message.
	 * @throws CryptoException If validation fails.
	 */
	public static function decrypt( ElGamalCiphertext $ciphertext, \GMP $p, \GMP $x ): \GMP {
		$rses_alpha = $ciphertext->getAlpha();
		$rses_beta  = $ciphertext->getBeta();

		self::rses_validate_ciphertext( $rses_alpha, $rses_beta, $p );

		$rses_s     = BigInt::modPow( $rses_alpha, $x, $p );
		$rses_s_inv = BigInt::modInv( $rses_s, $p );
		$rses_msg   = BigInt::modMul( $rses_beta, $rses_s_inv, $p );

		return $rses_msg;
	}

	/**
	 * Aggregate multiple ciphertexts homomorphically.
	 *
	 * @param array<int,ElGamalCiphertext> $ciphertexts Ciphertexts.
	 * @param \GMP                       $p           Prime p.
	 * @return ElGamalCiphertext
	 * @throws CryptoException If input invalid.
	 */
	public static function aggregate( array $ciphertexts, \GMP $p ): ElGamalCiphertext {
		if ( empty( $ciphertexts ) ) {
			throw new CryptoException( __( 'Cannot aggregate empty ciphertext set.', 'relatasoft-secure-election-suite' ) );
		}

		$rses_agg_alpha = \gmp_init( 1 );
		$rses_agg_beta  = \gmp_init( 1 );

		foreach ( $ciphertexts as $rses_ct ) {
			if ( ! $rses_ct instanceof ElGamalCiphertext ) {
				throw new CryptoException( __( 'Invalid ciphertext in aggregation.', 'relatasoft-secure-election-suite' ) );
			}

			self::rses_validate_ciphertext( $rses_ct->getAlpha(), $rses_ct->getBeta(), $p );

			$rses_agg_alpha = BigInt::modMul( $rses_agg_alpha, $rses_ct->getAlpha(), $p );
			$rses_agg_beta  = BigInt::modMul( $rses_agg_beta, $rses_ct->getBeta(), $p );
		}

		return new ElGamalCiphertext( $rses_agg_alpha, $rses_agg_beta );
	}

	/**
	 * Validate key pair properties.
	 *
	 * @param \GMP $p Prime.
	 * @param \GMP $q Subgroup order.
	 * @param \GMP $g Generator.
	 * @param \GMP $x Private exponent.
	 * @param \GMP $y Public key.
	 * @throws CryptoException If validation fails.
	 */
	private static function rses_validate_key_pair( \GMP $p, \GMP $q, \GMP $g, \GMP $x, \GMP $y ): void {
		$rses_two = \gmp_init( 2 );
		$rses_one = \gmp_init( 1 );

		BigInt::assertInRange( $x, $rses_two, \gmp_sub( $q, $rses_two ) );

		$rses_gq = BigInt::modPow( $g, $q, $p );
		if ( \gmp_cmp( $rses_gq, $rses_one ) !== 0 ) {
			throw new CryptoException( __( 'Generator validation failed: g^q mod p != 1.', 'relatasoft-secure-election-suite' ) );
		}

		if ( \gmp_cmp( $y, $rses_one ) === 0 ) {
			throw new CryptoException( __( 'Public key y must not be 1.', 'relatasoft-secure-election-suite' ) );
		}

		$rses_yq = BigInt::modPow( $y, $q, $p );
		if ( \gmp_cmp( $rses_yq, $rses_one ) !== 0 ) {
			throw new CryptoException( __( 'Public key validation failed: y^q mod p != 1.', 'relatasoft-secure-election-suite' ) );
		}

		$rses_expected_y = BigInt::modPow( $g, $x, $p );
		if ( \gmp_cmp( $y, $rses_expected_y ) !== 0 ) {
			throw new CryptoException( __( 'Public key does not match private exponent.', 'relatasoft-secure-election-suite' ) );
		}

		$rses_expected_p = \gmp_add( \gmp_mul( \gmp_init( 2 ), $q ), \gmp_init( 1 ) );
		if ( \gmp_cmp( $p, $rses_expected_p ) !== 0 ) {
			throw new CryptoException( __( 'Safe prime relationship p = 2q + 1 failed.', 'relatasoft-secure-election-suite' ) );
		}
	}

	/**
	 * Validate ciphertext components.
	 *
	 * @param \GMP $alpha Alpha.
	 * @param \GMP $beta  Beta.
	 * @param \GMP $p     Prime.
	 * @throws CryptoException If invalid.
	 */
	private static function rses_validate_ciphertext( \GMP $alpha, \GMP $beta, \GMP $p ): void {
		$rses_one = \gmp_init( 1 );
		$rses_max = \gmp_sub( $p, $rses_one );

		BigInt::assertInRange( $alpha, $rses_one, $rses_max );
		BigInt::assertInRange( $beta, $rses_one, $rses_max );
	}
}
