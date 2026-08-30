<?php
declare(strict_types=1);
/**
 * Schnorr signatures over the ElGamal subgroup (p, q, g, y=g^x).
 *
 * Portable Domain crypto (A1) — no WordPress runtime required.
 *
 * @package RelataSoft\SecureElectionSuite\Painel\Domain\Crypto
 */

namespace RelataSoft\SecureElectionSuite\Painel\Domain\Crypto;


/**
 * Sign / verify messages with the election private exponent.
 *
 * Scheme: schnorr-sha256-modpq-v1
 *   k ← random in [2, q-2]
 *   r = g^k mod p
 *   e = H(scheme || r || message) mod q
 *   s = (k + e·x) mod q
 * Verify: r' = g^s · y^{-e} mod p ; H(scheme || r' || message) mod q = e
 */
class SchnorrSignature {

	public const RSES_SCHEME = 'schnorr-sha256-modpq-v1';

	/**
	 * Sign a message string with private exponent x.
	 *
	 * @param string $message Message bytes (typically a hex SHA-256 digest).
	 * @param \GMP   $p       Prime p.
	 * @param \GMP   $q       Subgroup order q.
	 * @param \GMP   $g       Generator g.
	 * @param \GMP   $x       Private exponent.
	 * @param \GMP   $y       Public key y (validated against x).
	 * @return array{scheme:string,e:string,s:string,r:string}
	 * @throws CryptoException On failure.
	 */
	public static function sign( string $message, \GMP $p, \GMP $q, \GMP $g, \GMP $x, \GMP $y ): array {
		$rses_y_check = BigInt::modPow( $g, $x, $p );
		if ( \gmp_cmp( $rses_y_check, $y ) !== 0 ) {
			throw new CryptoException( 'Schnorr sign: private key does not match public y.' );
		}

		$rses_two = \gmp_init( 2 );
		$rses_k   = CryptoRandom::randomIntegerBetween( $rses_two, \gmp_sub( $q, $rses_two ) );
		$rses_r   = BigInt::modPow( $g, $rses_k, $p );
		$rses_e   = self::rses_challenge( $rses_r, $message, $q );
		$rses_s   = BigInt::mod( \gmp_add( $rses_k, \gmp_mul( $rses_e, $x ) ), $q );

		return array(
			'scheme' => self::RSES_SCHEME,
			'e'      => BigInt::toDecimalString( $rses_e ),
			's'      => BigInt::toDecimalString( $rses_s ),
			'r'      => BigInt::toDecimalString( $rses_r ),
		);
	}

	/**
	 * Verify a Schnorr signature.
	 *
	 * @param string              $message   Message bytes.
	 * @param array<string,mixed> $signature Signature fields.
	 * @param \GMP                $p         Prime p.
	 * @param \GMP                $q         Subgroup order q.
	 * @param \GMP                $g         Generator g.
	 * @param \GMP                $y         Public key y.
	 * @return bool
	 */
	public static function verify( string $message, array $signature, \GMP $p, \GMP $q, \GMP $g, \GMP $y ): bool {
		try {
			if ( ( $signature['scheme'] ?? '' ) !== self::RSES_SCHEME ) {
				return false;
			}
			if ( empty( $signature['e'] ) || empty( $signature['s'] ) ) {
				return false;
			}

			$rses_e = BigInt::fromDecimalString( (string) $signature['e'] );
			$rses_s = BigInt::fromDecimalString( (string) $signature['s'] );

			if ( \gmp_cmp( $rses_e, \gmp_init( 0 ) ) <= 0 || \gmp_cmp( $rses_e, $q ) >= 0 ) {
				return false;
			}
			if ( \gmp_cmp( $rses_s, \gmp_init( 0 ) ) < 0 || \gmp_cmp( $rses_s, $q ) >= 0 ) {
				return false;
			}

			$rses_gs     = BigInt::modPow( $g, $rses_s, $p );
			$rses_ye     = BigInt::modPow( $y, $rses_e, $p );
			$rses_ye_inv = BigInt::modInv( $rses_ye, $p );
			$rses_r      = BigInt::modMul( $rses_gs, $rses_ye_inv, $p );
			$rses_e_chk  = self::rses_challenge( $rses_r, $message, $q );

			return \gmp_cmp( $rses_e_chk, $rses_e ) === 0;
		} catch ( CryptoException $rses_e ) {
			return false;
		}
	}

	/**
	 * Fiat–Shamir challenge e = SHA-256(scheme||r||message) interpreted mod q.
	 *
	 * @param \GMP   $r       Commitment r.
	 * @param string $message Message.
	 * @param \GMP   $q       Subgroup order.
	 * @return \GMP
	 */
	public static function rses_challenge( \GMP $r, string $message, \GMP $q ): \GMP {
		$rses_digest = hash(
			'sha256',
			self::RSES_SCHEME . "\0" . BigInt::toDecimalString( $r ) . "\0" . $message,
			true
		);
		$rses_int = \gmp_import( $rses_digest );
		if ( false === $rses_int ) {
			$rses_int = \gmp_init( bin2hex( $rses_digest ), 16 );
		}
		$rses_e = BigInt::mod( $rses_int, $q );
		if ( \gmp_cmp( $rses_e, \gmp_init( 0 ) ) === 0 ) {
			$rses_e = \gmp_init( 1 );
		}
		return $rses_e;
	}
}
