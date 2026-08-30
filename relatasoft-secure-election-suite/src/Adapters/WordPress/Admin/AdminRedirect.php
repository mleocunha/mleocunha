<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Admin;

use RelataSoft\SecureElectionSuite\Painel\Core\PainelKernel;

final class AdminRedirect {

	public static function maybeRedirectDashboard(): void {
		$kernel = PainelKernel::instance();
		if ( ! $kernel ) {
			return;
		}
		$cfg = $kernel->settingsService->get();
		if ( empty( $cfg['shell_enabled'] ) || empty( $cfg['redirect_dashboard'] ) ) {
			return;
		}
		if ( ! $kernel->permissions->mayEnterAdminShell() ) {
			return;
		}
		global $pagenow;
		if ( 'index.php' !== $pagenow ) {
			return;
		}
		if ( isset( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=rses-dashboard' ) );
		exit;
	}
}
