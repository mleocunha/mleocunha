<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Journey;

use RelataSoft\SecureElectionSuite\Bootstrap\ModeLock;
use RelataSoft\SecureElectionSuite\I18n\Translator;
use RelataSoft\SecureElectionSuite\Painel\Application\Journey\JourneyGateway;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Journey\JourneySteps;

/**
 * Front controller for native elector routes under /voto/… (A5 / M5).
 *
 * Shortcodes remain thin adapters; electors do not need host pages.
 */
final class WordPressJourneyFrontController {

	public const QUERY_VAR = 'rses_journey';

	public static function register(): void {
		add_action( 'init', array( self::class, 'registerRewrites' ), 5 );
		add_filter( 'query_vars', array( self::class, 'addQueryVar' ) );
		add_action( 'template_redirect', array( self::class, 'dispatch' ), 0 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueueAssets' ) );
	}

	public static function registerRewrites(): void {
		add_rewrite_rule( '^voto/?$', 'index.php?' . self::QUERY_VAR . '=' . JourneySteps::WELCOME, 'top' );
		add_rewrite_rule( '^voto/boas-vindas/?$', 'index.php?' . self::QUERY_VAR . '=' . JourneySteps::WELCOME, 'top' );
		add_rewrite_rule( '^voto/cabina/?$', 'index.php?' . self::QUERY_VAR . '=' . JourneySteps::BOOTH, 'top' );
		add_rewrite_rule( '^voto/obrigado/?$', 'index.php?' . self::QUERY_VAR . '=' . JourneySteps::THANK_YOU, 'top' );
	}

	/**
	 * @param list<string> $vars
	 * @return list<string>
	 */
	public static function addQueryVar( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	public static function currentStep(): ?string {
		$step = get_query_var( self::QUERY_VAR, '' );
		if ( ! is_string( $step ) || '' === $step ) {
			return null;
		}
		return JourneySteps::isValid( $step ) ? $step : null;
	}

	public static function enqueueAssets(): void {
		$step = self::currentStep();
		if ( null === $step ) {
			return;
		}

		if ( ! ModeLock::rses_is_mode( ModeLock::RSES_MODE_VOTING ) && JourneySteps::BOOTH === $step ) {
			return;
		}

		wp_enqueue_style(
			'rses-journey-front',
			RSES_PLUGIN_URL . 'assets/css/journey-front.css',
			array(),
			RSES_VERSION
		);

		if ( JourneySteps::BOOTH === $step || JourneySteps::THANK_YOU === $step ) {
			wp_enqueue_script(
				'rses-voting',
				RSES_PLUGIN_URL . 'assets/js/voting.js',
				array( 'jquery' ),
				RSES_VERSION,
				true
			);
		}

		if ( JourneySteps::BOOTH === $step ) {
			wp_enqueue_style(
				'rses-frontend',
				RSES_PLUGIN_URL . 'assets/css/frontend.css',
				array(),
				RSES_VERSION
			);
		}
	}

	public static function dispatch(): void {
		$step = self::currentStep();
		if ( null === $step ) {
			return;
		}

		if ( ! JourneyGateway::isBooted() ) {
			return;
		}

		$status = 200;
		if ( JourneySteps::BOOTH === $step && ! ModeLock::rses_is_mode( ModeLock::RSES_MODE_VOTING ) ) {
			$status = 403;
		}

		$context = array(
			'election_id' => isset( $_GET['election_id'] ) ? absint( $_GET['election_id'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'round_id'    => isset( $_GET['round_id'] ) ? absint( $_GET['round_id'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		);

		$html = JourneyGateway::get()->render( $step, $context );

		status_header( $status );
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
		}

		self::renderShell( $step, $html );
		exit;
	}

	private static function renderShell( string $step, string $bodyHtml ): void {
		$title = match ( $step ) {
			JourneySteps::WELCOME   => __( 'Boas-vindas ao eleitor', 'relatasoft-secure-election-suite' ),
			JourneySteps::BOOTH     => __( 'Cabina de votação', 'relatasoft-secure-election-suite' ),
			JourneySteps::THANK_YOU => __( 'Obrigado por votar', 'relatasoft-secure-election-suite' ),
			default                 => __( 'Jornada do eleitor', 'relatasoft-secure-election-suite' ),
		};

		?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title><?php echo esc_html( $title ); ?></title>
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'rses-journey-route rses-journey-route--' . sanitize_html_class( $step ) ); ?> <?php echo Translator::rses_html_attrs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
<main class="rses-journey-shell" id="rses-journey-shell" data-rses-journey-step="<?php echo esc_attr( $step ); ?>">
	<?php echo $bodyHtml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- presenter HTML. ?>
</main>
<?php wp_footer(); ?>
</body>
</html>
		<?php
	}

	/**
	 * One-shot rewrite flush after A5 (or when version changes).
	 */
	public static function maybeFlushRewrites(): void {
		$flag = 'rses_journey_routes_version';
		$want = defined( 'RSES_VERSION' ) ? (string) RSES_VERSION : '1';
		if ( (string) get_option( $flag, '' ) === $want ) {
			return;
		}
		self::registerRewrites();
		flush_rewrite_rules( false );
		update_option( $flag, $want, false );
	}
}
