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
 * Polynomial coefficients live in Z/qZ. Commitments are C_k = g^{a_k} mod p.
 * Share i with value s satisfies g^s ≡ Π_k C_k^{i^k} (mod p).
 * C_0 = g^{a_0} = g^x = y.
 */
class FeldmanVss {

	public const RSES_SHARE_VERSION = '1.0';
	public const RSES_SHARE_SCHEME  = 'FeldmanVSS';
	public const SCHEME_ID          = CryptoSchemeRegistry::SCHEME_MODP_ELGAMAL_FELDMAN_V1;

	/**
	 * Split secret x and build Feldman commitments.
	 *
	 * @param \GMP $secret       Private exponent x ∈ [1, q-1].
	 * @param int  $threshold    t.
	 * @param int  $total_shares n.
	 * @param \GMP $p            Group prime.
	 * @param \GMP $q            Subgroup order (Shamir field).
	 * @param \GMP $g            Generator.
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

		$coefficients = array( $secret );
		$one          = \gmp_init( 1 );
		$q_minus_one  = \gmp_sub( $q, $one );

		for ( $i = 1; $i < $threshold; ++$i ) {
			$coefficients[] = CryptoRandom::randomIntegerBetween( $one, $q_minus_one );
		}

		$commitments = array();
		foreach ( $coefficients as $a_k ) {
			$commitments[] = BigInt::modPow( $g, $a_k, $p );
		}

		$shares = array();
		for ( $index = 1; $index <= $total_shares; ++$index ) {
			$xi       = \gmp_init( $index );
			$shares[] = array(
				'x' => $index,
				'y' => Polynomial::rses_evaluate( $coefficients, $xi, $q ),
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
	 * @param list<\GMP> $commitments C_k.
	 * @param \GMP       $p           Prime.
	 * @param \GMP       $q           Order.
	 * @param \GMP       $g           Generator.
	 * @param \GMP|null  $public_y    Optional; must equal C_0 when set.
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

		$rhs   = \gmp_init( 1 );
		$i_pow = \gmp_init( 1 );
		$i_gmp = \gmp_init( $share_index );

		foreach ( $commitments as $c_k ) {
			$term  = BigInt::modPow( $c_k, $i_pow, $p );
			$rhs   = BigInt::modMul( $rhs, $term, $p );
			$i_pow = BigInt::modMul( $i_pow, $i_gmp, $q );
		}

		$lhs = BigInt::modPow( $g, $share_value, $p );
		return \gmp_cmp( $lhs, $rhs ) === 0;
	}

	/**
	 * Build encrypted-share JSON payload (includes commitments for offline verify).
	 *
	 * @param array<string,mixed> $args Args.
	 * @return array<string,mixed>
	 */
	public static function rses_build_share_payload( array $args ): array {
		$payload = array(
			'format_version'         => '1.0',
			'version'                => self::RSES_SHARE_VERSION,
			'scheme'                 => self::RSES_SHARE_SCHEME,
			'scheme_id'              => self::SCHEME_ID,
			'ceremony_id'            => (string) ( $args['ceremony_id'] ?? '' ),
			'key_id'                 => (int) ( $args['key_id'] ?? 0 ),
			'election_round_id'      => (int) ( $args['election_round_id'] ?? 0 ),
			'threshold_t'            => (int) ( $args['threshold_t'] ?? 0 ),
			'total_n'                => (int) ( $args['total_n'] ?? 0 ),
			'participant_id'         => (int) ( $args['participant_id'] ?? 0 ),
			'field_prime'            => (string) ( $args['field_prime'] ?? '' ),
			'share_index'            => (string) (int) ( $args['share_index'] ?? 0 ),
			'share_value'            => (string) ( $args['share_value'] ?? '' ),
			'public_key'             => array(
				'p' => (string) ( $args['public_key']['p'] ?? '' ),
				'q' => (string) ( $args['public_key']['q'] ?? '' ),
				'g' => (string) ( $args['public_key']['g'] ?? '' ),
				'y' => (string) ( $args['public_key']['y'] ?? '' ),
			),
			'commitments'            => array_values( (array) ( $args['commitments'] ?? array() ) ),
			'public_transcript_hash' => (string) ( $args['public_transcript_hash'] ?? '' ),
		);

		$payload['checksum'] = self::rses_compute_payload_checksum( $payload );
		return $payload;
	}

