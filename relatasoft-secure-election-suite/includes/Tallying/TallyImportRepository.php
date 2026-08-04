<?php
/**
 * Tally import repository.
 *
 * @package RelataSoft\SecureElectionSuite\Tallying
 */

namespace RelataSoft\SecureElectionSuite\Tallying;

use RelataSoft\SecureElectionSuite\Database\Repository;
use RelataSoft\SecureElectionSuite\Database\Schema;
use RelataSoft\SecureElectionSuite\Exports\HashService;

defined( 'ABSPATH' ) || exit;

/**
 * Tally import database operations.
 */
class TallyImportRepository {

	/**
	 * Manifests larger than this are treated as unsafe to load on 128M hosts.
	 */
	public const RSES_MAX_SAFE_MANIFEST_BYTES = 524288; // 512 KiB

	/**
	 * Create import record.
	 *
	 * @param array<string,mixed> $data Import data.
	 * @return int
	 */
	public static function rses_create( array $data ): int {
		$rses_row = array(
			'source_site_url'      => $data['source_site_url'] ?? null,
			'election_external_id' => $data['election_external_id'] ?? null,
			'round_external_id'    => $data['round_external_id'] ?? null,
			'election_title'       => $data['election_title'] ?? null,
			'round_title'          => $data['round_title'] ?? null,
			'ballot_count'         => isset( $data['ballot_count'] ) ? (int) $data['ballot_count'] : null,
			'import_manifest_json' => $data['import_manifest_json'],
			'import_hash'          => $data['import_hash'],
			'imported_by'          => get_current_user_id(),
			'imported_at'          => current_time( 'mysql', true ),
			'status'               => $data['status'] ?? 'pending',
		);

		$rses_row['audit_hash'] = HashService::rses_hash_json( $rses_row );

		return Repository::rses_insert(
			'rses_tally_imports',
			$rses_row,
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Get import by ID.
	 *
	 * @param int $import_id Import ID.
	 * @return object|null
	 */
	public static function rses_get( int $import_id ): ?object {
		return Repository::rses_get_by_id( 'rses_tally_imports', $import_id );
	}

	/**
	 * List imports without loading import_manifest_json (can be multi‑MB).
	 *
	 * @return array<int,object>
	 */
	public static function rses_list(): array {
		global $wpdb;

		$rses_table = Schema::rses_table( 'rses_tally_imports' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rses_rows = $wpdb->get_results(
			"SELECT id, source_site_url, election_external_id, round_external_id,
				election_title, round_title, ballot_count,
				import_hash, imported_by, imported_at, status, audit_hash,
				LENGTH(import_manifest_json) AS manifest_bytes
			FROM {$rses_table}
			ORDER BY id DESC"
		);

		return is_array( $rses_rows ) ? $rses_rows : array();
	}

	/**
	 * Extract denormalized election/round labels from a parsed manifest.
	 *
	 * @param array<string,mixed> $manifest Manifest.
	 * @return array{election_title:string,round_title:string,ballot_count:int|null,election_external_id:string,round_external_id:string,source_site_url:?string,key_id:int,key_label:string}
	 */
	public static function rses_summary_from_manifest( array $manifest ): array {
		$rses_election   = is_array( $manifest['election'] ?? null ) ? $manifest['election'] : array();
		$rses_round      = is_array( $manifest['round'] ?? null ) ? $manifest['round'] : array();
		$rses_meta       = is_array( $manifest['manifest'] ?? null ) ? $manifest['manifest'] : array();
		$rses_public_key = is_array( $manifest['public_key'] ?? null ) ? $manifest['public_key'] : array();

		$rses_election_title = trim( (string) ( $rses_election['title'] ?? $rses_meta['election_title'] ?? '' ) );
		$rses_round_title    = trim( (string) ( $rses_round['title'] ?? $rses_meta['round_title'] ?? '' ) );
		if ( '' === $rses_round_title && isset( $rses_round['round_number'] ) ) {
			$rses_round_title = sprintf(
				/* translators: %d: round number */
				__( 'Round %d', 'relatasoft-secure-election-suite' ),
				(int) $rses_round['round_number']
			);
		}

		$rses_ballot = null;
		if ( isset( $rses_meta['ballot_count'] ) ) {
			$rses_ballot = (int) $rses_meta['ballot_count'];
		}

		$rses_key_id = (int) (
			$rses_meta['key_id']
			?? $rses_round['key_id']
			?? $rses_public_key['key_id']
			?? 0
		);
		$rses_key_label = trim(
			(string) (
				$rses_meta['key_label']
				?? $rses_public_key['key_label']
				?? ''
			)
		);

		return array(
			'election_title'       => $rses_election_title,
			'round_title'          => $rses_round_title,
			'ballot_count'         => $rses_ballot,
			'election_external_id' => (string) ( $rses_election['id'] ?? $manifest['election_id'] ?? $rses_meta['election_id'] ?? '' ),
			'round_external_id'    => (string) ( $rses_round['id'] ?? $manifest['round_id'] ?? $rses_meta['round_id'] ?? '' ),
			'source_site_url'      => $rses_meta['source_site'] ?? $manifest['source_site'] ?? null,
			'key_id'               => $rses_key_id,
			'key_label'            => $rses_key_label,
		);
	}

	/**
	 * Resolve key identity for an import (from denormalized fields or manifest).
	 *
	 * @param object                   $import   Import row.
	 * @param array<string,mixed>|null $manifest Optional preloaded manifest.
	 * @return array{key_id:int,key_label:string,fingerprint:string,public_y_prefix:string,source_site_url:string}
	 */
	public static function rses_key_identity( object $import, ?array $manifest = null ): array {
		if ( null === $manifest ) {
			$manifest = self::rses_get_manifest( $import );
		}
		$rses_summary = is_array( $manifest ) ? self::rses_summary_from_manifest( $manifest ) : array();
		$rses_public  = is_array( $manifest['public_key'] ?? null ) ? $manifest['public_key'] : array();
		$rses_y       = (string) ( $rses_public['y'] ?? '' );

		$rses_source = (string) ( $rses_summary['source_site_url'] ?? $import->source_site_url ?? '' );

		return array(
			'key_id'           => (int) ( $rses_summary['key_id'] ?? 0 ),
			'key_label'        => (string) ( $rses_summary['key_label'] ?? '' ),
			'fingerprint'      => self::rses_public_key_fingerprint( $rses_public ),
			'public_y_prefix'  => '' !== $rses_y ? substr( $rses_y, 0, 20 ) : '',
			'source_site_url'  => $rses_source,
		);
	}

	/**
	 * Short fingerprint of a public key (stable across sites; labels may collide).
	 *
	 * @param array<string,mixed> $public_key Public key fields.
	 */
	public static function rses_public_key_fingerprint( array $public_key ): string {
		$rses_y = (string) ( $public_key['y'] ?? '' );
		if ( '' === $rses_y ) {
			return '';
		}
		return substr( hash( 'sha256', $rses_y ), 0, 12 );
	}

	/**
	 * Whether share public_key matches the imported election package.
	 *
	 * @param array<string,mixed> $share_payload Cryptographic share payload.
	 * @param array<string,mixed> $manifest      Import manifest.
	 */
	public static function rses_share_matches_import_public_key( array $share_payload, array $manifest ): bool {
		$rses_expected = is_array( $manifest['public_key'] ?? null ) ? $manifest['public_key'] : array();
		$rses_share_pk = is_array( $share_payload['public_key'] ?? null ) ? $share_payload['public_key'] : array();

		foreach ( array( 'p', 'q', 'g', 'y' ) as $rses_field ) {
			$rses_a = (string) ( $rses_expected[ $rses_field ] ?? '' );
			$rses_b = (string) ( $rses_share_pk[ $rses_field ] ?? '' );
			if ( '' === $rses_a || '' === $rses_b || ! hash_equals( $rses_a, $rses_b ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Human label for admin lists/cards.
	 *
	 * @param object $import Import row (list or full).
	 */
	public static function rses_display_election_title( object $import ): string {
		$rses_title = trim( (string) ( $import->election_title ?? '' ) );
		if ( '' !== $rses_title ) {
			return $rses_title;
		}
		$rses_ext = trim( (string) ( $import->election_external_id ?? '' ) );
		if ( '' !== $rses_ext ) {
			return sprintf(
				/* translators: %s: external election id */
				__( 'Election #%s', 'relatasoft-secure-election-suite' ),
				$rses_ext
			);
		}
		return sprintf(
			/* translators: %d: local import id */
			__( 'Import #%d', 'relatasoft-secure-election-suite' ),
			(int) $import->id
		);
	}

	/**
	 * Round label for admin lists/cards.
	 *
	 * @param object $import Import row.
	 */
	public static function rses_display_round_title( object $import ): string {
		$rses_title = trim( (string) ( $import->round_title ?? '' ) );
		if ( '' !== $rses_title ) {
			return $rses_title;
		}
		$rses_ext = trim( (string) ( $import->round_external_id ?? '' ) );
		if ( '' !== $rses_ext ) {
			return sprintf(
				/* translators: %s: external round id */
				__( 'Round #%s', 'relatasoft-secure-election-suite' ),
				$rses_ext
			);
		}
		return '';
	}

	/**
	 * Backfill election/round titles for older imports (safe-sized manifests only).
	 *
	 * @return int Rows updated.
	 */
	public static function rses_backfill_summaries(): int {
		global $wpdb;

		$rses_table = Schema::rses_table( 'rses_tally_imports' );

		// Column may not exist until migration runs.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rses_has = $wpdb->get_results( "SHOW COLUMNS FROM {$rses_table} LIKE 'election_title'" );
		if ( empty( $rses_has ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rses_ids = $wpdb->get_col(
			"SELECT id FROM {$rses_table}
			WHERE (election_title IS NULL OR election_title = '')
			AND LENGTH(import_manifest_json) <= " . (int) self::RSES_MAX_SAFE_MANIFEST_BYTES . '
			ORDER BY id DESC
			LIMIT 50'
		);

		if ( empty( $rses_ids ) ) {
			return 0;
		}

		$rses_updated = 0;
		foreach ( $rses_ids as $rses_id ) {
			$rses_row = self::rses_get( (int) $rses_id );
			if ( ! $rses_row ) {
				continue;
			}
			$rses_manifest = self::rses_get_manifest( $rses_row );
			$rses_summary  = self::rses_summary_from_manifest( $rses_manifest );
			if ( '' === $rses_summary['election_title'] && '' === $rses_summary['round_title'] ) {
				continue;
			}
			$rses_ok = Repository::rses_update(
				'rses_tally_imports',
				array(
					'election_title' => $rses_summary['election_title'] ?: null,
					'round_title'    => $rses_summary['round_title'] ?: null,
					'ballot_count'   => $rses_summary['ballot_count'],
				),
				array( 'id' => (int) $rses_id ),
				array( '%s', '%s', '%d' ),
				array( '%d' )
			);
			if ( $rses_ok ) {
				++$rses_updated;
			}
		}

		return $rses_updated;
	}

	/**
	 * Replace oversized manifests with a tiny stub so admin pages stop white-screening.
	 *
	 * Uses SQL LENGTH only — never reads the huge JSON into PHP.
	 *
	 * @param int $max_bytes Max safe stored manifest size.
	 * @return int Rows updated.
	 */
	public static function rses_purge_oversized_manifests( int $max_bytes = self::RSES_MAX_SAFE_MANIFEST_BYTES ): int {
		global $wpdb;

		$rses_table = Schema::rses_table( 'rses_tally_imports' );
		$rses_stub  = wp_json_encode(
			array(
				'purged'            => true,
				'reason'            => 'import_manifest_json exceeded safe size for this PHP memory_limit; re-import with RelataSoft Secure Election Suite 1.0.27.4+',
				'public_key'        => array(),
				'encrypted_tallies' => array(),
			),
			JSON_UNESCAPED_SLASHES
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rses_result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$rses_table}
				SET import_manifest_json = %s, status = 'rejected'
				WHERE LENGTH(import_manifest_json) > %d",
				$rses_stub,
				$max_bytes
			)
		);

		return false === $rses_result ? 0 : (int) $rses_result;
	}

	/**
	 * Update import status.
	 *
	 * @param int    $import_id Import ID.
	 * @param string $status    Status.
	 * @return bool
	 */
	public static function rses_update_status( int $import_id, string $status ): bool {
		return Repository::rses_update(
			'rses_tally_imports',
			array( 'status' => $status ),
			array( 'id' => $import_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Get manifest as array (refuses to decode oversized JSON).
	 *
	 * @param object $import Import row (full or list stub).
	 * @return array<string,mixed>
	 */
	public static function rses_get_manifest( object $import ): array {
		if ( ! isset( $import->import_manifest_json ) && isset( $import->id ) ) {
			$rses_full = self::rses_get( (int) $import->id );
			if ( $rses_full ) {
				$import = $rses_full;
			}
		}

		$rses_json = (string) ( $import->import_manifest_json ?? '' );
		if ( strlen( $rses_json ) > self::RSES_MAX_SAFE_MANIFEST_BYTES ) {
			return array(
				'purged' => true,
				'reason' => 'manifest_too_large_to_decode',
			);
		}
		$rses_data = json_decode( $rses_json, true );
		return is_array( $rses_data ) ? $rses_data : array();
	}
}
