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
use RelataSoft\SecureElectionSuite\I18n\RoleLabels;
use RelataSoft\SecureElectionSuite\I18n\Translator;
use RelataSoft\SecureElectionSuite\Admin\Brand;
use RelataSoft\SecureElectionSuite\Admin\RedirectionsPage;
use RelataSoft\SecureElectionSuite\Admin\UserImportPage;

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

		// edit_posts so Editors always see Election Suite; admin-only pages use manage_options.
		$rses_official_cap = 'edit_posts';
		$rses_admin_cap    = 'manage_options';

		add_menu_page(
			__( 'Secure Election Suite', 'relatasoft-secure-election-suite' ),
			__( 'Election Suite', 'relatasoft-secure-election-suite' ),
			$rses_official_cap,
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
				$rses_admin_cap,
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
				Capability::rses_can_manage_election()
					? __( 'Key Authority', 'relatasoft-secure-election-suite' )
					: __( 'My Shamir Shares', 'relatasoft-secure-election-suite' ),
				$rses_official_cap,
				'rses-key-authority',
				array( KeyAuthorityViews::class, 'rses_render_dashboard' )
			);
		}

		if ( ModeLock::rses_is_mode( ModeLock::RSES_MODE_VOTING ) ) {
			add_submenu_page(
				'rses-dashboard',
				__( 'Public Keys', 'relatasoft-secure-election-suite' ),
				__( 'Public Keys', 'relatasoft-secure-election-suite' ),
				$rses_admin_cap,
				'rses-public-keys',
				array( VotingViews::class, 'rses_render_public_keys_page' )
			);

			add_submenu_page(
				'rses-dashboard',
				__( 'Elections', 'relatasoft-secure-election-suite' ),
				__( 'Elections', 'relatasoft-secure-election-suite' ),
				$rses_admin_cap,
				'rses-elections',
				array( VotingViews::class, 'rses_render_elections_list' )
			);

			add_submenu_page(
				'rses-dashboard',
				__( 'Shortcodes', 'relatasoft-secure-election-suite' ),
				__( 'Shortcodes', 'relatasoft-secure-election-suite' ),
				$rses_admin_cap,
				'rses-shortcodes',
				array( VotingViews::class, 'rses_render_shortcodes_page' )
			);

			add_submenu_page(
				'rses-dashboard',
				__( 'Redirections', 'relatasoft-secure-election-suite' ),
				__( 'Redirections', 'relatasoft-secure-election-suite' ),
				$rses_admin_cap,
				'rses-redirections',
				array( RedirectionsPage::class, 'rses_render' )
			);

			add_submenu_page(
				'rses-dashboard',
				__( 'Import users', 'relatasoft-secure-election-suite' ),
				__( 'Import users', 'relatasoft-secure-election-suite' ),
				$rses_admin_cap,
				'rses-user-import',
				array( UserImportPage::class, 'rses_render' )
			);

			add_submenu_page(
				'rses-dashboard',
				__( 'Voting Export', 'relatasoft-secure-election-suite' ),
				__( 'Voting Export', 'relatasoft-secure-election-suite' ),
				$rses_admin_cap,
				'rses-voting-export',
				array( VotingViews::class, 'rses_render_export_page' )
			);
		}

		if ( ModeLock::rses_is_mode( ModeLock::RSES_MODE_TALLYING ) ) {
			if ( Capability::rses_can_manage_election() ) {
				add_submenu_page(
					'rses-dashboard',
					__( 'Tally Import', 'relatasoft-secure-election-suite' ),
					__( 'Tally Import', 'relatasoft-secure-election-suite' ),
					$rses_admin_cap,
					'rses-tally-import',
					array( TallyingViews::class, 'rses_render_import_page' )
				);
			}

			// Every Editor must be able to submit their Shamir share.
			add_submenu_page(
				'rses-dashboard',
				__( 'Share Submission', 'relatasoft-secure-election-suite' ),
				__( 'Share Submission', 'relatasoft-secure-election-suite' ),
				$rses_official_cap,
				'rses-share-submission',
				array( TallyingViews::class, 'rses_render_share_submission_page' )
			);

			if ( Capability::rses_can_manage_election() ) {
				add_submenu_page(
					'rses-dashboard',
					__( 'Certification', 'relatasoft-secure-election-suite' ),
					__( 'Certification', 'relatasoft-secure-election-suite' ),
					$rses_admin_cap,
					'rses-certification',
					array( TallyingViews::class, 'rses_render_certification_page' )
				);
			}
		}

		if ( Capability::rses_can_manage_election() ) {
			add_submenu_page(
				'rses-dashboard',
				__( 'Settings', 'relatasoft-secure-election-suite' ),
				__( 'Settings', 'relatasoft-secure-election-suite' ),
				$rses_admin_cap,
				'rses-settings',
				array( SettingsPage::class, 'rses_render' )
			);

			add_submenu_page(
				'rses-dashboard',
				__( 'Audit Log', 'relatasoft-secure-election-suite' ),
				__( 'Audit Log', 'relatasoft-secure-election-suite' ),
				$rses_admin_cap,
				'rses-audit-log',
				array( AuditLogPage::class, 'rses_render' )
			);

			add_submenu_page(
				'rses-dashboard',
				__( 'Crypto Self Test', 'relatasoft-secure-election-suite' ),
				__( 'Crypto Self Test', 'relatasoft-secure-election-suite' ),
				$rses_admin_cap,
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
		<div class="wrap rses-wrap rses-screen" <?php echo Translator::rses_html_attrs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<header class="rses-hero rses-hero--brand">
				<?php Brand::rses_render_hero_brand(); ?>
				<p class="rses-hero-kicker"><?php esc_html_e( 'Secure Election Suite', 'relatasoft-secure-election-suite' ); ?></p>
				<h1 class="rses-hero-title"><?php esc_html_e( 'Dashboard', 'relatasoft-secure-election-suite' ); ?></h1>
				<p class="rses-hero-lead"><?php esc_html_e( 'Continue your locked-mode workflow with the actions below.', 'relatasoft-secure-election-suite' ); ?></p>
			</header>

			<?php if ( ! empty( $_GET['rses_mode_set'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="rses-panel rses-panel-success">
					<p><?php esc_html_e( 'Mode locked successfully. Use the actions below to continue.', 'relatasoft-secure-election-suite' ); ?></p>
				</div>
			<?php endif; ?>

			<div class="rses-panel rses-panel-info">
				<p>
					<strong><?php esc_html_e( 'Active Mode:', 'relatasoft-secure-election-suite' ); ?></strong>
					<?php echo esc_html( ModeLock::rses_get_mode_label( $rses_mode ) ); ?>
					— <?php esc_html_e( 'Locked', 'relatasoft-secure-election-suite' ); ?>
				</p>
			</div>

			<div class="rses-dashboard-grid">
				<?php if ( ModeLock::rses_is_mode( ModeLock::RSES_MODE_KEY_AUTHORITY ) ) : ?>
					<div class="rses-dashboard-card">
						<?php if ( Capability::rses_can_manage_election() ) : ?>
							<h2><?php esc_html_e( 'Key Authority', 'relatasoft-secure-election-suite' ); ?></h2>
							<p><?php esc_html_e( 'Generate ElGamal keys, split private exponents with Shamir Secret Sharing, and export public keys / shares.', 'relatasoft-secure-election-suite' ); ?></p>
							<p>
								<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=rses-key-authority' ) ); ?>">
									<?php esc_html_e( 'Open Key Authority', 'relatasoft-secure-election-suite' ); ?>
								</a>
							</p>
						<?php else : ?>
							<h2><?php esc_html_e( 'My Shamir Shares', 'relatasoft-secure-election-suite' ); ?></h2>
							<p><?php
								echo esc_html(
									sprintf(
										/* translators: %s: electoral authority role label (singular) */
										__( 'View, copy, and download the Shamir share assigned to your %s account. Keep it offline and confidential.', 'relatasoft-secure-election-suite' ),
										RoleLabels::rses_editor_singular()
									)
								);
							?></p>
							<p>
								<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=rses-key-authority' ) ); ?>">
									<?php esc_html_e( 'Open My Shares', 'relatasoft-secure-election-suite' ); ?>
								</a>
							</p>
						<?php endif; ?>
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
						<h2><?php esc_html_e( '3. Publish Voting Shortcodes', 'relatasoft-secure-election-suite' ); ?></h2>
						<p><?php
							echo esc_html(
								sprintf(
									/* translators: %s: elector role label (plural) */
									__( 'Copy shortcodes into any WordPress page or post so %s can cast encrypted ballots.', 'relatasoft-secure-election-suite' ),
									RoleLabels::rses_elector_plural()
								)
							);
						?></p>
						<p>
							<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=rses-shortcodes' ) ); ?>">
								<?php esc_html_e( 'Shortcode Generator', 'relatasoft-secure-election-suite' ); ?>
							</a>
						</p>
					</div>
					<div class="rses-dashboard-card">
						<h2><?php esc_html_e( '4. Elector journey & login', 'relatasoft-secure-election-suite' ); ?></h2>
						<p><?php esc_html_e( 'Brand wp-login.php, configure welcome and thank-you pages, and plan post-login redirects for electors.', 'relatasoft-secure-election-suite' ); ?></p>
						<p>
							<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=rses-redirections' ) ); ?>">
								<?php esc_html_e( 'Redirections', 'relatasoft-secure-election-suite' ); ?>
							</a>
						</p>
					</div>
					<div class="rses-dashboard-card">
						<h2><?php esc_html_e( '5. Import users', 'relatasoft-secure-election-suite' ); ?></h2>
						<p><?php esc_html_e( 'CSV import of electors with a final senha column; passwords are accepted as-is.', 'relatasoft-secure-election-suite' ); ?></p>
						<p>
							<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=rses-user-import' ) ); ?>">
								<?php esc_html_e( 'Import users', 'relatasoft-secure-election-suite' ); ?>
							</a>
						</p>
					</div>
					<div class="rses-dashboard-card">
						<h2><?php esc_html_e( '6. Export Encrypted Tallies', 'relatasoft-secure-election-suite' ); ?></h2>
						<p><?php esc_html_e( 'After closing an election, export ZIP/JSON packages for the Tallying platform.', 'relatasoft-secure-election-suite' ); ?></p>
						<p>
							<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=rses-voting-export' ) ); ?>">
								<?php esc_html_e( 'Voting Export', 'relatasoft-secure-election-suite' ); ?>
							</a>
						</p>
					</div>
				<?php elseif ( ModeLock::rses_is_mode( ModeLock::RSES_MODE_TALLYING ) ) : ?>
					<?php if ( Capability::rses_can_manage_election() ) : ?>
						<div class="rses-dashboard-card">
							<h2><?php esc_html_e( '1. Import Voting Package', 'relatasoft-secure-election-suite' ); ?></h2>
							<p><?php esc_html_e( 'Upload ZIP or JSON exports from voting servers and validate checksums.', 'relatasoft-secure-election-suite' ); ?></p>
							<p>
								<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=rses-tally-import' ) ); ?>">
									<?php esc_html_e( 'Tally Import', 'relatasoft-secure-election-suite' ); ?>
								</a>
							</p>
						</div>
					<?php endif; ?>
					<div class="rses-dashboard-card">
						<h2><?php echo Capability::rses_can_manage_election() ? esc_html__( '2. Collect Official Shares', 'relatasoft-secure-election-suite' ) : esc_html__( 'Submit Your Shamir Share', 'relatasoft-secure-election-suite' ); ?></h2>
						<p><?php
							echo esc_html(
								sprintf(
									/* translators: %s: electoral authority role label (singular) */
									__( 'Each %s pastes their offline Shamir share JSON here until the threshold is met.', 'relatasoft-secure-election-suite' ),
									RoleLabels::rses_editor_singular()
								)
							);
						?></p>
						<p>
							<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=rses-share-submission' ) ); ?>">
								<?php esc_html_e( 'Share Submission', 'relatasoft-secure-election-suite' ); ?>
							</a>
						</p>
					</div>
					<?php if ( Capability::rses_can_manage_election() ) : ?>
						<div class="rses-dashboard-card">
							<h2><?php esc_html_e( '3. Decrypt & Certify', 'relatasoft-secure-election-suite' ); ?></h2>
							<p><?php esc_html_e( 'Reconstruct the private exponent in memory, decrypt tallies, and export certification reports.', 'relatasoft-secure-election-suite' ); ?></p>
							<p>
								<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=rses-certification' ) ); ?>">
									<?php esc_html_e( 'Certification', 'relatasoft-secure-election-suite' ); ?>
								</a>
							</p>
						</div>
					<?php endif; ?>
				<?php endif; ?>

				<?php if ( Capability::rses_can_manage_election() ) : ?>
					<?php
					$rses_crypto_title = __( 'Crypto Self Test', 'relatasoft-secure-election-suite' );
					if ( ModeLock::rses_is_mode( ModeLock::RSES_MODE_VOTING ) ) {
						$rses_crypto_title = __( '7. Crypto Self Test', 'relatasoft-secure-election-suite' );
					} elseif ( ModeLock::rses_is_mode( ModeLock::RSES_MODE_TALLYING ) ) {
						$rses_crypto_title = __( '4. Crypto Self Test', 'relatasoft-secure-election-suite' );
					} elseif ( ModeLock::rses_is_mode( ModeLock::RSES_MODE_KEY_AUTHORITY ) ) {
						$rses_crypto_title = __( '2. Crypto Self Test', 'relatasoft-secure-election-suite' );
					}
					?>
					<div class="rses-dashboard-card">
						<h2><?php echo esc_html( $rses_crypto_title ); ?></h2>
						<p><?php esc_html_e( 'Verify ElGamal, homomorphic aggregation, and Shamir Secret Sharing on this server.', 'relatasoft-secure-election-suite' ); ?></p>
						<p>
							<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=rses-crypto-self-test' ) ); ?>">
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
		$rses_ran     = isset( $_GET['rses_ran'] ) && '1' === $_GET['rses_ran']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $rses_ran ) {
			$rses_results = CryptoSelfTest::runAll();
		}

		$rses_pass_count = 0;
		$rses_fail_count = 0;
		foreach ( $rses_results as $rses_test ) {
			if ( ! empty( $rses_test['passed'] ) ) {
				++$rses_pass_count;
			} else {
				++$rses_fail_count;
			}
		}
		$rses_all_passed = $rses_ran && empty( $rses_fail_count ) && ! empty( $rses_results );
		?>
		<div class="wrap rses-wrap rses-screen" <?php echo Translator::rses_html_attrs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<header class="rses-hero rses-hero--brand">
				<?php Brand::rses_render_hero_brand(); ?>
				<p class="rses-hero-kicker"><?php esc_html_e( 'Diagnostics', 'relatasoft-secure-election-suite' ); ?></p>
				<h1 class="rses-hero-title"><?php esc_html_e( 'Crypto Self Test', 'relatasoft-secure-election-suite' ); ?></h1>
				<p class="rses-hero-lead"><?php esc_html_e( 'Verify ElGamal, homomorphic tallying, and Shamir Secret Sharing on this server before you trust it with live election data.', 'relatasoft-secure-election-suite' ); ?></p>
			</header>

			<section class="rses-panel rses-panel-info">
				<p><?php esc_html_e( 'These checks exercise key generation, encrypt/decrypt, homomorphic aggregation, Shamir share split/reconstruct, and a mini end-to-end election simulation. They do not replace an independent cryptographic audit.', 'relatasoft-secure-election-suite' ); ?></p>
			</section>

			<section class="rses-panel rses-panel-card rses-self-test-run">
				<header class="rses-panel-header">
					<p class="rses-panel-kicker"><?php esc_html_e( 'Run', 'relatasoft-secure-election-suite' ); ?></p>
					<h2 class="rses-panel-title"><?php esc_html_e( 'Execute self-tests', 'relatasoft-secure-election-suite' ); ?></h2>
					<p class="rses-panel-desc"><?php esc_html_e( 'Results are computed on this request and are not stored. Re-run anytime after PHP or server changes.', 'relatasoft-secure-election-suite' ); ?></p>
				</header>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rses-form">
					<?php Nonce::rses_field( Nonce::RSES_ACTION_CRYPTO_SELF_TEST ); ?>
					<input type="hidden" name="action" value="rses_run_crypto_self_test" />
					<p class="rses-form-actions">
						<?php submit_button( __( 'Run Self Tests', 'relatasoft-secure-election-suite' ), 'primary rses-btn-primary', 'submit', false ); ?>
					</p>
				</form>
			</section>

			<?php if ( $rses_ran ) : ?>
				<?php if ( $rses_all_passed ) : ?>
					<div class="rses-panel rses-panel-success">
						<p>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: number of tests that passed */
									__( 'All %d cryptographic self-tests passed on this server.', 'relatasoft-secure-election-suite' ),
									$rses_pass_count
								)
							);
							?>
						</p>
					</div>
				<?php else : ?>
					<div class="rses-panel rses-panel-warning">
						<p>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: passed count, 2: failed count */
									__( '%1$d passed, %2$d failed. Review the failures below before using this installation for voting.', 'relatasoft-secure-election-suite' ),
									$rses_pass_count,
									$rses_fail_count
								)
							);
							?>
						</p>
					</div>
				<?php endif; ?>

				<section class="rses-panel rses-panel-card rses-self-test-results-panel">
					<header class="rses-panel-header">
						<p class="rses-panel-kicker"><?php esc_html_e( 'Results', 'relatasoft-secure-election-suite' ); ?></p>
						<h2 class="rses-panel-title"><?php esc_html_e( 'Self-test report', 'relatasoft-secure-election-suite' ); ?></h2>
					</header>

					<div class="rses-self-test-summary">
						<span class="rses-self-test-stat rses-self-test-stat--pass">
							<span class="rses-self-test-stat-value"><?php echo esc_html( (string) $rses_pass_count ); ?></span>
							<span class="rses-self-test-stat-label"><?php esc_html_e( 'Passed', 'relatasoft-secure-election-suite' ); ?></span>
						</span>
						<span class="rses-self-test-stat rses-self-test-stat--fail">
							<span class="rses-self-test-stat-value"><?php echo esc_html( (string) $rses_fail_count ); ?></span>
							<span class="rses-self-test-stat-label"><?php esc_html_e( 'Failed', 'relatasoft-secure-election-suite' ); ?></span>
						</span>
					</div>

					<table class="rses-self-test-results">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Test', 'relatasoft-secure-election-suite' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Result', 'relatasoft-secure-election-suite' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Message', 'relatasoft-secure-election-suite' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $rses_results as $rses_test ) : ?>
								<tr class="<?php echo ! empty( $rses_test['passed'] ) ? 'rses-pass' : 'rses-fail'; ?>">
									<td class="rses-self-test-name"><?php echo esc_html( $rses_test['name'] ); ?></td>
									<td>
										<?php if ( ! empty( $rses_test['passed'] ) ) : ?>
											<span class="rses-status-pill rses-status-pill--pass"><?php esc_html_e( 'PASS', 'relatasoft-secure-election-suite' ); ?></span>
										<?php else : ?>
											<span class="rses-status-pill rses-status-pill--fail"><?php esc_html_e( 'FAIL', 'relatasoft-secure-election-suite' ); ?></span>
										<?php endif; ?>
									</td>
									<td class="rses-self-test-message"><?php echo esc_html( $rses_test['message'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</section>
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
