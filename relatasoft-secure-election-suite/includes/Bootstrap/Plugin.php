<?php
/**
 * Main plugin bootstrap.
 *
 * @package RelataSoft\SecureElectionSuite\Bootstrap
 */

namespace RelataSoft\SecureElectionSuite\Bootstrap;

use RelataSoft\SecureElectionSuite\Admin\AdminMenu;
use RelataSoft\SecureElectionSuite\Admin\AuditLogPage;
use RelataSoft\SecureElectionSuite\Admin\ElectoralAuthoritiesPage;
use RelataSoft\SecureElectionSuite\Admin\UsersRegistryPage;
use RelataSoft\SecureElectionSuite\Admin\ModeSetupPage;
use RelataSoft\SecureElectionSuite\Admin\Notices;
use RelataSoft\SecureElectionSuite\Admin\SettingsPage;
use RelataSoft\SecureElectionSuite\Admin\SystemAppearancePage;
use RelataSoft\SecureElectionSuite\Admin\SystemBecapePage;
use RelataSoft\SecureElectionSuite\Admin\SystemModulesPage;
use RelataSoft\SecureElectionSuite\Admin\SystemUpdatePage;
use RelataSoft\SecureElectionSuite\Ajax\AjaxRouter;
use RelataSoft\SecureElectionSuite\Database\Migration;
use RelataSoft\SecureElectionSuite\I18n\LocaleResolver;
use RelataSoft\SecureElectionSuite\I18n\RoleLabels;
use RelataSoft\SecureElectionSuite\I18n\Translator;
use RelataSoft\SecureElectionSuite\KeyAuthority\KeyAuthorityController;
use RelataSoft\SecureElectionSuite\Tallying\CertificationService;
use RelataSoft\SecureElectionSuite\Tallying\OfficialShareSubmissionController;
use RelataSoft\SecureElectionSuite\Tallying\TallyImportController;
use RelataSoft\SecureElectionSuite\Admin\RedirectionsPage;
use RelataSoft\SecureElectionSuite\Admin\ElectoralRollImportPage;
use RelataSoft\SecureElectionSuite\Frontend\PasswordResetShortcode;
use RelataSoft\SecureElectionSuite\Frontend\VoterJourney;
use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Bootstrap as PainelBootstrap;
use RelataSoft\SecureElectionSuite\Voting\BallotController;
use RelataSoft\SecureElectionSuite\Voting\ElectionController;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin singleton.
 */
