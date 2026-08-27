<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Branding;

use RelataSoft\SecureElectionSuite\Admin\Brand;
use RelataSoft\SecureElectionSuite\Frontend\JourneySettings;

/**
 * Site-wide favicon (Roda de Fogo) for login, admin, and front.
 */
final class SiteIconBranding {

	public static function register(): void {
		add_action( 'login_head', array( self::class, 'printIcons' ), 1 );
		add_action( 'admin_head', array( self::class, 'printIcons' ), 1 );
		add_action( 'wp_head', array( self::class, 'printIcons' ), 1 );
		// Prefer our mark over the WP Customizer site icon when present.
		add_filter( 'get_site_icon_url', array( self::class, 'filterSiteIconUrl' ), 20, 3 );
	}

	/**
	 * Absolute URL for the Roda de Fogo favicon.
	 */
	public static function iconUrl(): string {
		if ( class_exists( JourneySettings::class ) ) {
			$url = JourneySettings::rses_get_login_logo_url();
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}
		return Brand::rses_asset_url( Brand::RSES_DEFAULT_MARK );
	}

	public static function printIcons(): void {
		$url = esc_url( self::iconUrl() );
		echo '<link rel="icon" href="' . $url . '" type="image/png" sizes="any" />' . "\n";
		echo '<link rel="shortcut icon" href="' . $url . '" type="image/png" />' . "\n";
		echo '<link rel="apple-touch-icon" href="' . $url . '" />' . "\n";
	}

	/**
	 * @param string $url       Existing icon URL.
	 * @param int    $size      Requested size.
	 * @param int    $blog_id   Blog ID.
	 */
	public static function filterSiteIconUrl( $url, $size = 512, $blog_id = 0 ): string {
		unset( $size, $blog_id );
		$mark = self::iconUrl();
		return is_string( $mark ) && '' !== $mark ? $mark : (string) $url;
	}
}
