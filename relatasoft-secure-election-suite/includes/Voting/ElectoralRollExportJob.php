<?php
/**
 * Chunked electoral-roll export job (per administrator).
 *
 * Writes .rsv lines for ONE WordPress role into uploads, then offers download.
 *
 * @package RelataSoft\SecureElectionSuite\Voting
 */

namespace RelataSoft\SecureElectionSuite\Voting;

use RelataSoft\SecureElectionSuite\Painel\Application\Identity\IdentityGateway;
use RelataSoft\SecureElectionSuite\Painel\Application\Jobs\JobGateway;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Jobs\JobSlots;
use RelataSoft\SecureElectionSuite\Painel\Domain\ElectoralRoll\RsvFormat;

defined( 'ABSPATH' ) || exit;

/**
 * Stores export progress so AJAX ticks stay under PHP time limits.
 */
class ElectoralRollExportJob {

	public const STAGE_PREPARING = 'preparing';
	public const STAGE_EXPORTING = 'exporting';
	public const STAGE_COMPLETE  = 'complete';
	public const STAGE_FAILED    = 'failed';
	public const STAGE_CANCELLED = 'cancelled';

	public const BATCH_ROWS  = 50;
	public const TTL_SECONDS = 6 * HOUR_IN_SECONDS;

	/**
	 * Option key for the current user (legacy; prefer {@see rses_slot()}).
	 */
	public static function rses_option_key( ?int $user_id = null ): string {
		$uid = $user_id ?? self::rses_owner_id();
		return 'rses_electoral_roll_export_job_' . (int) $uid;
	}

	/**
	 * JobStore slot for this owner.
	 */
	public static function rses_slot( ?int $user_id = null ): string {
		return JobSlots::rsvExport( $user_id ?? self::rses_owner_id() );
	}

