<?php
/**
 * Admin menu registration.
 *
 * @package RelataSoft\SecureElectionSuite\Admin
 */

namespace RelataSoft\SecureElectionSuite\Admin;

use RelataSoft\SecureElectionSuite\Bootstrap\ModeLock;
use RelataSoft\SecureElectionSuite\Crypto\CryptoSelfTest;
use RelataSoft\SecureElectionSuite\KeyAuthority\KeyAuthorityViews;
use RelataSoft\SecureElectionSuite\Security\Capability;
use RelataSoft\SecureElectionSuite\Security\Nonce;
use RelataSoft\SecureElectionSuite\Tallying\TallyingViews;
use RelataSoft\SecureElectionSuite\Voting\VotingViews;

defined( 'ABSPATH' ) || exit;

/**
 * Registers admin menus and pages.
 */
class AdminMenu {

	/**
	 * Register admin hooks.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'rses_register_menus' ) );
		add_action( 'admin_post_rses_run_crypto_self_test', array( self::class, 'rses_handle_crypto_self_test' ) );
	}

	/**
	 * Register admin menu pages.
	 */
	public static function rses_register_menus(): void {
		if ( ! Capability::rses_can_manage_election() && ! Capability::rses_is_election_official() ) {
			return;
		}

		$rses_cap = Capability::rses_can_manage_election() ? 'manage_options' : 'edit_posts';

		add_menu_page(
			__( 'Secure Election Suite', 'relatasoft-secure-election-suite' ),
			__( 'Election Suite', 'relatasoft-secure-election-suite' ),
			$rses_cap,
			'rses-dashboard',
			array( self::class, 'rses_render_dashboard' ),
			'dashicons-privacy',
			30
		);

		// Always available so admins can lock mode or perform destructive reset.
		if ( Capability::rses_can_manage_election() ) {
			add_submenu_page(
				'rses-dashboard',
				__( 'Mode Setup', 'relatasoft-secure-election-suite' ),
				__( 'Mode Setup', 'relatasoft-secure-election-suite' ),
				'manage_options',
				'rses-mode-setup',
				array( ModeSetupPage::class, 'rses_render' )
			);
		}

		if ( ! ModeLock::rses_has_mode() ) {
			return;
		}

		if ( ModeLock::rses_is_mode( ModeLock::RSES_MODE_KEY_AUTHORITY ) ) {
			add_submenu_page(
				'rses-dashboard',
				__( 'Key Authority', 'relatasoft-secure-election-suite' ),
				__( 'Key Authority', 'relatasoft-secure-election-suite' ),
				$rses_cap,
				'rses-key-authority',
				array( KeyAuthorityViews::class, 'rses_render_dashboard' )
			);
		}

		if ( ModeLock::rses_is_mode( ModeLock::RSES_MODE_VOTING ) ) {
			add_submenu_page(
				'rses-dashboard',
				__( 'Public Keys', 'relatasoft-secure-election-suite' ),
				__( 'Public Keys', 'relatasoft-secure-election-suite' ),
				'manage_options',
				'rses-public-keys',
				array( VotingViews::class, 'rses_render_public_keys_page' )
			);

			add_submenu_page(
				'rses-dashboard',
				__( 'Elections', 'relatasoft-secure-election-suite' ),
				__( 'Elections', 'relatasoft-secure-election-suite' ),
				'manage_options',
				'rses-elections',
				array( VotingViews::class, 'rses_render_elections_list' )
			);

			add_submenu_page(
				'rses-dashboard',
				__( 'Voting Export', 'relatasoft-secure-election-suite' ),
				__( 'Voting Export', 'relatasoft-secure-election-suite' ),
				'manage_options',
				'rses-voting-export',
				array( VotingViews::class, 'rses_render_export_page' )
			);
		}

		if ( ModeLock::rses_is_mode( ModeLock::RSES_MODE_TALLYING ) ) {
			add_submenu_page(
				'rses-dashboard',
				__( 'Tally Import', 'relatasoft-secure-election-suite' ),
				__( 'Tally Import', 'relatasoft-secure-election-suite' ),
				$rses_cap,
				'rses-tally-import',
				array( TallyingViews::class, 'rses_render_import_page' )
			);

			add_submenu_page(
				'rses-dashboard',
				__( 'Share Submission', 'relatasoft-secure-election-suite' ),
				__( 'Share Submission', 'relatasoft-secure-election-suite' ),
				$rses_cap,
				'rses-share-submission',
				array( TallyingViews::class, 'rses_render_share_submission_page' )
			);

			add_submenu_page(
				'rses-dashboard',
				__( 'Certification', 'relatasoft-secure-election-suite' ),
				__( 'Certification', 'relatasoft-secure-election-suite' ),
				'manage_options',
				'rses-certification',
				array( TallyingViews::class, 'rses_render_certification_page' )
			);
		}

		if ( Capability::rses_can_manage_election() ) {
			add_submenu_page(
				'rses-dashboard',
				__( 'Settings', 'relatasoft-secure-election-suite' ),
				__( 'Settings', 'relatasoft-secure-election-suite' ),
				'manage_options',
				'rses-settings',
				array( SettingsPage::class, 'rses_render' )
			);

			add_submenu_page(
				'rses-dashboard',
				__( 'Audit Log', 'relatasoft-secure-election-suite' ),
				__( 'Audit Log', 'relatasoft-secure-election-suite' ),
				'manage_options',
				'rses-audit-log',
				array( AuditLogPage::class, 'rses_render' )
			);

			add_submenu_page(
				'rses-dashboard',
				__( 'Crypto Self Test', 'relatasoft-secure-election-suite' ),
				__( 'Crypto Self Test', 'relatasoft-secure-election-suite' ),
				'manage_options',
				'rses-crypto-self-test',
				array( self::class, 'rses_render_crypto_self_test' )
			);
		}
	}

