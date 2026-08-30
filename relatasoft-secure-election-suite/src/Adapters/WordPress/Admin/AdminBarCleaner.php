<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Admin;

use RelataSoft\SecureElectionSuite\Painel\Core\PainelKernel;
use WP_Admin_Bar;

final class AdminBarCleaner {

	public static function filter(WP_Admin_Bar $bar): void {
		$kernel = PainelKernel::instance();
		if ( ! $kernel ) {
			return;
		}
		$cfg = $kernel->settingsService->get();
		if ( empty( $cfg['shell_enabled'] ) || empty( $cfg['hide_wp_admin_bar'] ) ) {
			return;
		}
		if ( ! $kernel->permissions->mayEnterAdminShell() ) {
			return;
		}

		$nodes = array(
			'wp-logo',
			'about',
			'wporg',
			'documentation',
			'support-forums',
			'feedback',
			'site-name',
			'view-site',
			'updates',
			'comments',
			'new-content',
			'new-post',
			'new-media',
			'new-page',
			'new-user',
			'customize',
			'themes',
			'widgets',
			'menus',
			'plugins',
			'search',
		);
		foreach ( $nodes as $id ) {
			$bar->remove_node( $id );
		}
	}
}