	private static function rses_owner_id( ?int $user_id = null ): int {
		if ( null !== $user_id ) {
			return (int) $user_id;
		}
		if ( IdentityGateway::isBooted() ) {
			return IdentityGateway::get()->session->currentUserId();
		}
		return (int) get_current_user_id();
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function rses_get( ?int $user_id = null ): ?array {
		self::rses_purge_if_expired( $user_id );
		$job = JobGateway::get()->store->get( self::rses_slot( $user_id ) );
		return is_array( $job ) ? $job : null;
	}

	/**
	 * @param array<string,mixed> $job Job.
	 */
	public static function rses_save( array $job, ?int $user_id = null ): void {
		$job['updated_at'] = time();
		JobGateway::get()->store->put( self::rses_slot( $user_id ), $job );
	}

	public static function rses_delete( ?int $user_id = null ): void {
		$slot = self::rses_slot( $user_id );
		$job  = JobGateway::get()->store->get( $slot );
		if ( is_array( $job ) ) {
			self::rses_unlink_file( $job );
		}
		JobGateway::get()->store->delete( $slot );
	}

	/**
	 * @return bool True if purged.
	 */
	public static function rses_purge_if_expired( ?int $user_id = null ): bool {
		$slot = self::rses_slot( $user_id );
		$job  = JobGateway::get()->store->get( $slot );
		if ( ! is_array( $job ) ) {
			return false;
		}
		$updated = (int) ( $job['updated_at'] ?? $job['created_at'] ?? 0 );
		if ( $updated > 0 && ( time() - $updated ) > self::TTL_SECONDS ) {
			self::rses_unlink_file( $job );
			JobGateway::get()->store->delete( $slot );
			return true;
		}
		return false;
	}

	public static function rses_has_active( ?int $user_id = null ): bool {
		$job = self::rses_get( $user_id );
		if ( ! $job ) {
			return false;
		}
		return in_array(
			(string) ( $job['stage'] ?? '' ),
			array( self::STAGE_PREPARING, self::STAGE_EXPORTING ),
			true
		);
	}

	/**
	 * Persistent directory for RSV exports.
	 *
	 * @return string|\WP_Error Absolute path.
	 */
	public static function rses_export_dir() {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new \WP_Error( 'rses_export_dir', (string) $uploads['error'] );
		}

		$dir = trailingslashit( $uploads['basedir'] ) . 'rses-electoral-roll';
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'rses_export_mkdir', __( 'Could not create export directory.', 'relatasoft-secure-election-suite' ) );
		}

		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}

		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $htaccess, "Deny from all\n" );
		}

		return $dir;
	}

	/**
	 * Start export for one WP role with a max data-row budget.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function rses_create( string $wp_role, int $max_lines ) {
		$wp_role   = sanitize_key( $wp_role );
		$roles     = ElectoralRollExportService::rses_exportable_roles();
		if ( ! isset( $roles[ $wp_role ] ) ) {
			return new \WP_Error( 'rses_export_role', __( 'Invalid role for RSV export.', 'relatasoft-secure-election-suite' ) );
		}

		$max_lines = max( 1, min( ElectoralRollExportService::MAX_LINES, $max_lines ) );

		if ( self::rses_has_active() ) {
			return new \WP_Error( 'rses_export_busy', __( 'An export job is already running. Cancel it or wait until it finishes.', 'relatasoft-secure-election-suite' ) );
		}

		// Drop previous completed job file if any.
		self::rses_delete();

		$dir = self::rses_export_dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$token    = wp_generate_password( 20, false, false );
		$papel    = $roles[ $wp_role ];
		$filename = 'cadastro-' . $papel . '-' . gmdate( 'Ymd-His' ) . '.' . RsvFormat::EXTENSION;
		$path     = trailingslashit( $dir ) . 'export-' . get_current_user_id() . '-' . $token . '.' . RsvFormat::EXTENSION;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen( $path, 'wb' );
		if ( false === $handle ) {
			return new \WP_Error( 'rses_export_create', __( 'Could not create export file.', 'relatasoft-secure-election-suite' ) );
		}
		fwrite( $handle, RsvFormat::headerLine() . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$available = ElectoralRollExportService::rses_count_role( $wp_role );
		$total     = min( $available, $max_lines );

		$job = array(
			'stage'          => self::STAGE_EXPORTING,
			'wp_role'        => $wp_role,
			'papel'          => $papel,
			'max_lines'      => $max_lines,
			'total_rows'     => $total,
			'processed_rows' => 0,
			'offset'         => 0,
			'file_path'      => $path,
			'original_name'  => $filename,
			'download_token' => $token,
			'message'        => __( 'Exportando cadastro eleitoral (.rsv)…', 'relatasoft-secure-election-suite' ),
			'created_at'     => time(),
			'updated_at'     => time(),
			'estimated_bytes'=> ElectoralRollExportService::rses_estimate_bytes( $max_lines ),
		);

		self::rses_save( $job );
		return $job;
	}

	/**
	 * Process one batch of users.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function rses_tick() {
		$job = self::rses_get();
		if ( ! $job ) {
			return new \WP_Error( 'rses_export_missing', __( 'No active export job.', 'relatasoft-secure-election-suite' ) );
		}

		$stage = (string) ( $job['stage'] ?? '' );
		if ( in_array( $stage, array( self::STAGE_COMPLETE, self::STAGE_FAILED, self::STAGE_CANCELLED ), true ) ) {
			return $job;
		}

		if ( self::STAGE_EXPORTING !== $stage && self::STAGE_PREPARING !== $stage ) {
			return new \WP_Error( 'rses_export_stage', __( 'Export job is not ready.', 'relatasoft-secure-election-suite' ) );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
			@set_time_limit( 60 );
		}

		$path = (string) ( $job['file_path'] ?? '' );
		if ( '' === $path || ! is_writable( $path ) ) {
			$job['stage']   = self::STAGE_FAILED;
			$job['message'] = __( 'Export file is not writable.', 'relatasoft-secure-election-suite' );
			self::rses_save( $job );
			return new \WP_Error( 'rses_export_write', $job['message'] );
		}

		$total   = (int) ( $job['total_rows'] ?? 0 );
		$done    = (int) ( $job['processed_rows'] ?? 0 );
		$remain  = max( 0, $total - $done );
		$batch_n = min( self::BATCH_ROWS, $remain );

		if ( $batch_n < 1 || $total < 1 ) {
			$job['stage']   = self::STAGE_COMPLETE;
			$job['message'] = __( 'Exportação .rsv concluída.', 'relatasoft-secure-election-suite' );
			ElectoralRollExportService::rses_log_export_audit(
				(string) $job['wp_role'],
				(int) $job['processed_rows'],
				(int) $job['max_lines']
			);
			self::rses_save( $job );
			return $job;
		}

		$users = ElectoralRollExportService::rses_fetch_batch(
			(string) $job['wp_role'],
			(int) $job['offset'],
			$batch_n
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen( $path, 'ab' );
		if ( false === $handle ) {
			$job['stage']   = self::STAGE_FAILED;
			$job['message'] = __( 'Could not append to export file.', 'relatasoft-secure-election-suite' );
			self::rses_save( $job );
			return new \WP_Error( 'rses_export_append', $job['message'] );
		}

		$written = 0;
		foreach ( $users as $user ) {
			if ( ! $user instanceof \WP_User ) {
				continue;
			}
			fwrite( $handle, ElectoralRollExportService::rses_user_to_line( $user ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			++$written;
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$job['offset']         = (int) $job['offset'] + count( $users );
		$job['processed_rows'] = $done + $written;
		$job['stage']          = self::STAGE_EXPORTING;

		if ( $job['processed_rows'] >= $total || count( $users ) < $batch_n ) {
			$job['stage']          = self::STAGE_COMPLETE;
			$job['processed_rows'] = min( $total, (int) $job['processed_rows'] );
			$job['message']        = __( 'Exportação .rsv concluída.', 'relatasoft-secure-election-suite' );
			ElectoralRollExportService::rses_log_export_audit(
				(string) $job['wp_role'],
				(int) $job['processed_rows'],
				(int) $job['max_lines']
			);
		} else {
			$job['message'] = sprintf(
				/* translators: 1: processed rows, 2: total rows */
				__( 'Exportados %1$d de %2$d registros…', 'relatasoft-secure-election-suite' ),
				(int) $job['processed_rows'],
				$total
			);
		}

		self::rses_save( $job );
		return $job;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function rses_cancel(): array {
		$job = self::rses_get();
		if ( ! $job ) {
			return array(
				'stage'   => self::STAGE_CANCELLED,
				'message' => __( 'Nenhuma exportação para cancelar.', 'relatasoft-secure-election-suite' ),
			);
		}
		self::rses_unlink_file( $job );
		$job['file_path'] = '';
		$job['stage']     = self::STAGE_CANCELLED;
		$job['message']   = __( 'Exportação do cadastro eleitoral cancelada.', 'relatasoft-secure-election-suite' );
		self::rses_save( $job );
		return $job;
	}

	/**
	 * Absolute path for download when job is complete (null otherwise).
	 */
	public static function rses_download_path( ?array $job = null ): ?string {
		$job = $job ?? self::rses_get();
		if ( ! $job || self::STAGE_COMPLETE !== ( $job['stage'] ?? '' ) ) {
			return null;
		}
		$path = (string) ( $job['file_path'] ?? '' );
		return ( '' !== $path && is_readable( $path ) ) ? $path : null;
	}

	/**
	 * @param array<string,mixed>|null $job Job.
	 * @return array<string,mixed>
	 */
	public static function rses_public_status( ?array $job ): array {
		if ( ! $job ) {
			return array(
				'active'          => false,
				'stage'           => '',
				'stage_label'     => '',
				'progress'        => 0,
				'export_progress' => 0,
				'message'         => '',
				'processed_rows'  => 0,
				'total_rows'      => 0,
				'wp_role'         => '',
				'papel'           => '',
				'original_name'   => '',
				'download_ready'  => false,
				'estimated_bytes' => 0,
			);
		}

		$stage    = (string) ( $job['stage'] ?? '' );
		$total    = max( 0, (int) ( $job['total_rows'] ?? 0 ) );
		$done     = min( $total, (int) ( $job['processed_rows'] ?? 0 ) );
		$progress = self::STAGE_COMPLETE === $stage
			? 100
			: ( $total > 0 ? max( 0, min( 100, (int) floor( ( $done / $total ) * 100 ) ) ) : 0 );

		return array(
			'active'          => in_array( $stage, array( self::STAGE_PREPARING, self::STAGE_EXPORTING ), true ),
			'stage'           => $stage,
			'stage_label'     => self::rses_stage_label( $stage ),
			'progress'        => $progress,
			'export_progress' => $progress,
			'message'         => (string) ( $job['message'] ?? '' ),
			'processed_rows'  => $done,
			'total_rows'      => $total,
			'wp_role'         => (string) ( $job['wp_role'] ?? '' ),
			'papel'           => (string) ( $job['papel'] ?? '' ),
			'original_name'   => (string) ( $job['original_name'] ?? '' ),
			'download_ready'  => self::STAGE_COMPLETE === $stage && null !== self::rses_download_path( $job ),
			'estimated_bytes' => (int) ( $job['estimated_bytes'] ?? 0 ),
		);
	}

	private static function rses_stage_label( string $stage ): string {
		$map = array(
			self::STAGE_PREPARING => __( 'Preparando', 'relatasoft-secure-election-suite' ),
			self::STAGE_EXPORTING => __( 'Exportando', 'relatasoft-secure-election-suite' ),
			self::STAGE_COMPLETE  => __( 'Concluído', 'relatasoft-secure-election-suite' ),
			self::STAGE_FAILED    => __( 'Falha', 'relatasoft-secure-election-suite' ),
			self::STAGE_CANCELLED => __( 'Cancelado', 'relatasoft-secure-election-suite' ),
		);
		return $map[ $stage ] ?? $stage;
	}

	/**
	 * @param array<string,mixed> $job Job.
	 */
	private static function rses_unlink_file( array $job ): void {
		$path = (string) ( $job['file_path'] ?? '' );
		if ( '' !== $path && is_file( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $path );
		}
	}
}
