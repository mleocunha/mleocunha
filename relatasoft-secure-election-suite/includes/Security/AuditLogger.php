<?php
/**
 * Hash-chained audit logger.
 *
 * @package RelataSoft\SecureElectionSuite\Security
 */

namespace RelataSoft\SecureElectionSuite\Security;

use RelataSoft\SecureElectionSuite\Database\Repository;
use RelataSoft\SecureElectionSuite\Database\Schema;
use RelataSoft\SecureElectionSuite\Exports\HashService;

defined( 'ABSPATH' ) || exit;

/**
 * Append-only hash-chained audit log.
 */
class AuditLogger {

	/**
	 * Sensitive keys that must never be logged.
	 *
	 * @var array<int,string>
	 */
	private static array $rses_forbidden_keys = array(
		'private_x',
		'private_key',
		'share_value',
		'plaintext_vote',
		'vote_choice',
		'reconstructed_x',
	);

	/**
	 * Soft-fail audit logging so privileged actions still complete.
	 *
	 * @param string              $action      Action name.
	 * @param string              $object_type Object type.
	 * @param int|null            $object_id   Object ID.
	 * @param array<string,mixed> $payload     Payload data.
	 * @return int Log entry ID or 0.
	 */
	public static function rses_log(
		string $action,
		string $object_type,
		?int $object_id = null,
		array $payload = array()
	): int {
		try {
			$rses_payload        = self::rses_sanitize_payload( $payload );
			$rses_previous_hash  = self::rses_get_last_hash();
			$rses_actor          = get_current_user_id();
			$rses_actor          = $rses_actor > 0 ? $rses_actor : null;

			$rses_entry = array(
				'actor_user_id' => $rses_actor,
				'action'        => Sanitizer::rses_text( $action ),
				'object_type'   => Sanitizer::rses_text( $object_type ),
				'object_id'     => $object_id,
				'previous_hash' => $rses_previous_hash,
				'payload_json'  => HashService::rses_encode_payload( $rses_payload ),
				'created_at'    => current_time( 'mysql', true ),
			);

			// Hash canonicalized fields so verification matches MySQL string types.
			$rses_current_hash          = HashService::rses_hash_audit_entry( $rses_entry );
			$rses_entry['current_hash'] = $rses_current_hash;

			return Repository::rses_insert(
				'rses_audit_log',
				$rses_entry,
				array( '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
			);
		} catch ( \Throwable $rses_e ) {
			return 0;
		}
	}

	/**
	 * Get the last audit log hash.
	 *
	 * @return string|null
	 */
	public static function rses_get_last_hash(): ?string {
		global $wpdb;

		$rses_table = Schema::rses_table( 'rses_audit_log' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rses_hash = $wpdb->get_var(
			"SELECT current_hash FROM {$rses_table} ORDER BY id DESC LIMIT 1"
		);

		return $rses_hash ? (string) $rses_hash : null;
	}

	/**
	 * Get audit log entries.
	 *
	 * @param int $limit Limit.
	 * @return array<int,object>
	 */
	public static function rses_get_entries( int $limit = 100 ): array {
		return Repository::rses_get_rows( 'rses_audit_log', '1=1', array(), 'id DESC', $limit );
	}

	/**
	 * Verify hash chain integrity.
	 *
	 * @return array{valid:bool,errors:array<int,string>}
	 */
	public static function rses_verify_chain(): array {
		global $wpdb;

		$rses_table = Schema::rses_table( 'rses_audit_log' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rses_rows = $wpdb->get_results(
			"SELECT * FROM {$rses_table} ORDER BY id ASC"
		);

		$rses_errors   = array();
		$rses_expected = null;

		if ( ! is_array( $rses_rows ) ) {
			return array( 'valid' => true, 'errors' => array() );
		}

		foreach ( $rses_rows as $rses_row ) {
			$rses_entry = array(
				'actor_user_id' => $rses_row->actor_user_id,
				'action'        => $rses_row->action,
				'object_type'   => $rses_row->object_type,
				'object_id'     => $rses_row->object_id,
				'previous_hash' => $rses_row->previous_hash,
				'payload_json'  => $rses_row->payload_json,
				'created_at'    => $rses_row->created_at,
			);

			$rses_computed = HashService::rses_hash_audit_entry( $rses_entry );

			if ( ! hash_equals( (string) $rses_row->current_hash, $rses_computed ) ) {
				$rses_errors[] = sprintf(
					/* translators: %d: audit log entry ID */
					__( 'Hash mismatch at entry %d', 'relatasoft-secure-election-suite' ),
					(int) $rses_row->id
				);
			}

			$rses_prev = ( null === $rses_row->previous_hash || '' === $rses_row->previous_hash )
				? null
				: (string) $rses_row->previous_hash;

			if ( $rses_prev !== $rses_expected ) {
				$rses_errors[] = sprintf(
					/* translators: %d: audit log entry ID */
					__( 'Chain break at entry %d', 'relatasoft-secure-election-suite' ),
					(int) $rses_row->id
				);
			}

			$rses_expected = (string) $rses_row->current_hash;
		}

		return array(
			'valid'  => empty( $rses_errors ),
			'errors' => $rses_errors,
		);
	}

	/**
	 * Recompute previous/current hashes for the entire audit chain.
	 *
	 * Use after upgrading hash canonicalization so existing rows verify.
	 * Does not alter action payloads — only hash columns.
	 *
	 * @return array{repaired:int,valid:bool}
	 */
	public static function rses_repair_chain(): array {
		global $wpdb;

		$rses_table = Schema::rses_table( 'rses_audit_log' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rses_rows = $wpdb->get_results(
			"SELECT * FROM {$rses_table} ORDER BY id ASC"
		);

		if ( ! is_array( $rses_rows ) || empty( $rses_rows ) ) {
			return array(
				'repaired' => 0,
				'valid'    => true,
			);
		}

		$rses_previous = null;
		$rses_repaired = 0;

		foreach ( $rses_rows as $rses_row ) {
			$rses_entry = array(
				'actor_user_id' => $rses_row->actor_user_id,
				'action'        => $rses_row->action,
				'object_type'   => $rses_row->object_type,
				'object_id'     => $rses_row->object_id,
				'previous_hash' => $rses_previous,
				'payload_json'  => $rses_row->payload_json,
				'created_at'    => $rses_row->created_at,
			);

			$rses_current = HashService::rses_hash_audit_entry( $rses_entry );

			$wpdb->update(
				$rses_table,
				array(
					'previous_hash' => $rses_previous,
					'current_hash'  => $rses_current,
				),
				array( 'id' => (int) $rses_row->id ),
				array( '%s', '%s' ),
				array( '%d' )
			);

			$rses_previous = $rses_current;
			++$rses_repaired;
		}

		$rses_verify = self::rses_verify_chain();

		return array(
			'repaired' => $rses_repaired,
			'valid'    => $rses_verify['valid'],
		);
	}

	/**
	 * Remove sensitive data from payload.
	 *
	 * @param array<string,mixed> $payload Payload.
	 * @return array<string,mixed>
	 */
	private static function rses_sanitize_payload( array $payload ): array {
		$rses_clean = array();

		foreach ( $payload as $rses_key => $rses_value ) {
			if ( in_array( $rses_key, self::$rses_forbidden_keys, true ) ) {
				$rses_clean[ $rses_key ] = '[REDACTED]';
				continue;
			}

			if ( is_array( $rses_value ) ) {
				$rses_clean[ $rses_key ] = self::rses_sanitize_payload( $rses_value );
			} else {
				$rses_clean[ $rses_key ] = is_string( $rses_value )
					? Sanitizer::rses_text( $rses_value )
					: $rses_value;
			}
		}

		return $rses_clean;
	}
}
