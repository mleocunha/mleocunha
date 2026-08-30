<?php
declare(strict_types=1);
/**
 * Vote count encoding for homomorphic tallying.
 *
 * Portable Domain crypto (A1) — no WordPress runtime required.
 *
 * @package RelataSoft\SecureElectionSuite\Painel\Domain\Crypto
 */

namespace RelataSoft\SecureElectionSuite\Painel\Domain\Crypto;


/**
 * Encode/decode vote counts as g^m mod p.
 */
class CryptoEncoding {

	/**
	 * Encode a count m as g^m mod p.
	 *
	 * @param int  $m Count value.
	 * @param \GMP $g Generator.
	 * @param \GMP $p Prime.
	 * @return \GMP
	 * @throws CryptoException If m is negative.
	 */
	public static function encodeCount( int $m, \GMP $g, \GMP $p ): \GMP {
		if ( $m < 0 ) {
			throw new CryptoException( 'Count must be non-negative.' );
		}

		return BigInt::modPow( $g, \gmp_init( $m ), $p );
	}

	/**
	 * Decode an encoded count via bounded discrete log.
	 *
	 * @param \GMP $encoded  Encoded value.
	 * @param \GMP $g        Generator.
	 * @param \GMP $p        Prime.
	 * @param int  $max_count Maximum count to search.
	 * @return int
	 * @throws CryptoException If not found within bounds.
	 */
	public static function decodeCount( \GMP $encoded, \GMP $g, \GMP $p, int $max_count ): int {
		for ( $rses_i = 0; $rses_i <= $max_count; ++$rses_i ) {
			$rses_candidate = BigInt::modPow( $g, \gmp_init( $rses_i ), $p );

			if ( \gmp_cmp( $rses_candidate, $encoded ) === 0 ) {
				return $rses_i;
			}
		}

		throw new CryptoException(
			sprintf(
				/* translators: %d: maximum decode count */
				'Encoded count not found within bounded range [0, %d].',
				$max_count
			)
		);
	}
}
