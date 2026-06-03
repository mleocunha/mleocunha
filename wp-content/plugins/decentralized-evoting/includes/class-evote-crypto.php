<?php
/**
 * Cryptographic facade — ElGamal and SSS (Phase 2 implementation).
 *
 * Uses bundled phpseclib for big-integer and curve operations.
 *
 * @package DecentralizedEvoting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use phpseclib3\Math\BigInteger;

/**
 * Wrapper for election cryptography; methods are stubs until Phase 2.
 */
class EVote_Crypto {

	/**
	 * Whether phpseclib loaded successfully.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( BigInteger::class );
	}

	/**
	 * Smoke test for vendor autoload (admin diagnostics).
	 *
	 * @return true|WP_Error
	 */
	public static function self_test() {
		if ( ! self::is_available() ) {
			return new WP_Error( 'evote_crypto_unavailable', __( 'phpseclib is not loaded.', 'decentralized-evoting' ) );
		}
		try {
			$n = new BigInteger( '255' );
			$m = $n->add( new BigInteger( '1' ) );
			if ( '256' !== (string) $m ) {
				return new WP_Error( 'evote_crypto_test_failed', __( 'BigInteger self-test failed.', 'decentralized-evoting' ) );
			}
		} catch ( Exception $e ) {
			return new WP_Error( 'evote_crypto_exception', $e->getMessage() );
		}
		return true;
	}

	/**
	 * Generate ElGamal key pair and SSS-split private key (not yet implemented).
	 *
	 * @param int $threshold Minimum shares to reconstruct.
	 * @param int $shares    Total shares to generate.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function generate_key_material( $threshold, $shares ) {
		return new WP_Error(
			'evote_not_implemented',
			__( 'Key generation will be implemented in Phase 2.', 'decentralized-evoting' ),
			array( 'threshold' => $threshold, 'shares' => $shares )
		);
	}

	/**
	 * Reconstruct private key from SSS shares (not yet implemented).
	 *
	 * @param array<int, array<string, mixed>> $shares Decoded share objects.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function reconstruct_private_key( array $shares ) {
		return new WP_Error( 'evote_not_implemented', __( 'SSS reconstruction will be implemented in Phase 2.', 'decentralized-evoting' ) );
	}
}