	/**
	 * @param array<string,mixed> $payload Payload.
	 */
	public static function rses_compute_payload_checksum( array $payload ): string {
		$data = $payload;
		unset( $data['checksum'] );
		return hash( 'sha256', (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Validate Feldman share payload (structure, checksum, VSS equation).
	 *
	 * @param array<string,mixed> $payload Share payload.
	 * @throws CryptoException If invalid.
	 */
	public static function validateSharePayload( array $payload ): void {
		$scheme_id = (string) ( $payload['scheme_id'] ?? '' );
		$scheme    = (string) ( $payload['scheme'] ?? '' );

		if ( self::SCHEME_ID !== $scheme_id && self::RSES_SHARE_SCHEME !== $scheme ) {
			throw new CryptoException( __( 'Invalid Feldman share scheme. Plain Shamir shares are not accepted in this plugin version.', 'relatasoft-secure-election-suite' ) );
		}
		if ( ! CryptoSchemeRegistry::rses_may_verify( self::SCHEME_ID === $scheme_id ? $scheme_id : self::SCHEME_ID ) ) {
			throw new CryptoException( __( 'Share scheme is not accepted in this plugin version.', 'relatasoft-secure-election-suite' ) );
		}

		$required = array(
			'key_id',
			'threshold_t',
			'total_n',
			'field_prime',
			'share_index',
			'share_value',
			'public_key',
			'commitments',
			'checksum',
		);
		foreach ( $required as $field ) {
			if ( ! array_key_exists( $field, $payload ) ) {
				throw new CryptoException(
					sprintf(
						/* translators: %s: field name */
						__( 'Missing required share field: %s', 'relatasoft-secure-election-suite' ),
						$field
					)
				);
			}
		}

		if ( ! is_array( $payload['public_key'] ) || ! is_array( $payload['commitments'] ) ) {
			throw new CryptoException( __( 'Invalid public key or commitments in share payload.', 'relatasoft-secure-election-suite' ) );
		}

		$expected = self::rses_compute_payload_checksum( $payload );
		if ( ! hash_equals( $expected, (string) $payload['checksum'] ) ) {
			throw new CryptoException( __( 'Share checksum validation failed.', 'relatasoft-secure-election-suite' ) );
		}

		$pk = $payload['public_key'];
		foreach ( array( 'p', 'q', 'g', 'y' ) as $pk_key ) {
			if ( empty( $pk[ $pk_key ] ) ) {
				throw new CryptoException( __( 'Incomplete public key in share payload.', 'relatasoft-secure-election-suite' ) );
			}
		}

		$p           = BigInt::fromDecimalString( (string) $pk['p'] );
		$q           = BigInt::fromDecimalString( (string) $pk['q'] );
		$g           = BigInt::fromDecimalString( (string) $pk['g'] );
		$y           = BigInt::fromDecimalString( (string) $pk['y'] );
		$share_value = BigInt::fromDecimalString( (string) $payload['share_value'] );
		$commitments = self::rses_commitments_from_decimal( array_map( 'strval', $payload['commitments'] ) );

		if ( empty( $commitments ) || \gmp_cmp( $commitments[0], $y ) !== 0 ) {
			throw new CryptoException( __( 'Commitment C0 does not match the public key y.', 'relatasoft-secure-election-suite' ) );
		}

		// field_prime must be q for Feldman.
		if ( (string) $payload['field_prime'] !== BigInt::toDecimalString( $q ) ) {
			throw new CryptoException( __( 'Feldman field_prime must equal the ElGamal subgroup order q.', 'relatasoft-secure-election-suite' ) );
		}

		$ok = self::rses_verify_share(
			(int) $payload['share_index'],
			$share_value,
			$commitments,
			$p,
			$q,
			$g,
			$y
		);
		if ( ! $ok ) {
			throw new CryptoException( __( 'Share is incompatible with the Feldman commitments.', 'relatasoft-secure-election-suite' ) );
		}
	}

	/**
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