class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Run the plugin.
	 */
	public function run(): void {
		Translator::rses_register();
		RoleLabels::register();
		add_action( 'init', array( $this, 'rses_init' ) );
		add_action( 'admin_init', array( Migration::class, 'rses_maybe_migrate' ) );

		// Admin-post handlers must register on every request (including admin-post.php).
		ModeSetupPage::register();
		SettingsPage::register();
		AuditLogPage::register();
		SystemUpdatePage::register();
		SystemAppearancePage::register();
		SystemModulesPage::register();
		SystemBecapePage::register();
		ElectoralAuthoritiesPage::register();
		UsersRegistryPage::register();
		CertificationService::register();
		AjaxRouter::register();
		KeyAuthorityController::register();
		ElectionController::register();
		BallotController::register();
		TallyImportController::register();
		OfficialShareSubmissionController::register();
		RedirectionsPage::register();
		ElectoralRollImportPage::register();
		// Login branding moved to Painel (WordPressLoginBranding) — LoginCustomizer retired.
		VoterJourney::register();
		PasswordResetShortcode::register();
		PainelBootstrap::register();

		if ( is_admin() ) {
			Notices::register();
			AdminMenu::register();
		}

		add_shortcode( 'rses_voting_booth', array( $this, 'rses_render_voting_booth_shortcode' ) );
		add_shortcode( 'rses_voter_receipt', array( $this, 'rses_render_voter_receipt_shortcode' ) );
		add_shortcode( 'rses_election_status', array( $this, 'rses_render_election_status_shortcode' ) );
		add_shortcode( 'rses_voter_welcome', array( VoterJourney::class, 'rses_render_welcome_shortcode' ) );
		add_shortcode( 'rses_voter_thank_you', array( VoterJourney::class, 'rses_render_thank_you_shortcode' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'rses_enqueue_frontend_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'rses_enqueue_admin_assets' ) );
	}

	/**
	 * Initialize plugin hooks.
	 */
	public function rses_init(): void {
		// Reserved for future init hooks.
	}

	/**
	 * Enqueue frontend assets.
	 */
	public function rses_enqueue_frontend_assets(): void {
		if ( ! ModeLock::rses_is_mode( 'voting' ) ) {
			return;
		}

		wp_enqueue_style(
			'rses-voting-front',
			RSES_PLUGIN_URL . 'assets/css/voting-front.css',
			array(),
			RSES_VERSION
		);

		wp_enqueue_script(
			'rses-voting',
			RSES_PLUGIN_URL . 'assets/js/voting.js',
			array( 'jquery' ),
			RSES_VERSION,
			true
		);

		wp_localize_script(
			'rses-voting',
			'rsesVoting',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'rses_vote_cast' ),
				'locale'  => LocaleResolver::rses_resolve(),
				'dir'     => Translator::rses_dir_attr(),
				'i18n'    => array(
					'confirm' => __( 'Submit your encrypted vote? This cannot be undone.', 'relatasoft-secure-election-suite' ),
				),
			)
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function rses_enqueue_admin_assets( string $hook_suffix ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin page id.
		$rses_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		// Prefer ?page= over hook_suffix: translated menu titles can rewrite the hook string.
		$rses_is_plugin_screen = ( 0 === strpos( $rses_page, 'rses-' ) )
			|| false !== strpos( $hook_suffix, 'rses-' )
			|| false !== strpos( $hook_suffix, 'relatasoft-secure-election' );

		if ( ! $rses_is_plugin_screen ) {
			return;
		}

		wp_enqueue_style(
			'rses-admin',
			RSES_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			RSES_VERSION
		);

		wp_enqueue_script(
			'rses-admin',
			RSES_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			RSES_VERSION,
			true
		);

		wp_localize_script(
			'rses-admin',
			'rsesAdmin',
			array(
				'i18n' => array(
					'selectMedia'   => __( 'Select option media', 'relatasoft-secure-election-suite' ),
					'useMedia'      => __( 'Use this media', 'relatasoft-secure-election-suite' ),
					'photo'         => __( 'Photo', 'relatasoft-secure-election-suite' ),
					'audio'         => __( 'Audio', 'relatasoft-secure-election-suite' ),
					'video'         => __( 'Video', 'relatasoft-secure-election-suite' ),
					'mediaAttached' => __( 'Media attached', 'relatasoft-secure-election-suite' ),
					'selectLoginLogo' => __( 'Choose login logo', 'relatasoft-secure-election-suite' ),
					'useLoginLogo'    => __( 'Use this logo', 'relatasoft-secure-election-suite' ),
					'selectAdminLogo' => __( 'Choose admin logo', 'relatasoft-secure-election-suite' ),
					'useAdminLogo'    => __( 'Use this logo', 'relatasoft-secure-election-suite' ),
				),
			)
		);

		// Ballot builder media library (photo / audio / video).
		if ( in_array( $rses_page, array( 'rses-elections', 'rses-redirections', 'rses-settings' ), true ) ) {
			wp_enqueue_media();
			wp_enqueue_script( 'rses-admin' );
		}

		$mode = ModeLock::rses_get_mode();

		// Key Authority screen: force the chunked keygen script onto the page.
		if ( 'rses-key-authority' === $rses_page || 'key_authority' === $mode ) {
			self::rses_enqueue_key_authority_script();
		}

		if ( 'rses-electoral-roll' === $rses_page ) {
			self::rses_enqueue_electoral_roll_script();
		}

		if ( 'tallying' === $mode ) {
			wp_enqueue_script(
				'rses-tallying',
				RSES_PLUGIN_URL . 'assets/js/tallying.js',
				array( 'jquery', 'rses-admin' ),
				RSES_VERSION,
				true
			);
		}
	}

	/**
	 * Register, localize, and enqueue Key Authority keygen script.
	 *
	 * Safe to call from admin_enqueue_scripts or during page render.
	 */
	public static function rses_enqueue_key_authority_script(): void {
		wp_register_script(
			'rses-key-authority',
			RSES_PLUGIN_URL . 'assets/js/key-authority.js',
			array( 'jquery' ),
			RSES_VERSION,
			true
		);
		wp_localize_script(
			'rses-key-authority',
			'rsesKeygen',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'rses_keygen' ),
				'doneUrl' => admin_url( 'admin.php?page=rses-key-authority' ),
				'locale'  => LocaleResolver::rses_resolve(),
				'dir'     => Translator::rses_dir_attr(),
				'i18n'    => array(
					'starting'  => __( 'Starting chunked key generation…', 'relatasoft-secure-election-suite' ),
					'error'     => __( 'Key generation request failed.', 'relatasoft-secure-election-suite' ),
					'cancelled' => __( 'Key generation cancelled.', 'relatasoft-secure-election-suite' ),
					'attempts'  => __( '%d candidates tested', 'relatasoft-secure-election-suite' ),
					'slowHint'  => __( 'Key generation at %d bits uses chunked AJAX (≤25s per step) and may take several minutes.', 'relatasoft-secure-election-suite' ),
					'noJs'      => __( 'JavaScript failed to start key generation. Hard-refresh this page (Ctrl/Cmd+Shift+R) and try again.', 'relatasoft-secure-election-suite' ),
				),
			)
		);
		wp_enqueue_script( 'rses-key-authority' );
	}

	/**
	 * Register, localize, and enqueue electoral-roll chunked import/export script.
	 */
	public static function rses_enqueue_electoral_roll_script(): void {
		wp_register_script(
			'rses-electoral-roll',
			RSES_PLUGIN_URL . 'assets/js/electoral-roll-import.js',
			array( 'jquery' ),
			RSES_VERSION,
			true
		);

		$resume = \RelataSoft\SecureElectionSuite\Voting\ElectoralRollImportJob::rses_public_status(
			\RelataSoft\SecureElectionSuite\Voting\ElectoralRollImportJob::rses_get()
		);
		$export_resume = \RelataSoft\SecureElectionSuite\Voting\ElectoralRollExportJob::rses_public_status(
			\RelataSoft\SecureElectionSuite\Voting\ElectoralRollExportJob::rses_get()
		);

		$php_ceiling = \RelataSoft\SecureElectionSuite\Admin\ElectoralRollImportPage::rses_php_upload_ceiling();
		$chunk_bytes = \RelataSoft\SecureElectionSuite\Painel\Domain\ElectoralRoll\RsvFormat::adaptiveChunkBytes( $php_ceiling );

		wp_localize_script(
			'rses-electoral-roll',
			'rsesElectoralRoll',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( \RelataSoft\SecureElectionSuite\Admin\ElectoralRollImportPage::AJAX_NONCE_ACTION ),
				'maxBytes'      => \RelataSoft\SecureElectionSuite\Voting\ElectoralRollImportJob::MAX_UPLOAD_BYTES,
				'chunkBytes'    => $chunk_bytes,
				'phpUploadMax'  => $php_ceiling,
				'resume'        => $resume,
				'exportResume'  => $export_resume,
				'beepOnExport'  => true,
				'downloadUrl'   => wp_nonce_url(
					admin_url( 'admin-post.php?action=rses_download_electoral_roll_export' ),
					\RelataSoft\SecureElectionSuite\Security\Nonce::RSES_ACTION_ELECTORAL_ROLL_SAMPLE,
					'_rses_nonce'
				),
				'i18n'          => array(
					'starting'        => __( 'Iniciando importação em pedaços…', 'relatasoft-secure-election-suite' ),
					'validating'      => __( 'Validando RSV…', 'relatasoft-secure-election-suite' ),
					'error'           => __( 'Falha na importação do cadastro eleitoral.', 'relatasoft-secure-election-suite' ),
					'cancelled'       => __( 'Importação do cadastro eleitoral cancelada.', 'relatasoft-secure-election-suite' ),
					'noFile'          => __( 'Escolher primeiro um arquivo .rsv.', 'relatasoft-secure-election-suite' ),
					'tooLarge'        => __( 'O arquivo RSV é grande demais para importar.', 'relatasoft-secure-election-suite' ),
					'noJs'            => __( 'O JavaScript não conseguiu iniciar a importação. Atualizar a página e tentar novamente.', 'relatasoft-secure-election-suite' ),
					'finished'        => __( 'Importação do cadastro eleitoral concluída. Criados: %1$d. Atualizados: %2$d. Ignorados: %3$d. Erros: %4$d.', 'relatasoft-secure-election-suite' ),
					'errorsDesc'      => __( '%d problema(s) reportado(s). Revisar a tabela ou baixar o relatório RSV.', 'relatasoft-secure-election-suite' ),
					'errorsTruncated' => __( 'Erros adicionais foram omitidos nesta pré-visualização; baixe o RSV para a amostra guardada.', 'relatasoft-secure-election-suite' ),
					'exportStarting'  => __( 'Iniciando exportação .rsv…', 'relatasoft-secure-election-suite' ),
					'exportDone'      => __( 'Exportação .rsv concluída.', 'relatasoft-secure-election-suite' ),
					'exportError'     => __( 'Falha na exportação do cadastro eleitoral.', 'relatasoft-secure-election-suite' ),
					'exportCancelled' => __( 'Exportação cancelada.', 'relatasoft-secure-election-suite' ),
					'stages'          => array(
						'receiving' => __( 'Recebendo', 'relatasoft-secure-election-suite' ),
						'ready'     => __( 'A validar', 'relatasoft-secure-election-suite' ),
						'importing' => __( 'Importando', 'relatasoft-secure-election-suite' ),
						'preparing' => __( 'Preparando', 'relatasoft-secure-election-suite' ),
						'exporting' => __( 'Exportando', 'relatasoft-secure-election-suite' ),
						'complete'  => __( 'Concluído', 'relatasoft-secure-election-suite' ),
						'failed'    => __( 'Falha', 'relatasoft-secure-election-suite' ),
						'cancelled' => __( 'Cancelado', 'relatasoft-secure-election-suite' ),
					),
				),
			)
		);
		wp_enqueue_script( 'rses-electoral-roll' );
	}

	/**
	 * Render voting booth shortcode.
	 *
	 * @param array<string,mixed> $atts Shortcode attributes.
	 * @return string
	 */
	public function rses_render_voting_booth_shortcode( array $atts = array() ): string {
		if ( ! ModeLock::rses_is_mode( 'voting' ) ) {
			return '<p>' . esc_html__( 'Voting is not available on this site.', 'relatasoft-secure-election-suite' ) . '</p>';
		}

		$atts = shortcode_atts(
			array(
				'election_id' => 0,
				'round_id'    => 0,
			),
			$atts,
			'rses_voting_booth'
		);

		$rses_attr_election = absint( $atts['election_id'] );
		$rses_attr_round    = absint( $atts['round_id'] );

		// Query args only fill blank shortcode attributes. A page may host several
		// pinned booths (one open, one not yet open); GET must not force every
		// shortcode onto the same election/round.
		$rses_get_election = isset( $_GET['election_id'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? absint( $_GET['election_id'] )
			: 0;
		$rses_get_round    = isset( $_GET['round_id'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? absint( $_GET['round_id'] )
			: 0;

		$rses_election_id = $rses_attr_election > 0 ? $rses_attr_election : $rses_get_election;
		$rses_round_id    = $rses_attr_round > 0 ? $rses_attr_round : $rses_get_round;

		ob_start();
		\RelataSoft\SecureElectionSuite\Voting\VotingViews::rses_render_voting_booth(
			$rses_election_id,
			$rses_round_id
		);
		return (string) ob_get_clean();
	}

	/**
	 * Render voter receipt shortcode.
	 *
	 * @param array<string,mixed> $atts Shortcode attributes.
	 * @return string
	 */
	public function rses_render_voter_receipt_shortcode( array $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'election_id' => 0,
				'round_id'    => 0,
			),
			$atts,
			'rses_voter_receipt'
		);

		ob_start();
		\RelataSoft\SecureElectionSuite\Voting\VotingViews::rses_render_voter_receipt(
			absint( $atts['election_id'] ),
			absint( $atts['round_id'] )
		);
		return (string) ob_get_clean();
	}

	/**
	 * Render election status shortcode.
	 *
	 * @param array<string,mixed> $atts Shortcode attributes.
	 * @return string
	 */
	public function rses_render_election_status_shortcode( array $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'election_id' => 0,
			),
			$atts,
			'rses_election_status'
		);

		ob_start();
		\RelataSoft\SecureElectionSuite\Voting\VotingViews::rses_render_election_status( absint( $atts['election_id'] ) );
		return (string) ob_get_clean();
	}
}
