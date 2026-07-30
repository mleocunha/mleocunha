<?php
/**
 * Voter journey and login branding settings.
 *
 * @package RelataSoft\SecureElectionSuite\Frontend
 */

namespace RelataSoft\SecureElectionSuite\Frontend;

use RelataSoft\SecureElectionSuite\Admin\Brand;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes rses_settings keys for the elector journey.
 */
class JourneySettings {

	/**
	 * Post meta key marking plugin-managed journey pages.
	 */
	public const RSES_JOURNEY_META = '_rses_journey_page';

	/**
	 * Default settings merged with stored option.
	 *
	 * @return array<string,mixed>
	 */
	public static function rses_defaults(): array {
		return array(
			'allow_full_private_export' => false,
			'login_logo_attachment_id'  => 0,
			'welcome_page_id'           => 0,
			'booth_page_id'             => 0,
			'thank_you_page_id'         => 0,
			'logout_redirect_url'       => '',
		);
	}

	/**
	 * Get merged settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function rses_get(): array {
		$rses_saved = get_option( 'rses_settings', array() );
		if ( ! is_array( $rses_saved ) ) {
			$rses_saved = array();
		}

		return array_merge( self::rses_defaults(), $rses_saved );
	}

	/**
	 * Persist a partial settings update.
	 *
	 * @param array<string,mixed> $rses_partial Settings to merge.
	 */
	public static function rses_save( array $rses_partial ): void {
		update_option( 'rses_settings', array_merge( self::rses_get(), $rses_partial ) );
	}

	/**
	 * Login logo URL (custom attachment or default pinwheel).
	 */
	public static function rses_get_login_logo_url(): string {
		$rses_id = absint( self::rses_get()['login_logo_attachment_id'] ?? 0 );
		if ( $rses_id > 0 ) {
			$rses_url = wp_get_attachment_image_url( $rses_id, 'medium' );
			if ( is_string( $rses_url ) && '' !== $rses_url ) {
				return $rses_url;
			}
		}

		return Brand::rses_asset_url( 'relatasoft-mark.svg' );
	}

	/**
	 * Resolve a configured page URL.
	 *
	 * @param string $rses_key Setting key (welcome_page_id, booth_page_id, thank_you_page_id).
	 */
	public static function rses_page_url( string $rses_key ): string {
		$rses_settings = self::rses_get();
		$rses_id       = absint( $rses_settings[ $rses_key ] ?? 0 );
		if ( $rses_id < 1 ) {
			return '';
		}

		$rses_url = get_permalink( $rses_id );
		return is_string( $rses_url ) ? $rses_url : '';
	}

	/**
	 * Stored page ID for a journey step.
	 *
	 * @param string $rses_key Setting key.
	 */
	public static function rses_page_id( string $rses_key ): int {
		return absint( self::rses_get()[ $rses_key ] ?? 0 );
	}
}
