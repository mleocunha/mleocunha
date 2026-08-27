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
 * Build a /painel/*.php stub that keeps WordPress $pagenow correct.
 *
 * @param string $base Basename under wp-admin (e.g. admin.php).
 */
function ve_painel_mu_stub_php( string $base ): string {
	return "<?php\n"
		. "/**\n * Voto Eletrônico — gateway do Painel de Controle Eleitoral.\n"
		. " * Arquivo gerado automaticamente. Não editar.\n"
		. " * Stub-Version: 2\n */\n"
		. "define( 'VE_ADMIN_ENTRY', true );\n"
		. "\$_SERVER['PHP_SELF']   = '/wp-admin/{$base}';\n"
		. "\$_SERVER['SCRIPT_NAME'] = '/wp-admin/{$base}';\n"
		. "require dirname( __DIR__ ) . '/wp-admin/{$base}';\n";
}

/**
 * Create/refresh ABSPATH/painel/*.php stubs and id.php as early as possible.
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
		$target  = $admin_dir . '/' . $base;
		$php     = ve_painel_mu_stub_php( $base );
		$current = is_readable( $target ) ? (string) file_get_contents( $target ) : '';
		// Refresh v1 stubs that omitted PHP_SELF normalization.
		if ( $current === $php || ( is_readable( $target ) && str_contains( $current, 'Stub-Version: 2' ) ) ) {
			continue;
		}
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
	if ( function_exists( 'str_ends_with' ) && ! str_ends_with( $site, '/' ) ) {
		$site .= '/';
	} elseif ( substr( $site, -1 ) !== '/' ) {
		$site .= '/';
	}
	return $site . 'painel';
}

/**
 * Mirror AUTH/SECURE_AUTH cookies onto /painel so sessions work outside /wp-admin.
 *
 * @param mixed $auth_cookie Cookie value.
 * @param mixed $expire      Expiry.
 * @param mixed $expiration  Duration.
 * @param mixed $user_id     User ID.
 * @param mixed $scheme      auth|secure_auth.
 * @param mixed $token       Session token.
 */
function ve_painel_mu_mirror_auth_cookie( $auth_cookie, $expire = 0, $expiration = 0, $user_id = 0, $scheme = '', $token = '' ): void {
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
 * @param mixed $login_url Login URL.
 * @return mixed
 */
function ve_painel_mu_filter_login_url( $login_url ) {
	if ( ! is_string( $login_url ) ) {
		return $login_url;
	}
	return str_replace( 'wp-login.php', 'id.php', $login_url );
}

/**
 * @param mixed $location Redirect target.
 * @return mixed
 */
function ve_painel_mu_filter_redirect( $location ) {
	if ( ! is_string( $location ) ) {
		return $location;
	}
	return str_replace( 'wp-login.php', 'id.php', $location );
}

ve_painel_mu_ensure_gateway();

/**
 * Classic WP screens retired from the Painel (404 even via /painel stubs).
 *
 * @return list<string>
 */
function ve_painel_mu_retired_admin_screens(): array {
	return array(
		'plugins.php',
		'plugin-install.php',
		'plugin-editor.php',
		'themes.php',
		'theme-install.php',
		'theme-editor.php',
		'site-editor.php',
		'customize.php',
		'widgets.php',
		'nav-menus.php',
		'users.php',
		'user-new.php',
		'edit.php',
		'edit-comments.php',
		'edit-tags.php',
		'post-new.php',
		'upload.php',
		'media-new.php',
		'tools.php',
		'import.php',
		'export.php',
		'export-personal-data.php',
		'erase-personal-data.php',
		'site-health.php',
		'options-general.php',
		'options-writing.php',
		'options-reading.php',
		'options-discussion.php',
		'options-media.php',
		'options-permalink.php',
		'options-privacy.php',
		'privacy.php',
		'privacy-policy-guide.php',
		'update-core.php',
		'about.php',
		'credits.php',
		'freedoms.php',
		'contribute.php',
		'press-this.php',
	);
}

/**
 * When disguise stubs exist, classic /wp-admin and /wp-login.php must 404
 * like any missing URL (no redirect that maps the disguise).
 * Also 404 retired classic screens under /painel (e.g. /painel/plugins.php).
 */
function ve_painel_mu_block_classic_surfaces(): void {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return;
	}
	if ( defined( 'VE_LOGIN_ENTRY' ) && VE_LOGIN_ENTRY ) {
		return;
	}
	if ( ! defined( 'ABSPATH' ) ) {
		return;
	}

	$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
	$path = parse_url( $uri, PHP_URL_PATH );
	$path = is_string( $path ) ? rawurldecode( $path ) : '';
	$base = strtolower( (string) basename( $path ) );

	$login_ready = is_readable( trailingslashit( ABSPATH ) . 'id.php' );
	$admin_ready = is_readable( trailingslashit( ABSPATH ) . 'painel/admin.php' );

	if ( $login_ready && preg_match( '#(^|/)wp-login\.php$#', $path ) ) {
		ve_painel_mu_send_404();
	}

	// /painel/plugins.php (and other retired WP screens) → 404.
	if ( $admin_ready
		&& $base !== ''
		&& in_array( $base, ve_painel_mu_retired_admin_screens(), true )
		&& preg_match( '#(^|/)(painel|wp-admin)(/|$)#', $path )
	) {
		ve_painel_mu_send_404();
	}

	// Skip further /wp-admin blocking when the request entered via /painel stubs.
	if ( defined( 'VE_ADMIN_ENTRY' ) && VE_ADMIN_ENTRY ) {
		return;
	}

	if ( ! $admin_ready || ! preg_match( '#(^|/)wp-admin(/|$)#', $path ) ) {
		return;
	}
	// Static theme of the admin chrome (css/js/images) + load-*.php aggregators only.
	if ( preg_match( '/\.(css|js|map|png|jpe?g|gif|svg|webp|woff2?|ttf|eot|ico)(\?|$)/i', $path ) ) {
		return;
	}
	if ( in_array( $base, array( 'load-scripts.php', 'load-styles.php' ), true ) ) {
		return;
	}
	ve_painel_mu_send_404();
}

