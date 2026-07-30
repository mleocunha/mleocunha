<?php
/**
 * Rename wp-admin → central and wp-login.php → id.php (voting mode).
 *
 * @package RelataSoft\SecureElectionSuite\Security
 */

namespace RelataSoft\SecureElectionSuite\Security;

use RelataSoft\SecureElectionSuite\Bootstrap\ModeLock;
use RelataSoft\SecureElectionSuite\Frontend\JourneySettings;

defined( 'ABSPATH' ) || exit;

/**
 * Hardens public login / admin entry URLs without breaking AJAX postbacks.
 */
class AdminUrlHardener {

	public const ADMIN_SLUG = 'central';
	public const LOGIN_SLUG = 'id.php';

	/**
	 * Register URL hardening (safe to call on every request).
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'rses_register_rewrites' ), 5 );
		add_action( 'init', array( self::class, 'rses_maybe_serve_custom_paths' ), 0 );
		add_action( 'admin_init', array( self::class, 'rses_redirect_legacy_admin' ), 0 );
		add_action( 'login_init', array( self::class, 'rses_block_default_login' ), 0 );

		add_filter( 'login_url', array( self::class, 'rses_filter_login_url' ), 10, 3 );
		add_filter( 'logout_url', array( self::class, 'rses_filter_logout_url' ), 10, 2 );
		add_filter( 'lostpassword_url', array( self::class, 'rses_filter_lostpassword_url' ), 10, 2 );
		add_filter( 'register_url', array( self::class, 'rses_filter_register_url' ), 10, 1 );
		add_filter( 'site_url', array( self::class, 'rses_filter_site_url' ), 10, 4 );
		add_filter( 'network_site_url', array( self::class, 'rses_filter_network_site_url' ), 10, 3 );
		add_filter( 'admin_url', array( self::class, 'rses_filter_admin_url' ), 10, 3 );
		add_filter( 'wp_redirect', array( self::class, 'rses_filter_redirect' ), 10, 1 );
	}

	/**
	 * Whether hardening is active (voting mode + setting).
	 */
	public static function rses_is_enabled(): bool {
		if ( ! ModeLock::rses_is_mode( ModeLock::RSES_MODE_VOTING ) ) {
			return false;
		}

		$settings = JourneySettings::rses_get();
		return ! empty( $settings['url_hardening_enabled'] );
	}

	/**
	 * Public login URL using id.php.
	 */
	public static function rses_login_url( string $redirect = '', bool $force_reauth = false ): string {
		$args = array();
		if ( '' !== $redirect ) {
			$args['redirect_to'] = $redirect;
		}
		if ( $force_reauth ) {
			$args['reauth'] = '1';
		}

		$url = home_url( '/' . self::LOGIN_SLUG );
		return empty( $args ) ? $url : add_query_arg( $args, $url );
	}

	/**
	 * Register soft rewrite markers (flush on activate / setting change).
	 */
	public static function rses_register_rewrites(): void {
		if ( ! self::rses_is_enabled() ) {
			return;
		}

		add_rewrite_rule( '^' . preg_quote( self::LOGIN_SLUG, '/' ) . '/?$', 'index.php?rses_login=1', 'top' );
		add_rewrite_rule( '^' . preg_quote( self::ADMIN_SLUG, '/' ) . '/?$', 'index.php?rses_central=1', 'top' );
		add_rewrite_rule( '^' . preg_quote( self::ADMIN_SLUG, '/' ) . '/(.+)$', 'index.php?rses_central=1&rses_central_path=$matches[1]', 'top' );
		add_rewrite_tag( '%rses_login%', '([0-9]+)' );
		add_rewrite_tag( '%rses_central%', '([0-9]+)' );
		add_rewrite_tag( '%rses_central_path%', '(.+)' );
	}

	/**
	 * Serve custom login / admin paths from the front controller.
	 */
	public static function rses_maybe_serve_custom_paths(): void {
		if ( ! self::rses_is_enabled() ) {
			return;
		}

		if ( is_admin() && empty( $GLOBALS['rses_serving_custom_admin'] ) ) {
			return;
		}

		$path = self::rses_request_path();
		if ( '' === $path ) {
			return;
		}

		if ( self::LOGIN_SLUG === $path || str_ends_with( $path, '/' . self::LOGIN_SLUG ) ) {
			self::rses_serve_login();
		}

		if ( self::ADMIN_SLUG === $path || str_starts_with( $path, self::ADMIN_SLUG . '/' ) ) {
			$rest = ( self::ADMIN_SLUG === $path ) ? '' : substr( $path, strlen( self::ADMIN_SLUG ) + 1 );
			self::rses_serve_admin( (string) $rest );
		}
	}

