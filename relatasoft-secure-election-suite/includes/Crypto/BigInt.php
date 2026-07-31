<?php
/**
 * Big integer helper utilities.
 *
 * @package RelataSoft\SecureElectionSuite\Crypto
 */

namespace RelataSoft\SecureElectionSuite\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * GMP big integer operations with decimal string serialization.
 */
class BigInt {

	/**
	 * Convert decimal string to GMP.
	 *
	 * @param string $value Decimal string.
	 * @return \GMP
	 * @throws CryptoException If invalid.
	 */
	public static function fromDecimalString( string $value ): \GMP {
		$rses_value = trim( $value );

		if ( '' === $rses_value || ! preg_match( '/^[0-9]+$/', $rses_value ) ) {
			throw new CryptoException( __( 'Invalid decimal string for big integer.', 'relatasoft-secure-election-suite' ) );
		}

		return \gmp_init( $rses_value, 10 );
	}

	/**
	 * Convert GMP to decimal string.
	 *
	 * @param \GMP $value GMP value.
	 * @return string
	 */
	public static function toDecimalString( \GMP $value ): string {
		return \gmp_strval( $value, 10 );
	}

	/**
	 * Assert value is a positive decimal string.
	 *
	 * @param string $value Decimal string.
	 * @throws CryptoException If invalid.
	 */
	public static function assertPositiveDecimalString( string $value ): void {
		self::fromDecimalString( $value );

		if ( '0' === $value ) {
			throw new CryptoException( __( 'Big integer must be positive.', 'relatasoft-secure-election-suite' ) );
		}
	}

	/**
	 * Assert value is within range [min, max] inclusive.
	 *
	 * @param \GMP $value Value.
	 * @param \GMP $min   Minimum.
	 * @param \GMP $max   Maximum.
	 * @throws CryptoException If out of range.
	 */
	public static function assertInRange( \GMP $value, \GMP $min, \GMP $max ): void {
		if ( \gmp_cmp( $value, $min ) < 0 || \gmp_cmp( $value, $max ) > 0 ) {
			throw new CryptoException( __( 'Big integer out of allowed range.', 'relatasoft-secure-election-suite' ) );
		}
	}

	/**
	 * Modular addition.
	 *
	 * @param \GMP $a First operand.
	 * @param \GMP $b Second operand.
	 * @param \GMP $m Modulus.
	 * @return \GMP
	 */
	public static function modAdd( \GMP $a, \GMP $b, \GMP $m ): \GMP {
		return self::mod( \gmp_add( $a, $b ), $m );
	}

	/**
	 * Modular subtraction.
	 *
	 * @param \GMP $a First operand.
	 * @param \GMP $b Second operand.
	 * @param \GMP $m Modulus.
	 * @return \GMP
	 */
	public static function modSub( \GMP $a, \GMP $b, \GMP $m ): \GMP {
		return self::mod( \gmp_sub( $a, $b ), $m );
	}

	/**
	 * Modular multiplication.
	 *
	 * @param \GMP $a First operand.
	 * @param \GMP $b Second operand.
	 * @param \GMP $m Modulus.
	 * @return \GMP
	 */
	public static function modMul( \GMP $a, \GMP $b, \GMP $m ): \GMP {
		return self::mod( \gmp_mul( $a, $b ), $m );
	}

	/**
	 * Modular exponentiation.
	 *
	 * @param \GMP $base Base.
	 * @param \GMP $exp  Exponent.
	 * @param \GMP $mod  Modulus.
	 * @return \GMP
	 */
	public static function modPow( \GMP $base, \GMP $exp, \GMP $mod ): \GMP {
		return \gmp_powm( $base, $exp, $mod );
	}

	/**
	 * Modular inverse.
	 *
	 * @param \GMP $a Value.
	 * @param \GMP $m Modulus.
	 * @return \GMP
	 * @throws CryptoException If inverse does not exist.
	 */
	public static function modInv( \GMP $a, \GMP $m ): \GMP {
		$rses_inv = \gmp_invert( $a, $m );

		if ( false === $rses_inv ) {
			throw new CryptoException( __( 'Modular inverse does not exist.', 'relatasoft-secure-election-suite' ) );
		}

		return $rses_inv;
	}

	/**
	 * Normalize modulo result into [0, m - 1].
	 *
	 * @param \GMP $a Value.
	 * @param \GMP $m Modulus.
	 * @return \GMP
	 */
	public static function mod( \GMP $a, \GMP $m ): \GMP {
		$rses_result = \gmp_mod( $a, $m );

		if ( \gmp_sign( $rses_result ) < 0 ) {
			$rses_result = \gmp_add( $rses_result, $m );
		}

		return $rses_result;
	}

	/**
	 * Compute bit length of a GMP integer.
	 *
	 * @param \GMP $n Integer.
	 * @return int
	 */
	public static function bitLength( \GMP $n ): int {
		if ( \gmp_cmp( $n, \gmp_init( 0 ) ) <= 0 ) {
			return 0;
		}

		return strlen( \gmp_strval( $n, 2 ) );
	}

	/**
	 * Check GMP extension is available.
	 *
	 * @return bool
	 */
	public static function rses_gmp_available(): bool {
		return extension_loaded( 'gmp' );
	}
}
