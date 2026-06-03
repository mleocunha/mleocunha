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
 * Documented schemas for cross-node transfer.
 */
class EVote_Json_Payloads {

	const SCHEMA_PUBLIC_KEY    = 'evote-public-key';
	const SCHEMA_SSS_SHARE     = 'evote-sss-share';
	const SCHEMA_BALLOT        = 'evote-encrypted-ballot';
	const SCHEMA_BALLOT_EXPORT = 'evote-ballot-export';
	const SCHEMA_TALLY_RESULT  = 'evote-tally-result';
	const VERSION              = '1';

	/**
	 * Skeleton for public key export (Node 1 → Node 2).
	 *
	 * @return array<string, mixed>
	 */
	public static function public_key_skeleton() {
		return array(
			'schema'     => self::SCHEMA_PUBLIC_KEY,
			'version'    => self::VERSION,
			'scheme'     => EVote_Elgamal::SCHEME,
			'group'      => 'rfc3526-group14',
			'created_at' => gmdate( 'c' ),
			'key_id'     => '<uuid>',
			'p'          => '<prime hex>',
			'q'          => '<subgroup order hex>',
			'g'          => '<generator hex>',
			'y'          => '<public key y hex>',
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
			'schema'       => self::SCHEMA_SSS_SHARE,
			'version'      => self::VERSION,
			'scheme'       => EVote_Elgamal::SCHEME,
			'key_id'       => '<uuid>',
			'share_index'  => 1,
			'threshold'    => 3,
			'total_shares' => 5,
			'x'            => '<evaluation point hex>',
			'value'        => '<share value hex>',
			'field_prime'  => '<q hex>',
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
				'id'            => 0,
				'title'         => '',
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
	 * Validate public key JSON structure.
	 *
	 * @param array<string, mixed> $data Decoded JSON.
	 * @return true|WP_Error
	 */
	public static function validate_public_key( $data ) {
		if ( ! is_array( $data ) || ( $data['schema'] ?? '' ) !== self::SCHEMA_PUBLIC_KEY ) {
			return new WP_Error( 'evote_invalid_schema', __( 'Invalid public key schema.', 'decentralized-evoting' ) );
		}
		if ( ( $data['scheme'] ?? '' ) !== EVote_Elgamal::SCHEME ) {
			return new WP_Error( 'evote_invalid_scheme', __( 'Unsupported public key scheme.', 'decentralized-evoting' ) );
		}
		foreach ( array( 'p', 'q', 'g', 'y', 'key_id' ) as $field ) {
			if ( empty( $data[ $field ] ) || ! is_string( $data[ $field ] ) ) {
				return new WP_Error( 'evote_missing_field', sprintf( __( 'Missing field: %s', 'decentralized-evoting' ), $field ) );
			}
		}
		if ( empty( $data['meta']['threshold'] ) || empty( $data['meta']['shares'] ) ) {
			return new WP_Error( 'evote_missing_meta', __( 'Public key meta must include threshold and shares.', 'decentralized-evoting' ) );
		}
		return true;
	}

	/**
	 * Validate one SSS share JSON object.
	 *
	 * @param array<string, mixed> $data Decoded JSON.
	 * @return true|WP_Error
	 */
	public static function validate_sss_share( $data ) {
		if ( ! is_array( $data ) || ( $data['schema'] ?? '' ) !== self::SCHEMA_SSS_SHARE ) {
			return new WP_Error( 'evote_invalid_schema', __( 'Invalid SSS share schema.', 'decentralized-evoting' ) );
		}
		if ( ( $data['scheme'] ?? '' ) !== EVote_Elgamal::SCHEME ) {
			return new WP_Error( 'evote_invalid_scheme', __( 'Unsupported share scheme.', 'decentralized-evoting' ) );
		}
		foreach ( array( 'key_id', 'x', 'value', 'field_prime', 'threshold' ) as $field ) {
			if ( ! isset( $data[ $field ] ) || ( is_string( $data[ $field ] ) && '' === $data[ $field ] ) ) {
				return new WP_Error( 'evote_missing_field', sprintf( __( 'Missing field: %s', 'decentralized-evoting' ), $field ) );
			}
		}
		return true;
	}

	/**
	 * Validate encrypted ballot JSON.
	 *
	 * @param array<string, mixed> $data Decoded JSON.
	 * @return true|WP_Error
	 */
	public static function validate_encrypted_ballot( $data ) {
		if ( ! is_array( $data ) || ( $data['schema'] ?? '' ) !== self::SCHEMA_BALLOT ) {
			return new WP_Error( 'evote_invalid_schema', __( 'Invalid encrypted ballot schema.', 'decentralized-evoting' ) );
		}
		foreach ( array( 'c1', 'c2' ) as $field ) {
			if ( empty( $data[ $field ] ) || ! is_string( $data[ $field ] ) ) {
				return new WP_Error( 'evote_missing_field', sprintf( __( 'Missing field: %s', 'decentralized-evoting' ), $field ) );
			}
		}
		return true;
	}

	/**
	 * Validate ballot box export structure (checksum verified separately).
	 *
	 * @param array<string, mixed> $data Decoded export.
	 * @return true|WP_Error
	 */
	public static function validate_ballot_export( $data ) {
		if ( ! is_array( $data ) || ( $data['schema'] ?? '' ) !== self::SCHEMA_BALLOT_EXPORT ) {
			return new WP_Error( 'evote_invalid_schema', __( 'Invalid ballot export schema.', 'decentralized-evoting' ) );
		}
		if ( empty( $data['checksum'] ) || ! is_string( $data['checksum'] ) ) {
			return new WP_Error( 'evote_missing_checksum', __( 'Export missing checksum.', 'decentralized-evoting' ) );
		}
		if ( ! is_array( $data['public_key'] ?? null ) ) {
			return new WP_Error( 'evote_missing_pubkey', __( 'Export missing public_key.', 'decentralized-evoting' ) );
		}
		$pk = self::validate_public_key( $data['public_key'] );
		if ( is_wp_error( $pk ) ) {
			return $pk;
		}
		if ( ! is_array( $data['ballots'] ?? null ) ) {
			return new WP_Error( 'evote_missing_ballots', __( 'Export missing ballots array.', 'decentralized-evoting' ) );
		}
		return true;
	}

	/**
	 * Canonical JSON encoding for checksums.
	 *
	 * @param array<string, mixed> $data Data without checksum field.
	 * @return string
	 */
	public static function canonical_encode( $data ) {
		return wp_json_encode( $data, JSON_UNESCAPED_SLASHES );
	}

	/**
	 * SHA-256 checksum of canonical payload (excluding checksum key).
	 *
	 * @param array<string, mixed> $data Export payload without checksum.
	 * @return string Hex digest.
	 */
	public static function compute_checksum( $data ) {
		$copy = $data;
		unset( $copy['checksum'] );
		return hash( 'sha256', self::canonical_encode( $copy ) );
	}
}
