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

		// Drop leftover classic submenu deep-links (Appearance → Editor, etc.).
		$submenu_kill = array(
			'themes.php'           => array( 'theme-editor.php', 'site-editor.php', 'customize.php', 'widgets.php', 'nav-menus.php' ),
			'plugins.php'          => array( 'plugin-install.php', 'plugin-editor.php' ),
			'users.php'            => array( 'user-new.php', 'profile.php' ),
			'tools.php'            => array( 'import.php', 'export.php', 'site-health.php', 'export-personal-data.php', 'erase-personal-data.php' ),
			'options-general.php'  => array( 'options-writing.php', 'options-reading.php', 'options-discussion.php', 'options-media.php', 'options-permalink.php', 'options-privacy.php', 'privacy.php' ),
		);
		foreach ( $submenu_kill as $parent => $children ) {
			foreach ( $children as $child ) {
				remove_submenu_page( $parent, $child );
			}
		}
	}
}
