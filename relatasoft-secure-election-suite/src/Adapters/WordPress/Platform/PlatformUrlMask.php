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

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'init', array( self::class, 'ensureArtifacts' ), 0 );
		add_action( 'init', array( self::class, 'bootstrapRequest' ), 0 );
		add_action( 'login_init', array( self::class, 'bootstrapRequest' ), 0 );
		add_action( 'admin_notices', array( self::class, 'maybeAliasNotice' ) );

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
		add_action( 'login_footer', array( self::class, 'forceLoginFormAction' ), 1 );

		// Plugin load may happen during plugins_loaded — enforce mask on this request too.
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
		self::writeAdminAlias();
		self::writeHtaccessRules();
	}

	/**
	 * Warn admins when /painel is not reachable on disk (typical nginx without alias).
	 */
	public static function maybeAliasNotice(): void {
		if ( ! self::enabled() || ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( self::adminAliasExists() ) {
			return;
		}
		$cmd = 'ln -sfn wp-admin ' . self::adminPath();
		echo '<div class="notice notice-error"><p>';
		echo esc_html__(
			'A URL pública /painel/ ainda não está disponível neste servidor (comum em nginx). Na raiz da instalação execute:',
			'relatasoft-secure-election-suite'
		);
		echo ' <code>' . esc_html( $cmd ) . '</code>';
		echo '</p></div>';
	}

	public static function adminAliasExists(): bool {
		if ( ! defined( 'ABSPATH' ) ) {
			return false;
		}
		$link = trailingslashit( ABSPATH ) . self::adminPath();
		if ( is_link( $link ) ) {
			return true;
		}
		return is_dir( $link ) && is_readable( $link . '/' . self::ADMIN_ALIAS_MARKER );
	}

	/**
	 * Nginx-friendly alias: symlink (or stub tree) so /painel/* maps to wp-admin/*.
	 */
	public static function writeAdminAlias(): bool {
		if ( ! defined( 'ABSPATH' ) ) {
			return false;
		}
		$admin_slug = self::adminPath();
		if ( '' === $admin_slug || false !== strpos( $admin_slug, '/' ) || false !== strpos( $admin_slug, '..' ) ) {
			return false;
		}
		$link   = trailingslashit( ABSPATH ) . $admin_slug;
		$target = trailingslashit( ABSPATH ) . 'wp-admin';

		if ( ! is_dir( $target ) ) {
			return false;
		}

		if ( is_link( $link ) ) {
			$current = readlink( $link );
			if ( 'wp-admin' === $current || $target === $current || realpath( $link ) === realpath( $target ) ) {
				return true;
			}
			return false;
		}

		if ( file_exists( $link ) ) {
			// Already a real dir we created?
			return is_dir( $link ) && is_readable( $link . '/' . self::ADMIN_ALIAS_MARKER );
		}

		// Prefer relative symlink (portable when docroot moves).
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( @symlink( 'wp-admin', $link ) ) {
			return true;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( @symlink( $target, $link ) ) {
			return true;
		}

		return self::writeAdminStubDirectory( $link, $target );
	}

	/**
	 * Fallback when symlink() is disabled: stub PHP entries + symlink asset dirs.
	 */
	private static function writeAdminStubDirectory( string $link, string $wp_admin ): bool {
		if ( ! wp_mkdir_p( $link ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents(
			$link . '/' . self::ADMIN_ALIAS_MARKER,
			"Voto Eletrônico admin alias — do not delete\n"
		);

		$files = glob( trailingslashit( $wp_admin ) . '*.php' ) ?: array();
		foreach ( $files as $file ) {
			$base = basename( $file );
			if ( ! preg_match( '/^[a-z0-9_-]+\.php$/i', $base ) ) {
				continue;
			}
			$stub = "<?php\n/** Voto Eletrônico — alias de gestão (não editar). */\n"
				. "require dirname( __DIR__ ) . '/wp-admin/{$base}';\n";
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $link . '/' . $base, $stub );
		}

		foreach ( array( 'css', 'js', 'images', 'maint', 'network', 'user' ) as $subdir ) {
			$from = trailingslashit( $wp_admin ) . $subdir;
			$to   = $link . '/' . $subdir;
			if ( ! is_dir( $from ) || file_exists( $to ) ) {
				continue;
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! @symlink( '../wp-admin/' . $subdir, $to ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				@symlink( $from, $to );
			}
		}

		return is_readable( $link . '/index.php' ) || is_readable( $link . '/admin.php' );
	}

	public static function removeAdminAlias(): void {
		if ( ! defined( 'ABSPATH' ) ) {
			return;
		}
		$link = trailingslashit( ABSPATH ) . self::adminPath();
		if ( is_link( $link ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $link );
			return;
		}
		if ( is_dir( $link ) && is_readable( $link . '/' . self::ADMIN_ALIAS_MARKER ) ) {
			self::rrmdir( $link );
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
	 * Block classic WP entry URLs; allow masked login stub.
	 *
	 * Important: never 404 POST to wp-login.php — the login form (or an old
	 * bookmark) may still post there; denying it makes valid credentials fail.
	 */
	public static function bootstrapRequest(): void {
		if ( ! self::enabled() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		$path = UrlMaskConfig::requestPath( $uri );
		$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );

		// Login via stub id.php — branding treats it as login screen.
		if ( defined( UrlMaskConfig::LOGIN_ENTRY_CONSTANT ) && constant( UrlMaskConfig::LOGIN_ENTRY_CONSTANT ) ) {
			global $pagenow;
			$pagenow = 'wp-login.php';
			return;
		}

		if ( UrlMaskConfig::isWpLoginPath( $path ) ) {
			// Allow authentication POST (and other login actions) to complete.
			if ( 'POST' === $method ) {
				return;
			}
			// GET/HEAD: send humans to the public identification URL.
			if ( function_exists( 'wp_safe_redirect' ) && function_exists( 'home_url' ) ) {
				$target = home_url( '/' . ltrim( self::loginPath(), '/' ) );
				$query  = parse_url( $uri, PHP_URL_QUERY );
				if ( is_string( $query ) && $query !== '' ) {
					$target .= ( str_contains( $target, '?' ) ? '&' : '?' ) . $query;
				}
				wp_safe_redirect( $target, 302 );
				exit;
			}
			self::denyAsNotFound();
		}

		// Direct /wp-admin while the public path is /painel.
		if ( UrlMaskConfig::isWpAdminPath( $path ) && ! self::isExemptWpAdminRequest( $path ) ) {
			if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
				$admin = self::adminPath();
				$masked_path = (string) preg_replace( '#(/)wp-admin(/|$)#', '$1' . $admin . '$2', $path, 1 );
				$target      = home_url( $masked_path );
				$query       = parse_url( $uri, PHP_URL_QUERY );
				if ( is_string( $query ) && $query !== '' ) {
					$target .= ( str_contains( $target, '?' ) ? '&' : '?' ) . $query;
				}
				if ( function_exists( 'wp_safe_redirect' ) ) {
					wp_safe_redirect( $target, 302 );
					exit;
				}
			}
			self::denyAsNotFound();
		}
	}

	/**
	 * Ensure post-login landing uses /painel, not /wp-admin.
	 *
	 * @param string             $redirect_to           Target.
	 * @param string             $requested_redirect_to Requested target.
	 * @param \WP_User|\WP_Error $user                  User or error.
	 */
	public static function filterLoginRedirect( string $redirect_to, string $requested_redirect_to, $user ): string {
		unset( $requested_redirect_to );
		if ( ! self::enabled() ) {
			return $redirect_to;
		}
		if ( is_wp_error( $user ) ) {
			return $redirect_to;
		}
		$fallback = admin_url( 'admin.php?page=rses-dashboard' );
		if ( '' === $redirect_to || str_contains( $redirect_to, 'wp-admin' ) || str_contains( $redirect_to, 'wp-login.php' ) ) {
			return $fallback;
		}
		return UrlMaskConfig::maskAdminUrl( $redirect_to, self::adminPath() );
	}

	/**
	 * Ensure the login <form action> points at /id.php.
	 */
	public static function forceLoginFormAction(): void {
		if ( ! self::enabled() ) {
			return;
		}
		$action = esc_url( home_url( '/' . ltrim( self::loginPath(), '/' ) ) );
		echo '<script>(function(){document.addEventListener("DOMContentLoaded",function(){var f=document.getElementById("loginform")||document.getElementById("lostpasswordform")||document.getElementById("resetpassform");if(f){f.action=' . wp_json_encode( $action ) . ';}});})();</script>' . "\n";
	}

	public static function filterAdminUrl( string $url, string $path = '', ?int $blog_id = null ): string {
		unset( $path, $blog_id );
		if ( ! self::enabled() ) {
			return $url;
		}
		return UrlMaskConfig::maskAdminUrl( $url, self::adminPath() );
	}

	public static function filterNetworkAdminUrl( string $url, string $path = '' ): string {
		unset( $path );
		if ( ! self::enabled() ) {
			return $url;
		}
		return UrlMaskConfig::maskAdminUrl( $url, self::adminPath() );
	}

	public static function filterSiteUrl( string $url, string $path, ?string $scheme, ?int $blog_id ): string {
		unset( $scheme, $blog_id );
		if ( ! self::enabled() ) {
			return $url;
		}
		if ( ( is_string( $path ) && str_contains( $path, 'wp-login.php' ) ) || str_contains( $url, 'wp-login.php' ) ) {
			return UrlMaskConfig::maskLoginUrl( $url, self::loginPath() );
		}
		if ( ( is_string( $path ) && str_contains( $path, 'wp-admin' ) ) || str_contains( $url, '/wp-admin' ) ) {
			return UrlMaskConfig::maskAdminUrl( $url, self::adminPath() );
		}
		return $url;
	}

	public static function filterNetworkSiteUrl( string $url, string $path, ?string $scheme ): string {
		return self::filterSiteUrl( $url, $path, $scheme, null );
	}

	public static function filterLoginUrl( string $login_url, string $redirect = '', bool $force_reauth = false ): string {
		unset( $redirect, $force_reauth );
		if ( ! self::enabled() ) {
			return $login_url;
		}
		return UrlMaskConfig::maskLoginUrl( $login_url, self::loginPath() );
	}

	public static function filterLogoutUrl( string $logout_url, string $redirect = '' ): string {
		unset( $redirect );
		if ( ! self::enabled() ) {
			return $logout_url;
		}
		return UrlMaskConfig::maskLoginUrl( $logout_url, self::loginPath() );
	}

	public static function filterLostpasswordUrl( string $url, string $redirect = '' ): string {
		unset( $redirect );
		if ( ! self::enabled() ) {
			return $url;
		}
		return UrlMaskConfig::maskLoginUrl( $url, self::loginPath() );
	}

	public static function filterRegisterUrl( string $url ): string {
		if ( ! self::enabled() ) {
			return $url;
		}
		return UrlMaskConfig::maskLoginUrl( $url, self::loginPath() );
	}

	public static function filterRedirect( string $location ): string {
		if ( ! self::enabled() ) {
			return $location;
		}
		$location = UrlMaskConfig::maskLoginUrl( $location, self::loginPath() );
		return UrlMaskConfig::maskAdminUrl( $location, self::adminPath() );
	}

	public static function filterEmailContent( string $content ): string {
		if ( ! self::enabled() ) {
			return $content;
		}
		$content = UrlMaskConfig::maskLoginUrl( $content, self::loginPath() );
		return UrlMaskConfig::maskAdminUrl( $content, self::adminPath() );
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

	/**
	 * Endpoints that must remain reachable under /wp-admin for compatibility.
	 */
	private static function isExemptWpAdminRequest( string $path ): bool {
		$base = basename( $path );
		$allow = array(
			'admin-ajax.php',
			'admin-post.php',
			'async-upload.php',
			'load-scripts.php',
			'load-styles.php',
		);
		return in_array( $base, $allow, true );
	}

	private static function denyAsNotFound(): void {
		if ( function_exists( 'status_header' ) ) {
			status_header( 404 );
		} else {
			http_response_code( 404 );
		}
		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}
		if ( ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex, nofollow', true );
		}
		exit;
	}
}
