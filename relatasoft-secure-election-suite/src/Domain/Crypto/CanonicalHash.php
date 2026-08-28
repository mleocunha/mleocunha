<?php
declare(strict_types=1);
/**
 * Hash service for manifests and audit chain.
 *
 * @package RelataSoft\SecureElectionSuite\Painel\Domain\Crypto
 */

namespace RelataSoft\SecureElectionSuite\Painel\Domain\Crypto;


/**
 * SHA-256 hashing utilities (portable Domain; A1).
 *
 * No WordPress runtime required — uses native json_encode.
 */
class CanonicalHash {

	/**
	 * JSON flags for canonical audit hashing.
	 */
	private const RSES_JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

	/**
	 * Hash an audit log entry for chain linking.
	 *
	 * Normalizes types so write-time PHP ints/nulls match read-time MySQL strings.
	 *
	 * @param array<string,mixed> $entry Entry data without current_hash.
	 * @return string
	 */
	public static function rses_hash_audit_entry( array $entry ): string {
		$rses_canonical = json_encode(
			self::rses_canonicalize_audit_entry( $entry ),
			self::RSES_JSON_FLAGS
		);

		return hash( 'sha256', (string) $rses_canonical );
	}

	/**
	 * Canonicalize audit fields for stable hashing across PHP/MySQL type differences.
	 *
	 * @param array<string,mixed> $entry Raw entry.
	 * @return array<string,mixed>
	 */
	public static function rses_canonicalize_audit_entry( array $entry ): array {
		return array(
			'actor_user_id' => self::rses_nullable_int( $entry['actor_user_id'] ?? null ),
			'action'        => (string) ( $entry['action'] ?? '' ),
			'object_type'   => (string) ( $entry['object_type'] ?? '' ),
			'object_id'     => self::rses_nullable_int( $entry['object_id'] ?? null ),
			'previous_hash' => self::rses_nullable_string( $entry['previous_hash'] ?? null ),
			'payload_json'  => (string) ( $entry['payload_json'] ?? '' ),
			'created_at'    => (string) ( $entry['created_at'] ?? '' ),
		);
	}

	/**
	 * Hash arbitrary data.
	 *
	 * @param string $data Data to hash.
	 * @return string
	 */
	public static function rses_sha256( string $data ): string {
		return hash( 'sha256', $data );
	}

	/**
	 * Hash JSON with canonical encoding.
	 *
	 * @param array<string,mixed> $data Data.
	 * @return string
	 */
	public static function rses_hash_json( array $data ): string {
		return self::rses_sha256( (string) json_encode( $data, self::RSES_JSON_FLAGS ) );
	}

	/**
	 * Encode payload JSON consistently for storage and hashing.
	 *
	 * @param array<string,mixed> $payload Payload.
	 * @return string
	 */
	public static function rses_encode_payload( array $payload ): string {
		$rses_json = json_encode( $payload, self::RSES_JSON_FLAGS );
		return false === $rses_json ? '{}' : $rses_json;
	}

	/**
	 * Normalize nullable integer (MySQL returns numeric columns as strings).
	 *
	 * @param mixed $value Value.
	 * @return int|null
	 */
	private static function rses_nullable_int( $value ): ?int {
		if ( null === $value || '' === $value ) {
			return null;
		}

		return (int) $value;
	}

	/**
	 * Normalize nullable string hash fields.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private static function rses_nullable_string( $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		return (string) $value;
	}
}
