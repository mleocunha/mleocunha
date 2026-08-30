<?php
/**
 * Admin: Importador de cadastro eleitoral (AJAX em lotes).
 *
 * @package RelataSoft\SecureElectionSuite\Admin
 */

namespace RelataSoft\SecureElectionSuite\Admin;

use RelataSoft\SecureElectionSuite\Bootstrap\ModeLock;
use RelataSoft\SecureElectionSuite\Bootstrap\Plugin;
use RelataSoft\SecureElectionSuite\I18n\RoleLabels;
use RelataSoft\SecureElectionSuite\I18n\Translator;
use RelataSoft\SecureElectionSuite\Painel\Application\Jobs\JobGateway;
use RelataSoft\SecureElectionSuite\Painel\Domain\ElectoralRoll\RsvFormat;
use RelataSoft\SecureElectionSuite\Security\Capability;
use RelataSoft\SecureElectionSuite\Security\Nonce;
use RelataSoft\SecureElectionSuite\Voting\ElectoralRollExportJob;
use RelataSoft\SecureElectionSuite\Voting\ElectoralRollExportService;
use RelataSoft\SecureElectionSuite\Voting\ElectoralRollImportJob;
use RelataSoft\SecureElectionSuite\Voting\ElectoralRollImportService;

defined( 'ABSPATH' ) || exit;

/**
 * Cadastro Eleitoral — listagem/criação + import/export .rsv (voting).
 */
class ElectoralRollImportPage {

	public const ERRORS_TRANSIENT_PREFIX = 'rses_electoral_roll_errors_';
	public const AJAX_NONCE_ACTION       = 'rses_electoral_roll';

	/**
	 * Register admin-post + AJAX handlers.
	 */
	public static function register(): void {
		add_action( 'admin_post_rses_import_electoral_roll', array( self::class, 'rses_handle_import' ) );
		add_action( 'admin_post_rses_download_electoral_roll_sample', array( self::class, 'rses_handle_sample' ) );
		add_action( 'admin_post_rses_download_electoral_roll_errors', array( self::class, 'rses_handle_errors_download' ) );
		add_action( 'admin_post_rses_download_electoral_roll_export', array( self::class, 'rses_handle_export_download' ) );

		add_action( 'wp_ajax_rses_electoral_roll_init', array( self::class, 'rses_ajax_init' ) );
		add_action( 'wp_ajax_rses_electoral_roll_chunk', array( self::class, 'rses_ajax_chunk' ) );
		add_action( 'wp_ajax_rses_electoral_roll_upload', array( self::class, 'rses_ajax_upload' ) );
		add_action( 'wp_ajax_rses_electoral_roll_begin', array( self::class, 'rses_ajax_begin' ) );
		add_action( 'wp_ajax_rses_electoral_roll_tick', array( self::class, 'rses_ajax_tick' ) );
		add_action( 'wp_ajax_rses_electoral_roll_status', array( self::class, 'rses_ajax_status' ) );
		add_action( 'wp_ajax_rses_electoral_roll_cancel', array( self::class, 'rses_ajax_cancel' ) );

		add_action( 'wp_ajax_rses_electoral_roll_export_init', array( self::class, 'rses_ajax_export_init' ) );
		add_action( 'wp_ajax_rses_electoral_roll_export_tick', array( self::class, 'rses_ajax_export_tick' ) );
		add_action( 'wp_ajax_rses_electoral_roll_export_status', array( self::class, 'rses_ajax_export_status' ) );
		add_action( 'wp_ajax_rses_electoral_roll_export_cancel', array( self::class, 'rses_ajax_export_cancel' ) );
		add_action( 'wp_ajax_rses_electoral_roll_export_estimate', array( self::class, 'rses_ajax_export_estimate' ) );

		// admin_init passes '' into callbacks — do not bind the typed method directly.
		add_action(
			'admin_init',
			static function (): void {
				if ( JobGateway::isBooted() ) {
					JobGateway::get()->rsvImport->purgeExpired();
					JobGateway::get()->rsvExport->purgeExpired();
				}
			}
		);
	}

