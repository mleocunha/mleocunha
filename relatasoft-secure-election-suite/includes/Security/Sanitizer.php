<?php
/**
 * Input sanitization utilities.
 *
 * @package RelataSoft\SecureElectionSuite\Security
 */

namespace RelataSoft\SecureElectionSuite\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Sanitizer helpers.
 */
class Sanitizer {

	/**
	 * Sanitize a positive integer ID.
	 *
	 * @param mixed $value Input value.
	 * @return int
	 */
	public static function rses_id( $value ): int {
		return absint( $value );
	}

	/**
	 * Sanitize text field.
	 *
	 * @param mixed $value Input value.
	 * @return string
	 */
	public static function rses_text( $value ): string {
		return sanitize_text_field( (string) $value );
	}

	/**
	 * Sanitize textarea field.
	 *
	 * @param mixed $value Input value.
	 * @return string
	 */
	public static function rses_textarea( $value ): string {
		return sanitize_textarea_field( (string) $value );
	}

	/**
	 * Sanitize URL.
	 *
	 * @param mixed $value Input value.
	 * @return string
	 */
	public static function rses_url( $value ): string {
		return esc_url_raw( (string) $value );
	}

	/**
	 * Sanitize filename.
	 *
	 * @param string $filename Filename.
	 * @return string
	 */
	public static function rses_filename( string $filename ): string {
		return sanitize_file_name( $filename );
	}

	/**
	 * Validate and decode JSON.
	 *
	 * @param string $json JSON string.
	 * @return array<string,mixed>|null
	 */
	public static function rses_json( string $json ): ?array {
		if ( '' === $json ) {
			return null;
		}

		$rses_decoded = json_decode( $json, true );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $rses_decoded ) ) {
			return null;
		}

		return $rses_decoded;
	}

	/**
	 * Sanitize decimal string for big integers.
	 *
	 * @param mixed $value Input value.
	 * @return string
	 */
	public static function rses_decimal_string( $value ): string {
		$rses_value = preg_replace( '/[^0-9]/', '', (string) $value );
		return $rses_value ?? '';
	}

	/**
	 * Sanitize mode slug.
	 *
	 * @param mixed $value Input value.
	 * @return string
	 */
	public static function rses_mode( $value ): string {
		$rses_mode = sanitize_key( (string) $value );
		$rses_valid = array( 'key_authority', 'voting', 'tallying' );
		return in_array( $rses_mode, $rses_valid, true ) ? $rses_mode : '';
	}

	/**
	 * Get POST value sanitized as text.
	 *
	 * @param string $key POST key.
	 * @return string
	 */
	public static function rses_post_text( string $key ): string {
		if ( ! isset( $_POST[ $key ] ) ) {
			return '';
		}
		return self::rses_text( wp_unslash( $_POST[ $key ] ) );
	}

	/**
	 * Get POST value sanitized as ID.
	 *
	 * @param string $key POST key.
	 * @return int
	 */
	public static function rses_post_id( string $key ): int {
		if ( ! isset( $_POST[ $key ] ) ) {
			return 0;
		}
		return self::rses_id( wp_unslash( $_POST[ $key ] ) );
	}
}
