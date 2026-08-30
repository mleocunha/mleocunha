<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Persistence\Audit;

use RelataSoft\SecureElectionSuite\Database\Repository;
use RelataSoft\SecureElectionSuite\Database\Schema;
use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Persistence\RowMapper;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Audit\AuditLogRepository;

final class WordPressAuditLogRepository implements AuditLogRepository {

	public function append(array $entry): int {
		return Repository::rses_insert(
			'rses_audit_log',
			$entry,
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	public function lastHash(): ?string {
		global $wpdb;
		$table = Schema::rses_table( 'rses_audit_log' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$hash = $wpdb->get_var( "SELECT current_hash FROM {$table} ORDER BY id DESC LIMIT 1" );
		return $hash ? (string) $hash : null;
	}

	public function listRecent(int $limit = 100): array {
		return RowMapper::toArrays(
			Repository::rses_get_rows( 'rses_audit_log', '1=1', array(), 'id DESC', $limit )
		);
	}

	public function listAllOrdered(): array {
		global $wpdb;
		$table = Schema::rses_table( 'rses_audit_log' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC" );
		return RowMapper::toArrays( is_array( $rows ) ? $rows : array() );
	}

	public function updateHashes(int $id, ?string $previous, string $current): void {
		global $wpdb;
		$table = Schema::rses_table( 'rses_audit_log' );
		$wpdb->update(
			$table,
			array(
				'previous_hash' => $previous,
				'current_hash'  => $current,
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}
}
