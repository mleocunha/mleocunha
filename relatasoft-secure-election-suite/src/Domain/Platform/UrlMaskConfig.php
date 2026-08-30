<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Domain\Platform;

/**
 * Política de caminhos públicos da máscara de URL (domínio puro, sem boot do sítio).
 *
 * Substitui os caminhos clássicos do motor hospedeiro (`wp-admin`, `wp-login.php`)
 * por fachadas de produto (`/painel`, `id.php`). Usada pelo Adapter #1 (hooks HTTP)
 * e pelos testes/smoke C2 / R4 sem precisar de WordPress a correr.
 *
 * Contrato de produto (E3 + C2):
 * - O operador entra só por `/painel` e `id.php`.
 * - Rotas clássicas e ecrãs retirados respondem como URL inexistente (`404`).
 * - Loaders de CSS/JS sob `/wp-admin/load-*.php` continuam acessíveis (assets).
 */
final class UrlMaskConfig {

	/** Slug canónico do painel administrativo mascarado (em vez de `wp-admin`). */
	public const DEFAULT_ADMIN_PATH = 'painel';

	/** Ficheiro canónico de login mascarado (em vez de `wp-login.php`). */
	public const DEFAULT_LOGIN_PATH = 'id.php';

	/** Constante PHP que o stub de login define para o motor reconhecer a entrada mascarada. */
	public const LOGIN_ENTRY_CONSTANT = 'VE_LOGIN_ENTRY';

	/** Marcador inserido em `.htaccess` para identificar regras geradas pela suíte. */
	public const HTACCESS_MARKER = 'Voto Eletronico URL Mask';

	/**
	 * Normalizar o slug do admin público.
	 *
	 * Remove barras, espaços e caracteres não alfanuméricos; força minúsculas.
	 * Se o valor for vazio ou ainda for `wp-admin`, devolve o default de produto.
	 */
	public static function normalizeAdminPath(string $path): string {
		$path = strtolower( trim( $path, "/ \t\n\r\0\x0B" ) );
		$path = preg_replace( '#[^a-z0-9\-_]+#', '', $path ) ?? '';
		if ( '' === $path || 'wp-admin' === $path ) {
			return self::DEFAULT_ADMIN_PATH;
		}
		return $path;
	}

	/**
	 * Normalizar o caminho de login público (permite ponto, ex.: `id.php`).
	 *
	 * Se vazio ou ainda for `wp-login.php`, devolve o default de produto.
	 */
	public static function normalizeLoginPath(string $path): string {
		$path = strtolower( trim( $path, "/ \t\n\r\0\x0B" ) );
		$path = preg_replace( '#[^a-z0-9\-_\.]+#', '', $path ) ?? '';
		if ( '' === $path || 'wp-login.php' === $path ) {
			return self::DEFAULT_LOGIN_PATH;
		}
		return $path;
	}

	/**
	 * Reescrever uma URL absoluta/relativa: `/wp-admin/` → `/painel/` (ou slug configurado).
	 *
	 * Mantém o `.php` seguinte para o Nginx/Apache poder servir os stubs PHP da máscara.
	 */
	public static function maskAdminUrl(string $url, string $admin_path): string {
		$admin_path = self::normalizeAdminPath( $admin_path );
		return (string) preg_replace( '#(/)wp-admin(/|$)#', '$1' . $admin_path . '$2', $url, 1 );
	}

	/**
	 * Reescrever o nome do ficheiro de login numa URL: `wp-login.php` → `id.php` (ou configurado).
	 */
	public static function maskLoginUrl(string $url, string $login_path): string {
		$login_path = self::normalizeLoginPath( $login_path );
		return str_replace( 'wp-login.php', $login_path, $url );
	}

	/**
	 * Extrair só o path de um `REQUEST_URI` (sem query string), com URL-decode.
	 */
	public static function requestPath(string $request_uri): string {
		$path = parse_url( $request_uri, PHP_URL_PATH );
		return is_string( $path ) ? rawurldecode( $path ) : '';
	}

	/**
	 * Verificar se `$path` termina exactamente com `$needle` (com ou sem barra final).
	 */
	public static function pathEndsWith(string $path, string $needle): bool {
		$path   = rtrim( $path, '/' );
		$needle = rtrim( $needle, '/' );
		if ( '' === $needle ) {
			return false;
		}
		return $path === $needle || str_ends_with( $path, '/' . ltrim( $needle, '/' ) );
	}

	/** O path aponta para a árvore clássica `/wp-admin`? */
	public static function isWpAdminPath(string $path): bool {
		return (bool) preg_match( '#(^|/)wp-admin(/|$)#', $path );
	}

