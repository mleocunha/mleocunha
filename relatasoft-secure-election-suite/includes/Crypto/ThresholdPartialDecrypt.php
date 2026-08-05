<?php
/**
 * Threshold ElGamal partial decryption with Chaum–Pedersen proofs.
 *
 * @package RelataSoft\SecureElectionSuite\Crypto
 */

namespace RelataSoft\SecureElectionSuite\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Fase 2: decrypt tallies by combining verified partials — never reconstruct x.
 */
class ThresholdPartialDecrypt {

	public const SCHEME_ID = CryptoSchemeRegistry::SCHEME_MODP_ELGAMAL_THRESHOLD_CP_V1;

	/**
	 * Public share commitment h_i = g^{s_i} = Π C_k^{i^k} mod p.
	 *
	 * @param list<\GMP> $commitments Feldman commitments.
	 */
	public static function rses_public_share_commitment(
		int $share_index,
		array $commitments,
		\GMP $p,
		\GMP $q
	): \GMP {
		if ( $share_index < 1 || empty( $commitments ) ) {
			throw new CryptoException( __( 'Invalid share index or commitments for public share commitment.', 'relatasoft-secure-election-suite' ) );
		}

		$rhs   = \gmp_init( 1 );
		$i_pow = \gmp_init( 1 );
		$i_gmp = \gmp_init( $share_index );

		foreach ( $commitments as $c_k ) {
			$rhs   = BigInt::modMul( $rhs, BigInt::modPow( $c_k, $i_pow, $p ), $p );
			$i_pow = BigInt::modMul( $i_pow, $i_gmp, $q );
		}

		return $rhs;
	}

	/**
	 * Compute one partial decryption + DLEQ proof for ciphertext (α, β).
	 *
	 * @return array{delta:string,proof:array<string,string>,share_index:int}
	 */
	public static function rses_partial_for_ciphertext(
		ElGamalCiphertext $ciphertext,
		int $share_index,
		\GMP $share_value,
		array $commitments,
		\GMP $p,
		\GMP $q,
		\GMP $g
	): array {
		$h = self::rses_public_share_commitment( $share_index, $commitments, $p, $q );
		// Sanity: h must equal g^{s}.
		if ( \gmp_cmp( $h, BigInt::modPow( $g, $share_value, $p ) ) !== 0 ) {
			throw new CryptoException( __( 'Share value does not match Feldman commitments.', 'relatasoft-secure-election-suite' ) );
		}

		$alpha = $ciphertext->getAlpha();
		$delta = BigInt::modPow( $alpha, $share_value, $p );
		$proof = ChaumPedersen::rses_prove_dleq( $g, $h, $alpha, $delta, $share_value, $p, $q );

		return array(
			'share_index' => $share_index,
			'delta'       => BigInt::toDecimalString( $delta ),
			'proof'       => $proof,
		);
	}

	/**
	 * Verify a partial against public commitments and ciphertext α.
	 *
	 * @param array<string,mixed> $partial Partial dict.
	 * @param list<\GMP>          $commitments Commitments.
	 */
	public static function rses_verify_partial(
		array $partial,
		ElGamalCiphertext $ciphertext,
		array $commitments,
		\GMP $p,
		\GMP $q,
		\GMP $g
	): bool {
		$share_index = (int) ( $partial['share_index'] ?? 0 );
		if ( $share_index < 1 || empty( $partial['delta'] ) || ! is_array( $partial['proof'] ?? null ) ) {
			return false;
		}

		try {
			$delta = BigInt::fromDecimalString( (string) $partial['delta'] );
			$h     = self::rses_public_share_commitment( $share_index, $commitments, $p, $q );
		} catch ( CryptoException $e ) {
			return false;
		}

		return ChaumPedersen::rses_verify_dleq(
			$g,
			$h,
			$ciphertext->getAlpha(),
			$delta,
			$partial['proof'],
			$p,
			$q
		);
	}

