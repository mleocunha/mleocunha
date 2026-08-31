<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\Http;

/**
 * Shell HTML reutilizando CSS do Painel (assets/).
 */
final class HtmlShell {

	public function __construct(
		private readonly CatalogI18n $i18n,
		private readonly string $pluginRoot,
		private readonly string $mode,
		private readonly string $modeLabel,
	) {}

	/**
	 * @param list<array{href:string,label:string}> $nav
	 */
	public function render( string $title, string $body, array $nav = array(), string $flash = '' ): string {
		$lang = htmlspecialchars( $this->i18n->locale(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		$dir  = htmlspecialchars( $this->i18n->dir(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		$t    = htmlspecialchars( $title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		$mode = htmlspecialchars( $this->modeLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		$css  = array(
			'/assets/painel/css/tokens.css',
			'/assets/painel/css/shell.css',
			'/assets/painel/css/login.css',
			'/assets/css/admin.css',
			'/assets/css/journey-front.css',
			'/assets/css/voting-front.css',
		);
		$links = '';
		foreach ( $css as $href ) {
			$links .= '<link rel="stylesheet" href="' . htmlspecialchars( $href, ENT_QUOTES, 'UTF-8' ) . '" />' . "\n";
		}
		$navHtml = '';
		foreach ( $nav as $item ) {
			$navHtml .= '<a class="ve-nav-link" href="' . htmlspecialchars( $item['href'], ENT_QUOTES, 'UTF-8' ) . '">'
				. htmlspecialchars( $item['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '</a>';
		}
		$flashHtml = '' !== $flash
			? '<div class="rses-panel rses-panel-info"><p>' . htmlspecialchars( $flash, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '</p></div>'
			: '';

		return <<<HTML
<!DOCTYPE html>
<html lang="{$lang}" dir="{$dir}">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>{$t} — Voto Eletrônico</title>
{$links}
<style>
  body.ve-standalone{margin:0;font-family:var(--ve-font-sans, "Open Sans", system-ui, sans-serif);background:linear-gradient(160deg,#f3f6f8 0%,#e8eef3 45%,#f7fafc 100%);min-height:100vh;color:#14212b}
  .ve-top{display:flex;flex-wrap:wrap;gap:0.75rem 1.25rem;align-items:center;padding:0.85rem 1.25rem;background:rgba(255,255,255,0.92);border-bottom:1px solid #d7e0e8;backdrop-filter:blur(6px)}
  .ve-brand{font-weight:800;letter-spacing:0.02em;font-size:1.05rem;color:#0b3d4a;text-decoration:none}
  .ve-mode{font-size:0.8rem;color:#5a6b78;border:1px solid #d0dbe4;border-radius:999px;padding:0.15rem 0.65rem}
  .ve-nav{display:flex;flex-wrap:wrap;gap:0.65rem 1rem;margin-left:auto}
  .ve-nav-link{color:#0b3d4a;text-decoration:none;font-weight:600;font-size:0.92rem}
  .ve-nav-link:hover{text-decoration:underline}
  .ve-main{max-width:960px;margin:0 auto;padding:1.25rem}
  .ve-card{background:#fff;border:1px solid #d7e0e8;border-radius:12px;padding:1.15rem 1.25rem;margin:0 0 1rem;box-shadow:0 1px 0 rgba(20,33,43,0.04)}
  .ve-card h1,.ve-card h2{margin:0 0 0.55rem;font-size:1.35rem}
  .ve-muted{color:#5a6b78;font-size:0.95rem}
  .ve-actions{display:flex;flex-wrap:wrap;gap:0.5rem;margin-top:0.85rem}
  .ve-actions a,.ve-actions button,button.ve-btn{display:inline-block;background:#0b3d4a;color:#fff;border:0;border-radius:8px;padding:0.55rem 0.95rem;font-weight:700;text-decoration:none;cursor:pointer}
  .ve-actions a.secondary,a.ve-btn-secondary{background:#e8eef3;color:#0b3d4a}
  label.ve-field{display:block;margin:0.65rem 0}
  label.ve-field span{display:block;font-size:0.8rem;font-weight:700;margin-bottom:0.25rem;color:#5a6b78}
  input,select,textarea{width:100%;max-width:28rem;padding:0.5rem 0.65rem;border:1px solid #c5d2dc;border-radius:8px;font:inherit}
  table.ve-table{width:100%;border-collapse:collapse}
  table.ve-table th,table.ve-table td{text-align:left;padding:0.55rem 0.4rem;border-bottom:1px solid #e8eef2;font-size:0.95rem}
</style>
</head>
<body class="ve-standalone rses-screen">
<header class="ve-top">
  <a class="ve-brand" href="/painel">Voto Eletrônico</a>
  <span class="ve-mode">{$mode}</span>
  <nav class="ve-nav">{$navHtml}</nav>
</header>
<main class="ve-main">
{$flashHtml}
{$body}
</main>
</body>
</html>
HTML;
	}
}