	/**
	 * Redirect browser hits on /wp-admin to /central (except ajax/post endpoints).
	 */
	public static function rses_redirect_legacy_admin(): void {
		if ( ! self::rses_is_enabled() ) {
			return;
		}

		if ( ! empty( $GLOBALS['rses_serving_custom_admin'] ) ) {
			return;
		}

		if ( wp_doing_ajax() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		$script = isset( $_SERVER['SCRIPT_NAME'] ) ? (string) $_SERVER['SCRIPT_NAME'] : '';
		$base   = basename( $script );
		if ( in_array( $base, array( 'admin-ajax.php', 'admin-post.php', 'async-upload.php' ), true ) ) {
			return;
		}

		$path  = self::rses_request_path();
		$rest  = preg_replace( '#^wp-admin/?#', '', $path );
		$rest  = is_string( $rest ) ? ltrim( $rest, '/' ) : '';
		$target = home_url( '/' . self::ADMIN_SLUG . ( '' !== $rest ? '/' . $rest : '' ) );
		$query  = isset( $_SERVER['QUERY_STRING'] ) ? (string) wp_unslash( $_SERVER['QUERY_STRING'] ) : '';
		if ( '' !== $query ) {
			$target .= ( str_contains( $target, '?' ) ? '&' : '?' ) . $query;
		}

		wp_safe_redirect( $target, 302 );
		exit;
	}

	/**
	 * Hide direct wp-login.php when hardening is on.
	 */
	public static function rses_block_default_login(): void {
		if ( ! self::rses_is_enabled() ) {
			return;
		}

		if ( ! empty( $GLOBALS['rses_serving_custom_login'] ) ) {
			return;
		}

		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : 'login'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( in_array( $action, array( 'logout', 'postpass' ), true ) ) {
			return;
		}

		wp_safe_redirect( home_url( '/' ), 302 );
		exit;
	}

	/**
	 * @param string $login_url    Login URL.
	 * @param string $redirect     Redirect.
	 * @param bool   $force_reauth Reauth.
	 */
	public static function rses_filter_login_url( string $login_url, string $redirect, bool $force_reauth ): string {
		if ( ! self::rses_is_enabled() ) {
			return $login_url;
		}
		return self::rses_login_url( $redirect, $force_reauth );
	}

	/**
	 * @param string $logout_url Logout URL.
	 * @param string $redirect   Redirect.
	 */
	public static function rses_filter_logout_url( string $logout_url, string $redirect ): string {
		if ( ! self::rses_is_enabled() ) {
			return $logout_url;
		}

		$args = array( 'action' => 'logout' );
		if ( '' !== $redirect ) {
			$args['redirect_to'] = $redirect;
		}
		$url = add_query_arg( $args, home_url( '/' . self::LOGIN_SLUG ) );
		return wp_nonce_url( $url, 'log-out' );
	}

	/**
	 * @param string $url      Lost password URL.
	 * @param string $redirect Redirect.
	 */
	public static function rses_filter_lostpassword_url( string $url, string $redirect ): string {
		if ( ! self::rses_is_enabled() ) {
			return $url;
		}
		$args = array( 'action' => 'lostpassword' );
		if ( '' !== $redirect ) {
			$args['redirect_to'] = $redirect;
		}
		return add_query_arg( $args, home_url( '/' . self::LOGIN_SLUG ) );
	}

	/**
	 * @param string $url Register URL.
	 */
	public static function rses_filter_register_url( string $url ): string {
		if ( ! self::rses_is_enabled() ) {
			return $url;
		}
		return add_query_arg( array( 'action' => 'register' ), home_url( '/' . self::LOGIN_SLUG ) );
	}

	/**
	 * Replace wp-login.php in site_url generations.
	 *
	 * @param string      $url     URL.
	 * @param string      $path    Path.
	 * @param string|null $scheme  Scheme.
	 * @param int|null    $blog_id Blog id.
	 */
	public static function rses_filter_site_url( string $url, string $path = '', $scheme = null, $blog_id = null ): string {
		unset( $path, $scheme, $blog_id );

		if ( ! self::rses_is_enabled() ) {
			return $url;
		}

		if ( str_contains( $url, 'wp-login.php' ) ) {
			$url = str_replace( 'wp-login.php', self::LOGIN_SLUG, $url );
		}

		return $url;
	}

	/**
	 * Network site_url variant (3 args).
	 *
	 * @param string      $url    URL.
	 * @param string      $path   Path.
	 * @param string|null $scheme Scheme.
	 */
	public static function rses_filter_network_site_url( string $url, string $path = '', $scheme = null ): string {
		return self::rses_filter_site_url( $url, $path, $scheme, null );
	}

	/**
	 * Replace wp-admin with central in generated admin URLs.
	 *
	 * @param string   $url     URL.
	 * @param string   $path    Path.
	 * @param int|null $blog_id Blog id.
	 */
	public static function rses_filter_admin_url( string $url, string $path = '', $blog_id = null ): string {
		unset( $path, $blog_id );

		if ( ! self::rses_is_enabled() ) {
			return $url;
		}

		if ( str_contains( $url, 'admin-ajax.php' ) || str_contains( $url, 'admin-post.php' ) ) {
			return $url;
		}

		return str_replace( '/wp-admin', '/' . self::ADMIN_SLUG, $url );
	}

	/**
	 * Rewrite redirects that still point at legacy paths.
	 */
	public static function rses_filter_redirect( string $location ): string {
		if ( ! self::rses_is_enabled() || '' === $location ) {
			return $location;
		}

		$location = str_replace( 'wp-login.php', self::LOGIN_SLUG, $location );

		if ( str_contains( $location, '/wp-admin' ) && ! str_contains( $location, 'admin-ajax.php' ) && ! str_contains( $location, 'admin-post.php' ) ) {
			$location = str_replace( '/wp-admin', '/' . self::ADMIN_SLUG, $location );
		}

		return $location;
	}

	/**
	 * Flush rewrite rules after enabling/disabling.
	 */
	public static function rses_flush_rules(): void {
		self::rses_register_rewrites();
		flush_rewrite_rules( false );
	}

	/**
	 * Relative request path without home subdirectory.
	 */
	private static function rses_request_path(): string {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path = (string) ( wp_parse_url( $uri, PHP_URL_PATH ) ?? '' );
		$home = (string) ( wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ?? '' );
		$home = untrailingslashit( $home );

		if ( '' !== $home && str_starts_with( $path, $home ) ) {
			$path = substr( $path, strlen( $home ) );
		}

		return trim( $path, '/' );
	}

	/**
	 * Load WordPress login under /id.php.
	 */
	private static function rses_serve_login(): void {
		$GLOBALS['rses_serving_custom_login'] = true;

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['pagenow'] = 'wp-login.php';

		if ( ! defined( 'RSES_CUSTOM_LOGIN' ) ) {
			define( 'RSES_CUSTOM_LOGIN', true );
		}

		require_once ABSPATH . 'wp-login.php';
		exit;
	}

	/**
	 * Load a wp-admin script under /central/...
	 */
	private static function rses_serve_admin( string $rest ): void {
		$GLOBALS['rses_serving_custom_admin'] = true;

		$rest = str_replace( '\\', '/', $rest );
		$rest = ltrim( $rest, '/' );
		if ( '' === $rest || str_ends_with( $rest, '/' ) ) {
			$rest = 'index.php';
		}

		if ( str_contains( $rest, '..' ) || str_starts_with( $rest, '/' ) ) {
			status_header( 404 );
			nocache_headers();
			exit;
		}

		$target = ABSPATH . 'wp-admin/' . $rest;
		$real   = realpath( $target );
		$root   = realpath( ABSPATH . 'wp-admin' );

		if ( false === $real || false === $root || ! str_starts_with( $real, $root ) ) {
			// Allow missing realpath for not-yet-existing but valid php under wp-admin.
			$normalized = ABSPATH . 'wp-admin/' . $rest;
			if ( ! is_file( $normalized ) || ! str_ends_with( strtolower( $normalized ), '.php' ) ) {
				status_header( 404 );
				nocache_headers();
				exit;
			}
			$real = $normalized;
		}

		if ( is_dir( $real ) ) {
			$real .= '/index.php';
		}

		if ( ! is_file( $real ) || ! str_ends_with( strtolower( $real ), '.php' ) ) {
			status_header( 404 );
			nocache_headers();
			exit;
		}

		$relative = ltrim( str_replace( (string) $root, '', (string) realpath( $real ) ?: $real ), '/' );
		$_SERVER['PHP_SELF']        = '/wp-admin/' . $relative;
		$_SERVER['SCRIPT_NAME']     = $_SERVER['PHP_SELF'];
		$_SERVER['SCRIPT_FILENAME'] = $real;

		require $real;
		exit;
	}
}