	/**
	 * PHP post/upload ceiling in bytes (0 = unknown/unlimited).
	 */
	public static function rses_php_upload_ceiling(): int {
		$post   = wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) );
		$upload = wp_convert_hr_to_bytes( (string) ini_get( 'upload_max_filesize' ) );
		$vals   = array();
		if ( $post > 0 ) {
			$vals[] = $post;
		}
		if ( $upload > 0 ) {
			$vals[] = $upload;
		}
		return $vals ? (int) min( $vals ) : 0;
	}

	/**
	 * True when the raw body is larger than post_max_size (PHP empties $_POST/$_FILES).
	 */
	private static function rses_request_exceeds_post_max(): bool {
		$cl = isset( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
		if ( $cl < 1 ) {
			return false;
		}
		$post_max = wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) );
		return $post_max > 0 && $cl > $post_max;
	}

	/**
	 * Human message for $_FILES error codes.
	 */
	private static function rses_upload_error_message( int $code ): string {
		switch ( $code ) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return sprintf(
					/* translators: %s: max size label */
					__( 'O arquivo RSV excede o limite de upload do servidor (%s). Tentar novamente — o importador enviará em pedaços menores.', 'relatasoft-secure-election-suite' ),
					size_format( self::rses_php_upload_ceiling() ?: ElectoralRollImportJob::MAX_UPLOAD_BYTES )
				);
			case UPLOAD_ERR_PARTIAL:
				return __( 'O upload do RSV foi recebido apenas parcialmente. Tentar novamente.', 'relatasoft-secure-election-suite' );
			case UPLOAD_ERR_NO_FILE:
				return __( 'Nenhum arquivo RSV enviado.', 'relatasoft-secure-election-suite' );
			case UPLOAD_ERR_NO_TMP_DIR:
				return __( 'Pasta temporária do servidor ausente; contate o hospedagem.', 'relatasoft-secure-election-suite' );
			case UPLOAD_ERR_CANT_WRITE:
				return __( 'O servidor não conseguiu gravar o RSV no disco.', 'relatasoft-secure-election-suite' );
			case UPLOAD_ERR_EXTENSION:
				return __( 'Uma extensão PHP bloqueou o upload do RSV.', 'relatasoft-secure-election-suite' );
			default:
				return __( 'Upload inválido.', 'relatasoft-secure-election-suite' );
		}
	}

	/**
	 * Shared AJAX guard (JSON errors — never HTML wp_die / check_ajax_referer).
	 */
	private static function rses_ajax_guard(): void {
		if ( self::rses_request_exceeds_post_max() ) {
			$ceiling = self::rses_php_upload_ceiling();
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: max size label */
						__( 'Upload rejected: the request is larger than PHP post_max_size (%s). The importer will retry in smaller chunks — reload and try again, or ask the host to raise post_max_size / upload_max_filesize.', 'relatasoft-secure-election-suite' ),
						$ceiling > 0 ? size_format( $ceiling ) : 'post_max_size'
					),
				)
			);
		}

		if ( ! Capability::rses_can_manage_election() ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'relatasoft-secure-election-suite' ) ) );
		}

		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::AJAX_NONCE_ACTION ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Security check failed. Hard-refresh this page (Ctrl/Cmd+Shift+R) and try the import again.', 'relatasoft-secure-election-suite' ),
				)
			);
		}

		if ( ! ModeLock::rses_is_mode( ModeLock::RSES_MODE_VOTING ) ) {
			wp_send_json_error( array( 'message' => __( 'Not available in this mode.', 'relatasoft-secure-election-suite' ) ) );
		}
	}

	/**
	 * Persist errors for CSV download after a finished job.
	 *
	 * @param array<string,mixed> $status Public status.
	 */
	private static function rses_store_errors_from_status( array $status ): void {
		$key = self::ERRORS_TRANSIENT_PREFIX . get_current_user_id();
		if ( ! empty( $status['errors'] ) && is_array( $status['errors'] ) ) {
			set_transient( $key, array_values( $status['errors'] ), 30 * MINUTE_IN_SECONDS );
		} elseif ( empty( $status['error_count'] ) ) {
			delete_transient( $key );
		}
	}

	/**
	 * AJAX: create receiving job.
	 */
	public static function rses_ajax_init(): void {
		self::rses_ajax_guard();

		$original = isset( $_POST['filename'] ) ? sanitize_file_name( wp_unslash( (string) $_POST['filename'] ) ) : 'cadastro.csv';
		$chunks   = isset( $_POST['total_chunks'] ) ? absint( $_POST['total_chunks'] ) : 1;
		$bytes    = isset( $_POST['total_bytes'] ) ? absint( $_POST['total_bytes'] ) : 0;
		$update   = ! empty( $_POST['update_existing'] );

		$status = JobGateway::get()->rsvImport->createReceiving( $original, $chunks, $bytes, $update );
		if ( is_wp_error( $status ) ) {
			wp_send_json_error( array( 'message' => $status->get_error_message() ) );
		}

		wp_send_json_success( $status );
	}

	/**
	 * AJAX: append one file chunk.
	 */
	public static function rses_ajax_chunk(): void {
		self::rses_ajax_guard();

		$index = isset( $_POST['chunk_index'] ) ? absint( $_POST['chunk_index'] ) : -1;
		$file_err = isset( $_FILES['chunk']['error'] ) ? (int) $_FILES['chunk']['error'] : UPLOAD_ERR_NO_FILE;
		if ( $index < 0 || UPLOAD_ERR_OK !== $file_err || empty( $_FILES['chunk']['tmp_name'] ) ) {
			wp_send_json_error(
				array(
					'message' => UPLOAD_ERR_OK !== $file_err
						? self::rses_upload_error_message( $file_err )
						: __( 'Invalid upload chunk.', 'relatasoft-secure-election-suite' ),
				)
			);
		}

		$tmp = (string) $_FILES['chunk']['tmp_name']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! is_uploaded_file( $tmp ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid upload chunk.', 'relatasoft-secure-election-suite' ) ) );
		}

		$status = JobGateway::get()->rsvImport->appendChunk( $tmp, $index );
		if ( is_wp_error( $status ) ) {
			wp_send_json_error( array( 'message' => $status->get_error_message() ) );
		}

		wp_send_json_success( $status );
	}

	/**
	 * AJAX: upload a complete CSV (preferred for typical rolls ≤ ~8 MiB).
	 */
	public static function rses_ajax_upload(): void {
		self::rses_ajax_guard();

		$file_err = isset( $_FILES['csv']['error'] ) ? (int) $_FILES['csv']['error'] : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_OK !== $file_err || empty( $_FILES['csv']['tmp_name'] ) ) {
			wp_send_json_error( array( 'message' => self::rses_upload_error_message( $file_err ) ) );
		}

		$tmp = (string) $_FILES['csv']['tmp_name']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! is_uploaded_file( $tmp ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid upload.', 'relatasoft-secure-election-suite' ) ) );
		}

		$original = isset( $_FILES['csv']['name'] )
			? sanitize_file_name( wp_unslash( (string) $_FILES['csv']['name'] ) )
			: 'cadastro.csv';
		$update   = ! empty( $_POST['update_existing'] );

		$import = JobGateway::get()->rsvImport;
		$status = $import->ingestFullUpload( $tmp, $original, $update );
		if ( is_wp_error( $status ) ) {
			wp_send_json_error(
				array(
					'message' => $status->get_error_message(),
					'status'  => $import->status(),
				)
			);
		}

		wp_send_json_success( $status );
	}

	/**
	 * AJAX: validate CSV and start importing.
	 */
	public static function rses_ajax_begin(): void {
		self::rses_ajax_guard();

		$import = JobGateway::get()->rsvImport;
		$status = $import->begin();
		if ( is_wp_error( $status ) ) {
			wp_send_json_error(
				array(
					'message' => $status->get_error_message(),
					'status'  => $import->status(),
				)
			);
		}

		wp_send_json_success( $status );
	}

	/**
	 * AJAX: process one batch.
	 */
	public static function rses_ajax_tick(): void {
		self::rses_ajax_guard();

		$import = JobGateway::get()->rsvImport;
		$status = $import->tick();
		if ( is_wp_error( $status ) ) {
			$current = $import->status();
			self::rses_store_errors_from_status( $current );
			wp_send_json_error(
				array(
					'message' => $status->get_error_message(),
					'status'  => $current,
				)
			);
		}

		if ( in_array( $status['stage'] ?? '', array( ElectoralRollImportJob::STAGE_COMPLETE, ElectoralRollImportJob::STAGE_FAILED ), true ) ) {
			self::rses_store_errors_from_status( $status );
		}

		wp_send_json_success( $status );
	}

	/**
	 * AJAX: status.
	 */
	public static function rses_ajax_status(): void {
		self::rses_ajax_guard();
		wp_send_json_success( JobGateway::get()->rsvImport->status() );
	}

	/**
	 * AJAX: cancel.
	 */
	public static function rses_ajax_cancel(): void {
		self::rses_ajax_guard();
		wp_send_json_success( JobGateway::get()->rsvImport->cancel() );
	}

	/**
	 * AJAX: start export job (one role + max data lines).
	 */
	public static function rses_ajax_export_init(): void {
		self::rses_ajax_guard();
		$role   = isset( $_POST['wp_role'] ) ? sanitize_key( wp_unslash( (string) $_POST['wp_role'] ) ) : '';
		$lines  = isset( $_POST['max_lines'] ) ? absint( $_POST['max_lines'] ) : 1000;
		$status = JobGateway::get()->rsvExport->start( $role, $lines );
		if ( is_wp_error( $status ) ) {
			wp_send_json_error( array( 'message' => $status->get_error_message() ) );
		}
		wp_send_json_success( $status );
	}

	/**
	 * AJAX: export tick.
	 */
	public static function rses_ajax_export_tick(): void {
		self::rses_ajax_guard();
		$export = JobGateway::get()->rsvExport;
		$status = $export->tick();
		if ( is_wp_error( $status ) ) {
			wp_send_json_error(
				array(
					'message' => $status->get_error_message(),
					'status'  => $export->status(),
				)
			);
		}
		wp_send_json_success( $status );
	}

	/**
	 * AJAX: export status.
	 */
	public static function rses_ajax_export_status(): void {
		self::rses_ajax_guard();
		wp_send_json_success( JobGateway::get()->rsvExport->status() );
	}

	/**
	 * AJAX: cancel export.
	 */
	public static function rses_ajax_export_cancel(): void {
		self::rses_ajax_guard();
		wp_send_json_success( JobGateway::get()->rsvExport->cancel() );
	}

	/**
	 * AJAX: estimate export bytes.
	 */
	public static function rses_ajax_export_estimate(): void {
		self::rses_ajax_guard();
		$lines = isset( $_POST['max_lines'] ) ? absint( $_POST['max_lines'] ) : 0;
		$role  = isset( $_POST['wp_role'] ) ? sanitize_key( wp_unslash( (string) $_POST['wp_role'] ) ) : '';
		$bytes = ElectoralRollExportService::rses_estimate_bytes( $lines );
		$count = '' !== $role ? ElectoralRollExportService::rses_count_role( $role ) : 0;
		wp_send_json_success(
			array(
				'estimated_bytes' => $bytes,
				'estimated_label' => size_format( $bytes ),
				'role_count'      => $count,
			)
		);
	}

	/**
	 * Download completed export RSV.
	 */
	public static function rses_handle_export_download(): void {
		Capability::rses_require_admin();
		ModeLock::rses_require_mode( ModeLock::RSES_MODE_VOTING );
		Nonce::rses_verify_or_die( Nonce::RSES_ACTION_ELECTORAL_ROLL_SAMPLE );

		$job  = ElectoralRollExportJob::rses_get();
		$path = JobGateway::isBooted()
			? JobGateway::get()->rsvExport->downloadPath()
			: ElectoralRollExportJob::rses_download_path( $job );
		if ( null === $path || ! $job ) {
			wp_die( esc_html__( 'Nenhuma exportação .rsv pronta para download.', 'relatasoft-secure-election-suite' ) );
		}

		$filename = sanitize_file_name( (string) ( $job['original_name'] ?? 'cadastro.rsv' ) );
		$size     = filesize( $path );

		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		if ( false !== $size ) {
			header( 'Content-Length: ' . (string) $size );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $path );
		exit;
	}

	/**
	 * Render page (all modes with the menu). Import/export AJAX remain voting-only.
	 */
	public static function rses_render(): void {
		Capability::rses_require_admin();
		if ( ! ModeLock::rses_has_mode() ) {
			echo '<div class="wrap rses-wrap"><p>' . esc_html__( 'Definir o modo de operação antes de gerenciar o cadastro eleitoral.', 'relatasoft-secure-election-suite' ) . '</p></div>';
			return;
		}

		$is_voting = ModeLock::rses_is_mode( ModeLock::RSES_MODE_VOTING );
		if ( $is_voting ) {
			Plugin::rses_enqueue_electoral_roll_script();
		}

		$electors    = RoleLabels::rses_elector_plural();
		$headers     = ElectoralRollImportService::rses_expected_headers();
		$sample_name = ElectoralRollImportService::rses_sample_filename();
		$errors      = array();
		$show_errors = ! empty( $_GET['rses_import_errors'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $show_errors ) {
			$raw    = get_transient( self::ERRORS_TRANSIENT_PREFIX . get_current_user_id() );
			$errors = is_array( $raw ) ? $raw : array();
		}

		$active_job  = JobGateway::get()->rsvImport->status();
		$export_job  = JobGateway::get()->rsvExport->status();
		$max_label   = number_format_i18n( ElectoralRollImportService::MAX_ROWS );
		$max_size    = size_format( ElectoralRollImportJob::MAX_UPLOAD_BYTES );
		$php_ceiling = self::rses_php_upload_ceiling();
		$chunk_bytes = RsvFormat::adaptiveChunkBytes( $php_ceiling );
		$export_roles = ElectoralRollExportService::rses_exportable_roles();
		?>
		<div class="wrap rses-wrap rses-screen rses-electoral-roll" <?php echo Translator::rses_html_attrs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<header class="rses-hero rses-hero--brand">
				<?php Brand::rses_render_hero_brand(); ?>
				<p class="rses-hero-kicker"><?php esc_html_e( 'Acessos do sistema', 'relatasoft-secure-election-suite' ); ?></p>
				<h1 class="rses-hero-title"><?php esc_html_e( 'Cadastro Eleitoral', 'relatasoft-secure-election-suite' ); ?></h1>
				<p class="rses-hero-lead">
					<?php
					echo esc_html(
						$is_voting
							? sprintf(
								/* translators: %s: electors label */
								__( 'Listar e criar contas, e importar/exportar o arquivo .rsv do cadastro usado para %s. No update, senha vazia mantém a senha atual.', 'relatasoft-secure-election-suite' ),
								$electors
							)
							: __( 'Liste e crie contas do Painel neste modo de operação.', 'relatasoft-secure-election-suite' )
					);
					?>
				</p>
			</header>

			<?php UsersRegistryPage::rses_render_sections(); ?>

			<?php if ( $is_voting ) : ?>
			<div id="rses-electoral-result" class="rses-electoral-result" <?php echo empty( $_GET['rses_import_done'] ) ? 'hidden' : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>>
				<?php if ( ! empty( $_GET['rses_import_done'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
					<?php
					$created = isset( $_GET['created'] ) ? absint( $_GET['created'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$updated = isset( $_GET['updated'] ) ? absint( $_GET['updated'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$skipped = isset( $_GET['skipped'] ) ? absint( $_GET['skipped'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$err_n   = count( $errors );
					?>
					<div class="rses-panel <?php echo $err_n > 0 ? 'rses-panel-warning' : 'rses-panel-success'; ?>">
						<p id="rses-electoral-result-text">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: created 2: updated 3: skipped 4: error count */
									__( 'Importação do cadastro eleitoral concluída. Criados: %1$d. Atualizados: %2$d. Ignorados: %3$d. Erros: %4$d.', 'relatasoft-secure-election-suite' ),
									$created,
									$updated,
									$skipped,
									$err_n
								)
							);
							?>
						</p>
					</div>
				<?php else : ?>
					<div class="rses-panel rses-panel-success">
						<p id="rses-electoral-result-text"></p>
					</div>
				<?php endif; ?>
			</div>

			<div id="rses-electoral-errors-live" class="rses-electoral-errors-live" <?php echo empty( $errors ) ? 'hidden' : ''; ?>>
				<section class="rses-panel rses-panel-card rses-electoral-errors">
					<div class="rses-panel-header">
						<h2 class="rses-panel-title"><?php esc_html_e( 'Erros de importação', 'relatasoft-secure-election-suite' ); ?></h2>
						<p class="rses-panel-desc" id="rses-electoral-errors-desc">
							<?php
							if ( ! empty( $errors ) ) {
								echo esc_html(
									sprintf(
										/* translators: %d: error count */
										__( '%d problema(s) reportado(s). Revisar a tabela ou baixar o relatório RSV.', 'relatasoft-secure-election-suite' ),
										count( $errors )
									)
								);
							}
							?>
						</p>
					</div>
					<p>
						<a class="button" id="rses-electoral-errors-download" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=rses_download_electoral_roll_errors' ), Nonce::RSES_ACTION_ELECTORAL_ROLL_ERRORS, '_rses_nonce' ) ); ?>">
							<?php esc_html_e( 'Baixar relatório de erros (.rsv)', 'relatasoft-secure-election-suite' ); ?>
						</a>
					</p>
					<div class="rses-security-table-scroll rses-electoral-errors-scroll">
						<table class="widefat striped">
							<thead>
								<tr>
									<th style="width:4rem"><?php esc_html_e( '#', 'relatasoft-secure-election-suite' ); ?></th>
									<th><?php esc_html_e( 'Mensagem', 'relatasoft-secure-election-suite' ); ?></th>
								</tr>
							</thead>
							<tbody id="rses-electoral-errors-body">
								<?php foreach ( $errors as $i => $err ) : ?>
									<tr>
										<td><?php echo esc_html( (string) ( $i + 1 ) ); ?></td>
										<td><?php echo esc_html( (string) $err ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</section>
			</div>

			<div class="rses-electoral-layout">
				<section class="rses-panel rses-panel-card rses-electoral-model">
					<header class="rses-panel-header">
						<p class="rses-panel-kicker"><?php esc_html_e( 'Modelo', 'relatasoft-secure-election-suite' ); ?></p>
						<h2 class="rses-panel-title"><?php esc_html_e( 'Arquivo modelo .rsv', 'relatasoft-secure-election-suite' ); ?></h2>
						<p class="rses-panel-desc">
							<?php esc_html_e( 'Formato RelataSoft Separated Values: campos separados por “:”, séries (e-mails/celulares) por “;”. Vírgulas só em texto livre (ex.: endereço). Papéis: eleitor, auditor, autoridade, administrador, gestor.', 'relatasoft-secure-election-suite' ); ?>
						</p>
					</header>

					<ul class="rses-electoral-meta">
						<li>
							<span class="rses-electoral-meta-label"><?php esc_html_e( 'Máx. linhas', 'relatasoft-secure-election-suite' ); ?></span>
							<strong><?php echo esc_html( $max_label ); ?></strong>
						</li>
						<li>
							<span class="rses-electoral-meta-label"><?php esc_html_e( 'Máx. upload', 'relatasoft-secure-election-suite' ); ?></span>
							<strong><?php echo esc_html( $max_size ); ?></strong>
						</li>
						<li>
							<span class="rses-electoral-meta-label"><?php esc_html_e( 'Transporte', 'relatasoft-secure-election-suite' ); ?></span>
							<strong><?php esc_html_e( 'AJAX em pedaços', 'relatasoft-secure-election-suite' ); ?></strong>
						</li>
					</ul>

					<div class="rses-electoral-headers" tabindex="0">
						<code><?php echo esc_html( implode( RsvFormat::FIELD_SEP, $headers ) ); ?></code>
					</div>

					<p class="rses-panel-desc">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: localized example filename */
								__( 'Baixe %s — uma linha de metadados (cabeçalho) e 10 linhas de exemplo.', 'relatasoft-secure-election-suite' ),
								$sample_name
							)
						);
						?>
					</p>
					<p>
						<a class="button button-secondary rses-btn-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=rses_download_electoral_roll_sample' ), Nonce::RSES_ACTION_ELECTORAL_ROLL_SAMPLE, '_rses_nonce' ) ); ?>">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: localized example filename */
									__( 'Baixar %s', 'relatasoft-secure-election-suite' ),
									$sample_name
								)
							);
							?>
						</a>
					</p>
				</section>

				<section class="rses-panel rses-panel-card rses-electoral-upload">
					<header class="rses-panel-header">
						<p class="rses-panel-kicker"><?php esc_html_e( 'Importar', 'relatasoft-secure-election-suite' ); ?></p>
						<h2 class="rses-panel-title"><?php esc_html_e( 'Importar .rsv', 'relatasoft-secure-election-suite' ); ?></h2>
						<p class="rses-panel-desc">
							<?php esc_html_e( 'Arquivos grandes são enviados em pedaços e importados em lotes com barras de progresso de upload e de importação.', 'relatasoft-secure-election-suite' ); ?>
						</p>
					</header>

					<div
						id="rses-electoral-progress"
						class="rses-electoral-progress is-idle"
						<?php echo ! empty( $active_job['active'] ) ? '' : 'hidden'; ?>
					>
						<div class="rses-panel rses-panel-info rses-electoral-progress-inner">
							<p id="rses-electoral-message"><?php echo esc_html( (string) ( $active_job['message'] ?: __( 'Preparando…', 'relatasoft-secure-election-suite' ) ) ); ?></p>
							<p class="rses-electoral-progress-label"><?php esc_html_e( 'Upload', 'relatasoft-secure-election-suite' ); ?></p>
							<div class="rses-progress-bar rses-electoral-bar">
								<div id="rses-electoral-upload-bar-fill" class="rses-progress-fill" style="width:<?php echo esc_attr( (string) (int) ( $active_job['upload_progress'] ?? 0 ) ); ?>%"></div>
							</div>
							<p class="rses-electoral-progress-meta">
								<span id="rses-electoral-upload-percent"><?php echo esc_html( (string) (int) ( $active_job['upload_progress'] ?? 0 ) ); ?>%</span>
							</p>
							<p class="rses-electoral-progress-label"><?php esc_html_e( 'Importação', 'relatasoft-secure-election-suite' ); ?></p>
							<div class="rses-progress-bar rses-electoral-bar">
								<div id="rses-electoral-import-bar-fill" class="rses-progress-fill" style="width:<?php echo esc_attr( (string) (int) ( $active_job['import_progress'] ?? 0 ) ); ?>%"></div>
							</div>
							<p class="rses-electoral-progress-meta">
								<span id="rses-electoral-import-percent"><?php echo esc_html( (string) (int) ( $active_job['import_progress'] ?? 0 ) ); ?>%</span>
								— <span id="rses-electoral-percent"><?php echo esc_html( (string) (int) $active_job['progress'] ); ?>%</span>
								— <span id="rses-electoral-stage"><?php echo esc_html( (string) ( $active_job['stage_label'] ?: $active_job['stage'] ) ); ?></span>
							</p>
							<ul class="rses-electoral-counters" aria-live="polite">
								<li><span><?php esc_html_e( 'Criados', 'relatasoft-secure-election-suite' ); ?></span> <strong id="rses-electoral-created"><?php echo esc_html( (string) (int) $active_job['created'] ); ?></strong></li>
								<li><span><?php esc_html_e( 'Atualizados', 'relatasoft-secure-election-suite' ); ?></span> <strong id="rses-electoral-updated"><?php echo esc_html( (string) (int) $active_job['updated'] ); ?></strong></li>
								<li><span><?php esc_html_e( 'Ignorados', 'relatasoft-secure-election-suite' ); ?></span> <strong id="rses-electoral-skipped"><?php echo esc_html( (string) (int) $active_job['skipped'] ); ?></strong></li>
								<li><span><?php esc_html_e( 'Erros', 'relatasoft-secure-election-suite' ); ?></span> <strong id="rses-electoral-error-count"><?php echo esc_html( (string) (int) $active_job['error_count'] ); ?></strong></li>
							</ul>
							<p>
								<button type="button" class="button rses-btn-secondary" id="rses-electoral-cancel"><?php esc_html_e( 'Cancelar', 'relatasoft-secure-election-suite' ); ?></button>
							</p>
						</div>
					</div>

					<form
						method="post"
						action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
						id="rses-electoral-form"
						class="rses-form rses-electoral-form"
						enctype="multipart/form-data"
						data-rses-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
						data-rses-nonce="<?php echo esc_attr( wp_create_nonce( self::AJAX_NONCE_ACTION ) ); ?>"
						data-rses-max-bytes="<?php echo esc_attr( (string) ElectoralRollImportJob::MAX_UPLOAD_BYTES ); ?>"
						data-rses-chunk-bytes="<?php echo esc_attr( (string) $chunk_bytes ); ?>"
						data-rses-php-upload-max="<?php echo esc_attr( (string) $php_ceiling ); ?>"
					>
						<?php Nonce::rses_field( Nonce::RSES_ACTION_ELECTORAL_ROLL_IMPORT ); ?>

						<label class="rses-electoral-dropzone" for="rses_electoral_roll_csv" id="rses-electoral-dropzone">
							<span class="rses-electoral-dropzone-title"><?php esc_html_e( 'Arquivo .rsv', 'relatasoft-secure-election-suite' ); ?></span>
							<span class="rses-electoral-dropzone-hint" id="rses-electoral-file-label"><?php esc_html_e( 'Escolher um arquivo ou soltá-lo aqui', 'relatasoft-secure-election-suite' ); ?></span>
							<input type="file" name="rses_electoral_roll_csv" id="rses_electoral_roll_csv" accept=".rsv,text/plain" required />
						</label>

						<p class="rses-electoral-option">
							<label>
								<input type="checkbox" name="rses_update_existing" id="rses_update_existing" value="1" checked />
								<?php esc_html_e( 'Atualizar registros existentes quando o login ou IDs coincidirem. Senha vazia no update mantém a senha atual.', 'relatasoft-secure-election-suite' ); ?>
							</label>
						</p>

						<p class="submit">
							<button type="submit" class="button button-primary" id="rses-electoral-submit">
								<?php esc_html_e( 'Importar cadastro .rsv', 'relatasoft-secure-election-suite' ); ?>
							</button>
						</p>
					</form>
				</section>

				<section class="rses-panel rses-panel-card rses-electoral-export">
					<header class="rses-panel-header">
						<p class="rses-panel-kicker"><?php esc_html_e( 'Exportar', 'relatasoft-secure-election-suite' ); ?></p>
						<h2 class="rses-panel-title"><?php esc_html_e( 'Exportar .rsv', 'relatasoft-secure-election-suite' ); ?></h2>
						<p class="rses-panel-desc">
							<?php esc_html_e( 'Exportar um papel de cada vez. O campo senha sai vazio (hashes não são recuperáveis).', 'relatasoft-secure-election-suite' ); ?>
						</p>
					</header>

					<div
						id="rses-electoral-export-progress"
						class="rses-electoral-progress is-idle"
						<?php echo ! empty( $export_job['active'] ) || ! empty( $export_job['download_ready'] ) ? '' : 'hidden'; ?>
					>
						<div class="rses-panel rses-panel-info rses-electoral-progress-inner" id="rses-electoral-export-alert">
							<p id="rses-electoral-export-message"><?php echo esc_html( (string) ( $export_job['message'] ?: '' ) ); ?></p>
							<div class="rses-progress-bar rses-electoral-bar">
								<div id="rses-electoral-export-bar-fill" class="rses-progress-fill" style="width:<?php echo esc_attr( (string) (int) $export_job['progress'] ); ?>%"></div>
							</div>
							<p class="rses-electoral-progress-meta">
								<span id="rses-electoral-export-percent"><?php echo esc_html( (string) (int) $export_job['progress'] ); ?>%</span>
								— <span id="rses-electoral-export-stage"><?php echo esc_html( (string) ( $export_job['stage_label'] ?: $export_job['stage'] ) ); ?></span>
							</p>
							<p id="rses-electoral-export-download-wrap" <?php echo empty( $export_job['download_ready'] ) ? 'hidden' : ''; ?>>
								<a class="button button-primary" id="rses-electoral-export-download" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=rses_download_electoral_roll_export' ), Nonce::RSES_ACTION_ELECTORAL_ROLL_SAMPLE, '_rses_nonce' ) ); ?>">
									<?php esc_html_e( 'Baixar .rsv', 'relatasoft-secure-election-suite' ); ?>
								</a>
							</p>
							<p>
								<button type="button" class="button rses-btn-secondary" id="rses-electoral-export-cancel"><?php esc_html_e( 'Cancelar', 'relatasoft-secure-election-suite' ); ?></button>
							</p>
						</div>
					</div>

					<form id="rses-electoral-export-form" class="rses-form">
						<div class="rses-field-grid">
							<div class="rses-field">
								<label class="rses-field-label" for="rses_export_role"><?php esc_html_e( 'Papel', 'relatasoft-secure-election-suite' ); ?></label>
								<select name="wp_role" id="rses_export_role" required>
									<?php foreach ( $export_roles as $wp_role => $papel ) : ?>
										<option value="<?php echo esc_attr( $wp_role ); ?>">
											<?php echo esc_html( $papel . ' (' . $wp_role . ')' ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="rses-field">
								<label class="rses-field-label" for="rses_export_max_lines"><?php esc_html_e( 'Máx. linhas de dados', 'relatasoft-secure-election-suite' ); ?></label>
								<input type="number" min="1" max="<?php echo esc_attr( (string) ElectoralRollExportService::MAX_LINES ); ?>" value="5000" id="rses_export_max_lines" name="max_lines" />
							</div>
						</div>
						<p class="description">
							<?php esc_html_e( 'Estimativa de tamanho:', 'relatasoft-secure-election-suite' ); ?>
							<strong id="rses-export-estimate"><?php echo esc_html( size_format( ElectoralRollExportService::rses_estimate_bytes( 5000 ) ) ); ?></strong>
						</p>
						<p class="submit">
							<button type="submit" class="button button-primary" id="rses-electoral-export-submit">
								<?php esc_html_e( 'Exportar cadastro .rsv', 'relatasoft-secure-election-suite' ); ?>
							</button>
						</p>
					</form>
				</section>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Legacy synchronous upload (noscript fallback).
	 */
	public static function rses_handle_import(): void {
		Capability::rses_require_admin();
		ModeLock::rses_require_mode( ModeLock::RSES_MODE_VOTING );
		Nonce::rses_verify_or_die( Nonce::RSES_ACTION_ELECTORAL_ROLL_IMPORT );

		if ( empty( $_FILES['rses_electoral_roll_csv']['tmp_name'] ) ) {
			wp_die( esc_html__( 'Nenhum arquivo RSV enviado.', 'relatasoft-secure-election-suite' ) );
		}

		$tmp = (string) $_FILES['rses_electoral_roll_csv']['tmp_name']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! is_uploaded_file( $tmp ) ) {
			wp_die( esc_html__( 'Invalid upload.', 'relatasoft-secure-election-suite' ) );
		}

		$update = ! empty( $_POST['rses_update_existing'] );
		$result = ElectoralRollImportService::rses_import_file( $tmp, $update );

		$key = self::ERRORS_TRANSIENT_PREFIX . get_current_user_id();
		if ( ! empty( $result['errors'] ) ) {
			set_transient( $key, array_values( $result['errors'] ), 30 * MINUTE_IN_SECONDS );
		} else {
			delete_transient( $key );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'               => 'rses-electoral-roll',
					'rses_import_done'   => '1',
					'rses_import_errors' => empty( $result['errors'] ) ? '0' : '1',
					'created'            => (int) $result['created'],
					'updated'            => (int) $result['updated'],
					'skipped'            => (int) $result['skipped'],
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Sample CSV download with localized filename.
	 */
	public static function rses_handle_sample(): void {
		Capability::rses_require_admin();
		ModeLock::rses_require_mode( ModeLock::RSES_MODE_VOTING );
		Nonce::rses_verify_or_die( Nonce::RSES_ACTION_ELECTORAL_ROLL_SAMPLE );

		$csv      = ElectoralRollImportService::rses_sample_csv();
		$filename = ElectoralRollImportService::rses_sample_filename();

		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $csv ) );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Download the last import error report as RSV.
	 */
	public static function rses_handle_errors_download(): void {
		Capability::rses_require_admin();
		ModeLock::rses_require_mode( ModeLock::RSES_MODE_VOTING );
		Nonce::rses_verify_or_die( Nonce::RSES_ACTION_ELECTORAL_ROLL_ERRORS );

		$raw    = get_transient( self::ERRORS_TRANSIENT_PREFIX . get_current_user_id() );
		$errors = is_array( $raw ) ? $raw : array();
		$csv    = ElectoralRollImportService::rses_errors_csv( $errors );

		$locale      = \RelataSoft\SecureElectionSuite\I18n\LocaleResolver::rses_resolve();
		$error_stems = array(
			'pt_BR' => 'erros',
			'pt_PT' => 'erros',
			'en_US' => 'errors',
			'es_ES' => 'errores',
			'ca'    => 'errors',
			'fr_FR' => 'erreurs',
			'de_DE' => 'fehler',
			'nl_NL' => 'fouten',
			'ru_RU' => 'oshibki',
			'zh_CN' => 'cuowu',
			'ar'    => 'akhata',
			'he_IL' => 'shgiot',
		);
		$error_stem = $error_stems[ $locale ] ?? 'errors';
		$filename   = $error_stem . '-cadastro-eleitoral.rsv';

		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $csv ) );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
}
