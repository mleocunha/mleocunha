<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Presentation\Admin;

use RelataSoft\SecureElectionSuite\Painel\Core\PainelKernel;

/**
 * Marks the admin body for the Painel shell (chrome is built in JS/CSS).
 */
final class ShellView {

	public static function bodyClass(string $classes): string {
		$kernel = PainelKernel::instance();
		if ( ! $kernel || ! $kernel->permissions->mayEnterAdminShell() ) {
			return $classes;
		}
		$cfg = $kernel->settingsService->get();
		if ( empty( $cfg['shell_enabled'] ) ) {
			return $classes;
		}
		$classes .= ' ve-painel-active';
		if ( ! empty( $cfg['dark_mode'] ) ) {
			$classes .= ' ve-painel-dark';
		}
		return $classes;
	}

	public static function renderOpen(): void {
		// Chrome is injected by assets/painel/js/shell.js to avoid breaking wp-admin DOM.
	}

	public static function renderClose(): void {
		// No-op — see renderOpen().
	}
}
