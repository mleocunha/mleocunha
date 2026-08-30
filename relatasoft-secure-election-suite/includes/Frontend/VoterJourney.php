<?php
/**
 * Elector journey: native /voto routes, redirects, and thin shortcode adapters.
 *
 * @package RelataSoft\SecureElectionSuite\Frontend
 */

namespace RelataSoft\SecureElectionSuite\Frontend;

use RelataSoft\SecureElectionSuite\Bootstrap\ModeLock;
use RelataSoft\SecureElectionSuite\I18n\Translator;
use RelataSoft\SecureElectionSuite\Security\Capability;
use RelataSoft\SecureElectionSuite\Voting\EncryptedVoteRepository;
use RelataSoft\SecureElectionSuite\Voting\OpenElectionsService;

defined( 'ABSPATH' ) || exit;

/**
 * Welcome → booth → thank-you flow for Subscriber electors.
 */
class VoterJourney {

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_filter( 'login_redirect', array( self::class, 'rses_filter_login_redirect' ), 10, 3 );
		add_filter( 'logout_redirect', array( self::class, 'rses_filter_logout_redirect' ), 10, 3 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'rses_enqueue_journey_assets' ) );
	}

	/**
	 * Provision default journey pages (idempotent).
	 *
	 * @return array<string,int> Created or existing page IDs keyed by setting name.
	 */
	public static function rses_provision_pages(): array {
		$rses_settings = JourneySettings::rses_get();
		$rses_result   = array();

		$rses_pages = array(
			'welcome_page_id'   => array(
				'title'   => __( 'Voter Welcome', 'relatasoft-secure-election-suite' ),
				'slug'    => 'voter-welcome',
				'content' => self::rses_default_welcome_content(),
				'meta'    => 'welcome',
			),
			'booth_page_id'     => array(
				'title'   => __( 'Voting Booth', 'relatasoft-secure-election-suite' ),
				'slug'    => 'voting-booth',
				'content' => self::rses_default_booth_content(),
				'meta'    => 'booth',
			),
			'thank_you_page_id' => array(
				'title'   => __( 'Thank You for Voting', 'relatasoft-secure-election-suite' ),
				'slug'    => 'thank-you-for-voting',
				'content' => self::rses_default_thank_you_content(),
				'meta'    => 'thank_you',
			),
		);

		foreach ( $rses_pages as $rses_key => $rses_def ) {
			$rses_existing = absint( $rses_settings[ $rses_key ] ?? 0 );
			if ( $rses_existing > 0 && get_post( $rses_existing ) instanceof \WP_Post ) {
				$rses_result[ $rses_key ] = $rses_existing;
				continue;
			}

			$rses_by_slug = get_page_by_path( $rses_def['slug'], OBJECT, 'page' );
			if ( $rses_by_slug instanceof \WP_Post ) {
				$rses_result[ $rses_key ] = (int) $rses_by_slug->ID;
				continue;
			}

			$rses_id = wp_insert_post(
				array(
					'post_title'   => $rses_def['title'],
					'post_name'    => $rses_def['slug'],
					'post_content' => $rses_def['content'],
					'post_status'  => 'publish',
					'post_type'    => 'page',
					'post_author'  => self::rses_provision_author_id(),
				),
				true
			);

			if ( is_wp_error( $rses_id ) ) {
				continue;
			}

			update_post_meta( (int) $rses_id, JourneySettings::RSES_JOURNEY_META, $rses_def['meta'] );
			$rses_result[ $rses_key ] = (int) $rses_id;
		}

		if ( ! empty( $rses_result ) ) {
			JourneySettings::rses_save( $rses_result );
		}

		return $rses_result;
	}

	/**
	 * Author ID for auto-created pages.
	 */
	private static function rses_provision_author_id(): int {
		$rses_users = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			)
		);

		if ( ! empty( $rses_users[0] ) ) {
			return (int) $rses_users[0];
		}

		return 1;
	}

	/**
	 * Default welcome page block content.
	 */
	public static function rses_default_welcome_content(): string {
		return "<!-- wp:shortcode -->\n[rses_voter_welcome]\n<!-- /wp:shortcode -->";
	}

	/**
	 * Default thank-you page block content.
	 */
	public static function rses_default_thank_you_content(): string {
		return "<!-- wp:shortcode -->\n[rses_voter_thank_you]\n<!-- /wp:shortcode -->";
	}

	/**
	 * Default voting booth page block content.
	 *
	 * election_id / round_id come from the query string override used by welcome
	 * links and the Votador PoC (`?election_id=&round_id=`).
	 */
	public static function rses_default_booth_content(): string {
		return "<!-- wp:shortcode -->\n[rses_voting_booth]\n<!-- /wp:shortcode -->";
	}

	/**
	 * Redirect electors to the welcome page after login.
	 *
	 * @param string           $redirect_to           Redirect destination.
	 * @param string           $requested_redirect_to Requested redirect.
	 * @param \WP_User|\WP_Error $user                User object.
	 */
	public static function rses_filter_login_redirect( string $redirect_to, string $requested_redirect_to, $user ): string {
		if ( is_wp_error( $user ) || ! ModeLock::rses_is_mode( ModeLock::RSES_MODE_VOTING ) ) {
			return $redirect_to;
		}

		if ( ! Capability::rses_user_has_voter_role( (int) $user->ID ) ) {
			return $redirect_to;
		}

		$rses_admin_url = admin_url();
		if (
			( '' !== $redirect_to && 0 === strpos( $redirect_to, $rses_admin_url ) )
			|| ( '' !== $requested_redirect_to && 0 === strpos( $requested_redirect_to, $rses_admin_url ) )
		) {
			if (
				Capability::rses_user_has_admin_role( (int) $user->ID )
				|| Capability::rses_user_has_official_role( (int) $user->ID )
			) {
				return $redirect_to;
			}
		}

		if ( '' !== $requested_redirect_to && 0 === strpos( $requested_redirect_to, home_url() ) ) {
			if ( 0 !== strpos( $requested_redirect_to, $rses_admin_url ) ) {
				return $requested_redirect_to;
			}
		}

		$rses_welcome = JourneySettings::rses_page_url( 'welcome_page_id' );
		return $rses_welcome ?: $redirect_to;
	}

	/**
	 * Redirect after logout.
	 *
	 * @param string $redirect_to           Default redirect.
	 * @param string $requested_redirect_to Requested redirect.
	 * @param \WP_User $user                User.
	 */
	public static function rses_filter_logout_redirect( string $redirect_to, string $requested_redirect_to, $user ): string {
		unset( $requested_redirect_to, $user );

		if ( ! ModeLock::rses_is_mode( ModeLock::RSES_MODE_VOTING ) ) {
			return $redirect_to;
		}

		$rses_custom = JourneySettings::rses_get()['logout_redirect_url'] ?? '';
		if ( is_string( $rses_custom ) && '' !== $rses_custom ) {
			return esc_url_raw( $rses_custom );
		}

		$rses_welcome = JourneySettings::rses_page_url( 'welcome_page_id' );
		return $rses_welcome ?: $redirect_to;
	}

	/**
	 * Thank-you page URL with optional receipt query args.
	 *
	 * @param string $rses_receipt   Receipt hash.
	 * @param int    $rses_election  Election ID.
	 * @param int    $rses_round     Round ID.
	 */
	public static function rses_thank_you_redirect_url( string $rses_receipt, int $rses_election, int $rses_round ): string {
		$rses_url = JourneySettings::rses_page_url( 'thank_you_page_id' );
		if ( '' === $rses_url ) {
			return '';
		}

		return add_query_arg(
			array(
				'rses_receipt' => rawurlencode( $rses_receipt ),
				'election_id'  => $rses_election,
				'round_id'     => $rses_round,
			),
			$rses_url
		);
	}

	/**
	 * Login URL for electors (returns to welcome when configured).
	 */
	public static function rses_login_url(): string {
		$rses_welcome = JourneySettings::rses_page_url( 'welcome_page_id' );
		return wp_login_url( $rses_welcome ?: ( wp_get_referer() ?: home_url( '/' ) ) );
	}

	/**
	 * Enqueue journey page styles on welcome/thank-you routes.
	 */
	public static function rses_enqueue_journey_assets(): void {
		if ( ! ModeLock::rses_is_mode( ModeLock::RSES_MODE_VOTING ) ) {
			return;
		}

		if ( ! is_singular( 'page' ) ) {
			return;
		}

		$rses_id = get_queried_object_id();
		$rses_settings = JourneySettings::rses_get();
		$rses_journey_ids = array(
			absint( $rses_settings['welcome_page_id'] ?? 0 ),
			absint( $rses_settings['thank_you_page_id'] ?? 0 ),
		);

		if ( ! in_array( $rses_id, $rses_journey_ids, true ) ) {
			return;
		}

		wp_enqueue_style(
			'rses-journey-front',
			RSES_PLUGIN_URL . 'assets/css/journey-front.css',
			array(),
			RSES_VERSION
		);

		if ( $rses_id === absint( $rses_settings['thank_you_page_id'] ?? 0 ) ) {
			wp_enqueue_script(
				'rses-voting',
				RSES_PLUGIN_URL . 'assets/js/voting.js',
				array( 'jquery' ),
				RSES_VERSION,
				true
			);
		}
	}

	/**
	 * Render welcome shortcode (thin adapter over JourneyPresenter).
	 *
	 * @param array<string,mixed> $atts Attributes.
	 * @return string
	 */
	public static function rses_render_welcome_shortcode( array $atts = array() ): string {
		unset( $atts );

		if ( ! is_user_logged_in() ) {
			$rses_login = self::rses_login_url();
			ob_start();
			?>
			<div class="rses-journey" <?php echo Translator::rses_html_attrs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
				<div class="rses-journey-card">
					<p class="rses-journey-kicker"><?php esc_html_e( 'Electronic voting', 'relatasoft-secure-election-suite' ); ?></p>
					<h2 class="rses-journey-title"><?php esc_html_e( 'Welcome, elector', 'relatasoft-secure-election-suite' ); ?></h2>
					<p class="rses-journey-lead"><?php esc_html_e( 'Please sign in with your Identification and Secret to continue.', 'relatasoft-secure-election-suite' ); ?></p>
					<p class="rses-journey-actions">
						<a class="rses-journey-btn rses-journey-btn--primary" href="<?php echo esc_url( $rses_login ); ?>">
							<?php esc_html_e( 'Sign in', 'relatasoft-secure-election-suite' ); ?>
						</a>
					</p>
				</div>
			</div>
			<?php
			return (string) ob_get_clean();
		}

		$rses_booth  = JourneySettings::rses_page_url( 'booth_page_id' );
		$rses_open   = OpenElectionsService::rses_open_round_links();
		$rses_user   = get_current_user_id();
		$rses_snap   = OpenElectionsService::rses_snapshot();
		ob_start();
		?>
		<div
			class="rses-journey"
			data-rses-journey="welcome"
			data-rses-user-locale="<?php echo esc_attr( get_user_locale( get_current_user_id() ) ); ?>"
			<?php echo Translator::rses_html_attrs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		>
			<script type="application/json" id="rses-open-elections-json"><?php echo wp_json_encode( $rses_snap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON for automation. ?></script>
			<div class="rses-journey-card">
				<p class="rses-journey-kicker"><?php esc_html_e( 'Electronic voting', 'relatasoft-secure-election-suite' ); ?></p>
				<h2 class="rses-journey-title"><?php esc_html_e( 'Welcome and instructions', 'relatasoft-secure-election-suite' ); ?></h2>
				<div class="rses-journey-body">
					<ol class="rses-journey-steps">
						<li><?php esc_html_e( 'Review the ballot questions carefully before making your choices.', 'relatasoft-secure-election-suite' ); ?></li>
						<li><?php esc_html_e( 'Select your options, then confirm to cast your encrypted vote.', 'relatasoft-secure-election-suite' ); ?></li>
						<li><?php esc_html_e( 'Save your vote receipt — you will need it to verify your ballot was recorded.', 'relatasoft-secure-election-suite' ); ?></li>
						<li><?php esc_html_e( 'Each elector may vote only once per election round.', 'relatasoft-secure-election-suite' ); ?></li>
					</ol>
				</div>
				<?php if ( '' === $rses_booth ) : ?>
					<div class="rses-journey-notice">
						<?php esc_html_e( 'A cabina de votação ainda não está disponível. Confirme as rotas em Redirecionamentos ou contacte o administrador do sítio.', 'relatasoft-secure-election-suite' ); ?>
					</div>
				<?php elseif ( empty( $rses_open ) ) : ?>
					<div class="rses-journey-notice" data-rses-open-count="0">
						<?php esc_html_e( 'There are no open elections right now. Please check back later.', 'relatasoft-secure-election-suite' ); ?>
					</div>
				<?php else : ?>
					<div class="rses-open-elections" data-rses-open-count="<?php echo esc_attr( (string) count( $rses_open ) ); ?>">
						<p class="rses-open-elections-label"><?php esc_html_e( 'Open elections', 'relatasoft-secure-election-suite' ); ?></p>
						<ul class="rses-open-elections-list">
							<?php foreach ( $rses_open as $rses_item ) : ?>
								<?php
								$rses_voted = EncryptedVoteRepository::rses_has_voted_round( $rses_user, (int) $rses_item['round_id'] );
								$rses_href  = add_query_arg(
									array(
										'election_id' => (int) $rses_item['election_id'],
										'round_id'    => (int) $rses_item['round_id'],
									),
									$rses_booth
								);
								?>
								<li>
									<a
										class="rses-open-election-link<?php echo $rses_voted ? ' rses-open-election-link--voted' : ''; ?>"
										href="<?php echo esc_url( $rses_href ); ?>"
										data-rses-election-id="<?php echo esc_attr( (string) (int) $rses_item['election_id'] ); ?>"
										data-rses-round-id="<?php echo esc_attr( (string) (int) $rses_item['round_id'] ); ?>"
										data-rses-already-voted="<?php echo $rses_voted ? '1' : '0'; ?>"
									>
										<span class="rses-open-election-title"><?php echo esc_html( $rses_item['title'] ); ?></span>
										<?php if ( $rses_voted ) : ?>
											<span class="rses-open-election-status"><?php esc_html_e( 'Already voted', 'relatasoft-secure-election-suite' ); ?></span>
										<?php else : ?>
											<span class="rses-open-election-status"><?php esc_html_e( 'Enter booth', 'relatasoft-secure-election-suite' ); ?></span>
										<?php endif; ?>
									</a>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render thank-you shortcode.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 * @return string
	 */
	public static function rses_render_thank_you_shortcode( array $atts = array() ): string {
		unset( $atts );

		$rses_receipt = isset( $_GET['rses_receipt'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? sanitize_text_field( wp_unslash( $_GET['rses_receipt'] ) )
			: '';
		$rses_election_id = isset( $_GET['election_id'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? absint( $_GET['election_id'] )
			: 0;
		$rses_round_id    = isset( $_GET['round_id'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? absint( $_GET['round_id'] )
			: 0;
		$rses_welcome     = JourneySettings::rses_page_url( 'welcome_page_id' );
		$rses_audio_url   = $rses_round_id > 0
			? \RelataSoft\SecureElectionSuite\Voting\ElectionController::rses_get_round_end_audio_url( $rses_round_id )
			: '';

		ob_start();
		?>
		<div
			class="rses-journey rses-journey--thanks"
			data-rses-journey="thank-you"
			data-rses-election-id="<?php echo esc_attr( (string) $rses_election_id ); ?>"
			data-rses-round-id="<?php echo esc_attr( (string) $rses_round_id ); ?>"
			<?php echo Translator::rses_html_attrs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		>
			<?php if ( '' !== $rses_audio_url ) : ?>
				<audio class="rses-round-end-audio" autoplay preload="auto" src="<?php echo esc_url( $rses_audio_url ); ?>"></audio>
			<?php endif; ?>
			<div class="rses-journey-card">
				<p class="rses-journey-kicker"><?php esc_html_e( 'Vote recorded', 'relatasoft-secure-election-suite' ); ?></p>
				<h2 class="rses-journey-title"><?php esc_html_e( 'Thank you for participating', 'relatasoft-secure-election-suite' ); ?></h2>
				<p class="rses-journey-lead"><?php esc_html_e( 'Your encrypted ballot has been submitted successfully. You may now close this window or sign out.', 'relatasoft-secure-election-suite' ); ?></p>

				<?php if ( '' !== $rses_receipt ) : ?>
					<div class="rses-journey-receipt" data-rses-receipt="1">
						<p class="rses-journey-receipt-label"><?php esc_html_e( 'Your vote receipt', 'relatasoft-secure-election-suite' ); ?></p>
						<code class="rses-journey-receipt-hash" id="rses-journey-receipt-hash" data-rses-receipt-hash="1"><?php echo esc_html( $rses_receipt ); ?></code>
						<p class="rses-journey-actions">
							<button type="button" class="rses-journey-btn rses-copy-receipt" data-rses-target="rses-journey-receipt-hash" data-copied-label="<?php esc_attr_e( 'Copied!', 'relatasoft-secure-election-suite' ); ?>">
								<?php esc_html_e( 'Copy receipt', 'relatasoft-secure-election-suite' ); ?>
							</button>
						</p>
					</div>
				<?php endif; ?>

				<p class="rses-journey-actions rses-journey-actions--secondary">
					<?php if ( $rses_welcome ) : ?>
						<a class="rses-journey-btn rses-journey-btn--primary" data-rses-continue-voting="1" href="<?php echo esc_url( $rses_welcome ); ?>">
							<?php esc_html_e( 'Continue voting', 'relatasoft-secure-election-suite' ); ?>
						</a>
					<?php endif; ?>
					<a class="rses-journey-btn rses-journey-btn--ghost" href="<?php echo esc_url( wp_logout_url( $rses_welcome ?: home_url( '/' ) ) ); ?>">
						<?php esc_html_e( 'Sign out', 'relatasoft-secure-election-suite' ); ?>
					</a>
				</p>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
