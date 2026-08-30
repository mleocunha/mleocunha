<?php
/**
 * Share payload encryption via SecretKeyProvider (A3).
 *
 * @package RelataSoft\SecureElectionSuite\KeyAuthority
 */

namespace RelataSoft\SecureElectionSuite\KeyAuthority;

use RelataSoft\SecureElectionSuite\Painel\Application\Identity\IdentityGateway;

defined( 'ABSPATH' ) || exit;

/**
 * Basic storage encryption abstraction (not HSM-grade).
 *
 * Key material comes only from {@see IdentityGateway} secrets — never host salts in this class.
 */
class ShareEncryptionService {

	/**
	 * Encrypt data for storage.
	 *
	 * @param string $plaintext Plaintext.
	 * @return string Base64 encoded ciphertext with IV.
	 */
	public static function rses_encrypt( string $plaintext ): string {
		$rses_key = self::rses_derive_key();
		$rses_iv  = random_bytes( 16 );

		$rses_ciphertext = openssl_encrypt(
			$plaintext,
			'AES-256-CBC',
			$rses_key,
			OPENSSL_RAW_DATA,
			$rses_iv
		);

		if ( false === $rses_ciphertext ) {
			return base64_encode( $plaintext );
		}

		return base64_encode( $rses_iv . $rses_ciphertext );
	}

	/**
	 * Decrypt stored data.
	 *
	 * @param string $encrypted Base64 encoded ciphertext.
	 * @return string
	 */
	public static function rses_decrypt( string $encrypted ): string {
		$rses_raw = base64_decode( $encrypted, true );

		if ( false === $rses_raw || strlen( $rses_raw ) < 17 ) {
			return $encrypted;
		}

		$rses_iv         = substr( $rses_raw, 0, 16 );
		$rses_ciphertext = substr( $rses_raw, 16 );
		$rses_key        = self::rses_derive_key();

		$rses_plaintext = openssl_decrypt(
			$rses_ciphertext,
			'AES-256-CBC',
			$rses_key,
			OPENSSL_RAW_DATA,
			$rses_iv
		);

		return false !== $rses_plaintext ? $rses_plaintext : '';
	}

	/**
	 * Derive encryption key via A3 SecretKeyProvider.
	 *
	 * @return string
	 */
	private static function rses_derive_key(): string {
		return IdentityGateway::get()->secrets->shareStorageKey();
	}
}
