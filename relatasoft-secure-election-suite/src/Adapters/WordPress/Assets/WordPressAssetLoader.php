<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Assets;

use RelataSoft\SecureElectionSuite\Painel\Contracts\Platform\AssetProvider;
use RelataSoft\SecureElectionSuite\Painel\Core\PainelKernel;

final class WordPressAssetLoader implements AssetProvider {

	public function enqueueAdminShell(): void {
		$kernel = PainelKernel::instance();
		if ( ! $kernel || ! $kernel->permissions->mayEnterAdminShell() ) {
			return;
		}
		$cfg = $kernel->settingsService->get();
		if ( empty( $cfg['shell_enabled'] ) ) {
			return;
		}

		wp_enqueue_style(
			've-painel-tokens',
			RSES_PLUGIN_URL . 'assets/painel/css/tokens.css',
			array(),
			RSES_VERSION
		);
		wp_enqueue_style(
			've-painel-shell',
			RSES_PLUGIN_URL . 'assets/painel/css/shell.css',
			array( 've-painel-tokens' ),
			RSES_VERSION
		);
		wp_enqueue_style(
			've-painel-system',
			RSES_PLUGIN_URL . 'assets/painel/css/system.css',
			array( 've-painel-shell' ),
			RSES_VERSION
		);
		wp_enqueue_script(
			've-painel-shell',
			RSES_PLUGIN_URL . 'assets/painel/js/shell.js',
			array(),
			RSES_VERSION,
			true
		);

		$mode = '';
		if ( class_exists( '\\RelataSoft\\SecureElectionSuite\\Bootstrap\\ModeLock' ) ) {
			$mode = \RelataSoft\SecureElectionSuite\Bootstrap\ModeLock::rses_get_mode();
		}
		$items = $kernel->navigation->visibleItems( is_string( $mode ) ? $mode : '' );
		$allowed_slugs = self::registeredPainelSlugs();
		$nav           = array();
		foreach ( $items as $item ) {
			if ( $allowed_slugs && ! in_array( $item->slug, $allowed_slugs, true ) ) {
				continue;
			}
			$nav[] = array(
				'id'       => $item->id,
				'title'    => $item->title,
				'slug'     => $item->slug,
				'parentId' => $item->parentId,
				'url'      => admin_url( 'admin.php?page=' . rawurlencode( $item->slug ) ),
			);
		}

		wp_localize_script(
			've-painel-shell',
			'vePainel',
			array(
				'productName'  => $kernel->loginBranding->productName(),
				'panelName'    => $kernel->loginBranding->panelName(),
				'persona'      => $kernel->permissions->currentPersona()->value,
				'personaLabel' => $kernel->permissions->currentPersona()->labelPt(),
				'mode'         => is_string( $mode ) ? $mode : '',
				'nav'          => $nav,
				'darkMode'     => ! empty( $cfg['dark_mode'] ),
			)
		);
	}

	/**
	 * Slugs actually registered under the Painel parent (avoids shell links to missing pages).
	 *
	 * @return list<string>
	 */
	private static function registeredPainelSlugs(): array {
		global $submenu;
		$slugs = array( 'rses-dashboard' );
		if ( ! is_array( $submenu ) || empty( $submenu['rses-dashboard'] ) ) {
			return $slugs;
		}
		foreach ( $submenu['rses-dashboard'] as $row ) {
			if ( ! empty( $row[2] ) && is_string( $row[2] ) ) {
				$slugs[] = $row[2];
			}
		}
		return array_values( array_unique( $slugs ) );
	}

	public function enqueueLoginBranding(): void {
		wp_enqueue_style(
			've-painel-tokens',
			RSES_PLUGIN_URL . 'assets/painel/css/tokens.css',
			array(),
			RSES_VERSION
		);
		wp_enqueue_style(
			've-painel-login',
			RSES_PLUGIN_URL . 'assets/painel/css/login.css',
			array( 've-painel-tokens' ),
			RSES_VERSION
		);
	}
}
