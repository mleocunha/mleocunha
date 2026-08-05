<?php
/**
 * Offline Feldman VSS share verification.
 *
 * @package RelataSoft\SecureElectionSuite\Crypto
 */

namespace RelataSoft\SecureElectionSuite\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Verifies a pasted share JSON without calling the Key Authority as an oracle.
 * Plain Shamir payloads are rejected (clean cut).
 */
class ShareVerifyService {

	public const CODE_VALID               = 'SHARE_VALID';
	public const CODE_CHECKSUM_MISMATCH   = 'VSS-CHECKSUM-MISMATCH';
	public const CODE_COMMITMENT_MISMATCH = 'VSS-COMMITMENT-MISMATCH';
	public const CODE_PUBLIC_KEY_MISMATCH = 'VSS-PUBLIC-KEY-MISMATCH';
	public const CODE_SCHEME_UNSUPPORTED  = 'VSS-SCHEME-UNSUPPORTED';
	public const CODE_MALFORMED           = 'VSS-MALFORMED';

	/**
	 * Validate a share for submission / tally (throws on failure).
	 *
	 * @param array<string,mixed> $payload Decoded share JSON.
	 * @throws CryptoException If invalid.
	 */
	public static function rses_validate_for_tally( array $payload ): void {
		$scheme_id = (string) ( $payload['scheme_id'] ?? '' );
		$scheme    = (string) ( $payload['scheme'] ?? '' );

		if (
			FeldmanVss::SCHEME_ID === $scheme_id
			|| CryptoSchemeRegistry::SCHEME_MODP_ELGAMAL_THRESHOLD_CP_V1 === $scheme_id
			|| FeldmanVss::RSES_SHARE_SCHEME === $scheme
		) {
			FeldmanVss::validateSharePayload( $payload );
			return;
		}

		throw new CryptoException(
			__( 'Unsupported share scheme. This plugin version accepts only Feldman VSS shares (modp-elgamal-feldman-v1 / modp-elgamal-threshold-cp-v1). Use the previous plugin version for legacy Shamir material.', 'relatasoft-secure-election-suite' )
		);
	}

	/**
	 * @param array<string,mixed> $payload Decoded share JSON.
	 * @return array{ok:bool,code:string,message:string,details:array<string,mixed>}
	 */
	public static function rses_verify_payload( array $payload ): array {
		$scheme_id = (string) ( $payload['scheme_id'] ?? '' );
		$scheme    = (string) ( $payload['scheme'] ?? '' );

		if (
			FeldmanVss::SCHEME_ID === $scheme_id
			|| CryptoSchemeRegistry::SCHEME_MODP_ELGAMAL_THRESHOLD_CP_V1 === $scheme_id
			|| FeldmanVss::RSES_SHARE_SCHEME === $scheme
		) {
			return self::rses_verify_feldman( $payload );
		}

		return self::rses_result(
			false,
			self::CODE_SCHEME_UNSUPPORTED,
			__( 'Unsupported share scheme for verification. Plain Shamir shares are not accepted in this plugin version.', 'relatasoft-secure-election-suite' ),
			array( 'scheme_id' => $scheme_id, 'scheme' => $scheme )
		);
	}

	/**
	 * @param array<string,mixed> $payload Payload.
	 * @return array{ok:bool,code:string,message:string,details:array<string,mixed>}
	 */
	private static function rses_verify_feldman( array $payload ): array {
		$required = array( 'share_index', 'share_value', 'public_key', 'commitments', 'checksum' );
		foreach ( $required as $field ) {
			if ( ! isset( $payload[ $field ] ) ) {
				return self::rses_result(
					false,
					self::CODE_MALFORMED,
					sprintf(
						/* translators: %s: field name */
						__( 'Share JSON is missing required field: %s', 'relatasoft-secure-election-suite' ),
						$field
					),
					array()
				);
			}
		}

		$expected_checksum = FeldmanVss::rses_compute_payload_checksum( $payload );
		if ( ! hash_equals( $expected_checksum, (string) $payload['checksum'] ) ) {
			return self::rses_result(
				false,
				self::CODE_CHECKSUM_MISMATCH,
				__( 'Share checksum mismatch. The file may be truncated or altered.', 'relatasoft-secure-election-suite' ),
				array()
			);
		}

		$pk = (array) $payload['public_key'];
		try {
			$p           = BigInt::fromDecimalString( (string) ( $pk['p'] ?? '' ) );
			$q           = BigInt::fromDecimalString( (string) ( $pk['q'] ?? '' ) );
			$g           = BigInt::fromDecimalString( (string) ( $pk['g'] ?? '' ) );
			$y           = BigInt::fromDecimalString( (string) ( $pk['y'] ?? '' ) );
			$s           = BigInt::fromDecimalString( (string) $payload['share_value'] );
			$commitments = FeldmanVss::rses_commitments_from_decimal( array_map( 'strval', (array) $payload['commitments'] ) );
		} catch ( CryptoException $e ) {
			return self::rses_result( false, self::CODE_MALFORMED, $e->getMessage(), array() );
		}

		if ( empty( $commitments ) || \gmp_cmp( $commitments[0], $y ) !== 0 ) {
			return self::rses_result(
				false,
				self::CODE_PUBLIC_KEY_MISMATCH,
				__( 'Commitment C0 does not match the public key y.', 'relatasoft-secure-election-suite' ),
				array()
			);
		}

		try {
			$ok = FeldmanVss::rses_verify_share(
				(int) $payload['share_index'],
				$s,
				$commitments,
				$p,
				$q,
				$g,
				$y
			);
		} catch ( CryptoException $e ) {
			return self::rses_result( false, self::CODE_MALFORMED, $e->getMessage(), array() );
		}

		if ( ! $ok ) {
			return self::rses_result(
				false,
				self::CODE_COMMITMENT_MISMATCH,
				__( 'Share is incompatible with the Feldman commitments. Do not use this file. The ceremony must be annulled.', 'relatasoft-secure-election-suite' ),
				array(
					'ceremony_id' => (string) ( $payload['ceremony_id'] ?? '' ),
					'key_id'      => (int) ( $payload['key_id'] ?? 0 ),
				)
			);
		}

		return self::rses_result(
			true,
			self::CODE_VALID,
			__( 'Share is valid against the Feldman commitments and public key.', 'relatasoft-secure-election-suite' ),
			array(
				'ceremony_id'            => (string) ( $payload['ceremony_id'] ?? '' ),
				'key_id'                 => (int) ( $payload['key_id'] ?? 0 ),
				'share_index'            => (int) $payload['share_index'],
				'threshold_t'            => (int) ( $payload['threshold_t'] ?? 0 ),
				'total_n'                => (int) ( $payload['total_n'] ?? 0 ),
				'public_transcript_hash' => (string) ( $payload['public_transcript_hash'] ?? '' ),
				'scheme_id'              => FeldmanVss::SCHEME_ID,
			)
		);
	}

	/**
	 * @param array<string,mixed> $details Details.
	 * @return array{ok:bool,code:string,message:string,details:array<string,mixed>}
	 */
	private static function rses_result( bool $ok, string $code, string $message, array $details ): array {
		return array(
			'ok'      => $ok,
			'code'    => $code,
			'message' => $message,
			'details' => $details,
		);
	}
}
