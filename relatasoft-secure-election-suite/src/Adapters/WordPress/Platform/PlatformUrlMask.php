<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Platform;

use RelataSoft\SecureElectionSuite\Painel\Core\PainelKernel;
use RelataSoft\SecureElectionSuite\Painel\Domain\Platform\UrlMaskConfig;

/**
 * Public URL mask: /wp-admin → /painel, /wp-login.php → /id.php.
 *
 * Apache: .htaccess rewrite markers.
 * Nginx (and hosts that ignore .htaccess): filesystem alias
 *   ABSPATH/painel → wp-admin (symlink preferred; stub directory fallback).
 * Login: ABSPATH/id.php stub.
 */
final class PlatformUrlMask {

	private static bool $registered = false;
	public const ADMIN_ALIAS_MARKER = '.ve-admin-alias';
	public const QUERY_VAR = 've_painel_admin';

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'init', array( self::class, 'ensureArtifacts' ), 0 );
		add_action( 'init', array( self::class, 'registerRewrites' ), 1 );
		add_action( 'init', array( self::class, 'bootstrapRequest' ), 0 );
		add_action( 'login_init', array( self::class, 'bootstrapRequest' ), 0 );
		add_action( 'admin_notices', array( self::class, 'maybeAliasNotice' ) );
		add_filter( 'query_vars', array( self::class, 'addQueryVar' ) );
		add_action( 'parse_request', array( self::class, 'handleRewriteRequest' ), 1 );

		add_filter( 'admin_url', array( self::class, 'filterAdminUrl' ), 100, 3 );
		add_filter( 'network_admin_url', array( self::class, 'filterNetworkAdminUrl' ), 100, 2 );
		add_filter( 'site_url', array( self::class, 'filterSiteUrl' ), 100, 4 );
		add_filter( 'network_site_url', array( self::class, 'filterNetworkSiteUrl' ), 100, 3 );
		add_filter( 'login_url', array( self::class, 'filterLoginUrl' ), 100, 3 );
		add_filter( 'logout_url', array( self::class, 'filterLogoutUrl' ), 100, 2 );
		add_filter( 'lostpassword_url', array( self::class, 'filterLostpasswordUrl' ), 100, 2 );
		add_filter( 'register_url', array( self::class, 'filterRegisterUrl' ), 100 );
		add_filter( 'wp_redirect', array( self::class, 'filterRedirect' ), 100 );
		add_filter( 'user_request_action_email_content', array( self::class, 'filterEmailContent' ), 100 );
		add_filter( 'retrieve_password_message', array( self::class, 'filterEmailContent' ), 100 );
		add_filter( 'login_redirect', array( self::class, 'filterLoginRedirect' ), 100, 3 );
		add_action( 'login_header', array( self::class, 'forceLoginFormAction' ), 1 );
		add_action( 'login_footer', array( self::class, 'forceLoginFormAction' ), 1 );

		// Auth cookies are scoped to /wp-admin by default — also scope to /painel.
		add_action( 'set_auth_cookie', array( self::class, 'mirrorAuthCookieForPainel' ), 10, 6 );
		add_action( 'clear_auth_cookie', array( self::class, 'clearMirroredAuthCookie' ), 10 );

		// Front-controller early: /painel/* may arrive via index.php (nginx try_files).
		self::maybeFrontController();
		self::bootstrapRequest();
	}

	public static function enabled(): bool {
		$kernel = PainelKernel::instance();
		if ( ! $kernel ) {
			return true;
		}
		$cfg = $kernel->settingsService->get();
		return ! array_key_exists( 'mask_platform_urls', $cfg ) || ! empty( $cfg['mask_platform_urls'] );
	}

	public static function adminPath(): string {
		$kernel = PainelKernel::instance();
		$path   = $kernel ? (string) ( $kernel->settingsService->get()['admin_path'] ?? '' ) : '';
		return UrlMaskConfig::normalizeAdminPath( $path );
	}

	public static function loginPath(): string {
		$kernel = PainelKernel::instance();
		$path   = $kernel ? (string) ( $kernel->settingsService->get()['login_path'] ?? '' ) : '';
		return UrlMaskConfig::normalizeLoginPath( $path );
	}

	public static function ensureArtifacts(): void {
		if ( ! self::enabled() ) {
			return;
		}
		self::writeLoginStub();
		self::writeAdminGateway();
		self::writeHtaccessRules();
		self::writeNginxSnippet();
		self::installMuPlugin();
	}

	/** @param list<string> $vars */
	public static function addQueryVar( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	public static function registerRewrites(): void {
		if ( ! self::enabled() ) {
			return;
		}
		$slug = preg_quote( self::adminPath(), '#' );
		add_rewrite_rule( '^' . $slug . '/?$', 'index.php?' . self::QUERY_VAR . '=index.php', 'top' );
		add_rewrite_rule( '^' . $slug . '/(.+)$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
	}

	/**
	 * When WP rewrite delivers ?ve_painel_admin=admin.php, bootstrap that admin script.
	 *
	 * @param \WP $wp WP request.
	 */
	public static function handleRewriteRequest( $wp ): void {
		if ( ! self::enabled() || ! is_object( $wp ) || empty( $wp->query_vars[ self::QUERY_VAR ] ) ) {
			return;
		}
		self::bootstrapAdminScript( (string) $wp->query_vars[ self::QUERY_VAR ] );
	}

	/**
	 * If nginx handed /painel/... to index.php without rewrite vars, route here.
	 */
	public static function maybeFrontController(): void {
		if ( ! self::enabled() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		if ( defined( 'VE_ADMIN_ENTRY' ) || ( defined( 'WP_ADMIN' ) && WP_ADMIN ) ) {
			return;
		}
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		$path = UrlMaskConfig::requestPath( $uri );
		$slug = self::adminPath();
		if ( ! preg_match( '#/(?:' . preg_quote( $slug, '#' ) . ')(?:/(.*))?$#', $path, $m ) ) {
			return;
		}
		// Only when the current script is the WP front controller (not a gateway stub).
		$script = isset( $_SERVER['SCRIPT_FILENAME'] ) ? (string) $_SERVER['SCRIPT_FILENAME'] : '';
		if ( $script !== '' && str_contains( wp_normalize_path( $script ), '/' . $slug . '/' ) ) {
			return;
		}
		$rest = isset( $m[1] ) ? (string) $m[1] : '';
		self::bootstrapAdminScript( $rest !== '' ? $rest : 'index.php' );
	}

	/**
	 * Load a wp-admin PHP entry by basename (no directory traversal).
	 */
	private static function bootstrapAdminScript( string $rest ): void {
		$rest = rawurldecode( $rest );
		$rest = ltrim( $rest, '/' );
		if ( '' === $rest || str_ends_with( $rest, '/' ) ) {
			$rest = 'index.php';
		}
		// Drop query string if present in rewrite match.
		$qpos = strpos( $rest, '?' );
		if ( false !== $qpos ) {
			$rest = substr( $rest, 0, $qpos );
		}
		$base = basename( $rest );
		$base = preg_replace( '/\.php$/i', '', $base ) ?? $base;
		$base = sanitize_file_name( $base );
		if ( '' === $base || 'index' === $base ) {
			$base = 'index';
		}
		$base .= '.php';
		if ( ! preg_match( '/^[a-z0-9_-]+\.php$/i', $base ) ) {
			$base = 'index.php';
		}
		$file = trailingslashit( ABSPATH ) . 'wp-admin/' . $base;
		if ( ! is_readable( $file ) ) {
			return;
		}
		if ( ! defined( 'VE_ADMIN_ENTRY' ) ) {
			define( 'VE_ADMIN_ENTRY', true );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_require
		require $file;
		exit;
	}

	/**
	 * Warn when the public /painel gateway could not be materialized.
	 */
	public static function maybeAliasNotice(): void {
		if ( ! self::enabled() || ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( self::adminUrlMaskReady() ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>';
		echo esc_html__(
			'O gateway /painel/ ainda não está pronto. Use /wp-admin/ para ativar e gerir plugins. Depois da ativação, recarregue o site para gerar o gateway (sem links simbólicos).',
			'relatasoft-secure-election-suite'
		);
		echo '</p></div>';
	}

	/**
	 * Whether outbound admin links may use /painel (stubs must exist for nginx *.php).
	 */
	public static function adminUrlMaskReady(): bool {
		if ( ! self::enabled() ) {
			return false;
		}
		return self::adminGatewayExists();
	}

	public static function adminGatewayExists(): bool {
		if ( ! defined( 'ABSPATH' ) ) {
			return false;
		}
		$dir = trailingslashit( ABSPATH ) . self::adminPath();
		return is_dir( $dir )
			&& ! is_link( $dir )
			&& is_readable( $dir . '/' . self::ADMIN_ALIAS_MARKER )
			&& is_readable( $dir . '/admin.php' )
			&& is_readable( $dir . '/plugins.php' )
			&& is_readable( $dir . '/index.php' );
	}

	/**
	 * Real PHP gateway directory (no symlinks): each wp-admin/*.php gets a thin stub.
	 * Static assets keep using /wp-admin/ via URL filters.
	 */
	public static function writeAdminGateway(): bool {
		if ( ! defined( 'ABSPATH' ) ) {
			return false;
		}
		$admin_slug = self::adminPath();
		if ( '' === $admin_slug || false !== strpos( $admin_slug, '/' ) || false !== strpos( $admin_slug, '..' ) ) {
			return false;
		}
		$dir      = trailingslashit( ABSPATH ) . $admin_slug;
		$wp_admin = trailingslashit( ABSPATH ) . 'wp-admin';

		if ( ! is_dir( $wp_admin ) ) {
			return false;
		}

		// Replace legacy symlink with a proper gateway.
		if ( is_link( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $dir );
		}

		if ( file_exists( $dir ) && ! is_dir( $dir ) ) {
			return false;
		}

		return self::writeAdminStubDirectory( $dir, $wp_admin );
	}

	/**
	 * Create real stub PHP files that require the corresponding wp-admin scripts.
	 */
	private static function writeAdminStubDirectory( string $dir, string $wp_admin ): bool {
		if ( ! function_exists( 'wp_mkdir_p' ) ) {
			return false;
		}
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents(
			$dir . '/' . self::ADMIN_ALIAS_MARKER,
			"Voto Eletrônico — gateway do Painel (arquivos stub; não é symlink).\n"
		);

		// Deny directory listing.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $dir . '/index.php', self::stubPhp( 'index.php' ) );

		$files = glob( trailingslashit( $wp_admin ) . '*.php' ) ?: array();
		foreach ( $files as $file ) {
			$base = basename( $file );
			if ( ! preg_match( '/^[a-z0-9_-]+\.php$/i', $base ) ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dir . '/' . $base, self::stubPhp( $base ) );
		}

		return is_readable( $dir . '/admin.php' );
	}

	private static function stubPhp( string $base ): string {
		// WordPress (wp-includes/vars.php) derives $pagenow from PHP_SELF with
		// the regex #/wp-admin/#. Requests to /painel/admin.php do not match, so
		// $pagenow becomes index.php and user_can_access_admin_page() denies every
		// plugin screen ("Sem permissão para acessar esta página.").
		return "<?php\n"
			. "/**\n * Voto Eletrônico — gateway do Painel de Controle Eleitoral.\n"
			. " * Arquivo gerado automaticamente (não é link simbólico). Não editar.\n"
			. " * Stub-Version: 2\n */\n"
			. "define( 'VE_ADMIN_ENTRY', true );\n"
			. "\$_SERVER['PHP_SELF']   = '/wp-admin/{$base}';\n"
			. "\$_SERVER['SCRIPT_NAME'] = '/wp-admin/{$base}';\n"
			. "require dirname( __DIR__ ) . '/wp-admin/{$base}';\n";
	}

	/**
	 * Optional Nginx include (no server mutation — operator includes one file).
	 */
	public static function writeNginxSnippet(): void {
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return;
		}
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) || empty( $upload['basedir'] ) ) {
			return;
		}
		$slug = self::adminPath();
		$body = "# Voto Eletrônico — include no bloco server{} do Nginx (recomendado)\n"
			. "# Faz /painel/* chegar ao WordPress mesmo sem ficheiros .php físicos.\n"
			. "location ^~ /{$slug} {\n"
			. "    rewrite ^/{$slug}/?\$ /index.php?" . self::QUERY_VAR . "=index.php last;\n"
			. "    rewrite ^/{$slug}/(.+)\$ /index.php?" . self::QUERY_VAR . "=\$1 last;\n"
			. "}\n";
		$path = trailingslashit( (string) $upload['basedir'] ) . 've-painel-nginx.conf';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		@file_put_contents( $path, $body );
	}

	/**
	 * Install must-use helper so gateway exists even during plugin activate/update.
	 */
	public static function installMuPlugin(): bool {
		if ( ! defined( 'WP_CONTENT_DIR' ) || ! defined( 'RSES_PLUGIN_DIR' ) ) {
			return false;
		}
		$src = trailingslashit( RSES_PLUGIN_DIR ) . 'mu-plugins/ve-painel-gateway.php';
		if ( ! is_readable( $src ) ) {
			return false;
		}
		$dir = trailingslashit( WP_CONTENT_DIR ) . 'mu-plugins';
		if ( ! is_dir( $dir ) && function_exists( 'wp_mkdir_p' ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! is_dir( $dir ) ) {
			return false;
		}
		$dest = $dir . '/ve-painel-gateway.php';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
		return (bool) @copy( $src, $dest );
	}

	public static function removeAdminAlias(): void {
		if ( ! defined( 'ABSPATH' ) ) {
			return;
		}
		$dir = trailingslashit( ABSPATH ) . self::adminPath();
		if ( is_link( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $dir );
			return;
		}
		if ( is_dir( $dir ) && is_readable( $dir . '/' . self::ADMIN_ALIAS_MARKER ) ) {
			self::rrmdir( $dir );
		}
	}

	private static function rrmdir( string $dir ): void {
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $file ) {
			/** @var \SplFileInfo $file */
			$path = $file->getPathname();
			if ( $file->isLink() || $file->isFile() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				@unlink( $path );
			} elseif ( $file->isDir() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
				@rmdir( $path );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		@rmdir( $dir );
	}

	/**
	 * Keep CSS/JS/fonts on /wp-admin (public page URLs use /painel).
	 */
	private static function isStaticAdminAsset( string $path, string $url ): bool {
		return (bool) preg_match( '/\.(css|js|map|png|jpe?g|gif|svg|webp|woff2?|ttf|eot|ico)(\?|$)/i', $path . ' ' . $url );
	}

	/**
	 * Soft-handle classic login URL. Never lock the operator out of /wp-admin
	 * (activation/update must always work on nginx).
	 *
	 * Do NOT auto-redirect /wp-admin → /painel for "logged in" users: the auth
	 * cookie is path-scoped to /wp-admin unless mirrored (see mirrorAuthCookieForPainel).
	 * Redirecting with only the logged_in cookie causes a false login loop.
	 */
	public static function bootstrapRequest(): void {
		if ( ! self::enabled() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		$path   = UrlMaskConfig::requestPath( $uri );
		$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );

		if ( defined( UrlMaskConfig::LOGIN_ENTRY_CONSTANT ) && constant( UrlMaskConfig::LOGIN_ENTRY_CONSTANT ) ) {
			global $pagenow;
			$pagenow = 'wp-login.php';
			return;
		}

		if ( UrlMaskConfig::isWpLoginPath( $path ) ) {
			// Allow credential POST on classic path (plugins / recovery); rewrite GETs to /id.php.
			if ( 'POST' === $method ) {
				return;
			}
			if ( function_exists( 'wp_safe_redirect' ) && function_exists( 'home_url' ) ) {
				$target = home_url( '/' . ltrim( self::loginPath(), '/' ) );
				$query  = parse_url( $uri, PHP_URL_QUERY );
				if ( is_string( $query ) && $query !== '' ) {
					$target .= ( str_contains( $target, '?' ) ? '&' : '?' ) . $query;
				}
				wp_safe_redirect( $target, 302 );
				exit;
			}
		}
	}

	/**
	 * Duplicate AUTH/SECURE_AUTH cookies onto /painel so admin sessions work there.
	 *
	 * @param string $auth_cookie Authentication cookie value.
	 * @param int    $expire      Expiry timestamp (0 = session).
	 * @param int    $expiration  Duration used by WP.
	 * @param int    $user_id     User ID.
	 * @param string $scheme      'auth' or 'secure_auth'.
	 * @param string $token       Session token.
	 */
	public static function mirrorAuthCookieForPainel( $auth_cookie, $expire, $expiration, $user_id, $scheme, $token = '' ): void {
		unset( $expiration, $user_id, $token );
		if ( ! self::enabled() || ! is_string( $auth_cookie ) || '' === $auth_cookie ) {
			return;
		}
		if ( ! defined( 'AUTH_COOKIE' ) || ! defined( 'SECURE_AUTH_COOKIE' ) || ! defined( 'COOKIE_DOMAIN' ) ) {
			return;
		}
		$secure = ( 'secure_auth' === $scheme );
		$name   = $secure ? SECURE_AUTH_COOKIE : AUTH_COOKIE;
		$path   = self::painelAuthCookiePath();
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie
		setcookie( $name, $auth_cookie, (int) $expire, $path, COOKIE_DOMAIN, $secure, true );
	}

	/**
	 * Clear the mirrored /painel auth cookies on logout.
	 */
	public static function clearMirroredAuthCookie(): void {
		if ( ! defined( 'AUTH_COOKIE' ) || ! defined( 'SECURE_AUTH_COOKIE' ) || ! defined( 'COOKIE_DOMAIN' ) ) {
			return;
		}
		$path    = self::painelAuthCookiePath();
		$expired = time() - ( defined( 'YEAR_IN_SECONDS' ) ? YEAR_IN_SECONDS : 31536000 );
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie
		setcookie( AUTH_COOKIE, ' ', $expired, $path, COOKIE_DOMAIN );
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie
		setcookie( SECURE_AUTH_COOKIE, ' ', $expired, $path, COOKIE_DOMAIN );
	}

	private static function painelAuthCookiePath(): string {
		$site = defined( 'SITECOOKIEPATH' ) ? (string) SITECOOKIEPATH : '/';
		return UrlMaskConfig::adminCookiePath( self::adminPath(), $site );
	}

	/**
	 * Ensure post-login landing uses /painel, not /wp-admin.
	 *
	 * @param mixed              $redirect_to           Target.
	 * @param mixed              $requested_redirect_to Requested target.
	 * @param \WP_User|\WP_Error $user                  User or error.
	 * @return mixed
	 */
	public static function filterLoginRedirect( $redirect_to, $requested_redirect_to, $user ) {
		unset( $requested_redirect_to );
		if ( ! is_string( $redirect_to ) ) {
			return $redirect_to;
		}
		if ( ! self::enabled() || is_wp_error( $user ) ) {
			return $redirect_to;
		}
		// Prefer Painel home when gateway is ready; otherwise classic admin is fine.
		if ( self::adminUrlMaskReady() ) {
			$fallback = admin_url( 'admin.php?page=rses-dashboard' );
			if ( '' === $redirect_to || str_contains( $redirect_to, 'wp-admin' ) || str_contains( $redirect_to, 'wp-login.php' ) ) {
				return $fallback;
			}
			return UrlMaskConfig::maskAdminUrl( $redirect_to, self::adminPath() );
		}
		if ( '' === $redirect_to || str_contains( $redirect_to, 'wp-login.php' ) ) {
			return admin_url( 'admin.php?page=rses-dashboard' );
		}
		return $redirect_to;
	}

	/**
	 * Ensure the login <form action> points at /id.php (server-rendered action can lag filters).
	 */
	public static function forceLoginFormAction(): void {
		if ( ! self::enabled() ) {
			return;
		}
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;
		$action = esc_url( home_url( '/' . ltrim( self::loginPath(), '/' ) ) );
		echo '<script>(function(){document.addEventListener("DOMContentLoaded",function(){var f=document.getElementById("loginform")||document.getElementById("lostpasswordform")||document.getElementById("resetpassform");if(f){f.action=' . wp_json_encode( $action ) . ';}});})();</script>' . "\n";
	}

	/**
	 * @param mixed $url     Admin URL.
	 * @param mixed $path    Path fragment.
	 * @param mixed $blog_id Blog id.
	 * @return mixed
	 */
	public static function filterAdminUrl( $url, $path = '', $blog_id = null ) {
		unset( $blog_id );
		if ( ! is_string( $url ) || ! self::adminUrlMaskReady() ) {
			return $url;
		}
		$path_str = is_string( $path ) ? $path : '';
		if ( self::isStaticAdminAsset( $path_str, $url ) ) {
			return $url;
		}
		return UrlMaskConfig::maskAdminUrl( $url, self::adminPath() );
	}

	/**
	 * @param mixed $url  URL.
	 * @param mixed $path Path.
	 * @return mixed
	 */
	public static function filterNetworkAdminUrl( $url, $path = '' ) {
		if ( ! is_string( $url ) || ! self::adminUrlMaskReady() ) {
			return $url;
		}
		$path_str = is_string( $path ) ? $path : '';
		if ( self::isStaticAdminAsset( $path_str, $url ) ) {
			return $url;
		}
		return UrlMaskConfig::maskAdminUrl( $url, self::adminPath() );
	}

	/**
	 * @param mixed $url     URL.
	 * @param mixed $path    Path.
	 * @param mixed $scheme  Scheme.
	 * @param mixed $blog_id Blog id.
	 * @return mixed
	 */
	public static function filterSiteUrl( $url, $path = '', $scheme = null, $blog_id = null ) {
		unset( $scheme, $blog_id );
		if ( ! is_string( $url ) || ! self::enabled() ) {
			return $url;
		}
		$path_str = is_string( $path ) ? $path : '';
		if ( str_contains( $path_str, 'wp-login.php' ) || str_contains( $url, 'wp-login.php' ) ) {
			return UrlMaskConfig::maskLoginUrl( $url, self::loginPath() );
		}
		if ( self::adminUrlMaskReady()
			&& ( str_contains( $path_str, 'wp-admin' ) || str_contains( $url, '/wp-admin' ) )
		) {
			return UrlMaskConfig::maskAdminUrl( $url, self::adminPath() );
		}
		return $url;
	}

	/**
	 * @param mixed $url    URL.
	 * @param mixed $path   Path.
	 * @param mixed $scheme Scheme.
	 * @return mixed
	 */
	public static function filterNetworkSiteUrl( $url, $path = '', $scheme = null ) {
		return self::filterSiteUrl( $url, $path, $scheme, null );
	}

	/**
	 * @param mixed $login_url    Login URL.
	 * @param mixed $redirect     Redirect target.
	 * @param mixed $force_reauth Force reauth flag.
	 * @return mixed
	 */
	public static function filterLoginUrl( $login_url, $redirect = '', $force_reauth = false ) {
		unset( $redirect, $force_reauth );
		if ( ! is_string( $login_url ) || ! self::enabled() ) {
			return $login_url;
		}
		return UrlMaskConfig::maskLoginUrl( $login_url, self::loginPath() );
	}

	/**
	 * @param mixed $logout_url Logout URL.
	 * @param mixed $redirect   Redirect.
	 * @return mixed
	 */
	public static function filterLogoutUrl( $logout_url, $redirect = '' ) {
		unset( $redirect );
		if ( ! is_string( $logout_url ) || ! self::enabled() ) {
			return $logout_url;
		}
		return UrlMaskConfig::maskLoginUrl( $logout_url, self::loginPath() );
	}

	/**
	 * @param mixed $url      URL.
	 * @param mixed $redirect Redirect.
	 * @return mixed
	 */
	public static function filterLostpasswordUrl( $url, $redirect = '' ) {
		unset( $redirect );
		if ( ! is_string( $url ) || ! self::enabled() ) {
			return $url;
		}
		return UrlMaskConfig::maskLoginUrl( $url, self::loginPath() );
	}

	/**
	 * @param mixed $url Register URL.
	 * @return mixed
	 */
	public static function filterRegisterUrl( $url ) {
		if ( ! is_string( $url ) || ! self::enabled() ) {
			return $url;
		}
		return UrlMaskConfig::maskLoginUrl( $url, self::loginPath() );
	}

	/**
	 * @param mixed $location Redirect location.
	 * @return mixed
	 */
	public static function filterRedirect( $location ) {
		if ( ! is_string( $location ) || ! self::enabled() ) {
			return $location;
		}
		$location = UrlMaskConfig::maskLoginUrl( $location, self::loginPath() );
		if ( self::adminUrlMaskReady() ) {
			$location = UrlMaskConfig::maskAdminUrl( $location, self::adminPath() );
		}
		return $location;
	}

	/**
	 * @param mixed $content Email body.
	 * @return mixed
	 */
	public static function filterEmailContent( $content ) {
		if ( ! is_string( $content ) || ! self::enabled() ) {
			return $content;
		}
		$content = UrlMaskConfig::maskLoginUrl( $content, self::loginPath() );
		if ( self::adminUrlMaskReady() ) {
			$content = UrlMaskConfig::maskAdminUrl( $content, self::adminPath() );
		}
		return $content;
	}

	/**
	 * Create ABSPATH/id.php (or configured login path) stub.
	 */
	public static function writeLoginStub(): bool {
		if ( ! defined( 'ABSPATH' ) ) {
			return false;
		}
		$login = self::loginPath();
		// Only create a PHP stub for *.php login paths at document root.
		if ( ! str_ends_with( $login, '.php' ) ) {
			return false;
		}
		$target = trailingslashit( ABSPATH ) . $login;
		if ( is_readable( $target ) ) {
			$existing = (string) file_get_contents( $target );
			if ( str_contains( $existing, UrlMaskConfig::LOGIN_ENTRY_CONSTANT ) ) {
				return true;
			}
			// Do not overwrite an unrelated file.
			return false;
		}
		$stub = "<?php\n"
			. "/**\n * Voto Eletrônico — entrada de identificação.\n"
			. " * Gerado automaticamente; não editar.\n */\n"
			. 'define( \'' . UrlMaskConfig::LOGIN_ENTRY_CONSTANT . "', true );\n"
			. "require __DIR__ . '/wp-login.php';\n";
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$ok = false !== file_put_contents( $target, $stub );
		return $ok;
	}

	public static function removeLoginStub(): void {
		if ( ! defined( 'ABSPATH' ) ) {
			return;
		}
		$login  = self::loginPath();
		$target = trailingslashit( ABSPATH ) . $login;
		if ( ! is_readable( $target ) ) {
			return;
		}
		$existing = (string) file_get_contents( $target );
		if ( str_contains( $existing, UrlMaskConfig::LOGIN_ENTRY_CONSTANT ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $target );
		}
	}

	public static function writeHtaccessRules(): void {
		if ( ! function_exists( 'insert_with_markers' ) || ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		if ( ! function_exists( 'insert_with_markers' ) || ! function_exists( 'get_home_path' ) ) {
			return;
		}
		$home_path = get_home_path();
		if ( ! is_string( $home_path ) || '' === $home_path ) {
			return;
		}
		$htaccess = trailingslashit( $home_path ) . '.htaccess';
		if ( ! file_exists( $htaccess ) && ! is_writable( $home_path ) ) {
			return;
		}
		$admin = preg_quote( self::adminPath(), '#' );
		$rules = array(
			'<IfModule mod_rewrite.c>',
			'RewriteEngine On',
			'RewriteRule ^' . $admin . '/?$ /wp-admin/ [QSA,L]',
			'RewriteRule ^' . $admin . '/(.*)$ /wp-admin/$1 [QSA,L]',
			'</IfModule>',
		);
		insert_with_markers( $htaccess, UrlMaskConfig::HTACCESS_MARKER, $rules );
	}

	public static function clearHtaccessRules(): void {
		if ( ! function_exists( 'insert_with_markers' ) || ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		if ( ! function_exists( 'insert_with_markers' ) || ! function_exists( 'get_home_path' ) ) {
			return;
		}
		$home_path = get_home_path();
		if ( ! is_string( $home_path ) || '' === $home_path ) {
			return;
		}
		$htaccess = trailingslashit( $home_path ) . '.htaccess';
		if ( file_exists( $htaccess ) ) {
			insert_with_markers( $htaccess, UrlMaskConfig::HTACCESS_MARKER, array() );
		}
	}
}
