<?php
/**
 * Cryptographic scheme identifiers and lifecycle status.
 *
 * @package RelataSoft\SecureElectionSuite\Crypto
 */

namespace RelataSoft\SecureElectionSuite\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Canonical catalogue of crypto schemes.
 *
 * Clean cut: plain Shamir (`modp-elgamal-shamir-v1`) is retired in this lineage.
 * Legacy elections remain on the previous plugin version.
 *
 * @see docs/CRYPTO-EVOLUTION.md
 */
final class CryptoSchemeRegistry {

	/** Retired: plain Shamir (previous plugin versions only). */
	public const SCHEME_MODP_ELGAMAL_SHAMIR_V1 = 'modp-elgamal-shamir-v1';

	/** Fase 1: Feldman VSS, trusted dealer, reconstruct-x still permitted. */
	public const SCHEME_MODP_ELGAMAL_FELDMAN_V1 = 'modp-elgamal-feldman-v1';

	/** Fase 2: threshold partial decryption + Chaum–Pedersen (no reconstruct-x). */
	public const SCHEME_MODP_ELGAMAL_THRESHOLD_CP_V1 = 'modp-elgamal-threshold-cp-v1';

	/** Planned: EC ElGamal on NIST P-521. */
	public const SCHEME_EC_ELGAMAL_P521_THRESHOLD_CP_V1 = 'ec-elgamal-p521-threshold-cp-v1';

	/** Planned: Pedersen VSS + DKG on P-521. */
	public const SCHEME_EC_ELGAMAL_P521_PEDERSEN_DKG_V1 = 'ec-elgamal-p521-pedersen-dkg-v1';

	public const STATUS_RETIRED        = 'retired';
	public const STATUS_TRANSITIONAL   = 'transitional';
	public const STATUS_TARGET_MODULAR = 'target-modular';
	public const STATUS_TARGET_FINAL   = 'target-final';
	public const STATUS_PLANNED        = 'planned';

	/**
	 * Scheme used for new Key Authority ceremonies.
	 *
	 * Fase 2: generate under threshold-cp (Feldman VSS shares + partial decrypt tally).
	 */
	public static function rses_active_generation_scheme(): string {
		return self::SCHEME_MODP_ELGAMAL_THRESHOLD_CP_V1;
	}

	/**
	 * @return array<string,array{status:string,implementation:string,notes:string}>
	 */
	public static function rses_all(): array {
		return array(
			self::SCHEME_MODP_ELGAMAL_SHAMIR_V1           => array(
				'status'         => self::STATUS_RETIRED,
				'implementation' => 'none',
				'notes'          => 'Plain Shamir retired in this lineage; use previous plugin version for legacy artefacts.',
			),
			self::SCHEME_MODP_ELGAMAL_FELDMAN_V1          => array(
				'status'         => self::STATUS_TRANSITIONAL,
				'implementation' => 'php',
				'notes'          => 'Feldman VSS; trusted dealer; reconstruct x still permitted during tally.',
			),
			self::SCHEME_MODP_ELGAMAL_THRESHOLD_CP_V1     => array(
				'status'         => self::STATUS_TARGET_MODULAR,
				'implementation' => 'php',
				'notes'          => 'Partial decryption + Chaum–Pedersen; reconstruct x prohibited.',
			),
			self::SCHEME_EC_ELGAMAL_P521_THRESHOLD_CP_V1 => array(
				'status'         => self::STATUS_TARGET_FINAL,
				'implementation' => 'rust',
				'notes'          => 'EC ElGamal on secp521r1; TSE-aligned primitives.',
			),
			self::SCHEME_EC_ELGAMAL_P521_PEDERSEN_DKG_V1  => array(
				'status'         => self::STATUS_PLANNED,
				'implementation' => 'rust',
				'notes'          => 'Distributed key generation; no full x on any host.',
			),
		);
	}

	/**
	 * Whether a scheme may generate new election material.
	 */
	public static function rses_may_generate( string $scheme_id ): bool {
		return self::SCHEME_MODP_ELGAMAL_THRESHOLD_CP_V1 === $scheme_id;
	}

	/**
	 * Whether a scheme may be verified / tallied in this plugin version.
	 */
	public static function rses_may_verify( string $scheme_id ): bool {
		return in_array(
			$scheme_id,
			array(
				self::SCHEME_MODP_ELGAMAL_FELDMAN_V1,
				self::SCHEME_MODP_ELGAMAL_THRESHOLD_CP_V1,
			),
			true
		);
	}
}
