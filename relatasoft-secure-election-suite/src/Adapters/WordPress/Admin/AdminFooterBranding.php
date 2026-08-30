<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Admin;

use RelataSoft\SecureElectionSuite\Painel\Core\PainelKernel;

/**
 * Replaces residual WordPress admin footer chrome with product branding.
 */
final class AdminFooterBranding {

	public static function register(): void {
		add_filter( 'admin_footer_text', array( self::class, 'footerText' ), 999 );
		add_filter( 'update_footer', array( self::class, 'footerVersion' ), 999 );
	}

	public static function footerText( string $text ): string {
		$kernel = PainelKernel::instance();
		if ( ! $kernel || ! $kernel->permissions->mayEnterAdminShell() ) {
			return $text;
		}
		$name = $kernel->loginBranding->productName();
		return esc_html( $name );
	}

	public static function footerVersion( string $text ): string {
		$kernel = PainelKernel::instance();
		if ( ! $kernel || ! $kernel->permissions->mayEnterAdminShell() ) {
			return $text;
		}
		$version = defined( 'RSES_VERSION' ) ? RSES_VERSION : '';
		return esc_html(
			sprintf(
				/* translators: %s: plugin version */
				__( 'Version %s', 'relatasoft-secure-election-suite' ),
				$version
			)
		);
	}
}
