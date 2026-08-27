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
	 * Replace /wp-admin/… with /painel/… and drop .php for nginx-safe public URLs.
	 *
	 * Example: /wp-admin/plugins.php?x=1 → /painel/plugins?x=1
	 * (Routed by WP rewrites / front-controller; optional stub files keep legacy .php working.)
	 */
	public static function maskAdminUrl(string $url, string $admin_path): string {
		$admin_path = self::normalizeAdminPath( $admin_path );
		$url        = (string) preg_replace( '#(/)wp-admin(/|$)#', '$1' . $admin_path . '$2', $url, 1 );
		$url        = (string) preg_replace(
			'#(/' . preg_quote( $admin_path, '#' ) . '/[A-Za-z0-9_-]+)\.php(?=[\?#]|$)#',
			'$1',
			$url
		);
		return $url;
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
}
