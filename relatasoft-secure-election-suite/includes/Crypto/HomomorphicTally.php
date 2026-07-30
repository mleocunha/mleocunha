<?php
/**
 * Homomorphic tally operations.
 *
 * @package RelataSoft\SecureElectionSuite\Crypto
 */

namespace RelataSoft\SecureElectionSuite\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Homomorphic tally via ElGamal aggregation and bounded decode.
 */
class HomomorphicTally {

	/**
	 * Encrypt a vote count.
	 *
	 * @param int    $count Vote count (0 or 1 typically).
	 * @param \GMP   $p     Prime.
	 * @param \GMP   $q     Subgroup order.
	 * @param \GMP   $g     Generator.
	 * @param \GMP   $y     Public key.
	 * @return ElGamalCiphertext
	 */
	public static function encryptCount( int $count, \GMP $p, \GMP $q, \GMP $g, \GMP $y ): ElGamalCiphertext {
		$rses_encoded = CryptoEncoding::encodeCount( $count, $g, $p );
		return ElGamal::encrypt( $rses_encoded, $p, $q, $g, $y );
	}

	/**
	 * Aggregate encrypted vote counts.
	 *
	 * @param array<int,ElGamalCiphertext> $ciphertexts Ciphertexts.
	 * @param \GMP                         $p           Prime.
	 * @return ElGamalCiphertext
	 */
	public static function aggregateCounts( array $ciphertexts, \GMP $p ): ElGamalCiphertext {
		return ElGamal::aggregate( $ciphertexts, $p );
	}

	/**
	 * Decrypt and decode aggregated tally count.
	 *
	 * @param ElGamalCiphertext $aggregate Aggregated ciphertext.
	 * @param \GMP              $p         Prime.
	 * @param \GMP              $q         Subgroup order (unused but kept for API consistency).
	 * @param \GMP              $g         Generator.
	 * @param \GMP              $x         Private exponent.
	 * @param int               $max_count Maximum decode bound.
	 * @return int Decoded count.
	 */
	public static function decryptAndDecode(
		ElGamalCiphertext $aggregate,
		\GMP $p,
		\GMP $q,
		\GMP $g,
		\GMP $x,
		int $max_count
	): int {
		unset( $q );

		$rses_decrypted = ElGamal::decrypt( $aggregate, $p, $x );
		return CryptoEncoding::decodeCount( $rses_decrypted, $g, $p, $max_count );
	}
}
