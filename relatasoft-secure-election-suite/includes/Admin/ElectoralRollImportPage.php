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
use RelataSoft\SecureElectionSuite\Security\Capability;
use RelataSoft\SecureElectionSuite\Security\Nonce;
use RelataSoft\SecureElectionSuite\Voting\ElectoralRollImportJob;
use RelataSoft\SecureElectionSuite\Voting\ElectoralRollImportService;

defined( 'ABSPATH' ) || exit;

/**
 * Electoral roll CSV import screen (Voting Platform).
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

		add_action( 'wp_ajax_rses_electoral_roll_init', array( self::class, 'rses_ajax_init' ) );
		add_action( 'wp_ajax_rses_electoral_roll_chunk', array( self::class, 'rses_ajax_chunk' ) );
		add_action( 'wp_ajax_rses_electoral_roll_begin', array( self::class, 'rses_ajax_begin' ) );
		add_action( 'wp_ajax_rses_electoral_roll_tick', array( self::class, 'rses_ajax_tick' ) );
		add_action( 'wp_ajax_rses_electoral_roll_status', array( self::class, 'rses_ajax_status' ) );
		add_action( 'wp_ajax_rses_electoral_roll_cancel', array( self::class, 'rses_ajax_cancel' ) );

		add_action( 'admin_init', array( ElectoralRollImportJob::class, 'rses_purge_if_expired' ) );
	}

	/**
	 * Shared AJAX guard.
	 */
	private static function rses_ajax_guard(): void {
		Capability::rses_require_admin();
		ModeLock::rses_require_mode( ModeLock::RSES_MODE_VOTING );
		check_ajax_referer( self::AJAX_NONCE_ACTION, 'nonce' );
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

		$job = ElectoralRollImportJob::rses_create_receiving( $original, $chunks, $bytes, $update );
		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ), 400 );
		}

		wp_send_json_success( ElectoralRollImportJob::rses_public_status( $job ) );
	}

	/**
	 * AJAX: append one file chunk.
	 */
	public static function rses_ajax_chunk(): void {
		self::rses_ajax_guard();

		$index = isset( $_POST['chunk_index'] ) ? absint( $_POST['chunk_index'] ) : -1;
		if ( $index < 0 || empty( $_FILES['chunk']['tmp_name'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid upload chunk.', 'relatasoft-secure-election-suite' ) ), 400 );
		}

		$tmp = (string) $_FILES['chunk']['tmp_name']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! is_uploaded_file( $tmp ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid upload chunk.', 'relatasoft-secure-election-suite' ) ), 400 );
		}

		$job = ElectoralRollImportJob::rses_append_chunk( $tmp, $index );
		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ), 400 );
		}

		wp_send_json_success( ElectoralRollImportJob::rses_public_status( $job ) );
	}

	/**
	 * AJAX: validate CSV and start importing.
	 */
	public static function rses_ajax_begin(): void {
		self::rses_ajax_guard();

		$job = ElectoralRollImportJob::rses_begin_import();
		if ( is_wp_error( $job ) ) {
			$current = ElectoralRollImportJob::rses_get();
			wp_send_json_error(
				array(
					'message' => $job->get_error_message(),
					'status'  => ElectoralRollImportJob::rses_public_status( $current ),
				),
				400
			);
		}

		wp_send_json_success( ElectoralRollImportJob::rses_public_status( $job ) );
	}

	/**
	 * AJAX: process one batch.
	 */
	public static function rses_ajax_tick(): void {
		self::rses_ajax_guard();

		$job = ElectoralRollImportJob::rses_tick();
		if ( is_wp_error( $job ) ) {
			$current = ElectoralRollImportJob::rses_get();
			$status  = ElectoralRollImportJob::rses_public_status( $current );
			self::rses_store_errors_from_status( $status );
			wp_send_json_error(
				array(
					'message' => $job->get_error_message(),
					'status'  => $status,
				),
				400
			);
		}

		$status = ElectoralRollImportJob::rses_public_status( $job );
		if ( in_array( $status['stage'], array( ElectoralRollImportJob::STAGE_COMPLETE, ElectoralRollImportJob::STAGE_FAILED ), true ) ) {
			self::rses_store_errors_from_status( $status );
		}

		wp_send_json_success( $status );
	}

	/**
	 * AJAX: status.
	 */
	public static function rses_ajax_status(): void {
		self::rses_ajax_guard();
		wp_send_json_success( ElectoralRollImportJob::rses_public_status( ElectoralRollImportJob::rses_get() ) );
	}

	/**
	 * AJAX: cancel.
	 */
	public static function rses_ajax_cancel(): void {
		self::rses_ajax_guard();
		$job = ElectoralRollImportJob::rses_cancel();
		wp_send_json_success( ElectoralRollImportJob::rses_public_status( $job ) );
	}

	/**
	 * Render page.
	 */
	public static function rses_render(): void {
		Capability::rses_require_admin();
		ModeLock::rses_require_mode( ModeLock::RSES_MODE_VOTING );
		Plugin::rses_enqueue_electoral_roll_script();

		$electors    = RoleLabels::rses_elector_plural();
		$headers     = ElectoralRollImportService::rses_expected_headers();
		$sample_name = ElectoralRollImportService::rses_sample_filename();
		$errors      = array();
		$show_errors = ! empty( $_GET['rses_import_errors'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $show_errors ) {
			$raw    = get_transient( self::ERRORS_TRANSIENT_PREFIX . get_current_user_id() );
			$errors = is_array( $raw ) ? $raw : array();
		}

		$active_job = ElectoralRollImportJob::rses_public_status( ElectoralRollImportJob::rses_get() );
		$max_label  = number_format_i18n( ElectoralRollImportService::MAX_ROWS );
		$max_size   = size_format( ElectoralRollImportJob::MAX_UPLOAD_BYTES );
		?>
		<div class="wrap rses-wrap rses-screen rses-electoral-roll" <?php echo Translator::rses_html_attrs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<header class="rses-hero rses-hero--brand">
				<?php Brand::rses_render_hero_brand(); ?>
				<p class="rses-hero-kicker"><?php esc_html_e( 'Voting Platform', 'relatasoft-secure-election-suite' ); ?></p>
				<h1 class="rses-hero-title"><?php esc_html_e( 'Electoral roll import', 'relatasoft-secure-election-suite' ); ?></h1>
				<p class="rses-hero-lead">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: electors label */
							__( 'Import the electoral registration spreadsheet used for %s. Keep the existing columns and add password as the last column on the right. Passwords are accepted as provided, without WordPress strength checks.', 'relatasoft-secure-election-suite' ),
							$electors
						)
					);
					?>
				</p>
			</header>

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
									__( 'Electoral roll import finished. Created: %1$d. Updated: %2$d. Skipped: %3$d. Errors: %4$d.', 'relatasoft-secure-election-suite' ),
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
						<h2 class="rses-panel-title"><?php esc_html_e( 'Import errors', 'relatasoft-secure-election-suite' ); ?></h2>
						<p class="rses-panel-desc" id="rses-electoral-errors-desc">
							<?php
							if ( ! empty( $errors ) ) {
								echo esc_html(
									sprintf(
										/* translators: %d: error count */
										__( '%d issue(s) were reported. Review the table below or download the error CSV.', 'relatasoft-secure-election-suite' ),
										count( $errors )
									)
								);
							}
							?>
						</p>
					</div>
					<p>
						<a class="button" id="rses-electoral-errors-download" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=rses_download_electoral_roll_errors' ), Nonce::RSES_ACTION_ELECTORAL_ROLL_ERRORS, '_rses_nonce' ) ); ?>">
							<?php esc_html_e( 'Download error report CSV', 'relatasoft-secure-election-suite' ); ?>
						</a>
					</p>
					<div class="rses-security-table-scroll rses-electoral-errors-scroll">
						<table class="widefat striped">
							<thead>
								<tr>
									<th style="width:4rem"><?php esc_html_e( '#', 'relatasoft-secure-election-suite' ); ?></th>
									<th><?php esc_html_e( 'Message', 'relatasoft-secure-election-suite' ); ?></th>
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
						<p class="rses-panel-kicker"><?php esc_html_e( 'Template', 'relatasoft-secure-election-suite' ); ?></p>
						<h2 class="rses-panel-title"><?php esc_html_e( 'Spreadsheet model', 'relatasoft-secure-election-suite' ); ?></h2>
						<p class="rses-panel-desc">
							<?php esc_html_e( 'Compatible with the Brazilian test roll columns (user_login … shipping_postcode). Append password as the rightmost column. Role “customer” is mapped to Electors (subscriber).', 'relatasoft-secure-election-suite' ); ?>
						</p>
					</header>

					<ul class="rses-electoral-meta">
						<li>
							<span class="rses-electoral-meta-label"><?php esc_html_e( 'Max rows', 'relatasoft-secure-election-suite' ); ?></span>
							<strong><?php echo esc_html( $max_label ); ?></strong>
						</li>
						<li>
							<span class="rses-electoral-meta-label"><?php esc_html_e( 'Max upload', 'relatasoft-secure-election-suite' ); ?></span>
							<strong><?php echo esc_html( $max_size ); ?></strong>
						</li>
						<li>
							<span class="rses-electoral-meta-label"><?php esc_html_e( 'Transport', 'relatasoft-secure-election-suite' ); ?></span>
							<strong><?php esc_html_e( 'Chunked AJAX', 'relatasoft-secure-election-suite' ); ?></strong>
						</li>
					</ul>

					<div class="rses-electoral-headers" tabindex="0">
						<code><?php echo esc_html( implode( ',', $headers ) ); ?></code>
					</div>

					<p class="rses-panel-desc">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: localized example filename */
								__( 'Download %s — one metadata (header) line and 10 example data rows.', 'relatasoft-secure-election-suite' ),
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
									__( 'Download %s', 'relatasoft-secure-election-suite' ),
									$sample_name
								)
							);
							?>
						</a>
					</p>
				</section>

				<section class="rses-panel rses-panel-card rses-electoral-upload">
					<header class="rses-panel-header">
						<p class="rses-panel-kicker"><?php esc_html_e( 'Import', 'relatasoft-secure-election-suite' ); ?></p>
						<h2 class="rses-panel-title"><?php esc_html_e( 'Upload electoral roll', 'relatasoft-secure-election-suite' ); ?></h2>
						<p class="rses-panel-desc">
							<?php esc_html_e( 'Large files are uploaded in chunks and imported in small batches with a live progress bar, so PHP time limits and upload size caps are less likely to interrupt the job.', 'relatasoft-secure-election-suite' ); ?>
						</p>
					</header>

					<div
						id="rses-electoral-progress"
						class="rses-electoral-progress is-idle"
						<?php echo ! empty( $active_job['active'] ) ? '' : 'hidden'; ?>
					>
						<div class="rses-panel rses-panel-info rses-electoral-progress-inner">
							<p id="rses-electoral-message"><?php echo esc_html( (string) ( $active_job['message'] ?: __( 'Preparing…', 'relatasoft-secure-election-suite' ) ) ); ?></p>
							<div class="rses-progress-bar rses-electoral-bar">
								<div id="rses-electoral-bar-fill" class="rses-progress-fill" style="width:<?php echo esc_attr( (string) (int) $active_job['progress'] ); ?>%"></div>
							</div>
							<p class="rses-electoral-progress-meta">
								<span id="rses-electoral-percent"><?php echo esc_html( (string) (int) $active_job['progress'] ); ?>%</span>
								— <span id="rses-electoral-stage"><?php echo esc_html( (string) $active_job['stage'] ); ?></span>
							</p>
							<ul class="rses-electoral-counters" aria-live="polite">
								<li><span><?php esc_html_e( 'Created', 'relatasoft-secure-election-suite' ); ?></span> <strong id="rses-electoral-created"><?php echo esc_html( (string) (int) $active_job['created'] ); ?></strong></li>
								<li><span><?php esc_html_e( 'Updated', 'relatasoft-secure-election-suite' ); ?></span> <strong id="rses-electoral-updated"><?php echo esc_html( (string) (int) $active_job['updated'] ); ?></strong></li>
								<li><span><?php esc_html_e( 'Skipped', 'relatasoft-secure-election-suite' ); ?></span> <strong id="rses-electoral-skipped"><?php echo esc_html( (string) (int) $active_job['skipped'] ); ?></strong></li>
								<li><span><?php esc_html_e( 'Errors', 'relatasoft-secure-election-suite' ); ?></span> <strong id="rses-electoral-error-count"><?php echo esc_html( (string) (int) $active_job['error_count'] ); ?></strong></li>
							</ul>
							<p>
								<button type="button" class="button rses-btn-secondary" id="rses-electoral-cancel"><?php esc_html_e( 'Cancel', 'relatasoft-secure-election-suite' ); ?></button>
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
						data-rses-chunk-bytes="262144"
					>
						<?php Nonce::rses_field( Nonce::RSES_ACTION_ELECTORAL_ROLL_IMPORT ); ?>

						<label class="rses-electoral-dropzone" for="rses_electoral_roll_csv" id="rses-electoral-dropzone">
							<span class="rses-electoral-dropzone-title"><?php esc_html_e( 'CSV file', 'relatasoft-secure-election-suite' ); ?></span>
							<span class="rses-electoral-dropzone-hint" id="rses-electoral-file-label"><?php esc_html_e( 'Choose a file or drop it here', 'relatasoft-secure-election-suite' ); ?></span>
							<input type="file" name="rses_electoral_roll_csv" id="rses_electoral_roll_csv" accept=".csv,text/csv" required />
						</label>

						<p class="rses-electoral-option">
							<label>
								<input type="checkbox" name="rses_update_existing" id="rses_update_existing" value="1" checked />
								<?php esc_html_e( 'Update existing records when user_login or user_email matches (including password).', 'relatasoft-secure-election-suite' ); ?>
							</label>
						</p>

						<p class="submit">
							<button type="submit" class="button button-primary" id="rses-electoral-submit">
								<?php esc_html_e( 'Import electoral roll', 'relatasoft-secure-election-suite' ); ?>
							</button>
						</p>
					</form>

					<noscript>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="rses-form">
							<?php Nonce::rses_field( Nonce::RSES_ACTION_ELECTORAL_ROLL_IMPORT ); ?>
							<input type="hidden" name="action" value="rses_import_electoral_roll" />
							<p><?php esc_html_e( 'JavaScript is required for chunked import. Without it, a single synchronous upload is used and may time out on large files.', 'relatasoft-secure-election-suite' ); ?></p>
							<p>
								<input type="file" name="rses_electoral_roll_csv" accept=".csv,text/csv" required />
							</p>
							<p>
								<label>
									<input type="checkbox" name="rses_update_existing" value="1" checked />
									<?php esc_html_e( 'Update existing records when user_login or user_email matches (including password).', 'relatasoft-secure-election-suite' ); ?>
								</label>
							</p>
							<?php submit_button( __( 'Import electoral roll', 'relatasoft-secure-election-suite' ), 'primary' ); ?>
						</form>
					</noscript>
				</section>
			</div>
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
			wp_die( esc_html__( 'No CSV file uploaded.', 'relatasoft-secure-election-suite' ) );
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
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $csv ) );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Download the last import error report as CSV.
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
		$filename   = $error_stem . '-cadastro-eleitoral.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $csv ) );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
}