/**
 * Emit branded 404 ("Página Inexistente") and stop.
 */
function ve_painel_mu_send_404(): void {
	$not_found = WP_PLUGIN_DIR . '/relatasoft-secure-election-suite/src/Adapters/WordPress/Platform/NotFoundPage.php';
	if ( is_readable( $not_found ) ) {
		require_once $not_found;
		if ( class_exists( '\\RelataSoft\\SecureElectionSuite\\Painel\\Adapters\\WordPress\\Platform\\NotFoundPage', false ) ) {
			\RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Platform\NotFoundPage::renderAndExit();
		}
	}

	if ( function_exists( 'status_header' ) ) {
		status_header( 404 );
	} else {
		http_response_code( 404 );
	}
	if ( function_exists( 'nocache_headers' ) ) {
		nocache_headers();
	}
	if ( ! headers_sent() ) {
		header( 'Content-Type: text/html; charset=UTF-8', true );
		header( 'X-Robots-Tag: noindex, nofollow', true );
	}
	$logo = ( function_exists( 'content_url' ) )
		? content_url( 'plugins/relatasoft-secure-election-suite/assets/brand/relatasoft-404-lockup.png' )
		: '/wp-content/plugins/relatasoft-secure-election-suite/assets/brand/relatasoft-404-lockup.png';
	$logo = htmlspecialchars( $logo, ENT_QUOTES, 'UTF-8' );
	echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"/><meta name="viewport" content="width=device-width, initial-scale=1"/><title>Página Inexistente</title>'
		. '<style>html,body{margin:0;background:#000;color:#fff;min-height:100%}body{min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:"Avenir Next","Nunito Sans","Trebuchet MS",sans-serif}.ve{text-align:center;padding:2rem 1rem}img{width:min(92vw,20rem);height:auto;margin:0 auto 2rem;display:block}h1{font-size:clamp(1.65rem,4.5vw,2.15rem);margin:0 0 .75rem}p{margin:0;color:rgba(255,255,255,.72)}</style></head><body><main class="ve">'
		. '<img src="' . $logo . '" width="323" height="82" alt="RelataSoft — Participação mais Inteligente"/>'
		. '<h1>Página Inexistente</h1><p>O endereço informado não existe neste site.</p></main></body></html>';
	exit;
}

add_action( 'muplugins_loaded', 've_painel_mu_block_classic_surfaces', 0 );
add_action( 'plugins_loaded', 've_painel_mu_block_classic_surfaces', 0 );

add_action( 'set_auth_cookie', 've_painel_mu_mirror_auth_cookie', 10, 6 );
add_action( 'clear_auth_cookie', 've_painel_mu_clear_auth_cookie', 10 );
add_filter( 'login_url', 've_painel_mu_filter_login_url', 5 );
add_filter( 'logout_url', 've_painel_mu_filter_login_url', 5 );
add_filter( 'wp_redirect', 've_painel_mu_filter_redirect', 5 );
