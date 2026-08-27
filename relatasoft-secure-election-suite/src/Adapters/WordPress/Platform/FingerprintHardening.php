<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Platform;

use RelataSoft\SecureElectionSuite\Painel\Core\PainelKernel;
use RelataSoft\SecureElectionSuite\Painel\Domain\Platform\UrlMaskConfig;

/**
 * Reduce WordPress surface visible to bots, crawlers and casual fingerprinting.
 */
final class FingerprintHardening {

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'init', array( self::class, 'harden' ), 1 );
		add_action( 'send_headers', array( self::class, 'sendHeaders' ) );
		add_filter( 'the_generator', '__return_empty_string', 100 );
		add_filter( 'robots_txt', array( self::class, 'robotsTxt' ), 100, 2 );
		add_filter( 'xmlrpc_enabled', array( self::class, 'disableXmlrpc' ) );
		add_filter( 'wp_headers', array( self::class, 'filterHeaders' ) );
		add_filter( 'style_loader_src', array( self::class, 'stripVersionQuery' ), 100 );
		add_filter( 'script_loader_src', array( self::class, 'stripVersionQuery' ), 100 );
	}

	public static function enabled(): bool {
		$kernel = PainelKernel::instance();
		if ( ! $kernel ) {
			return true;
		}
		$cfg = $kernel->settingsService->get();
		return ! array_key_exists( 'hide_platform_fingerprint', $cfg ) || ! empty( $cfg['hide_platform_fingerprint'] );
	}

	public static function harden(): void {
		if ( ! self::enabled() ) {
			return;
		}

		remove_action( 'wp_head', 'wp_generator' );
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wlwmanifest_link' );
		remove_action( 'wp_head', 'wp_shortlink_wp_head', 10 );
		remove_action( 'wp_head', 'rest_output_link_wp_head', 10 );
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		remove_action( 'template_redirect', 'rest_output_link_header', 11 );
		remove_action( 'wp_head', 'adjacent_posts_rel_link_wp_head', 10 );

		add_filter( 'rest_authentication_errors', array( self::class, 'restrictRest' ), 99 );

		self::blockSensitiveFiles();
	}

	public static function sendHeaders(): void {
		if ( ! self::enabled() || headers_sent() ) {
			return;
		}
		header_remove( 'X-Powered-By' );
	}

	/**
	 * @param array<string, string> $headers
	 * @return array<string, string>
	 */
	public static function filterHeaders( array $headers ): array {
		if ( ! self::enabled() ) {
			return $headers;
		}
		unset( $headers['X-Pingback'], $headers['X-Powered-By'] );
		return $headers;
	}

	public static function disableXmlrpc( $enabled ) {
		if ( self::enabled() ) {
			return false;
		}
		return $enabled;
	}

	/**
	 * @param mixed $result
	 * @return mixed
	 */
	public static function restrictRest( $result ) {
		if ( ! self::enabled() ) {
			return $result;
		}
		if ( true === $result || is_wp_error( $result ) ) {
			return $result;
		}
		if ( is_user_logged_in() ) {
			return $result;
		}
		return new \WP_Error(
			've_rest_disabled',
			__( 'API indisponível.', 'relatasoft-secure-election-suite' ),
			array( 'status' => 404 )
		);
	}

	public static function stripVersionQuery( $src ) {
		if ( ! is_string( $src ) || ! self::enabled() || $src === '' ) {
			return $src;
		}
		if ( str_contains( $src, 'ver=' ) ) {
			$src = remove_query_arg( 'ver', $src );
		}
		return $src;
	}

	/**
	 * @param mixed $output Robots.txt body.
	 * @param mixed $public Blog public flag.
	 * @return mixed
	 */
	public static function robotsTxt( $output, $public = true ) {
		unset( $public );
		if ( ! is_string( $output ) || ! self::enabled() ) {
			return $output;
		}
		$admin = UrlMaskConfig::normalizeAdminPath(
			PainelKernel::instance()
				? (string) ( PainelKernel::instance()->settingsService->get()['admin_path'] ?? '' )
				: UrlMaskConfig::DEFAULT_ADMIN_PATH
		);
		$login = UrlMaskConfig::normalizeLoginPath(
			PainelKernel::instance()
				? (string) ( PainelKernel::instance()->settingsService->get()['login_path'] ?? '' )
				: UrlMaskConfig::DEFAULT_LOGIN_PATH
		);
		$extra = "\n# Voto Eletrônico — superfície de gestão\n"
			. "User-agent: *\n"
			. "Disallow: /wp-admin/\n"
			. "Disallow: /wp-includes/\n"
			. "Disallow: /wp-login.php\n"
			. "Disallow: /xmlrpc.php\n"
			. "Disallow: /{$admin}/\n"
			. "Disallow: /{$login}\n"
			. "Disallow: /wp-json/\n"
			. "Disallow: /?rest_route=\n";
		return $output . $extra;
	}

	/**
	 * 404 classic WP disclosure files when requested directly.
	 */
	private static function blockSensitiveFiles(): void {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		$path = strtolower( UrlMaskConfig::requestPath( $uri ) );
		$blocked = array(
			'/readme.html',
			'/license.txt',
			'/wp-config-sample.php',
			'/xmlrpc.php',
		);
		foreach ( $blocked as $needle ) {
			if ( str_ends_with( $path, $needle ) || $path === $needle ) {
				status_header( 404 );
				nocache_headers();
				if ( ! headers_sent() ) {
					header( 'X-Robots-Tag: noindex, nofollow', true );
				}
				exit;
			}
		}
	}
}
