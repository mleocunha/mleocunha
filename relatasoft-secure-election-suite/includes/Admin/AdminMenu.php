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
use RelataSoft\SecureElectionSuite\Tallying\CertificationAuditPage;
use RelataSoft\SecureElectionSuite\Tallying\TallyingViews;
use RelataSoft\SecureElectionSuite\Voting\VotingViews;
use RelataSoft\SecureElectionSuite\I18n\RoleLabels;
use RelataSoft\SecureElectionSuite\I18n\Translator;
use RelataSoft\SecureElectionSuite\Admin\Brand;
use RelataSoft\SecureElectionSuite\Admin\ElectoralAuthoritiesPage;
use RelataSoft\SecureElectionSuite\Admin\UsersRegistryPage;
use RelataSoft\SecureElectionSuite\Admin\ElectoralRollImportPage;
use RelataSoft\SecureElectionSuite\Admin\KnowledgePage;
use RelataSoft\SecureElectionSuite\Admin\RedirectionsPage;
use RelataSoft\SecureElectionSuite\Admin\SystemAppearancePage;
use RelataSoft\SecureElectionSuite\Admin\SystemBecapePage;
use RelataSoft\SecureElectionSuite\Admin\SystemModulesPage;
use RelataSoft\SecureElectionSuite\Admin\SystemUpdatePage;
use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Bootstrap as PainelBootstrap;

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
		// Register by capability string — never gate registration on the current user's role.
		// Hosts (Virtualmin etc.) sometimes use custom roles with manage_options but without
		// the slug "administrator"; wrapping add_*_page in role checks left pages unregistered
		// and the shell linked to them → "Sem permissão para acessar esta página."
		$rses_official_cap = 'edit_posts';
		$rses_admin_cap    = 'manage_options';

		add_menu_page(
			__( 'Painel de Controle Eleitoral', 'relatasoft-secure-election-suite' ),
			__( 'Painel Eleitoral', 'relatasoft-secure-election-suite' ),
			$rses_official_cap,
			'rses-dashboard',
			array( self::class, 'rses_render_dashboard' ),
			'dashicons-privacy',
			30
		);

		// Platform management — available before and after mode lock.
		add_submenu_page(
			'rses-dashboard',
			__( 'Configuração de Modo', 'relatasoft-secure-election-suite' ),
			__( 'Configuração de Modo', 'relatasoft-secure-election-suite' ),
			$rses_admin_cap,
			'rses-mode-setup',
			array( ModeSetupPage::class, 'rses_render' )
		);
		add_submenu_page(
			'rses-dashboard',
			__( 'Atualizar o Sistema', 'relatasoft-secure-election-suite' ),
			__( 'Atualizar o Sistema', 'relatasoft-secure-election-suite' ),
			$rses_admin_cap,
			'rses-system-update',
			array( SystemUpdatePage::class, 'render' )
		);
		add_submenu_page(
			'rses-dashboard',
			__( 'Identidade Visual', 'relatasoft-secure-election-suite' ),
			__( 'Identidade Visual', 'relatasoft-secure-election-suite' ),
			$rses_admin_cap,
			'rses-system-appearance',
			array( SystemAppearancePage::class, 'render' )
		);
		add_submenu_page(
			'rses-dashboard',
			__( 'Módulos do Sistema', 'relatasoft-secure-election-suite' ),
			__( 'Módulos do Sistema', 'relatasoft-secure-election-suite' ),
			$rses_admin_cap,
			'rses-system-modules',
			array( SystemModulesPage::class, 'render' )
		);
		add_submenu_page(
			'rses-dashboard',
			__( 'Becape e Restauração', 'relatasoft-secure-election-suite' ),
			__( 'Becape e Restauração', 'relatasoft-secure-election-suite' ),
			$rses_admin_cap,
			'rses-system-becape',
			array( SystemBecapePage::class, 'render' )
		);
		add_submenu_page(
			'rses-dashboard',
			__( 'Configurações', 'relatasoft-secure-election-suite' ),
			__( 'Configurações', 'relatasoft-secure-election-suite' ),
			$rses_admin_cap,
			'rses-settings',
			array( SettingsPage::class, 'rses_render' )
		);
		add_submenu_page(
			'rses-dashboard',
			__( 'Registro de Auditoria', 'relatasoft-secure-election-suite' ),
			__( 'Registro de Auditoria', 'relatasoft-secure-election-suite' ),
			$rses_official_cap,
			'rses-audit-log',
			array( AuditLogPage::class, 'rses_render' )
		);
		add_submenu_page(
			'rses-dashboard',
			__( 'Conhecimento', 'relatasoft-secure-election-suite' ),
			__( 'Conhecimento', 'relatasoft-secure-election-suite' ),
			$rses_official_cap,
			KnowledgePage::SLUG,
			array( KnowledgePage::class, 'rses_render' )
		);
		add_submenu_page(
			'rses-dashboard',
			__( 'Autoteste Criptográfico', 'relatasoft-secure-election-suite' ),
			__( 'Autoteste Criptográfico', 'relatasoft-secure-election-suite' ),
			$rses_admin_cap,
			'rses-crypto-self-test',
			array( self::class, 'rses_render_crypto_self_test' )
		);

		if ( ! ModeLock::rses_has_mode() ) {
			return;
		}

		if ( ModeLock::rses_is_mode( ModeLock::RSES_MODE_KEY_AUTHORITY ) ) {
			add_submenu_page(
				'rses-dashboard',
				__( 'Autoridade de Chaves', 'relatasoft-secure-election-suite' ),
				__( 'Autoridade de Chaves', 'relatasoft-secure-election-suite' ),
				$rses_official_cap,
				'rses-key-authority',
				array( KeyAuthorityViews::class, 'rses_render_dashboard' )
			);

			add_submenu_page(
				'rses-dashboard',
				__( 'Cadastro Eleitoral', 'relatasoft-secure-election-suite' ),
				__( 'Cadastro Eleitoral', 'relatasoft-secure-election-suite' ),
				$rses_admin_cap,
				'rses-electoral-roll',
				array( ElectoralRollImportPage::class, 'rses_render' )
			);

			// Alias legado — redireciona via UsersRegistryPage::render.
			add_submenu_page(
				null,
				__( 'Cadastro Eleitoral', 'relatasoft-secure-election-suite' ),
				__( 'Cadastro Eleitoral', 'relatasoft-secure-election-suite' ),
				$rses_admin_cap,
				UsersRegistryPage::SLUG,
				array( UsersRegistryPage::class, 'render' )
			);

			add_submenu_page(
				'rses-dashboard',
				__( 'Exportar Autoridades Eleitorais', 'relatasoft-secure-election-suite' ),
				__( 'Exportar Autoridades Eleitorais', 'relatasoft-secure-election-suite' ),
				$rses_admin_cap,
				ElectoralAuthoritiesPage::SLUG,
				array( ElectoralAuthoritiesPage::class, 'render' )
			);
		}

		if ( ModeLock::rses_is_mode( ModeLock::RSES_MODE_VOTING ) ) {
			add_submenu_page(
				'rses-dashboard',
				__( 'Chaves Públicas', 'relatasoft-secure-election-suite' ),
				__( 'Chaves Públicas', 'relatasoft-secure-election-suite' ),
				$rses_admin_cap,
				'rses-public-keys',
				array( VotingViews::class, 'rses_render_public_keys_page' )
			);

			add_submenu_page(
				'rses-dashboard',
				__( 'Eleições', 'relatasoft-secure-election-suite' ),
				__( 'Eleições', 'relatasoft-secure-election-suite' ),
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
				__( 'Redirecionamentos', 'relatasoft-secure-election-suite' ),
				__( 'Redirecionamentos', 'relatasoft-secure-election-suite' ),
				$rses_admin_cap,
				'rses-redirections',
				array( RedirectionsPage::class, 'rses_render' )
			);

			add_submenu_page(
				'rses-dashboard',
				__( 'Cadastro Eleitoral', 'relatasoft-secure-election-suite' ),
				__( 'Cadastro Eleitoral', 'relatasoft-secure-election-suite' ),
				$rses_admin_cap,
				'rses-electoral-roll',
				array( ElectoralRollImportPage::class, 'rses_render' )
			);

			add_submenu_page(
				null,
				__( 'Cadastro Eleitoral', 'relatasoft-secure-election-suite' ),
				__( 'Cadastro Eleitoral', 'relatasoft-secure-election-suite' ),
				$rses_admin_cap,
				UsersRegistryPage::SLUG,
				array( UsersRegistryPage::class, 'render' )
			);

			add_submenu_page(
				'rses-dashboard',
				__( 'Importar Autoridades Eleitorais', 'relatasoft-secure-election-suite' ),
				__( 'Importar Autoridades Eleitorais', 'relatasoft-secure-election-suite' ),
				$rses_admin_cap,
				ElectoralAuthoritiesPage::SLUG,
				array( ElectoralAuthoritiesPage::class, 'render' )
			);

			add_submenu_page(
				'rses-dashboard',
				__( 'Exportação', 'relatasoft-secure-election-suite' ),
				__( 'Exportação', 'relatasoft-secure-election-suite' ),
				$rses_admin_cap,
				'rses-voting-export',
				array( VotingViews::class, 'rses_render_export_page' )
			);
		}

		if ( ModeLock::rses_is_mode( ModeLock::RSES_MODE_TALLYING ) ) {
			add_submenu_page(
				'rses-dashboard',
				__( 'Importação / Apuração', 'relatasoft-secure-election-suite' ),
				__( 'Importação / Apuração', 'relatasoft-secure-election-suite' ),
				$rses_admin_cap,
				'rses-tally-import',
				array( TallyingViews::class, 'rses_render_import_page' )
			);

			add_submenu_page(
				'rses-dashboard',
				__( 'Cadastro Eleitoral', 'relatasoft-secure-election-suite' ),
				__( 'Cadastro Eleitoral', 'relatasoft-secure-election-suite' ),
				$rses_admin_cap,
				'rses-electoral-roll',
				array( ElectoralRollImportPage::class, 'rses_render' )
			);

			add_submenu_page(
				null,
				__( 'Cadastro Eleitoral', 'relatasoft-secure-election-suite' ),
				__( 'Cadastro Eleitoral', 'relatasoft-secure-election-suite' ),
				$rses_admin_cap,
				UsersRegistryPage::SLUG,
				array( UsersRegistryPage::class, 'render' )
			);

			add_submenu_page(
				'rses-dashboard',
				__( 'Importar Autoridades Eleitorais', 'relatasoft-secure-election-suite' ),
				__( 'Importar Autoridades Eleitorais', 'relatasoft-secure-election-suite' ),
				$rses_admin_cap,
				ElectoralAuthoritiesPage::SLUG,
				array( ElectoralAuthoritiesPage::class, 'render' )
			);

			add_submenu_page(
				'rses-dashboard',
				__( 'Submissão de Parcelas', 'relatasoft-secure-election-suite' ),
				__( 'Submissão de Parcelas', 'relatasoft-secure-election-suite' ),
				$rses_official_cap,
				'rses-share-submission',
				array( TallyingViews::class, 'rses_render_share_submission_page' )
			);

			add_submenu_page(
				'rses-dashboard',
				__( 'Certificação', 'relatasoft-secure-election-suite' ),
				__( 'Certificação', 'relatasoft-secure-election-suite' ),
				$rses_admin_cap,
				'rses-certification',
				array( TallyingViews::class, 'rses_render_certification_page' )
			);

			add_submenu_page(
				'rses-dashboard',
				__( 'Audit Certification', 'relatasoft-secure-election-suite' ),
				__( 'Audit Certification', 'relatasoft-secure-election-suite' ),
				$rses_official_cap,
				CertificationAuditPage::SLUG,
				array( CertificationAuditPage::class, 'rses_render' )
			);
		}
	}

	/**
	 * Render main dashboard with mode-specific actions.
	 */
	public static function rses_render_dashboard(): void {
		PainelBootstrap::renderHome();
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
