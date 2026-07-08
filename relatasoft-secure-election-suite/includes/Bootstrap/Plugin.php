<?php
/**
 * Main plugin bootstrap.
 *
 * @package RelataSoft\SecureElectionSuite\Bootstrap
 */

namespace RelataSoft\SecureElectionSuite\Bootstrap;

use RelataSoft\SecureElectionSuite\Admin\AdminMenu;
use RelataSoft\SecureElectionSuite\Admin\Notices;
use RelataSoft\SecureElectionSuite\Ajax\AjaxRouter;
use RelataSoft\SecureElectionSuite\Database\Migration;
use RelataSoft\SecureElectionSuite\KeyAuthority\KeyAuthorityController;
use RelataSoft\SecureElectionSuite\Tallying\OfficialShareSubmissionController;
use RelataSoft\SecureElectionSuite\Tallying\TallyImportController;
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
		add_action( 'plugins_loaded', array( $this, 'rses_load_textdomain' ) );
		add_action( 'init', array( $this, 'rses_init' ) );
		add_action( 'admin_init', array( Migration::class, 'rses_maybe_migrate' ) );

		if ( is_admin() ) {
			Notices::register();
			AdminMenu::register();
		}

		AjaxRouter::register();
		KeyAuthorityController::register();
		ElectionController::register();
		BallotController::register();
		TallyImportController::register();
		OfficialShareSubmissionController::register();

		add_shortcode( 'rses_voting_booth', array( $this, 'rses_render_voting_booth_shortcode' ) );
		add_shortcode( 'rses_voter_receipt', array( $this, 'rses_render_voter_receipt_shortcode' ) );
		add_shortcode( 'rses_election_status', array( $this, 'rses_render_election_status_shortcode' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'rses_enqueue_frontend_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'rses_enqueue_admin_assets' ) );
	}

	/**
	 * Load plugin text domain.
	 */
	public function rses_load_textdomain(): void {
		load_plugin_textdomain(
			'relatasoft-secure-election-suite',
			false,
			dirname( RSES_PLUGIN_BASENAME ) . '/languages'
		);
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
			'rses-admin',
			RSES_PLUGIN_URL . 'assets/css/admin.css',
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
			)
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function rses_enqueue_admin_assets( string $hook_suffix ): void {
		if ( strpos( $hook_suffix, 'rses-' ) === false && strpos( $hook_suffix, 'relatasoft-secure-election' ) === false ) {
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

		$mode = ModeLock::rses_get_mode();
		if ( 'key_authority' === $mode ) {
			wp_enqueue_script(
				'rses-key-authority',
				RSES_PLUGIN_URL . 'assets/js/key-authority.js',
				array( 'jquery', 'rses-admin' ),
				RSES_VERSION,
				true
			);
		} elseif ( 'tallying' === $mode ) {
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

		ob_start();
		\RelataSoft\SecureElectionSuite\Voting\VotingViews::rses_render_voting_booth(
			absint( $atts['election_id'] ),
			absint( $atts['round_id'] )
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
