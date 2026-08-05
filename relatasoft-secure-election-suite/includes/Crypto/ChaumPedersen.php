<?php
/**
 * Chaum–Pedersen equality-of-discrete-logs proofs (Fiat–Shamir).
 *
 * @package RelataSoft\SecureElectionSuite\Crypto
 */

namespace RelataSoft\SecureElectionSuite\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Prove / verify log_g(h) = log_u(v) = w without revealing w.
 */
class ChaumPedersen {

	public const SCHEME = 'chaum-pedersen-dleq-sha256-v1';

	/**
	 * Prove knowledge of w such that h = g^w and v = u^w (mod p).
	 *
	 * @return array{scheme:string,a:string,b:string,e:string,z:string}
	 * @throws CryptoException If parameters are invalid.
	 */
	public static function rses_prove_dleq(
		\GMP $g,
		\GMP $h,
		\GMP $u,
		\GMP $v,
		\GMP $w,
		\GMP $p,
		\GMP $q
	): array {
		self::rses_assert_in_field( $w, $q );

		$k = CryptoRandom::randomIntegerBetween( \gmp_init( 1 ), \gmp_sub( $q, \gmp_init( 1 ) ) );
		$a = BigInt::modPow( $g, $k, $p );
		$b = BigInt::modPow( $u, $k, $p );

		$e = self::rses_challenge( $g, $h, $u, $v, $a, $b, $p, $q );
		$z = BigInt::modAdd( $k, BigInt::modMul( $e, $w, $q ), $q );

		return array(
			'scheme' => self::SCHEME,
			'a'      => BigInt::toDecimalString( $a ),
			'b'      => BigInt::toDecimalString( $b ),
			'e'      => BigInt::toDecimalString( $e ),
			'z'      => BigInt::toDecimalString( $z ),
		);
	}

	/**
	 * @param array<string,mixed> $proof Proof dict from rses_prove_dleq.
	 */
	public static function rses_verify_dleq(
		\GMP $g,
		\GMP $h,
		\GMP $u,
		\GMP $v,
		array $proof,
		\GMP $p,
		\GMP $q
	): bool {
		if ( self::SCHEME !== (string) ( $proof['scheme'] ?? '' ) ) {
			return false;
		}
		foreach ( array( 'a', 'b', 'e', 'z' ) as $key ) {
			if ( empty( $proof[ $key ] ) ) {
				return false;
			}
		}

		try {
			$a = BigInt::fromDecimalString( (string) $proof['a'] );
			$b = BigInt::fromDecimalString( (string) $proof['b'] );
			$e = BigInt::fromDecimalString( (string) $proof['e'] );
			$z = BigInt::fromDecimalString( (string) $proof['z'] );
		} catch ( CryptoException $e ) {
			return false;
		}

		$e_expected = self::rses_challenge( $g, $h, $u, $v, $a, $b, $p, $q );
		if ( \gmp_cmp( $e, $e_expected ) !== 0 ) {
			return false;
		}

		// g^z ?= a * h^e
		$lhs1 = BigInt::modPow( $g, $z, $p );
		$rhs1 = BigInt::modMul( $a, BigInt::modPow( $h, $e, $p ), $p );
		if ( \gmp_cmp( $lhs1, $rhs1 ) !== 0 ) {
			return false;
		}

		// u^z ?= b * v^e
		$lhs2 = BigInt::modPow( $u, $z, $p );
		$rhs2 = BigInt::modMul( $b, BigInt::modPow( $v, $e, $p ), $p );
		return \gmp_cmp( $lhs2, $rhs2 ) === 0;
	}

	/**
	 * Fiat–Shamir challenge in Z/qZ.
	 */
	public static function rses_challenge(
		\GMP $g,
		\GMP $h,
		\GMP $u,
		\GMP $v,
		\GMP $a,
		\GMP $b,
		\GMP $p,
		\GMP $q
	): \GMP {
		$payload = array(
			'scheme' => self::SCHEME,
			'p'      => BigInt::toDecimalString( $p ),
			'q'      => BigInt::toDecimalString( $q ),
			'g'      => BigInt::toDecimalString( $g ),
			'h'      => BigInt::toDecimalString( $h ),
			'u'      => BigInt::toDecimalString( $u ),
			'v'      => BigInt::toDecimalString( $v ),
			'a'      => BigInt::toDecimalString( $a ),
			'b'      => BigInt::toDecimalString( $b ),
		);
		$digest = hash( 'sha256', (string) wp_json_encode( $payload, JSON_UNESCAPED_SLASHES ), true );
		$int    = \gmp_import( $digest );
		return \gmp_mod( $int, $q );
	}

	/**
	 * @throws CryptoException If out of range.
	 */
	private static function rses_assert_in_field( \GMP $w, \GMP $q ): void {
		if ( \gmp_cmp( $w, \gmp_init( 0 ) ) < 0 || \gmp_cmp( $w, $q ) >= 0 ) {
			throw new CryptoException( __( 'Chaum–Pedersen witness must be in [0, q-1].', 'relatasoft-secure-election-suite' ) );
		}
	}
}
