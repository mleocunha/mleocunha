<?php
/**
 * Key repository.
 *
 * @package RelataSoft\SecureElectionSuite\KeyAuthority
 */

namespace RelataSoft\SecureElectionSuite\KeyAuthority;

use RelataSoft\SecureElectionSuite\Exports\HashService;
use RelataSoft\SecureElectionSuite\Painel\Application\Persistence\PersistenceGateway;

defined( 'ABSPATH' ) || exit;

/**
 * Database operations for ElGamal keys (delegates to A2 persistence ports).
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

		return PersistenceGateway::get()->keys->create( $rses_row );
	}

	/**
	 * Get key by ID.
	 *
	 * @param int $key_id Key ID.
	 * @return object|null
	 */
	public static function rses_get( int $key_id ): ?object {
		$row = PersistenceGateway::get()->keys->find( $key_id );
		return null === $row ? null : (object) $row;
	}

	/**
	 * List active keys.
	 *
	 * @return array<int,object>
	 */
	public static function rses_list_active(): array {
		return array_map(
			static fn( array $row ) => (object) $row,
			PersistenceGateway::get()->keys->listActive()
		);
	}

	/**
	 * Soft delete a key.
	 *
	 * @param int $key_id Key ID.
	 * @return bool
	 */
	public static function rses_trash( int $key_id ): bool {
		return PersistenceGateway::get()->keys->trash( $key_id );
	}

	/**
	 * Restore a trashed key.
	 *
	 * @param int $key_id Key ID.
	 * @return bool
	 */
	public static function rses_restore( int $key_id ): bool {
		return PersistenceGateway::get()->keys->restore( $key_id );
	}

	/**
	 * Permanently delete a key.
	 *
	 * @param int $key_id Key ID.
	 * @return bool
	 */
	public static function rses_delete( int $key_id ): bool {
		return PersistenceGateway::get()->keys->delete( $key_id );
	}

	/**
	 * Get shares for a key.
	 *
	 * @param int $key_id Key ID.
	 * @return array<int,object>
	 */
	public static function rses_get_shares( int $key_id ): array {
		return array_map(
			static fn( array $row ) => (object) $row,
			PersistenceGateway::get()->shares->listByKey( $key_id )
		);
	}

	/**
	 * Get share for official user.
	 *
	 * @param int $key_id Key ID.
	 * @param int $user_id User ID.
	 * @return object|null
	 */
	public static function rses_get_share_for_user( int $key_id, int $user_id ): ?object {
		$row = PersistenceGateway::get()->shares->findForUser( $key_id, $user_id );
		return null === $row ? null : (object) $row;
	}
}
