<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Platform;

/**
 * Branded soft-404 surface ("Página Inexistente") — black canvas matching RelataSoft lockup.
 */
final class NotFoundPage {

	public const LOCKUP_BASENAME = 'relatasoft-404-lockup.png';

	/**
	 * Emit HTTP 404 headers and the branded HTML body, then stop.
	 */
	public static function renderAndExit(): void {
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

		$logo = self::lockupUrl();
		$title = 'Página Inexistente';
		$esc_title = htmlspecialchars( $title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		$esc_logo  = htmlspecialchars( $logo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );

		echo '<!DOCTYPE html>' . "\n";
		echo '<html lang="pt-BR">' . "\n";
		echo '<head>' . "\n";
		echo '<meta charset="utf-8"/>' . "\n";
		echo '<meta name="viewport" content="width=device-width, initial-scale=1"/>' . "\n";
		echo '<meta name="robots" content="noindex,nofollow"/>' . "\n";
		echo '<title>' . $esc_title . '</title>' . "\n";
		echo '<style>' . self::css() . '</style>' . "\n";
		echo '</head>' . "\n";
		echo '<body>' . "\n";
		echo '<main class="ve-404" role="main">' . "\n";
		echo '  <img class="ve-404__lockup" src="' . $esc_logo . '" width="323" height="82" alt="RelataSoft — Participação mais Inteligente" decoding="async"/>' . "\n";
		echo '  <h1 class="ve-404__title">' . $esc_title . '</h1>' . "\n";
		echo '  <p class="ve-404__lead">O endereço informado não existe neste site.</p>' . "\n";
		echo '</main>' . "\n";
		echo '</body>' . "\n";
		echo '</html>';
		exit;
	}

	/**
	 * Absolute URL for the lockup asset (plugin URL when available).
	 */
	public static function lockupUrl(): string {
		if ( defined( 'RSES_PLUGIN_URL' ) ) {
			return rtrim( (string) RSES_PLUGIN_URL, '/' ) . '/assets/brand/' . self::LOCKUP_BASENAME;
		}
		if ( function_exists( 'content_url' ) ) {
			return content_url( 'plugins/relatasoft-secure-election-suite/assets/brand/' . self::LOCKUP_BASENAME );
		}
		return '/wp-content/plugins/relatasoft-secure-election-suite/assets/brand/' . self::LOCKUP_BASENAME;
	}

	private static function css(): string {
		return <<<'CSS'
html,body{margin:0;padding:0;min-height:100%;background:#000000;color:#ffffff}
body{min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:"Avenir Next","Nunito Sans","Trebuchet MS",sans-serif;-webkit-font-smoothing:antialiased}
.ve-404{width:min(92vw,28rem);text-align:center;padding:2.5rem 1.25rem}
.ve-404__lockup{display:block;width:min(100%,20.1875rem);height:auto;margin:0 auto 2rem;animation:ve404In .7s ease both}
.ve-404__title{margin:0 0 .75rem;font-size:clamp(1.65rem,4.5vw,2.15rem);font-weight:700;letter-spacing:.01em;line-height:1.2;animation:ve404In .7s .12s ease both}
.ve-404__lead{margin:0;font-size:.98rem;font-weight:400;line-height:1.5;color:rgba(255,255,255,.72);animation:ve404In .7s .22s ease both}
@keyframes ve404In{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
@media (prefers-reduced-motion:reduce){.ve-404__lockup,.ve-404__title,.ve-404__lead{animation:none}}
CSS;
	}
}
