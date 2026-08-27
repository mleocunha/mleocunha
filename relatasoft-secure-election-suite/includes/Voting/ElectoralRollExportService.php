<?php
/**
 * Electoral roll (cadastro eleitoral) .rsv export.
 *
 * @package RelataSoft\SecureElectionSuite\Voting
 */

namespace RelataSoft\SecureElectionSuite\Voting;

use RelataSoft\SecureElectionSuite\Painel\Domain\ElectoralRoll\RsvFormat;
use RelataSoft\SecureElectionSuite\Security\AuditLogger;

defined( 'ABSPATH' ) || exit;

/**
 * Exports WordPress users of one role as .rsv lines (senha always empty).
 */
class ElectoralRollExportService {

	/**
	 * Soft cap of data rows per export job.
	 */
	public const MAX_LINES = 500000;

	/**
	 * Default average bytes per data row (for UI estimate).
	 */
	public const AVG_ROW_BYTES = 180;

	/**
	 * Roles allowed for export (WP slug => RSV papel).
	 *
	 * @return array<string,string>
	 */
	public static function rses_exportable_roles(): array {
		return array(
			'subscriber'    => 'eleitor',
			've_auditor'    => 'auditor',
			'editor'        => 'autoridade',
			'administrator' => 'administrador',
			've_gestor'     => 'gestor',
		);
	}

	/**
	 * Count users with a given WP role.
	 */
	public static function rses_count_role( string $wp_role ): int {
		$wp_role = sanitize_key( $wp_role );
		if ( ! isset( self::rses_exportable_roles()[ $wp_role ] ) ) {
			return 0;
		}
		$counts = count_users();
		$avail  = is_array( $counts['avail_roles'] ?? null ) ? $counts['avail_roles'] : array();
		return (int) ( $avail[ $wp_role ] ?? 0 );
	}

	/**
	 * Estimate export size in bytes: header + avg_row * max_lines.
	 */
	public static function rses_estimate_bytes( int $max_lines ): int {
		$max_lines = max( 0, min( self::MAX_LINES, $max_lines ) );
		$header    = strlen( RsvFormat::headerLine() ) + 1; // + newline
		return $header + ( $max_lines * self::AVG_ROW_BYTES );
	}

	/**
	 * Build one RSV data line for a WP user (senha always empty — hashes are irreversible).
	 */
	public static function rses_user_to_line( \WP_User $user ): string {
		$roles   = array_map( 'strval', (array) $user->roles );
		$papel   = '';
		foreach ( $roles as $role ) {
			$papel = RsvFormat::reverseRole( $role );
			if ( '' !== $papel ) {
				break;
			}
		}

		$emails_meta = (string) get_user_meta( $user->ID, ElectoralRollImportService::META_EMAILS, true );
		if ( '' === $emails_meta ) {
			$emails_meta = (string) $user->user_email;
		}

		$celular = (string) get_user_meta( $user->ID, ElectoralRollImportService::META_CELULAR, true );
		$nome    = (string) $user->display_name;
		if ( '' === $nome ) {
			$nome = (string) $user->user_login;
		}

		return RsvFormat::serializeLine(
			array(
				(string) $user->user_login,
				(string) get_user_meta( $user->ID, ElectoralRollImportService::META_ID_CIVIL, true ),
				(string) get_user_meta( $user->ID, ElectoralRollImportService::META_ID_ELEITORAL, true ),
				(string) get_user_meta( $user->ID, ElectoralRollImportService::META_REGIAO_AMPLA, true ),
				(string) get_user_meta( $user->ID, ElectoralRollImportService::META_REGIAO_ESPECIFICA, true ),
				$nome,
				$celular,
				$emails_meta,
				(string) get_user_meta( $user->ID, ElectoralRollImportService::META_ENDERECO, true ),
				$papel,
				'', // senha never exported
			)
		);
	}

	/**
	 * Fetch a page of users for one role (ordered by ID for stable chunked export).
	 *
	 * @return list<\WP_User>
	 */
	public static function rses_fetch_batch( string $wp_role, int $offset, int $limit ): array {
		$wp_role = sanitize_key( $wp_role );
		if ( ! isset( self::rses_exportable_roles()[ $wp_role ] ) ) {
			return array();
		}
		$limit  = max( 1, $limit );
		$offset = max( 0, $offset );
		$users  = get_users(
			array(
				'role'    => $wp_role,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'number'  => $limit,
				'offset'  => $offset,
			)
		);
		return is_array( $users ) ? $users : array();
	}

	/**
	 * Audit helper shared by export jobs.
	 */
	public static function rses_log_export_audit( string $wp_role, int $exported, int $max_lines ): void {
		AuditLogger::rses_log(
			'electoral_roll_export',
			'users',
			null,
			array(
				'command'   => 'export_electoral_roll_rsv',
				'role'      => $wp_role,
				'exported'  => $exported,
				'max_lines' => $max_lines,
			)
		);
	}
}
