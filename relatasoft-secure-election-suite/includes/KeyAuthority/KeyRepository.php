<?php
/**
 * Key repository.
 *
 * @package RelataSoft\SecureElectionSuite\KeyAuthority
 */

namespace RelataSoft\SecureElectionSuite\KeyAuthority;

use RelataSoft\SecureElectionSuite\Database\Repository;
use RelataSoft\SecureElectionSuite\Database\Schema;
use RelataSoft\SecureElectionSuite\Exports\HashService;
use RelataSoft\SecureElectionSuite\Voting\ElectionRepository;

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
	 * Delete Feldman VSS share rows for a key.
	 *
	 * @param int $key_id Key ID.
	 * @return int Rows deleted.
	 */
	public static function rses_delete_shares_for_key( int $key_id ): int {
		global $wpdb;

		$key_id = absint( $key_id );
		if ( $key_id < 1 ) {
			return 0;
		}

		$rses_table = Schema::rses_table( 'rses_shares' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rses_deleted = $wpdb->delete(
			$rses_table,
			array( 'key_id' => $key_id ),
			array( '%d' )
		);

		return false === $rses_deleted ? 0 : (int) $rses_deleted;
	}

	/**
	 * Whether this key is assigned to any election round on this site.
	 *
	 * @param int $key_id Key ID.
	 */
	public static function rses_is_linked_to_elections( int $key_id ): bool {
		return ! empty( ElectionRepository::rses_list_usage_by_key( $key_id ) );
	}

	/**
	 * Permanently delete a key, its shares, and clear an active keygen job for it.
	 *
	 * Refuses when the key is still linked to an election round on this site.
	 *
	 * @param int $key_id Key ID.
	 * @return array{ok:bool,error:?string,label:string}
	 */
	public static function rses_delete_permanently( int $key_id ): array {
		$key_id     = absint( $key_id );
		$rses_key   = self::rses_get( $key_id );
		$rses_label = $rses_key ? (string) $rses_key->key_label : '';

		if ( ! $rses_key ) {
			return array(
				'ok'    => false,
				'error' => __( 'Key not found.', 'relatasoft-secure-election-suite' ),
				'label' => '',
			);
		}

		if ( self::rses_is_linked_to_elections( $key_id ) ) {
			return array(
				'ok'    => false,
				'error' => __( 'This key is still linked to an election on this site. Remove or reassign that election before deleting the key.', 'relatasoft-secure-election-suite' ),
				'label' => $rses_label,
			);
		}

		self::rses_delete_shares_for_key( $key_id );

		$rses_job = KeyGenerationJob::rses_get();
		if ( is_array( $rses_job ) && (int) ( $rses_job['key_id'] ?? 0 ) === $key_id ) {
			KeyGenerationJob::rses_delete();
		}

		$rses_ok = self::rses_delete( $key_id );
		if ( ! $rses_ok ) {
			return array(
				'ok'    => false,
				'error' => __( 'Could not delete this key.', 'relatasoft-secure-election-suite' ),
				'label' => $rses_label,
			);
		}

		return array(
			'ok'    => true,
			'error' => null,
			'label' => $rses_label,
		);
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
