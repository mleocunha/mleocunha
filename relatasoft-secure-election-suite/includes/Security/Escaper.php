<?php
/**
 * Output escaping utilities.
 *
 * @package RelataSoft\SecureElectionSuite\Security
 */

namespace RelataSoft\SecureElectionSuite\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Escaper helpers.
 */
class Escaper {

	/**
	 * Escape HTML text.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function rses_html( string $value ): string {
		return esc_html( $value );
	}

	/**
	 * Escape HTML attribute.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function rses_attr( string $value ): string {
		return esc_attr( $value );
	}

	/**
	 * Escape URL.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function rses_url( string $value ): string {
		return esc_url( $value );
	}

	/**
	 * Escape textarea content.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function rses_textarea( string $value ): string {
		return esc_textarea( $value );
	}

	/**
	 * Get attachment URL safely.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	public static function rses_attachment_url( int $attachment_id ): string {
		if ( $attachment_id <= 0 ) {
			return '';
		}

		$rses_url = wp_get_attachment_url( $attachment_id );
		return $rses_url ? esc_url( $rses_url ) : '';
	}
}
