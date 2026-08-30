<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Domain\Platform;

/**
 * Canonical public paths that replace WordPress defaults (pure domain).
 */
final class UrlMaskConfig {

	public const DEFAULT_ADMIN_PATH = 'painel';
	public const DEFAULT_LOGIN_PATH = 'id.php';
	public const LOGIN_ENTRY_CONSTANT = 'VE_LOGIN_ENTRY';
	public const HTACCESS_MARKER = 'Voto Eletronico URL Mask';

	public static function normalizeAdminPath(string $path): string {
		$path = strtolower( trim( $path, "/ \t\n\r\0\x0B" ) );
		$path = preg_replace( '#[^a-z0-9\-_]+#', '', $path ) ?? '';
		if ( '' === $path || 'wp-admin' === $path ) {
			return self::DEFAULT_ADMIN_PATH;
		}
		return $path;
	}

	public static function normalizeLoginPath(string $path): string {
		$path = strtolower( trim( $path, "/ \t\n\r\0\x0B" ) );
		$path = preg_replace( '#[^a-z0-9\-_\.]+#', '', $path ) ?? '';
		if ( '' === $path || 'wp-login.php' === $path ) {
			return self::DEFAULT_LOGIN_PATH;
		}
		return $path;
	}

	/**
	 * Replace /wp-admin/ with /painel/ (keeps .php so nginx PHP location can hit stub files).
	 */
	public static function maskAdminUrl(string $url, string $admin_path): string {
		$admin_path = self::normalizeAdminPath( $admin_path );
		return (string) preg_replace( '#(/)wp-admin(/|$)#', '$1' . $admin_path . '$2', $url, 1 );
	}

	/**
	 * Replace wp-login.php in a URL with the masked login path.
	 */
	public static function maskLoginUrl(string $url, string $login_path): string {
		$login_path = self::normalizeLoginPath( $login_path );
		return str_replace( 'wp-login.php', $login_path, $url );
	}

	public static function requestPath(string $request_uri): string {
		$path = parse_url( $request_uri, PHP_URL_PATH );
		return is_string( $path ) ? rawurldecode( $path ) : '';
	}

	public static function pathEndsWith(string $path, string $needle): bool {
		$path   = rtrim( $path, '/' );
		$needle = rtrim( $needle, '/' );
		if ( '' === $needle ) {
			return false;
		}
		return $path === $needle || str_ends_with( $path, '/' . ltrim( $needle, '/' ) );
	}

	public static function isWpAdminPath(string $path): bool {
		return (bool) preg_match( '#(^|/)wp-admin(/|$)#', $path );
	}

	public static function isWpLoginPath(string $path): bool {
		return (bool) preg_match( '#(^|/)wp-login\.php$#', $path );
	}

	/**
	 * Endpoints that must remain reachable under /wp-admin (asset loaders only).
	 * admin-ajax / admin-post go through /painel once the gateway exists.
	 */
	public static function isExemptWpAdminEndpoint( string $path ): bool {
		return self::isWpAdminAssetLoader( $path );
	}

	/**
	 * Core script/style aggregators still referenced as /wp-admin/load-*.php.
	 */
	public static function isWpAdminAssetLoader( string $path ): bool {
		$base = strtolower( basename( $path ) );
		return in_array(
			$base,
			array(
				'load-scripts.php',
				'load-styles.php',
			),
			true
		);
	}

	public static function isStaticAdminAssetPath( string $path ): bool {
		return (bool) preg_match( '/\.(css|js|map|png|jpe?g|gif|svg|webp|woff2?|ttf|eot|ico)(\?|$)/i', $path );
	}

	/**
	 * Classic WordPress admin screens retired from the public Painel surface.
	 * Access via /painel/plugins.php (etc.) must 404 like a missing URL.
	 */
	public static function isRetiredClassicAdminScreen( string $path_or_basename ): bool {
		$base = strtolower( basename( $path_or_basename ) );
		if ( '' === $base || ! str_ends_with( $base, '.php' ) ) {
			return false;
		}
		$retired = array(
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
		return in_array( $base, $retired, true );
	}

	/**
	 * Essential Painel entry points that must remain reachable under /painel.
	 */
	public static function isAllowedPainelAdminScreen( string $path_or_basename ): bool {
		$base = strtolower( basename( $path_or_basename ) );
		$allow = array(
			'admin.php',
			'admin-ajax.php',
			'admin-post.php',
			'async-upload.php',
			'media-upload.php',
			'load-styles.php',
			'load-scripts.php',
			'index.php',
			'profile.php',
			'user-edit.php',
			'update.php',
			'upgrade.php',
			'options.php',
			'post.php',
			'term.php',
			'comment.php',
			'edit-form-advanced.php',
			'edit-tag-form.php',
			'js.php', // rare
		);
		return in_array( $base, $allow, true );
	}

	/**
	 * Auth cookie path for the masked admin slug (mirrors ADMIN_COOKIE_PATH = SITECOOKIEPATH . 'wp-admin').
	 *
	 * Without this, browsers never send wordpress_* auth cookies on /painel/*,
	 * so auth_redirect() sends operators back to login even after a valid session.
	 */
	/**
	 * Public request access once URL mask stubs are ready (R4 / C2 policy).
	 *
	 * Mirrors {@see PlatformUrlMask} deny rules without needing a sítio boot.
	 *
	 * @return 'allow'|'not_found'
	 */
	public static function publicAccessDecision( string $path, bool $adminGatewayReady, bool $loginStubReady ): string {
		if ( self::isWpLoginPath( $path ) && $loginStubReady ) {
			return 'not_found';
		}
		if ( $adminGatewayReady && self::isRetiredClassicAdminScreen( $path ) ) {
			return 'not_found';
		}
		if ( self::isWpAdminPath( $path )
			&& $adminGatewayReady
			&& ! self::isStaticAdminAssetPath( $path )
			&& ! self::isWpAdminAssetLoader( $path )
		) {
			return 'not_found';
		}
		return 'allow';
	}

	public static function adminCookiePath( string $admin_path, string $site_cookie_path = '/' ): string {
		$admin_path = self::normalizeAdminPath( $admin_path );
		if ( '' === $site_cookie_path ) {
			$site_cookie_path = '/';
		}
		if ( ! str_ends_with( $site_cookie_path, '/' ) ) {
			$site_cookie_path .= '/';
		}
		return $site_cookie_path . $admin_path;
	}
}