	/**
	 * Combine verified partials into c1^x = α^x using Lagrange coefficients at 0.
	 *
	 * @param array<int,array{share_index:int,delta:string}> $partials Partials (threshold many).
	 * @param \GMP                                           $p        Prime.
	 * @param \GMP                                           $q        Order.
	 */
	public static function rses_combine_partials( array $partials, \GMP $p, \GMP $q ): \GMP {
		if ( count( $partials ) < 2 ) {
			throw new CryptoException( __( 'At least 2 partials required to combine.', 'relatasoft-secure-election-suite' ) );
		}

		$indices = array();
		foreach ( $partials as $partial ) {
			$indices[] = (int) $partial['share_index'];
		}
		$lambdas = Polynomial::rses_lagrange_coefficients_at_zero( $indices, $q );

		$combined = \gmp_init( 1 );
		foreach ( $partials as $partial ) {
			$i     = (int) $partial['share_index'];
			$delta = BigInt::fromDecimalString( (string) $partial['delta'] );
			$lam   = $lambdas[ $i ] ?? null;
			if ( null === $lam ) {
				throw new CryptoException( __( 'Missing Lagrange coefficient for partial.', 'relatasoft-secure-election-suite' ) );
			}
			$combined = BigInt::modMul( $combined, BigInt::modPow( $delta, $lam, $p ), $p );
		}

		return $combined;
	}

	/**
	 * Decrypt aggregated tally using combined α^x without knowing x.
	 *
	 * @param \GMP $alpha_to_x Combined partial product α^x.
	 */
	public static function rses_decrypt_and_decode(
		ElGamalCiphertext $aggregate,
		\GMP $alpha_to_x,
		\GMP $p,
		\GMP $g,
		int $max_count
	): int {
		// m_enc = β / α^x = β * (α^x)^{-1}
		$m_enc = BigInt::modMul( $aggregate->getBeta(), BigInt::modInv( $alpha_to_x, $p ), $p );
		return CryptoEncoding::decodeCount( $m_enc, $g, $p, $max_count );
	}

	/**
	 * Build a contribution package from a validated Feldman share + tallies.
	 *
	 * The share value is used ephemerally and must not be persisted by the caller.
	 *
	 * @param array<string,mixed>      $share_payload Validated Feldman share.
	 * @param array<int,array<string,mixed>> $tallies Encrypted tallies from import.
	 * @return array<string,mixed>
	 */
	public static function rses_build_contribution( array $share_payload, array $tallies ): array {
		$pk = (array) $share_payload['public_key'];
		$p  = BigInt::fromDecimalString( (string) $pk['p'] );
		$q  = BigInt::fromDecimalString( (string) $pk['q'] );
		$g  = BigInt::fromDecimalString( (string) $pk['g'] );
		$y  = BigInt::fromDecimalString( (string) $pk['y'] );

		$share_index = (int) $share_payload['share_index'];
		$share_value = BigInt::fromDecimalString( (string) $share_payload['share_value'] );
		$commitments = FeldmanVss::rses_commitments_from_decimal( array_map( 'strval', (array) $share_payload['commitments'] ) );

		$partials = array();
		foreach ( $tallies as $tally ) {
			if ( ! is_array( $tally ) ) {
				continue;
			}
			$ct = ElGamalCiphertext::fromDecimalStrings(
				(string) ( $tally['aggregate_alpha'] ?? '' ),
				(string) ( $tally['aggregate_beta'] ?? '' )
			);
			$partial = self::rses_partial_for_ciphertext( $ct, $share_index, $share_value, $commitments, $p, $q, $g );
			$partials[] = array(
				'question_id' => $tally['question_id'] ?? null,
				'option_id'   => $tally['option_id'] ?? null,
				'share_index' => $share_index,
				'delta'       => $partial['delta'],
				'proof'       => $partial['proof'],
				'alpha'       => BigInt::toDecimalString( $ct->getAlpha() ),
				'beta'        => BigInt::toDecimalString( $ct->getBeta() ),
			);
		}

		$package = array(
			'format_version'         => '1.0',
			'scheme_id'              => self::SCHEME_ID,
			'parent_vss_scheme_id'   => FeldmanVss::SCHEME_ID,
			'ceremony_id'            => (string) ( $share_payload['ceremony_id'] ?? '' ),
			'key_id'                 => (int) ( $share_payload['key_id'] ?? 0 ),
			'share_index'            => $share_index,
			'threshold_t'            => (int) ( $share_payload['threshold_t'] ?? 0 ),
			'total_n'                => (int) ( $share_payload['total_n'] ?? 0 ),
			'public_key'             => array(
				'p' => (string) $pk['p'],
				'q' => (string) $pk['q'],
				'g' => (string) $pk['g'],
				'y' => (string) $pk['y'],
			),
			'commitments'            => array_map( 'strval', (array) $share_payload['commitments'] ),
			'public_transcript_hash' => (string) ( $share_payload['public_transcript_hash'] ?? '' ),
			'partials'               => $partials,
			'private_key_reconstruction' => 'prohibited',
		);

		unset( $share_value );
		$package['checksum'] = hash( 'sha256', (string) wp_json_encode( self::rses_checksum_body( $package ), JSON_UNESCAPED_SLASHES ) );
		return $package;
	}

