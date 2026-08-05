<?php
/**
 * Key repository.
 *
 * @package RelataSoft\SecureElectionSuite\KeyAuthority
 */

namespace RelataSoft\SecureElectionSuite\KeyAuthority;

use RelataSoft\SecureElectionSuite\Database\Repository;
use RelataSoft\SecureElectionSuite\Exports\HashService;

defined( 'ABSPATH' ) || exit;

/**
 * Database operations for ElGamal keys.
 */
class KeyRepository {

	/**
	 * Create a key record.
	 *
	 * @param array<string,mixed> $data Key data.
	 * @return int Key ID.
	 */
	public static function rses_create( array $data ): int {
		$rses_now = current_time( 'mysql', true );

		$rses_row = array(
			'election_round_id'     => $data['election_round_id'] ?? null,
			'key_label'             => $data['key_label'],
			'public_p'              => $data['public_p'],
			'public_q'              => $data['public_q'],
			'public_g'              => $data['public_g'],
			'public_y'              => $data['public_y'],
			'key_size'              => (int) $data['key_size'],
			'encoding_mode'         => $data['encoding_mode'] ?? 'g_power_count',
			'private_key_persisted' => (int) ( $data['private_key_persisted'] ?? 0 ),
			'private_x_encrypted'   => $data['private_x_encrypted'] ?? null,
			'field_prime'           => $data['field_prime'] ?? null,
			'threshold_t'           => $data['threshold_t'] ?? null,
			'total_n'               => $data['total_n'] ?? null,
			'description'           => $data['description'] ?? null,
			'attachments'           => isset( $data['attachments'] ) ? wp_json_encode( $data['attachments'] ) : null,
			'created_by'            => get_current_user_id(),
			'created_at'            => $rses_now,
			'is_deleted'            => 0,
		);

		$rses_row['audit_hash'] = HashService::rses_hash_json( $rses_row );

		return Repository::rses_insert(
			'rses_keys',
			$rses_row,
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%d', '%s' )
		);
	}

	/**
	 * Get key by ID.
	 *
	 * @param int $key_id Key ID.
	 * @return object|null
	 */
	public static function rses_get( int $key_id ): ?object {
		$rses_key = Repository::rses_get_by_id( 'rses_keys', $key_id );

		if ( $rses_key && (int) $rses_key->is_deleted === 1 ) {
			return null;
		}

		return $rses_key;
	}

	/**
	 * List active keys.
	 *
	 * @return array<int,object>
	 */
	public static function rses_list_active(): array {
		return Repository::rses_get_rows( 'rses_keys', 'is_deleted = 0', array() );
	}

	/**
	 * Soft delete a key.
	 *
	 * @param int $key_id Key ID.
	 * @return bool
	 */
	public static function rses_trash( int $key_id ): bool {
		return Repository::rses_update(
			'rses_keys',
			array(
				'is_deleted' => 1,
				'deleted_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $key_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Restore a trashed key.
	 *
	 * @param int $key_id Key ID.
	 * @return bool
	 */
	public static function rses_restore( int $key_id ): bool {
		return Repository::rses_update(
			'rses_keys',
			array(
				'is_deleted' => 0,
				'deleted_at' => null,
			),
			array( 'id' => $key_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Permanently delete a key.
	 *
	 * @param int $key_id Key ID.
	 * @return bool
	 */
	public static function rses_delete( int $key_id ): bool {
		return Repository::rses_delete_by_id( 'rses_keys', $key_id );
	}

	/**
	 * Get shares for a key.
	 *
	 * @param int $key_id Key ID.
	 * @return array<int,object>
	 */
	public static function rses_get_shares( int $key_id ): array {
		return Repository::rses_get_rows( 'rses_shares', 'key_id = %d', array( $key_id ) );
	}

	/**
	 * Get share for official user.
	 *
	 * @param int $key_id Key ID.
	 * @param int $user_id User ID.
	 * @return object|null
	 */
	public static function rses_get_share_for_user( int $key_id, int $user_id ): ?object {
		$rses_shares = Repository::rses_get_rows(
			'rses_shares',
			'key_id = %d AND official_user_id = %d',
			array( $key_id, $user_id ),
			'id ASC',
			1
		);

		return $rses_shares[0] ?? null;
	}

	/**
	 * Fail-closed ceremony invalidation after a failed share verification.
	 *
	 * @param int    $key_id Key ID.
	 * @param string $reason Short reason code.
	 */
	public static function rses_invalidate_ceremony( int $key_id, string $reason ): bool {
		return Repository::rses_update(
			'rses_keys',
			array(
				'ceremony_status' => 'CEREMONY_INVALID:' . $reason,
				'updated_at'      => current_time( 'mysql', true ),
			),
			array( 'id' => $key_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Whether the ceremony is still usable for exports / tally material.
	 *
	 * @param object $key Key row.
	 */
	public static function rses_ceremony_is_active( object $key ): bool {
		$status = (string) ( $key->ceremony_status ?? 'active' );
		return 'active' === $status || '' === $status;
	}
}
