<?php
/**
 * Parse and validate JSON imports.
 *
 * @package DecentralizedEvoting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ballot box import (Node 3).
 */
class EVote_Json_Import {

	/**
	 * Parse raw JSON string.
	 *
	 * @param string $raw JSON text.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function parse( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return new WP_Error( 'evote_empty', __( 'Empty JSON input.', 'decentralized-evoting' ) );
		}
		$data = json_decode( $raw, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
			return new WP_Error( 'evote_json', __( 'Invalid JSON.', 'decentralized-evoting' ) );
		}
		return $data;
	}

	/**
	 * Validate ballot box export and checksum.
	 *
	 * @param array<string, mixed> $data Decoded export.
	 * @return array<string, mixed>|WP_Error Normalized package.
	 */
	public static function ballot_box( $data ) {
		$valid = EVote_Json_Payloads::validate_ballot_export( $data );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$checksum = $data['checksum'] ?? '';
		unset( $data['checksum'] );
		$expected = EVote_Json_Payloads::compute_checksum( $data );
		$data['checksum'] = $checksum;

		if ( ! hash_equals( $expected, (string) $checksum ) ) {
			return new WP_Error( 'evote_checksum', __( 'Checksum mismatch — export may be corrupted.', 'decentralized-evoting' ) );
		}

		return $data;
	}
}
