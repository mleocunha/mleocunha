<?php
/**
 * Cryptographic scheme identifiers and lifecycle status.
 *
 * @package RelataSoft\SecureElectionSuite\Crypto
 */

namespace RelataSoft\SecureElectionSuite\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Registry of crypto schemes used by the suite.
 *
 * Artefacts carry scheme_id from Fase 1 exporters. This class is the canonical
 * catalogue for docs, tests, and generation / archive gating.
 *
 * @see docs/CRYPTO-EVOLUTION.md
 * @see docs/crypto/modp-elgamal-shamir-v1.md
 */
final class CryptoSchemeRegistry {

	/** Baseline shipping today (plain Shamir, reconstruct-x tally). */
	public const SCHEME_MODP_ELGAMAL_SHAMIR_V1 = 'modp-elgamal-shamir-v1';

	/** Planned: Feldman VSS, still reconstruct-x (Fase 1). */
	public const SCHEME_MODP_ELGAMAL_FELDMAN_V1 = 'modp-elgamal-feldman-v1';

	/** Planned: threshold partial decryption + Chaum–Pedersen (Fase 2). */
	public const SCHEME_MODP_ELGAMAL_THRESHOLD_CP_V1 = 'modp-elgamal-threshold-cp-v1';

	/** Planned: EC ElGamal on NIST P-521 (Fase 4). */
	public const SCHEME_EC_ELGAMAL_P521_THRESHOLD_CP_V1 = 'ec-elgamal-p521-threshold-cp-v1';

	/** Planned: Pedersen VSS + DKG on P-521 (Fase 5). */
	public const SCHEME_EC_ELGAMAL_P521_PEDERSEN_DKG_V1 = 'ec-elgamal-p521-pedersen-dkg-v1';

	public const STATUS_LEGACY_BASELINE   = 'legacy-baseline';
	public const STATUS_TRANSITIONAL      = 'transitional';
	public const STATUS_TARGET_MODULAR    = 'target-modular';
	public const STATUS_TARGET_FINAL      = 'target-final';
	public const STATUS_PLANNED           = 'planned';

	/**
	 * Scheme used for new Key Authority ceremonies.
	 */
	public static function rses_active_generation_scheme(): string {
		return self::SCHEME_MODP_ELGAMAL_FELDMAN_V1;
	}

	/**
	 * @return array<string,array{status:string,implementation:string,notes:string}>
	 */
	public static function rses_all(): array {
		return array(
			self::SCHEME_MODP_ELGAMAL_SHAMIR_V1         => array(
				'status'         => self::STATUS_LEGACY_BASELINE,
				'implementation' => 'php',
				'notes'          => 'Plain Shamir; reconstruct x at tally; no VSS commitments.',
			),
			self::SCHEME_MODP_ELGAMAL_FELDMAN_V1        => array(
				'status'         => self::STATUS_TRANSITIONAL,
				'implementation' => 'php',
				'notes'          => 'Feldman VSS; trusted dealer; reconstruct x still permitted.',
			),
			self::SCHEME_MODP_ELGAMAL_THRESHOLD_CP_V1   => array(
				'status'         => self::STATUS_TARGET_MODULAR,
				'implementation' => 'php',
				'notes'          => 'Partial decryption + Chaum–Pedersen; reconstruct x prohibited.',
			),
			self::SCHEME_EC_ELGAMAL_P521_THRESHOLD_CP_V1 => array(
				'status'         => self::STATUS_TARGET_FINAL,
				'implementation' => 'rust',
				'notes'          => 'EC ElGamal on secp521r1; TSE-aligned primitives.',
			),
			self::SCHEME_EC_ELGAMAL_P521_PEDERSEN_DKG_V1 => array(
				'status'         => self::STATUS_PLANNED,
				'implementation' => 'rust',
				'notes'          => 'Distributed key generation; no full x on any host.',
			),
		);
	}

	/**
	 * Whether a scheme may still generate new election material.
	 */
	public static function rses_may_generate( string $scheme_id ): bool {
		return self::SCHEME_MODP_ELGAMAL_FELDMAN_V1 === $scheme_id;
	}

	/**
	 * Whether a scheme may be used to verify / decrypt archived elections.
	 */
	public static function rses_may_verify_archive( string $scheme_id ): bool {
		return isset( self::rses_all()[ $scheme_id ] );
	}
}
