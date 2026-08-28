<?php
/**
 * Hash service for manifests and audit chain (Adapter #1 facade).
 *
 * @package RelataSoft\SecureElectionSuite\Exports
 */

namespace RelataSoft\SecureElectionSuite\Exports;

use RelataSoft\SecureElectionSuite\Painel\Domain\Crypto\CanonicalHash;

defined( 'ABSPATH' ) || exit;

/**
 * SHA-256 hashing utilities — delegates to portable CanonicalHash.
 */
class HashService {

	/**
	 * @param array<string,mixed> $entry Entry data without current_hash.
	 */
	public static function rses_hash_audit_entry( array $entry ): string {
		return CanonicalHash::rses_hash_audit_entry( $entry );
	}

	/**
	 * @param array<string,mixed> $entry Raw entry.
	 * @return array<string,mixed>
	 */
	public static function rses_canonicalize_audit_entry( array $entry ): array {
		return CanonicalHash::rses_canonicalize_audit_entry( $entry );
	}

	public static function rses_sha256( string $data ): string {
		return CanonicalHash::rses_sha256( $data );
	}

	/**
	 * @param array<string,mixed> $data Data.
	 */
	public static function rses_hash_json( array $data ): string {
		return CanonicalHash::rses_hash_json( $data );
	}

	/**
	 * @param array<string,mixed> $payload Payload.
	 */
	public static function rses_encode_payload( array $payload ): string {
		return CanonicalHash::rses_encode_payload( $payload );
	}
}
