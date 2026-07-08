<?php
/**
 * Hash service for manifests and audit chain.
 *
 * @package RelataSoft\SecureElectionSuite\Exports
 */

namespace RelataSoft\SecureElectionSuite\Exports;

defined( 'ABSPATH' ) || exit;

/**
 * SHA-256 hashing utilities.
 */
class HashService {

	/**
	 * Hash an audit log entry for chain linking.
	 *
	 * @param array<string,mixed> $entry Entry data without current_hash.
	 * @return string
	 */
	public static function rses_hash_audit_entry( array $entry ): string {
		$rses_canonical = wp_json_encode(
			array(
				'actor_user_id' => $entry['actor_user_id'] ?? null,
				'action'        => $entry['action'] ?? '',
				'object_type'   => $entry['object_type'] ?? '',
				'object_id'     => $entry['object_id'] ?? null,
				'previous_hash' => $entry['previous_hash'] ?? null,
				'payload_json'  => $entry['payload_json'] ?? '',
				'created_at'    => $entry['created_at'] ?? '',
			),
			JSON_UNESCAPED_SLASHES
		);

		return hash( 'sha256', (string) $rses_canonical );
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
		return self::rses_sha256( (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES ) );
	}
}
