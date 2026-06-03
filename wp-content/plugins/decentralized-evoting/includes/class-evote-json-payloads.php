<?php
/**
 * JSON payload shapes exchanged between nodes.
 *
 * @package DecentralizedEvoting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Documented schemas for cross-node transfer (validation in later phases).
 */
class EVote_Json_Payloads {

	const SCHEMA_PUBLIC_KEY   = 'evote-public-key';
	const SCHEMA_SSS_SHARE    = 'evote-sss-share';
	const SCHEMA_BALLOT       = 'evote-encrypted-ballot';
	const SCHEMA_BALLOT_EXPORT = 'evote-ballot-export';
	const VERSION             = '1';

	/**
	 * Skeleton for public key export (Node 1 → Node 2).
	 *
	 * @return array<string, mixed>
	 */
	public static function public_key_skeleton() {
		return array(
			'schema'     => self::SCHEMA_PUBLIC_KEY,
			'version'    => self::VERSION,
			'created_at' => gmdate( 'c' ),
			'curve'      => 'P-256',
			'p'          => '<prime hex>',
			'g'          => '<generator hex>',
			'y'          => '<public key y hex>',
			'key_id'     => '<uuid>',
			'meta'       => array(
				'threshold' => 3,
				'shares'    => 5,
			),
		);
	}

	/**
	 * Skeleton for one Shamir share (Node 1 → trustees).
	 *
	 * @return array<string, mixed>
	 */
	public static function sss_share_skeleton() {
		return array(
			'schema'     => self::SCHEMA_SSS_SHARE,
			'version'    => self::VERSION,
			'key_id'     => '<uuid>',
			'share_index' => 1,
			'threshold'  => 3,
			'total_shares' => 5,
			'x'          => '<evaluation point hex>',
			'value'      => '<share value hex>',
		);
	}

	/**
	 * Single encrypted ballot record.
	 *
	 * @return array<string, mixed>
	 */
	public static function encrypted_ballot_skeleton() {
		return array(
			'schema'  => self::SCHEMA_BALLOT,
			'version' => self::VERSION,
			'c1'      => '<ciphertext component 1 hex>',
			'c2'      => '<ciphertext component 2 hex>',
			'proof'   => null,
		);
	}

	/**
	 * Full ballot box export (Node 2 → Node 3).
	 *
	 * @return array<string, mixed>
	 */
	public static function ballot_export_skeleton() {
		return array(
			'schema'      => self::SCHEMA_BALLOT_EXPORT,
			'version'     => self::VERSION,
			'exported_at' => gmdate( 'c' ),
			'running'     => array(
				'id'    => 0,
				'title' => '',
				'modality_type' => 'single',
			),
			'public_key'  => self::public_key_skeleton(),
			'ballots'     => array(
				self::encrypted_ballot_skeleton(),
			),
			'checksum'    => '<sha256 of canonical json>',
		);
	}

	/**
	 * Validate public key JSON structure (basic, Phase 1).
	 *
	 * @param array<string, mixed> $data Decoded JSON.
	 * @return true|WP_Error
	 */
	public static function validate_public_key( $data ) {
		if ( ! is_array( $data ) || ( $data['schema'] ?? '' ) !== self::SCHEMA_PUBLIC_KEY ) {
			return new WP_Error( 'evote_invalid_schema', __( 'Invalid public key schema.', 'decentralized-evoting' ) );
		}
		foreach ( array( 'p', 'g', 'y', 'key_id' ) as $field ) {
			if ( empty( $data[ $field ] ) || ! is_string( $data[ $field ] ) ) {
				return new WP_Error( 'evote_missing_field', sprintf( __( 'Missing field: %s', 'decentralized-evoting' ), $field ) );
			}
		}
		return true;
	}
}
