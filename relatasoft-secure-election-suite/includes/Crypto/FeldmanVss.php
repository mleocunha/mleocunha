<?php
/**
 * Feldman Verifiable Secret Sharing over the ElGamal subgroup order q.
 *
 * @package RelataSoft\SecureElectionSuite\Crypto
 */

namespace RelataSoft\SecureElectionSuite\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Feldman VSS for scheme modp-elgamal-feldman-v1.
 *
 * Polynomial coefficients live in Z/qZ (ElGamal subgroup order). Commitments
 * are C_k = g^{a_k} mod p. Share i with value s satisfies:
 *
 *   g^s ≡ Π_k C_k^{i^k}  (mod p)
 *
 * C_0 = g^{a_0} = g^x = y (the ElGamal public key).
 *
 * Note: unlike modp-elgamal-shamir-v1 (separate field prime P > x), Feldman
 * requires the Shamir field to be q so exponent arithmetic matches the group.
 */
class FeldmanVss {

	public const RSES_SHARE_VERSION = '1.0';
	public const RSES_SHARE_SCHEME  = 'FeldmanVSS';
	public const SCHEME_ID          = CryptoSchemeRegistry::SCHEME_MODP_ELGAMAL_FELDMAN_V1;

	/**
	 * Split secret x and build Feldman commitments.
	 *
	 * @param \GMP $secret Private exponent x (must be in [1, q-1]).
	 * @param int  $threshold t.
	 * @param int  $total_shares n.
	 * @param \GMP $p Group prime.
	 * @param \GMP $q Subgroup order (Shamir field).
	 * @param \GMP $g Generator.
	 * @return array{
	 *   shares: list<array{x:int,y:\GMP}>,
	 *   commitments: list<\GMP>,
	 *   coefficients: list<\GMP>
	 * }
	 * @throws CryptoException If validation fails.
	 */
	public static function rses_split_with_commitments(
		\GMP $secret,
		int $threshold,
		int $total_shares,
		\GMP $p,
		\GMP $q,
		\GMP $g
	): array {
		self::rses_validate_params( $secret, $threshold, $total_shares, $p, $q, $g );

		$coefficients   = array( $secret );
		$one            = \gmp_init( 1 );
		$q_minus_one    = \gmp_sub( $q, $one );

		for ( $i = 1; $i < $threshold; ++$i ) {
			$coefficients[] = CryptoRandom::randomIntegerBetween( $one, $q_minus_one );
		}

		$commitments = array();
		foreach ( $coefficients as $a_k ) {
			$commitments[] = BigInt::modPow( $g, $a_k, $p );
		}

		$shares = array();
		for ( $index = 1; $index <= $total_shares; ++$index ) {
			$xi = \gmp_init( $index );
			$yi = ShamirSecretSharing::evaluatePolynomial( $coefficients, $xi, $q );
			$shares[] = array(
				'x' => $index,
				'y' => $yi,
			);
		}

		return array(
			'shares'       => $shares,
			'commitments'  => $commitments,
			'coefficients' => $coefficients,
		);
	}

	/**
	 * Verify share (i, s) against Feldman commitments.
	 *
	 * @param int        $share_index i ∈ [1, n].
	 * @param \GMP       $share_value s = f(i) mod q.
	 * @param list<\GMP> $commitments C_k = g^{a_k} mod p.
	 * @param \GMP       $p Prime.
	 * @param \GMP       $q Order.
	 * @param \GMP       $g Generator.
	 * @param \GMP|null  $public_y Optional public key; must equal C_0 when set.
	 * @return bool
	 * @throws CryptoException If inputs are malformed.
	 */
	public static function rses_verify_share(
		int $share_index,
		\GMP $share_value,
		array $commitments,
		\GMP $p,
		\GMP $q,
		\GMP $g,
		?\GMP $public_y = null
	): bool {
		if ( $share_index < 1 ) {
			throw new CryptoException( __( 'Share index must be at least 1.', 'relatasoft-secure-election-suite' ) );
		}
		if ( empty( $commitments ) ) {
			throw new CryptoException( __( 'Feldman commitments are required.', 'relatasoft-secure-election-suite' ) );
		}
		if ( \gmp_cmp( $share_value, \gmp_init( 0 ) ) < 0 || \gmp_cmp( $share_value, $q ) >= 0 ) {
			throw new CryptoException( __( 'Share value must be in [0, q-1].', 'relatasoft-secure-election-suite' ) );
		}

		if ( null !== $public_y && \gmp_cmp( $commitments[0], $public_y ) !== 0 ) {
			return false;
		}

		// RHS = Π_k C_k^{i^k} mod p.
		$rhs     = \gmp_init( 1 );
		$i_pow   = \gmp_init( 1 ); // i^0
		$i_gmp   = \gmp_init( $share_index );

		foreach ( $commitments as $c_k ) {
			$term = BigInt::modPow( $c_k, $i_pow, $p );
			$rhs  = BigInt::modMul( $rhs, $term, $p );
			$i_pow = BigInt::modMul( $i_pow, $i_gmp, $q ); // next exponent power mod q
		}

		$lhs = BigInt::modPow( $g, $share_value, $p );
		return \gmp_cmp( $lhs, $rhs ) === 0;
	}

	/**
	 * Commitments as decimal strings for JSON transcripts.
	 *
	 * @param list<\GMP> $commitments Commitments.
	 * @return list<string>
	 */
	public static function rses_commitments_to_decimal( array $commitments ): array {
		$out = array();
		foreach ( $commitments as $c ) {
			$out[] = BigInt::toDecimalString( $c );
		}
		return $out;
	}

	/**
	 * @param list<string> $decimals Decimal strings.
	 * @return list<\GMP>
	 */
	public static function rses_commitments_from_decimal( array $decimals ): array {
		$out = array();
		foreach ( $decimals as $d ) {
			$out[] = BigInt::fromDecimalString( (string) $d );
		}
		return $out;
	}

	/**
	 * @throws CryptoException If invalid.
	 */
	private static function rses_validate_params(
		\GMP $secret,
		int $threshold,
		int $total_shares,
		\GMP $p,
		\GMP $q,
		\GMP $g
	): void {
		if ( $threshold < 2 ) {
			throw new CryptoException( __( 'Feldman threshold must be at least 2.', 'relatasoft-secure-election-suite' ) );
		}
		if ( $total_shares < $threshold ) {
			throw new CryptoException( __( 'Total shares must be >= threshold.', 'relatasoft-secure-election-suite' ) );
		}
		if ( \gmp_cmp( $secret, \gmp_init( 1 ) ) < 0 || \gmp_cmp( $secret, $q ) >= 0 ) {
			throw new CryptoException( __( 'Secret must be in [1, q-1] for Feldman VSS.', 'relatasoft-secure-election-suite' ) );
		}
		if ( \gmp_cmp( $q, \gmp_init( $total_shares ) ) <= 0 ) {
			throw new CryptoException( __( 'Subgroup order q must be greater than total shares.', 'relatasoft-secure-election-suite' ) );
		}
		if ( \gmp_cmp( BigInt::modPow( $g, $q, $p ), \gmp_init( 1 ) ) !== 0 ) {
			throw new CryptoException( __( 'Generator validation failed: g^q mod p != 1.', 'relatasoft-secure-election-suite' ) );
		}
	}
}
