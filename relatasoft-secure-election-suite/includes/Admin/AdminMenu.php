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

		if ( ! ModeLock::rses_has_mode() ) {
			add_submenu_page(
				'rses-dashboard',
				__( 'Mode Setup', 'relatasoft-secure-election-suite' ),
				__( 'Mode Setup', 'relatasoft-secure-election-suite' ),
				'manage_options',
				'rses-mode-setup',
				array( ModeSetupPage::class, 'rses_render' )
			);
			return;
		}

		if ( ModeLock::rses_is_mode( 'key_authority' ) ) {
			add_submenu_page(
				'rses-dashboard',
				__( 'Key Authority', 'relatasoft-secure-election-suite' ),
				__( 'Key Authority', 'relatasoft-secure-election-suite' ),
				$rses_cap,
				'rses-key-authority',
				array( KeyAuthorityViews::class, 'rses_render_dashboard' )
			);
		}

		if ( ModeLock::rses_is_mode( 'voting' ) ) {
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

		if ( ModeLock::rses_is_mode( 'tallying' ) ) {
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
	 * Render main dashboard.
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
			<div class="rses-notice rses-notice-info">
				<p>
					<strong><?php esc_html_e( 'Active Mode:', 'relatasoft-secure-election-suite' ); ?></strong>
					<?php echo esc_html( ModeLock::rses_get_mode_label( $rses_mode ) ); ?>
				</p>
			</div>
			<p><?php esc_html_e( 'Use the submenu to manage elections, keys, or tallying operations.', 'relatasoft-secure-election-suite' ); ?></p>
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
		if ( isset( $_GET['rses_ran'] ) && '1' === $_GET['rses_ran'] ) {
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
