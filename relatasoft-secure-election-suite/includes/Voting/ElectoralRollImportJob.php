<?php
/**
 * Chunked electoral-roll import job (per administrator).
 *
 * Uploads and imports .rsv (RelataSoft Separated Values) cadastro eleitoral files.
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
 * Stores upload + import progress so AJAX ticks stay under PHP time limits.
 */
class ElectoralRollImportJob {

	public const STAGE_RECEIVING = 'receiving';
	public const STAGE_READY     = 'ready';
	public const STAGE_IMPORTING = 'importing';
	public const STAGE_COMPLETE  = 'complete';
	public const STAGE_FAILED    = 'failed';
	public const STAGE_CANCELLED = 'cancelled';

	public const BATCH_ROWS      = 50;
	public const MAX_ERRORS_KEEP = 150;
	public const TTL_SECONDS     = 6 * HOUR_IN_SECONDS;

	/**
	 * Max upload size: 4 GiB (matches RsvFormat::maxUploadBytes(); class const must be compile-time).
	 */
	public const MAX_UPLOAD_BYTES = 4294967296; // 4 * 1024 * 1024 * 1024

	/**
	 * Option key for the current user (legacy; prefer {@see rses_slot()}).
	 */
	public static function rses_option_key( ?int $user_id = null ): string {
		$uid = $user_id ?? self::rses_owner_id();
		return 'rses_electoral_roll_job_' . (int) $uid;
	}

