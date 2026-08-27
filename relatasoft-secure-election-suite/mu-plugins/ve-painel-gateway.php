<?php
/**
 * Must-use helper: materialize /painel gateway + /id.php without symlinks,
 * and mirror auth cookies onto /painel (WordPress defaults to /wp-admin only).
 * Loaded even if the main plugin is being activated/updated.
 *
 * @package RelataSoft_Secure_Election_Suite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Create ABSPATH/painel/*.php stubs and id.php as early as possible.
 */
function ve_painel_mu_ensure_gateway(): void {
	if ( ! defined( 'ABSPATH' ) ) {
		return;
	}

	$admin_slug = 'painel';
	$login_file = 'id.php';
	$admin_dir  = trailingslashit( ABSPATH ) . $admin_slug;
	$wp_admin   = trailingslashit( ABSPATH ) . 'wp-admin';

	// Login stub.
	$login_path = trailingslashit( ABSPATH ) . $login_file;
	if ( ! file_exists( $login_path ) ) {
		$stub = "<?php\n/** Voto Eletrônico — identificação */\ndefine( 'VE_LOGIN_ENTRY', true );\nrequire __DIR__ . '/wp-login.php';\n";
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		@file_put_contents( $login_path, $stub );
	}

	if ( ! is_dir( $wp_admin ) ) {
		return;
	}

	// Remove legacy symlink if present.
	if ( is_link( $admin_dir ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		@unlink( $admin_dir );
	}

	if ( ! is_dir( $admin_dir ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		if ( ! @mkdir( $admin_dir, 0755 ) && ! is_dir( $admin_dir ) ) {
			return;
		}
	}

	$marker = $admin_dir . '/.ve-admin-alias';
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	@file_put_contents( $marker, "Voto Eletrônico gateway\n" );

	$files = glob( $wp_admin . '/*.php' ) ?: array();
	foreach ( $files as $file ) {
		$base = basename( $file );
		if ( ! preg_match( '/^[a-z0-9_-]+\.php$/i', $base ) ) {
			continue;
		}
		$target = $admin_dir . '/' . $base;
		if ( is_readable( $target ) ) {
			continue;
		}
		$php = "<?php\ndefine( 'VE_ADMIN_ENTRY', true );\nrequire dirname( __DIR__ ) . '/wp-admin/{$base}';\n";
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		@file_put_contents( $target, $php );
	}
}

/**
 * Cookie path for /painel (same construction as ADMIN_COOKIE_PATH for wp-admin).
 */
function ve_painel_mu_auth_cookie_path(): string {
	$site = defined( 'SITECOOKIEPATH' ) ? (string) SITECOOKIEPATH : '/';
	if ( '' === $site ) {
		$site = '/';
	}
	if ( ! str_ends_with( $site, '/' ) ) {
		$site .= '/';
	}
	return $site . 'painel';
}

/**
 * Mirror AUTH/SECURE_AUTH cookies onto /painel so sessions work outside /wp-admin.
 *
 * @param string $auth_cookie Cookie value.
 * @param int    $expire      Expiry.
 * @param int    $expiration  Duration.
 * @param int    $user_id     User ID.
 * @param string $scheme      auth|secure_auth.
 * @param string $token       Session token.
 */
function ve_painel_mu_mirror_auth_cookie( $auth_cookie, $expire, $expiration, $user_id, $scheme, $token = '' ): void {
	unset( $expiration, $user_id, $token );
	if ( ! is_string( $auth_cookie ) || '' === $auth_cookie ) {
		return;
	}
	if ( ! defined( 'AUTH_COOKIE' ) || ! defined( 'SECURE_AUTH_COOKIE' ) || ! defined( 'COOKIE_DOMAIN' ) ) {
		return;
	}
	$secure = ( 'secure_auth' === $scheme );
	$name   = $secure ? SECURE_AUTH_COOKIE : AUTH_COOKIE;
	// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie
	setcookie( $name, $auth_cookie, (int) $expire, ve_painel_mu_auth_cookie_path(), COOKIE_DOMAIN, $secure, true );
}

/**
 * Clear mirrored /painel auth cookies.
 */
function ve_painel_mu_clear_auth_cookie(): void {
	if ( ! defined( 'AUTH_COOKIE' ) || ! defined( 'SECURE_AUTH_COOKIE' ) || ! defined( 'COOKIE_DOMAIN' ) ) {
		return;
	}
	$path    = ve_painel_mu_auth_cookie_path();
	$expired = time() - 31536000;
	// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie
	setcookie( AUTH_COOKIE, ' ', $expired, $path, COOKIE_DOMAIN );
	// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie
	setcookie( SECURE_AUTH_COOKIE, ' ', $expired, $path, COOKIE_DOMAIN );
}

/**
 * Keep public login links on /id.php even while the main plugin is mid-activate.
 *
 * @param string $login_url Login URL.
 */
function ve_painel_mu_filter_login_url( string $login_url ): string {
	return str_replace( 'wp-login.php', 'id.php', $login_url );
}

/**
 * @param string $location Redirect target.
 */
function ve_painel_mu_filter_redirect( string $location ): string {
	return str_replace( 'wp-login.php', 'id.php', $location );
}

ve_painel_mu_ensure_gateway();

add_action( 'set_auth_cookie', 've_painel_mu_mirror_auth_cookie', 10, 6 );
add_action( 'clear_auth_cookie', 've_painel_mu_clear_auth_cookie', 10 );
add_filter( 'login_url', 've_painel_mu_filter_login_url', 5 );
add_filter( 'logout_url', 've_painel_mu_filter_login_url', 5 );
add_filter( 'wp_redirect', 've_painel_mu_filter_redirect', 5 );
