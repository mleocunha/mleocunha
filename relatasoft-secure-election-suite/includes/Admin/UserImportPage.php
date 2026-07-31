<?php
/**
 * Admin page: CSV user / elector import.
 *
 * @package RelataSoft\SecureElectionSuite\Admin
 */

namespace RelataSoft\SecureElectionSuite\Admin;

use RelataSoft\SecureElectionSuite\Bootstrap\ModeLock;
use RelataSoft\SecureElectionSuite\I18n\RoleLabels;
use RelataSoft\SecureElectionSuite\I18n\Translator;
use RelataSoft\SecureElectionSuite\Security\Capability;
use RelataSoft\SecureElectionSuite\Security\Nonce;
use RelataSoft\SecureElectionSuite\Voting\UserImportService;

defined( 'ABSPATH' ) || exit;

/**
 * Voting-mode CSV importer UI.
 */
class UserImportPage {

	/**
	 * Register handlers.
	 */
	public static function register(): void {
		add_action( 'admin_post_rses_import_users_csv', array( self::class, 'rses_handle_import' ) );
		add_action( 'admin_post_rses_download_users_csv_sample', array( self::class, 'rses_handle_sample' ) );
	}

	/**
	 * Render import page.
	 */
	public static function rses_render(): void {
		Capability::rses_require_admin();
		ModeLock::rses_require_mode( ModeLock::RSES_MODE_VOTING );

		$electors = RoleLabels::rses_elector_plural();
		$headers  = UserImportService::rses_sample_headers();
		?>
		<div class="wrap rses-wrap rses-screen" <?php echo Translator::rses_html_attrs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<header class="rses-hero rses-hero--brand">
				<?php Brand::rses_render_hero_brand(); ?>
				<p class="rses-hero-kicker"><?php esc_html_e( 'Voting Platform', 'relatasoft-secure-election-suite' ); ?></p>
				<h1 class="rses-hero-title"><?php esc_html_e( 'Import users', 'relatasoft-secure-election-suite' ); ?></h1>
				<p class="rses-hero-lead">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: electors role label */
							__( 'Upload a CSV to create or update %s. The last column must be senha; passwords are stored as provided without WordPress strength checks or confirmation prompts.', 'relatasoft-secure-election-suite' ),
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
				?>
				<div class="rses-panel rses-panel-success">
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: created, 2: updated, 3: skipped */
								__( 'Import finished. Created: %1$d. Updated: %2$d. Skipped: %3$d.', 'relatasoft-secure-election-suite' ),
								$created,
								$updated,
								$skipped
							)
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $_GET['rses_import_errors'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<?php
				$raw = get_transient( 'rses_user_import_errors_' . get_current_user_id() );
				$errs = is_array( $raw ) ? $raw : array();
				delete_transient( 'rses_user_import_errors_' . get_current_user_id() );
				?>
				<?php if ( ! empty( $errs ) ) : ?>
					<div class="rses-panel rses-panel-warning">
						<p><strong><?php esc_html_e( 'Some rows reported issues:', 'relatasoft-secure-election-suite' ); ?></strong></p>
						<ul>
							<?php foreach ( array_slice( $errs, 0, 50 ) as $err ) : ?>
								<li><?php echo esc_html( (string) $err ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>
			<?php endif; ?>

			<section class="rses-panel rses-panel-card">
				<h2 class="rses-panel-title"><?php esc_html_e( 'CSV format', 'relatasoft-secure-election-suite' ); ?></h2>
				<p class="rses-panel-desc">
					<?php esc_html_e( 'Required columns: login, email, and senha as the final column. Optional: nome (display name), first_name, last_name, role (defaults to subscriber / Electors).', 'relatasoft-secure-election-suite' ); ?>
				</p>
				<p><code><?php echo esc_html( implode( ',', $headers ) ); ?></code></p>
				<p>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=rses_download_users_csv_sample' ), Nonce::RSES_ACTION_USER_IMPORT_SAMPLE, '_rses_nonce' ) ); ?>">
						<?php esc_html_e( 'Download sample CSV', 'relatasoft-secure-election-suite' ); ?>
					</a>
				</p>
			</section>

			<section class="rses-panel rses-panel-card">
				<h2 class="rses-panel-title"><?php esc_html_e( 'Upload CSV', 'relatasoft-secure-election-suite' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="rses-form">
					<?php Nonce::rses_field( Nonce::RSES_ACTION_USER_IMPORT ); ?>
					<input type="hidden" name="action" value="rses_import_users_csv" />
					<p>
						<label for="rses_users_csv"><strong><?php esc_html_e( 'CSV file', 'relatasoft-secure-election-suite' ); ?></strong></label><br />
						<input type="file" name="rses_users_csv" id="rses_users_csv" accept=".csv,text/csv" required />
					</p>
					<p>
						<label>
							<input type="checkbox" name="rses_update_existing" value="1" checked />
							<?php esc_html_e( 'Update existing users when login or email matches (including senha).', 'relatasoft-secure-election-suite' ); ?>
						</label>
					</p>
					<?php submit_button( __( 'Import users', 'relatasoft-secure-election-suite' ), 'primary' ); ?>
				</form>
			</section>
		</div>
		<?php
	}

	/**
	 * Handle CSV upload.
	 */
	public static function rses_handle_import(): void {
		Capability::rses_require_admin();
		ModeLock::rses_require_mode( ModeLock::RSES_MODE_VOTING );
		Nonce::rses_verify_or_die( Nonce::RSES_ACTION_USER_IMPORT );

		if ( empty( $_FILES['rses_users_csv']['tmp_name'] ) ) {
			wp_die( esc_html__( 'No CSV file uploaded.', 'relatasoft-secure-election-suite' ) );
		}

		$tmp = (string) $_FILES['rses_users_csv']['tmp_name']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- path from PHP upload.
		if ( ! is_uploaded_file( $tmp ) ) {
			wp_die( esc_html__( 'Invalid upload.', 'relatasoft-secure-election-suite' ) );
		}

		$update = ! empty( $_POST['rses_update_existing'] );
		$result = UserImportService::rses_import_file( $tmp, $update );

		if ( ! empty( $result['errors'] ) ) {
			set_transient( 'rses_user_import_errors_' . get_current_user_id(), $result['errors'], 10 * MINUTE_IN_SECONDS );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => 'rses-user-import',
					'rses_import_done' => '1',
					'rses_import_errors' => empty( $result['errors'] ) ? '0' : '1',
					'created'          => (int) $result['created'],
					'updated'          => (int) $result['updated'],
					'skipped'          => (int) $result['skipped'],
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Download sample CSV.
	 */
	public static function rses_handle_sample(): void {
		Capability::rses_require_admin();
		ModeLock::rses_require_mode( ModeLock::RSES_MODE_VOTING );
		Nonce::rses_verify_or_die( Nonce::RSES_ACTION_USER_IMPORT_SAMPLE );

		$csv = UserImportService::rses_sample_csv();
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=rses-users-sample.csv' );
		header( 'Content-Length: ' . strlen( $csv ) );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download.
		exit;
	}
}