	/**
	 * @param array<string,mixed> $package Contribution.
	 * @throws CryptoException If invalid.
	 */
	public static function rses_validate_contribution( array $package ): void {
		if ( self::SCHEME_ID !== (string) ( $package['scheme_id'] ?? '' ) ) {
			throw new CryptoException( __( 'Invalid threshold partial-decrypt contribution scheme.', 'relatasoft-secure-election-suite' ) );
		}

		$expected = hash( 'sha256', (string) wp_json_encode( self::rses_checksum_body( $package ), JSON_UNESCAPED_SLASHES ) );
		if ( ! hash_equals( $expected, (string) ( $package['checksum'] ?? '' ) ) ) {
			throw new CryptoException( __( 'Partial-decrypt contribution checksum validation failed.', 'relatasoft-secure-election-suite' ) );
		}

		$pk = (array) ( $package['public_key'] ?? array() );
		$p  = BigInt::fromDecimalString( (string) ( $pk['p'] ?? '' ) );
		$q  = BigInt::fromDecimalString( (string) ( $pk['q'] ?? '' ) );
		$g  = BigInt::fromDecimalString( (string) ( $pk['g'] ?? '' ) );
		$commitments = FeldmanVss::rses_commitments_from_decimal( array_map( 'strval', (array) ( $package['commitments'] ?? array() ) ) );

		$partials = (array) ( $package['partials'] ?? array() );
		if ( empty( $partials ) ) {
			throw new CryptoException( __( 'Contribution has no partials.', 'relatasoft-secure-election-suite' ) );
		}

		foreach ( $partials as $partial ) {
			if ( ! is_array( $partial ) ) {
				throw new CryptoException( __( 'Malformed partial in contribution.', 'relatasoft-secure-election-suite' ) );
			}
			$ct = ElGamalCiphertext::fromDecimalStrings(
				(string) ( $partial['alpha'] ?? '' ),
				(string) ( $partial['beta'] ?? '' )
			);
			if ( ! self::rses_verify_partial( $partial, $ct, $commitments, $p, $q, $g ) ) {
				throw new CryptoException( __( 'Chaum–Pedersen proof failed for a partial decryption.', 'relatasoft-secure-election-suite' ) );
			}
		}
	}

	/**
	 * @param array<string,mixed> $package Package.
	 * @return array<string,mixed>
	 */
	private static function rses_checksum_body( array $package ): array {
		$body = $package;
		unset( $body['checksum'] );
		return $body;
	}
}