	/**
	 * Render main dashboard with mode-specific actions.
	 */
	public static function rses_render_dashboard(): void {
		if ( ! ModeLock::rses_has_mode() ) {
			ModeSetupPage::rses_render();
			return;
		}

		$rses_mode = ModeLock::rses_get_mode();
		?>
		<div class="wrap rses-wrap">
			<h1><?php esc_html_e( 'RelataSoft Secure Election Suite', 'relatasoft-secure-election-suite' ); ?></h1>

			<?php if ( ! empty( $_GET['rses_mode_set'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Mode locked successfully. Use the actions below to continue.', 'relatasoft-secure-election-suite' ); ?></p>
				</div>
			<?php endif; ?>

			<div class="rses-notice rses-notice-info">
				<p>
					<strong><?php esc_html_e( 'Active Mode:', 'relatasoft-secure-election-suite' ); ?></strong>
					<?php echo esc_html( ModeLock::rses_get_mode_label( $rses_mode ) ); ?>
					— <?php esc_html_e( 'Locked', 'relatasoft-secure-election-suite' ); ?>
				</p>
			</div>

			<div class="rses-dashboard-grid">
				<?php if ( ModeLock::rses_is_mode( ModeLock::RSES_MODE_KEY_AUTHORITY ) ) : ?>
					<div class="rses-dashboard-card">
						<h2><?php esc_html_e( 'Key Authority', 'relatasoft-secure-election-suite' ); ?></h2>
						<p><?php esc_html_e( 'Generate ElGamal keys, split private exponents with Shamir Secret Sharing, and export public keys / shares.', 'relatasoft-secure-election-suite' ); ?></p>
						<p>
							<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=rses-key-authority' ) ); ?>">
								<?php esc_html_e( 'Open Key Authority', 'relatasoft-secure-election-suite' ); ?>
							</a>
						</p>
					</div>
				<?php elseif ( ModeLock::rses_is_mode( ModeLock::RSES_MODE_VOTING ) ) : ?>
					<div class="rses-dashboard-card">
						<h2><?php esc_html_e( '1. Import Public Key', 'relatasoft-secure-election-suite' ); ?></h2>
						<p><?php esc_html_e( 'Import the public key package exported from the Key Authority site.', 'relatasoft-secure-election-suite' ); ?></p>
						<p>
							<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=rses-public-keys' ) ); ?>">
								<?php esc_html_e( 'Manage Public Keys', 'relatasoft-secure-election-suite' ); ?>
							</a>
						</p>
					</div>
					<div class="rses-dashboard-card">
						<h2><?php esc_html_e( '2. Create Election & Ballot', 'relatasoft-secure-election-suite' ); ?></h2>
						<p><?php esc_html_e( 'Create elections, attach a public key, build ballot questions, then open voting.', 'relatasoft-secure-election-suite' ); ?></p>
						<p>
							<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=rses-elections' ) ); ?>">
								<?php esc_html_e( 'Manage Elections', 'relatasoft-secure-election-suite' ); ?>
							</a>
						</p>
					</div>
					<div class="rses-dashboard-card">
						<h2><?php esc_html_e( '3. Export Encrypted Tallies', 'relatasoft-secure-election-suite' ); ?></h2>
						<p><?php esc_html_e( 'After closing an election, export ZIP/JSON packages for the Tallying platform.', 'relatasoft-secure-election-suite' ); ?></p>
						<p>
							<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=rses-voting-export' ) ); ?>">
								<?php esc_html_e( 'Voting Export', 'relatasoft-secure-election-suite' ); ?>
							</a>
						</p>
					</div>
				<?php elseif ( ModeLock::rses_is_mode( ModeLock::RSES_MODE_TALLYING ) ) : ?>
					<div class="rses-dashboard-card">
						<h2><?php esc_html_e( '1. Import Voting Package', 'relatasoft-secure-election-suite' ); ?></h2>
						<p><?php esc_html_e( 'Upload ZIP or JSON exports from voting servers and validate checksums.', 'relatasoft-secure-election-suite' ); ?></p>
						<p>
							<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=rses-tally-import' ) ); ?>">
								<?php esc_html_e( 'Tally Import', 'relatasoft-secure-election-suite' ); ?>
							</a>
						</p>
					</div>
					<div class="rses-dashboard-card">
						<h2><?php esc_html_e( '2. Collect Official Shares', 'relatasoft-secure-election-suite' ); ?></h2>
						<p><?php esc_html_e( 'Officials submit Shamir Secret Sharing shares until the threshold is met.', 'relatasoft-secure-election-suite' ); ?></p>
						<p>
							<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=rses-share-submission' ) ); ?>">
								<?php esc_html_e( 'Share Submission', 'relatasoft-secure-election-suite' ); ?>
							</a>
						</p>
					</div>
					<div class="rses-dashboard-card">
						<h2><?php esc_html_e( '3. Decrypt & Certify', 'relatasoft-secure-election-suite' ); ?></h2>
						<p><?php esc_html_e( 'Reconstruct the private exponent in memory, decrypt tallies, and export certification reports.', 'relatasoft-secure-election-suite' ); ?></p>
						<p>
							<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=rses-certification' ) ); ?>">
								<?php esc_html_e( 'Certification', 'relatasoft-secure-election-suite' ); ?>
							</a>
						</p>
					</div>
				<?php endif; ?>

				<?php if ( Capability::rses_can_manage_election() ) : ?>
					<div class="rses-dashboard-card">
						<h2><?php esc_html_e( 'Crypto Self Test', 'relatasoft-secure-election-suite' ); ?></h2>
						<p><?php esc_html_e( 'Verify ElGamal, homomorphic aggregation, and Shamir Secret Sharing on this server.', 'relatasoft-secure-election-suite' ); ?></p>
						<p>
							<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=rses-crypto-self-test' ) ); ?>">
								<?php esc_html_e( 'Run Self Tests', 'relatasoft-secure-election-suite' ); ?>
							</a>
						</p>
					</div>
				<?php endif; ?>
			</div>

			<p class="rses-production-warning">
				<?php esc_html_e( 'This plugin is engineered toward production-grade use, but cryptography has not been independently reviewed for binding public elections.', 'relatasoft-secure-election-suite' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render crypto self-test page.
	 */
	public static function rses_render_crypto_self_test(): void {
		Capability::rses_require_admin();

		$rses_results = array();
		if ( isset( $_GET['rses_ran'] ) && '1' === $_GET['rses_ran'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$rses_results = CryptoSelfTest::runAll();
		}
		?>
		<div class="wrap rses-wrap">
			<h1><?php esc_html_e( 'Crypto Self Test', 'relatasoft-secure-election-suite' ); ?></h1>
			<p><?php esc_html_e( 'Run cryptographic self-tests to verify ElGamal, homomorphic tallying, and Shamir Secret Sharing implementations.', 'relatasoft-secure-election-suite' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php Nonce::rses_field( Nonce::RSES_ACTION_CRYPTO_SELF_TEST ); ?>
				<input type="hidden" name="action" value="rses_run_crypto_self_test" />
				<?php submit_button( __( 'Run Self Tests', 'relatasoft-secure-election-suite' ) ); ?>
			</form>

			<?php if ( ! empty( $rses_results ) ) : ?>
				<table class="widefat striped rses-self-test-results">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Test', 'relatasoft-secure-election-suite' ); ?></th>
							<th><?php esc_html_e( 'Result', 'relatasoft-secure-election-suite' ); ?></th>
							<th><?php esc_html_e( 'Message', 'relatasoft-secure-election-suite' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rses_results as $rses_test ) : ?>
							<tr class="<?php echo $rses_test['passed'] ? 'rses-pass' : 'rses-fail'; ?>">
								<td><?php echo esc_html( $rses_test['name'] ); ?></td>
								<td><?php echo $rses_test['passed'] ? esc_html__( 'PASS', 'relatasoft-secure-election-suite' ) : esc_html__( 'FAIL', 'relatasoft-secure-election-suite' ); ?></td>
								<td><?php echo esc_html( $rses_test['message'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handle crypto self-test form submission.
	 */
	public static function rses_handle_crypto_self_test(): void {
		Capability::rses_require_admin();
		Nonce::rses_verify_or_die( Nonce::RSES_ACTION_CRYPTO_SELF_TEST );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => 'rses-crypto-self-test',
					'rses_ran' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
