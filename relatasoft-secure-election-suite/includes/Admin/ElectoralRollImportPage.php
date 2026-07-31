<?php
/**
 * Admin: Importador de cadastro eleitoral.
 *
 * @package RelataSoft\SecureElectionSuite\Admin
 */

namespace RelataSoft\SecureElectionSuite\Admin;

use RelataSoft\SecureElectionSuite\Bootstrap\ModeLock;
use RelataSoft\SecureElectionSuite\I18n\RoleLabels;
use RelataSoft\SecureElectionSuite\I18n\Translator;
use RelataSoft\SecureElectionSuite\Security\Capability;
use RelataSoft\SecureElectionSuite\Security\Nonce;
use RelataSoft\SecureElectionSuite\Voting\ElectoralRollImportService;

defined( 'ABSPATH' ) || exit;

/**
 * Electoral roll CSV import screen (Voting Platform).
 */
class ElectoralRollImportPage {

	public const ERRORS_TRANSIENT_PREFIX = 'rses_electoral_roll_errors_';

	/**
	 * Register admin-post handlers.
	 */
	public static function register(): void {
		add_action( 'admin_post_rses_import_electoral_roll', array( self::class, 'rses_handle_import' ) );
		add_action( 'admin_post_rses_download_electoral_roll_sample', array( self::class, 'rses_handle_sample' ) );
		add_action( 'admin_post_rses_download_electoral_roll_errors', array( self::class, 'rses_handle_errors_download' ) );
	}

	/**
	 * Render page.
	 */
	public static function rses_render(): void {
		Capability::rses_require_admin();
		ModeLock::rses_require_mode( ModeLock::RSES_MODE_VOTING );

		$electors       = RoleLabels::rses_elector_plural();
		$headers        = ElectoralRollImportService::rses_expected_headers();
		$sample_name    = ElectoralRollImportService::rses_sample_filename();
		$errors         = array();
		$show_errors    = ! empty( $_GET['rses_import_errors'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $show_errors ) {
			$raw = get_transient( self::ERRORS_TRANSIENT_PREFIX . get_current_user_id() );
			$errors = is_array( $raw ) ? $raw : array();
		}
		?>
		<div class="wrap rses-wrap rses-screen" <?php echo Translator::rses_html_attrs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
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

			<?php if ( ! empty( $_GET['rses_import_done'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<?php
				$created = isset( $_GET['created'] ) ? absint( $_GET['created'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$updated = isset( $_GET['updated'] ) ? absint( $_GET['updated'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$skipped = isset( $_GET['skipped'] ) ? absint( $_GET['skipped'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$err_n   = count( $errors );
				?>
				<div class="rses-panel <?php echo $err_n > 0 ? 'rses-panel-warning' : 'rses-panel-success'; ?>">
					<p>
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
			<?php endif; ?>

			<?php if ( ! empty( $errors ) ) : ?>
				<section class="rses-panel rses-panel-card rses-electoral-errors">
					<div class="rses-panel-header">
						<h2 class="rses-panel-title"><?php esc_html_e( 'Import errors', 'relatasoft-secure-election-suite' ); ?></h2>
						<p class="rses-panel-desc">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: error count */
									__( '%d issue(s) were reported. Review the table below or download the error CSV.', 'relatasoft-secure-election-suite' ),
									count( $errors )
								)
							);
							?>
						</p>
					</div>
					<p>
						<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=rses_download_electoral_roll_errors' ), Nonce::RSES_ACTION_ELECTORAL_ROLL_ERRORS, '_rses_nonce' ) ); ?>">
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
							<tbody>
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
			<?php endif; ?>

			<section class="rses-panel rses-panel-card">
				<h2 class="rses-panel-title"><?php esc_html_e( 'Spreadsheet model', 'relatasoft-secure-election-suite' ); ?></h2>
				<p class="rses-panel-desc">
					<?php esc_html_e( 'Compatible with the Brazilian test roll columns (user_login … shipping_postcode). Append password as the rightmost column. Role “customer” is mapped to Electors (subscriber).', 'relatasoft-secure-election-suite' ); ?>
				</p>
				<p class="description" style="word-break:break-all;"><code><?php echo esc_html( implode( ',', $headers ) ); ?></code></p>
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
					<a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=rses_download_electoral_roll_sample' ), Nonce::RSES_ACTION_ELECTORAL_ROLL_SAMPLE, '_rses_nonce' ) ); ?>">
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

			<section class="rses-panel rses-panel-card">
				<h2 class="rses-panel-title"><?php esc_html_e( 'Upload electoral roll', 'relatasoft-secure-election-suite' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="rses-form">
					<?php Nonce::rses_field( Nonce::RSES_ACTION_ELECTORAL_ROLL_IMPORT ); ?>
					<input type="hidden" name="action" value="rses_import_electoral_roll" />
					<p>
						<label for="rses_electoral_roll_csv"><strong><?php esc_html_e( 'CSV file', 'relatasoft-secure-election-suite' ); ?></strong></label><br />
						<input type="file" name="rses_electoral_roll_csv" id="rses_electoral_roll_csv" accept=".csv,text/csv" required />
					</p>
					<p>
						<label>
							<input type="checkbox" name="rses_update_existing" value="1" checked />
							<?php esc_html_e( 'Update existing records when user_login or user_email matches (including password).', 'relatasoft-secure-election-suite' ); ?>
						</label>
					</p>
					<?php submit_button( __( 'Import electoral roll', 'relatasoft-secure-election-suite' ), 'primary' ); ?>
				</form>
			</section>
		</div>
		<?php
	}

	/**
	 * Handle upload.
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
