<?php
/**
 * Key import service.
 *
 * @package RelataSoft\SecureElectionSuite\KeyAuthority
 */

namespace RelataSoft\SecureElectionSuite\KeyAuthority;

use RelataSoft\SecureElectionSuite\Crypto\BigInt;
use RelataSoft\SecureElectionSuite\Crypto\CryptoException;
use RelataSoft\SecureElectionSuite\Security\AuditLogger;
use RelataSoft\SecureElectionSuite\Security\Sanitizer;

defined( 'ABSPATH' ) || exit;

/**
 * Imports key packages from JSON.
 */
class KeyImportService {

	/**
	 * Import a public key from JSON data.
	 *
	 * @param array<string,mixed> $data Import data.
	 * @param string              $label Key label.
	 * @return int Key ID.
	 * @throws CryptoException On validation failure.
	 */
	public static function rses_import_public_key( array $data, string $label ): int {
		$rses_required = array( 'p', 'q', 'g', 'y' );

		foreach ( $rses_required as $rses_field ) {
			if ( empty( $data[ $rses_field ] ) ) {
				throw new CryptoException(
					sprintf(
						/* translators: %s: field name */
						__( 'Missing required field: %s', 'relatasoft-secure-election-suite' ),
						$rses_field
					)
				);
			}
		}

		foreach ( $rses_required as $rses_field ) {
			BigInt::assertPositiveDecimalString( (string) $data[ $rses_field ] );
		}

		$rses_key_id = KeyRepository::rses_create(
			array(
				'key_label'             => Sanitizer::rses_text( $label ),
				'public_p'              => (string) $data['p'],
				'public_q'              => (string) $data['q'],
				'public_g'              => (string) $data['g'],
				'public_y'              => (string) $data['y'],
				'key_size'              => (int) ( $data['keySizeBits'] ?? 2048 ),
				'private_key_persisted' => 0,
			)
		);

		AuditLogger::rses_log( 'key_import', 'key', $rses_key_id );

		return $rses_key_id;
	}
}