	/**
	 * JobStore slot for this owner.
	 */
	public static function rses_slot( ?int $user_id = null ): string {
		return JobSlots::rsvImport( $user_id ?? self::rses_owner_id() );
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

	/**
	 * Whether a non-terminal job exists.
	 */
	public static function rses_has_active( ?int $user_id = null ): bool {
		$job = self::rses_get( $user_id );
		if ( ! $job ) {
			return false;
		}
		return in_array(
			(string) ( $job['stage'] ?? '' ),
			array( self::STAGE_RECEIVING, self::STAGE_READY, self::STAGE_IMPORTING ),
			true
		);
	}

	/**
	 * Persistent directory for RSV uploads (must survive across AJAX requests).
	 *
	 * System temp via wp_tempnam() is not safe here: many hosts clear /tmp between
	 * requests, which makes validation fail immediately after a successful upload.
	 *
	 * @return string|\WP_Error Absolute path.
	 */
	public static function rses_upload_dir() {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new \WP_Error( 'rses_upload_dir', (string) $uploads['error'] );
		}

		$dir = trailingslashit( $uploads['basedir'] ) . 'rses-electoral-roll';
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'rses_upload_mkdir', __( 'Could not create import upload directory.', 'relatasoft-secure-election-suite' ) );
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
	 * Create an empty RSV file in the persistent import directory.
	 *
	 * @return string|\WP_Error Absolute path.
	 */
	private static function rses_make_temp_file() {
		$dir = self::rses_upload_dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$token = wp_generate_password( 20, false, false );
		$path  = trailingslashit( $dir ) . 'import-' . get_current_user_id() . '-' . $token . '.' . RsvFormat::EXTENSION;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen( $path, 'wb' );
		if ( false === $handle ) {
			return new \WP_Error( 'rses_file_create', __( 'Could not create temporary import file.', 'relatasoft-secure-election-suite' ) );
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $path;
	}

	/**
	 * Ensure on-disk size matches the client-declared upload size.
	 *
	 * Parallel imports / retried chunks can append the RSV twice; truncate back to the
	 * declared byte length when that happens.
	 *
	 * @param array<string,mixed> $job  Job.
	 * @param string              $path Absolute RSV path.
	 * @param int                 $size Current filesize.
	 * @return array<string,mixed>|\WP_Error Updated job.
	 */
	private static function rses_normalize_uploaded_size( array $job, string $path, int $size ) {
		$declared = (int) ( $job['total_bytes'] ?? 0 );
		$received = (int) ( $job['bytes_received'] ?? 0 );

		if ( $declared < 1 ) {
			return $job;
		}

		// Incomplete assembly.
		if ( $size < (int) floor( $declared * 0.98 ) ) {
			return new \WP_Error(
				'rses_upload_short',
				sprintf(
					/* translators: 1: bytes on disk, 2: declared bytes */
					__( 'RSV upload is incomplete on the server (%1$s of %2$s). Please start the import again.', 'relatasoft-secure-election-suite' ),
					size_format( $size ),
					size_format( $declared )
				)
			);
		}

		$oversize = $size > (int) ceil( $declared * 1.02 ) || $received > (int) ceil( $declared * 1.02 );
		if ( $oversize ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$fh = fopen( $path, 'rb+' );
			if ( false === $fh ) {
				return new \WP_Error(
					'rses_upload_dup',
					__( 'RSV upload was larger than declared (possible duplicate append). Please start the import again.', 'relatasoft-secure-election-suite' )
				);
			}
			$ok = ftruncate( $fh, $declared );
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			clearstatcache( true, $path );
			$new_size = filesize( $path );
			if ( ! $ok || false === $new_size || $new_size !== $declared ) {
				return new \WP_Error(
					'rses_upload_dup',
					__( 'RSV upload was larger than declared (possible duplicate append). Please start the import again.', 'relatasoft-secure-election-suite' )
				);
			}
			$job['bytes_received'] = $declared;
			$job['message']        = __( 'Removed duplicated upload bytes; validating RSV…', 'relatasoft-secure-election-suite' );
		}

		return $job;
	}

	/**
	 * Localized label for a job stage (UI must not show raw English keys).
	 */
	public static function rses_stage_label( string $stage ): string {
		switch ( $stage ) {
			case self::STAGE_RECEIVING:
				return __( 'Receiving', 'relatasoft-secure-election-suite' );
			case self::STAGE_READY:
				return __( 'Validating', 'relatasoft-secure-election-suite' );
			case self::STAGE_IMPORTING:
				return __( 'Importing', 'relatasoft-secure-election-suite' );
			case self::STAGE_COMPLETE:
				return __( 'Finished', 'relatasoft-secure-election-suite' );
			case self::STAGE_FAILED:
				// Distinct from crypto self-test "Failed" → "Falharam".
				return __( 'Failure', 'relatasoft-secure-election-suite' );
			case self::STAGE_CANCELLED:
				return __( 'Cancelled', 'relatasoft-secure-election-suite' );
			default:
				return '';
		}
	}

	/**
	 * Create a receiving job and empty temp file.
	 *
	 * A new start always replaces any prior job for this administrator (avoids stuck uploads).
	 *
	 * @param string $original_name Original filename.
	 * @param int    $total_chunks  Expected upload chunks.
	 * @param int    $total_bytes   Declared file size.
	 * @param bool   $update_existing Update matching users.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function rses_create_receiving( string $original_name, int $total_chunks, int $total_bytes, bool $update_existing ) {
		$max = self::MAX_UPLOAD_BYTES;
		if ( $total_bytes < 1 || $total_bytes > $max ) {
			return new \WP_Error(
				'rses_file_size',
				sprintf(
					/* translators: %s: max size label */
					__( 'RSV file must be between 1 byte and %s.', 'relatasoft-secure-election-suite' ),
					size_format( $max )
				)
			);
		}

		$total_chunks = max( 1, $total_chunks );

		// Replace any stuck/active job from a previous attempt.
		self::rses_delete();

		$path = self::rses_make_temp_file();
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$token = wp_generate_password( 20, false, false );
		$job   = array(
			'stage'           => self::STAGE_RECEIVING,
			'file_path'       => $path,
			'file_token'      => $token,
			'original_name'   => sanitize_file_name( $original_name ),
			'update_existing' => (bool) $update_existing,
			'total_chunks'    => $total_chunks,
			'chunks_received' => 0,
			'total_bytes'     => $total_bytes,
			'bytes_received'  => 0,
			'map'             => array(),
			'byte_offset'     => 0,
			'next_row_num'    => 1,
			'total_rows'      => 0,
			'processed_rows'  => 0,
			'created'         => 0,
			'updated'         => 0,
			'skipped'         => 0,
			'errors'          => array(),
			'error_count'     => 0,
			'message'         => __( 'Receiving RSV upload…', 'relatasoft-secure-election-suite' ),
			'created_at'      => time(),
			'updated_at'      => time(),
		);

		self::rses_save( $job );
		return $job;
	}

	/**
	 * Ingest a complete uploaded RSV and move straight to importing.
	 *
	 * @param string $tmp_path        Uploaded temp path.
	 * @param string $original_name   Original filename.
	 * @param bool   $update_existing Update matching users.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function rses_ingest_full_upload( string $tmp_path, string $original_name, bool $update_existing ) {
		if ( ! is_readable( $tmp_path ) ) {
			return new \WP_Error( 'rses_chunk_read', __( 'Could not read upload chunk.', 'relatasoft-secure-election-suite' ) );
		}

		$size = filesize( $tmp_path );
		$max  = self::MAX_UPLOAD_BYTES;
		if ( false === $size || $size < 1 || $size > $max ) {
			return new \WP_Error(
				'rses_file_size',
				sprintf(
					/* translators: %s: max size label */
					__( 'RSV file must be between 1 byte and %s.', 'relatasoft-secure-election-suite' ),
					size_format( $max )
				)
			);
		}

		$job = self::rses_create_receiving( $original_name, 1, (int) $size, $update_existing );
		if ( is_wp_error( $job ) ) {
			return $job;
		}

		$dest = (string) $job['file_path'];
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$in = fopen( $tmp_path, 'rb' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$out = fopen( $dest, 'wb' );
		if ( false === $in || false === $out ) {
			if ( is_resource( $in ) ) {
				fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
			if ( is_resource( $out ) ) {
				fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
			self::rses_delete();
			return new \WP_Error( 'rses_chunk_append', __( 'Could not append upload chunk.', 'relatasoft-secure-election-suite' ) );
		}
		$copied = stream_copy_to_stream( $in, $out );
		fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( false === $copied || (int) $copied < 1 ) {
			self::rses_delete();
			return new \WP_Error( 'rses_chunk_append', __( 'Could not append upload chunk.', 'relatasoft-secure-election-suite' ) );
		}

		$job['chunks_received'] = 1;
		$job['bytes_received']  = (int) $size;
		$job['message']         = __( 'Validating RSV…', 'relatasoft-secure-election-suite' );
		self::rses_save( $job );

		return self::rses_begin_import();
	}

	/**
	 * Append one upload chunk.
	 *
	 * @param string $chunk_path Temporary uploaded chunk path.
	 * @param int    $chunk_index Zero-based index.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function rses_append_chunk( string $chunk_path, int $chunk_index ) {
		$job = self::rses_get();
		if ( ! $job || self::STAGE_RECEIVING !== ( $job['stage'] ?? '' ) ) {
			return new \WP_Error( 'rses_job_missing', __( 'No active upload job.', 'relatasoft-secure-election-suite' ) );
		}

		$expected = (int) ( $job['chunks_received'] ?? 0 );
		// Idempotent: a retried chunk that was already accepted must not be appended again.
		if ( $chunk_index < $expected ) {
			return $job;
		}
		if ( $chunk_index !== $expected ) {
			return new \WP_Error(
				'rses_chunk_order',
				sprintf(
					/* translators: 1: expected index, 2: received index */
					__( 'Upload chunk out of order (expected %1$d, got %2$d).', 'relatasoft-secure-election-suite' ),
					$expected,
					$chunk_index
				)
			);
		}

		$path = (string) ( $job['file_path'] ?? '' );
		if ( '' === $path || ! is_writable( $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			return new \WP_Error( 'rses_file_write', __( 'Temporary import file is not writable.', 'relatasoft-secure-election-suite' ) );
		}

		$chunk_size = filesize( $chunk_path );
		if ( false === $chunk_size ) {
			return new \WP_Error( 'rses_chunk_read', __( 'Could not read upload chunk.', 'relatasoft-secure-election-suite' ) );
		}

		$new_total = (int) $job['bytes_received'] + (int) $chunk_size;
		if ( $new_total > self::MAX_UPLOAD_BYTES ) {
			return new \WP_Error( 'rses_file_size', __( 'RSV upload exceeded the maximum allowed size.', 'relatasoft-secure-election-suite' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$out = fopen( $path, 'ab' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$in  = fopen( $chunk_path, 'rb' );
		if ( false === $out || false === $in ) {
			if ( is_resource( $out ) ) {
				fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
			if ( is_resource( $in ) ) {
				fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
			return new \WP_Error( 'rses_chunk_append', __( 'Could not append upload chunk.', 'relatasoft-secure-election-suite' ) );
		}

		stream_copy_to_stream( $in, $out );
		fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$job['chunks_received'] = $expected + 1;
		$job['bytes_received']  = $new_total;
		$job['message']         = sprintf(
			/* translators: 1: chunks received, 2: total chunks */
			__( 'Uploaded chunk %1$d of %2$d…', 'relatasoft-secure-election-suite' ),
			$job['chunks_received'],
			(int) $job['total_chunks']
		);
		self::rses_save( $job );
		return $job;
	}

	/**
	 * Validate RSV after upload and prepare for import ticks.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function rses_begin_import() {
		$job = self::rses_get();
		if ( ! $job || self::STAGE_RECEIVING !== ( $job['stage'] ?? '' ) ) {
			return new \WP_Error( 'rses_job_missing', __( 'No completed upload to import.', 'relatasoft-secure-election-suite' ) );
		}

		if ( (int) ( $job['chunks_received'] ?? 0 ) < (int) ( $job['total_chunks'] ?? 1 ) ) {
			return new \WP_Error( 'rses_upload_incomplete', __( 'RSV upload is incomplete.', 'relatasoft-secure-election-suite' ) );
		}

		$path = (string) ( $job['file_path'] ?? '' );
		if ( '' === $path || ! is_readable( $path ) ) {
			$err = new \WP_Error(
				'rses_file_missing',
				__( 'The uploaded RSV is no longer available on the server. Please start the import again.', 'relatasoft-secure-election-suite' )
			);
			$job['stage']   = self::STAGE_FAILED;
			$job['message'] = $err->get_error_message();
			self::rses_push_error( $job, $err->get_error_message() );
			self::rses_save( $job );
			return $err;
		}

		$size = filesize( $path );
		if ( false === $size || $size < 1 ) {
			$err = new \WP_Error(
				'rses_file_empty',
				__( 'The uploaded RSV is empty on the server. Please start the import again.', 'relatasoft-secure-election-suite' )
			);
			$job['stage']   = self::STAGE_FAILED;
			$job['message'] = $err->get_error_message();
			self::rses_push_error( $job, $err->get_error_message() );
			self::rses_save( $job );
			return $err;
		}

		// Recover from duplicated chunk appends (e.g. double-submit): keep the declared prefix.
		$normalized = self::rses_normalize_uploaded_size( $job, $path, (int) $size );
		if ( is_wp_error( $normalized ) ) {
			$job['stage']   = self::STAGE_FAILED;
			$job['message'] = $normalized->get_error_message();
			self::rses_push_error( $job, $normalized->get_error_message() );
			self::rses_save( $job );
			return $normalized;
		}
		$job = $normalized;

		$job['stage']   = self::STAGE_READY;
		$job['message'] = __( 'Validating RSV…', 'relatasoft-secure-election-suite' );
		self::rses_save( $job );

		$prep = ElectoralRollImportService::rses_prepare_file( $path );
		if ( is_wp_error( $prep ) ) {
			$job['stage']   = self::STAGE_FAILED;
			$job['message'] = $prep->get_error_message();
			self::rses_push_error( $job, $prep->get_error_message() );
			self::rses_save( $job );
			return $prep;
		}

		$job['stage']          = self::STAGE_IMPORTING;
		$job['map']            = $prep['map'];
		$job['format']         = (string) ( $prep['format'] ?? 'rsv' );
		$job['byte_offset']    = $prep['byte_offset'];
		$job['next_row_num']   = 1;
		$job['total_rows']     = $prep['total_rows'];
		$job['processed_rows'] = 0;
		$job['message']        = __( 'Importing electoral roll (.rsv)…', 'relatasoft-secure-election-suite' );
		self::rses_save( $job );
		return $job;
	}

	/**
	 * Process one batch of rows.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function rses_tick() {
		$job = self::rses_get();
		if ( ! $job ) {
			return new \WP_Error( 'rses_job_missing', __( 'No active import job.', 'relatasoft-secure-election-suite' ) );
		}

		if ( self::STAGE_COMPLETE === ( $job['stage'] ?? '' ) || self::STAGE_FAILED === ( $job['stage'] ?? '' ) ) {
			return $job;
		}

		if ( self::STAGE_CANCELLED === ( $job['stage'] ?? '' ) ) {
			return $job;
		}

		if ( self::STAGE_IMPORTING !== ( $job['stage'] ?? '' ) ) {
			return new \WP_Error( 'rses_job_stage', __( 'Import job is not ready to process rows.', 'relatasoft-secure-election-suite' ) );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
			@set_time_limit( 60 );
		}

		$batch = ElectoralRollImportService::rses_process_batch(
			(string) $job['file_path'],
			(array) $job['map'],
			(int) $job['byte_offset'],
			(int) $job['next_row_num'],
			self::BATCH_ROWS,
			(bool) $job['update_existing'],
			ElectoralRollImportService::MAX_ROWS
		);

		if ( is_wp_error( $batch ) ) {
			$job['stage']   = self::STAGE_FAILED;
			$job['message'] = $batch->get_error_message();
			self::rses_push_error( $job, $batch->get_error_message() );
			self::rses_save( $job );
			return $batch;
		}

		$job['byte_offset']    = (int) $batch['byte_offset'];
		$job['next_row_num']   = (int) $batch['next_row_num'];
		$job['processed_rows'] = (int) $job['processed_rows'] + (int) $batch['processed'];
		$job['created']        = (int) $job['created'] + (int) $batch['created'];
		$job['updated']        = (int) $job['updated'] + (int) $batch['updated'];
		$job['skipped']        = (int) $job['skipped'] + (int) $batch['skipped'];

		foreach ( $batch['errors'] as $err ) {
			self::rses_push_error( $job, (string) $err );
		}

		if ( ! empty( $batch['done'] ) ) {
			$job['stage']   = self::STAGE_COMPLETE;
			$job['message'] = __( 'Electoral roll RSV import finished.', 'relatasoft-secure-election-suite' );
			ElectoralRollImportService::rses_log_import_audit(
				(int) $job['created'],
				(int) $job['updated'],
				(int) $job['skipped'],
				(int) $job['error_count']
			);
			self::rses_unlink_file( $job );
			$job['file_path'] = '';
		} else {
			$total          = max( 1, (int) $job['total_rows'] );
			$job['message'] = sprintf(
				/* translators: 1: processed rows, 2: total rows */
				__( 'Imported %1$d of %2$d rows…', 'relatasoft-secure-election-suite' ),
				min( (int) $job['processed_rows'], $total ),
				$total
			);
		}

		self::rses_save( $job );
		return $job;
	}

	/**
	 * Cancel active job and remove temp file.
	 *
	 * @return array<string,mixed>
	 */
	public static function rses_cancel(): array {
		$job = self::rses_get();
		if ( ! $job ) {
			return array(
				'stage'   => self::STAGE_CANCELLED,
				'message' => __( 'No import job to cancel.', 'relatasoft-secure-election-suite' ),
			);
		}
		self::rses_unlink_file( $job );
		$job['file_path'] = '';
		$job['stage']     = self::STAGE_CANCELLED;
		$job['message']   = __( 'Electoral roll import cancelled.', 'relatasoft-secure-election-suite' );
		self::rses_save( $job );
		return $job;
	}

	/**
	 * Upload progress 0–100 from chunks (or bytes when available).
	 *
	 * @param array<string,mixed> $job Job.
	 */
	private static function rses_upload_progress( array $job ): int {
		$total_chunks = max( 1, (int) ( $job['total_chunks'] ?? 1 ) );
		$chunks       = (int) ( $job['chunks_received'] ?? 0 );
		$by_chunks    = (int) floor( ( $chunks / $total_chunks ) * 100 );

		$total_bytes = (int) ( $job['total_bytes'] ?? 0 );
		if ( $total_bytes > 0 ) {
			$received = (int) ( $job['bytes_received'] ?? 0 );
			$by_bytes = (int) floor( ( min( $received, $total_bytes ) / $total_bytes ) * 100 );
			return max( 0, min( 100, max( $by_chunks, $by_bytes ) ) );
		}

		return max( 0, min( 100, $by_chunks ) );
	}

	/**
	 * Import progress 0–100 from processed data rows.
	 *
	 * @param array<string,mixed> $job Job.
	 */
	private static function rses_import_progress( array $job ): int {
		$stage = (string) ( $job['stage'] ?? '' );
		if ( self::STAGE_COMPLETE === $stage ) {
			return 100;
		}
		$total = (int) ( $job['total_rows'] ?? 0 );
		if ( $total < 1 ) {
			return 0;
		}
		$done = min( $total, (int) ( $job['processed_rows'] ?? 0 ) );
		return max( 0, min( 100, (int) floor( ( $done / $total ) * 100 ) ) );
	}

	/**
	 * Overall progress: upload weighted 0–40, import weighted 40–100 (backward compat).
	 *
	 * @param array<string,mixed> $job Job.
	 */
	private static function rses_overall_progress( array $job, int $upload_progress, int $import_progress ): int {
		$stage = (string) ( $job['stage'] ?? '' );

		if ( self::STAGE_COMPLETE === $stage ) {
			return 100;
		}

		if ( self::STAGE_RECEIVING === $stage ) {
			return max( 0, min( 40, (int) floor( $upload_progress * 0.4 ) ) );
		}

		if ( self::STAGE_READY === $stage ) {
			// Upload done; validation in progress — park at end of upload band.
			return 40;
		}

		if ( self::STAGE_IMPORTING === $stage ) {
			return 40 + max( 0, min( 60, (int) floor( $import_progress * 0.6 ) ) );
		}

		if ( self::STAGE_FAILED === $stage || self::STAGE_CANCELLED === $stage ) {
			if ( $import_progress > 0 || (int) ( $job['processed_rows'] ?? 0 ) > 0 ) {
				return 40 + max( 0, min( 60, (int) floor( $import_progress * 0.6 ) ) );
			}
			if ( $upload_progress > 0 || (int) ( $job['chunks_received'] ?? 0 ) > 0 ) {
				return max( 0, min( 40, (int) floor( $upload_progress * 0.4 ) ) );
			}
			return 0;
		}

		return 0;
	}

	/**
	 * Public status payload for the admin UI.
	 *
	 * @param array<string,mixed>|null $job Job.
	 * @return array<string,mixed>
	 */
	public static function rses_public_status( ?array $job ): array {
		if ( ! $job ) {
			return array(
				'active'           => false,
				'stage'            => '',
				'stage_label'      => '',
				'progress'         => 0,
				'upload_progress'  => 0,
				'import_progress'  => 0,
				'message'          => '',
				'created'          => 0,
				'updated'          => 0,
				'skipped'          => 0,
				'error_count'      => 0,
				'processed_rows'   => 0,
				'total_rows'       => 0,
				'errors'           => array(),
				'chunks_received'  => 0,
				'total_chunks'     => 0,
			);
		}

		$stage            = (string) ( $job['stage'] ?? '' );
		$upload_progress  = self::rses_upload_progress( $job );
		$import_progress  = self::rses_import_progress( $job );

		// Once past receiving, treat upload as complete for the dedicated meter.
		if ( in_array( $stage, array( self::STAGE_READY, self::STAGE_IMPORTING, self::STAGE_COMPLETE ), true ) ) {
			$upload_progress = 100;
		} elseif ( self::STAGE_FAILED === $stage || self::STAGE_CANCELLED === $stage ) {
			if ( (int) ( $job['total_rows'] ?? 0 ) > 0 || (int) ( $job['processed_rows'] ?? 0 ) > 0 ) {
				$upload_progress = 100;
			}
		}

		$progress = self::rses_overall_progress( $job, $upload_progress, $import_progress );

		return array(
			'active'           => in_array( $stage, array( self::STAGE_RECEIVING, self::STAGE_READY, self::STAGE_IMPORTING ), true ),
			'stage'            => $stage,
			'stage_label'      => self::rses_stage_label( $stage ),
			'progress'         => max( 0, min( 100, $progress ) ),
			'upload_progress'  => max( 0, min( 100, $upload_progress ) ),
			'import_progress'  => max( 0, min( 100, $import_progress ) ),
			'message'          => (string) ( $job['message'] ?? '' ),
			'created'          => (int) ( $job['created'] ?? 0 ),
			'updated'          => (int) ( $job['updated'] ?? 0 ),
			'skipped'          => (int) ( $job['skipped'] ?? 0 ),
			'error_count'      => (int) ( $job['error_count'] ?? 0 ),
			'processed_rows'   => (int) ( $job['processed_rows'] ?? 0 ),
			'total_rows'       => (int) ( $job['total_rows'] ?? 0 ),
			'errors'           => array_values( (array) ( $job['errors'] ?? array() ) ),
			'chunks_received'  => (int) ( $job['chunks_received'] ?? 0 ),
			'total_chunks'     => (int) ( $job['total_chunks'] ?? 0 ),
			'original_name'    => (string) ( $job['original_name'] ?? '' ),
		);
	}

	/**
	 * @param array<string,mixed> $job Job (by ref).
	 */
	private static function rses_push_error( array &$job, string $message ): void {
		++$job['error_count'];
		if ( ! isset( $job['errors'] ) || ! is_array( $job['errors'] ) ) {
			$job['errors'] = array();
		}
		if ( count( $job['errors'] ) < self::MAX_ERRORS_KEEP ) {
			$job['errors'][] = $message;
		}
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