	/** O path é exactamente o login clássico `wp-login.php`? */
	public static function isWpLoginPath(string $path): bool {
		return (bool) preg_match( '#(^|/)wp-login\.php$#', $path );
	}

	/**
	 * Endpoints que ainda podem viver sob `/wp-admin` depois da máscara activa.
	 *
	 * Hoje: só agregadores de assets (`load-scripts.php` / `load-styles.php`).
	 * `admin-ajax` / `admin-post` passam a ir por `/painel` quando o gateway existe.
	 */
	public static function isExemptWpAdminEndpoint( string $path ): bool {
		return self::isWpAdminAssetLoader( $path );
	}

	/**
	 * Agregadores core de scripts/estilos ainda referenciados como `/wp-admin/load-*.php`.
	 * Sem estes, o Painel perde CSS/JS mesmo com a máscara correcta.
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

	/**
	 * Extensão estática típica de asset admin (css, js, fontes, imagens).
	 * Estes ficheiros não devem ser forçados a 404 sob `/wp-admin`.
	 */
	public static function isStaticAdminAssetPath( string $path ): bool {
		return (bool) preg_match( '/\.(css|js|map|png|jpe?g|gif|svg|webp|woff2?|ttf|eot|ico)(\?|$)/i', $path );
	}

	/**
	 * Ecrãs clássicos do motor hospedeiro retirados da superfície pública do Painel.
	 *
	 * Acesso via `/painel/plugins.php` (etc.) deve parecer URL inexistente (404),
	 * alinhado com a UX “só existe o Painel de Controle Eleitoral”.
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
	 * Entradas essenciais do Painel que têm de continuar alcançáveis sob `/painel`.
	 *
	 * Inclui `admin.php` (menu da suíte), AJAX/POST, upload, loaders e alguns
	 * ecrãs de perfil/actualização ainda usados pelo fluxo interno.
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
			'js.php', // raro; mantido por compatibilidade de ecrãs legados
		);
		return in_array( $base, $allow, true );
	}

	/**
	 * Decisão de acesso público quando os stubs da máscara estão prontos (C2 / R4).
	 *
	 * Espelha as regras de negação de {@see PlatformUrlMask} sem boot do sítio,
	 * para PHPUnit e `bin/ve-url-mask-smoke` poderem regressar a matriz em CI.
	 *
	 * Ordem das regras (primeira que bater ganha):
	 * 1. Login clássico + stub de login pronto → `not_found` (forçar `id.php`).
	 * 2. Ecrã clássico retirado + gateway admin pronto → `not_found`.
	 * 3. Qualquer `/wp-admin/*` (excepto assets estáticos e loaders) + gateway → `not_found`.
	 * 4. Caso contrário → `allow` (inclui `/painel/admin.php` e assets).
	 *
	 * Sem gateway/stub (`false`), a política não força 404: o motor clássico
	 * ainda é a superfície real até a máscara estar instalada.
	 *
	 * @param string $path               Path do pedido (ex.: `/wp-login.php`).
	 * @param bool   $adminGatewayReady  Stub/rewrite de `/painel` activo.
	 * @param bool   $loginStubReady     Stub `id.php` (ou equivalente) activo.
	 * @return 'allow'|'not_found'
	 */
	public static function publicAccessDecision( string $path, bool $adminGatewayReady, bool $loginStubReady ): string {
		// 1) Esconder login clássico quando o stub de produto já existe.
		if ( self::isWpLoginPath( $path ) && $loginStubReady ) {
			return 'not_found';
		}
		// 2) Ecrãs clássicos (plugins, temas, …) nunca na superfície pública.
		if ( $adminGatewayReady && self::isRetiredClassicAdminScreen( $path ) ) {
			return 'not_found';
		}
		// 3) Qualquer outro /wp-admin que não seja asset/loader → 404.
		if ( self::isWpAdminPath( $path )
			&& $adminGatewayReady
			&& ! self::isStaticAdminAssetPath( $path )
			&& ! self::isWpAdminAssetLoader( $path )
		) {
			return 'not_found';
		}
		return 'allow';
	}

	/**
	 * Path do cookie de autenticação para o slug admin mascarado.
	 *
	 * Espelha `ADMIN_COOKIE_PATH = SITECOOKIEPATH . 'wp-admin'`. Sem isto, o
	 * browser não envia cookies `wordpress_*` em `/painel/*` e o operador
	 * é mandado de volta ao login mesmo com sessão válida.
	 *
	 * @param string $admin_path       Slug normalizado (ex.: `painel`).
	 * @param string $site_cookie_path Prefixo de cookie do sítio (default `/`).
	 */
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
