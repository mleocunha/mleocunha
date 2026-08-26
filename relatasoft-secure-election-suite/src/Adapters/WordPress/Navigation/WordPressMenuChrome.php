<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Navigation;

use RelataSoft\SecureElectionSuite\Painel\Contracts\Navigation\NavigationRegistrar;
use RelataSoft\SecureElectionSuite\Painel\Core\PainelKernel;
use RelataSoft\SecureElectionSuite\Painel\Domain\Navigation\MenuItem;

/**
 * Hides native WP menus when the Painel shell is active.
 */
final class WordPressMenuChrome implements NavigationRegistrar {

	/** @param list<MenuItem> $items */
	public function sync(array $items): void {
		// Phase 1: RSES AdminMenu still registers WP menus; shell mirrors items in UI.
	}

	public static function hideNativeMenus(): void {
		$kernel = PainelKernel::instance();
		if ( ! $kernel ) {
			return;
		}
		$cfg = $kernel->settingsService->get();
		if ( empty( $cfg['shell_enabled'] ) || empty( $cfg['hide_wp_menus'] ) ) {
			return;
		}
		if ( ! $kernel->permissions->mayEnterAdminShell() ) {
			return;
		}

		$remove = array(
			'index.php',
			'edit.php',
			'upload.php',
			'edit.php?post_type=page',
			'edit-comments.php',
			'themes.php',
			'plugins.php',
			'users.php',
			'tools.php',
			'options-general.php',
			'separator1',
			'separator2',
			'separator-last',
		);
		foreach ( $remove as $slug ) {
			remove_menu_page( $slug );
		}
	}
}
