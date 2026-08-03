<?php
/**
 * Key export service.
 *
 * @package RelataSoft\SecureElectionSuite\KeyAuthority
 */

namespace RelataSoft\SecureElectionSuite\KeyAuthority;

use RelataSoft\SecureElectionSuite\Exports\JsonExport;
use RelataSoft\SecureElectionSuite\Exports\ManifestBuilder;
use RelataSoft\SecureElectionSuite\Exports\ZipExport;
use RelataSoft\SecureElectionSuite\Security\AuditLogger;
use RelataSoft\SecureElectionSuite\Security\Capability;
use RelataSoft\SecureElectionSuite\Voting\ElectionRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Exports keys, public keys, and Shamir shares.
 */
class KeyExportService {

	/**
	 * Package type for human-readable Shamir share exports.
	 */
	public const RSES_SHARE_PACKAGE = 'shamir-share-v1';

	/**
	 * Export public key as JSON download.
	 *
	 * @param int $key_id Key ID.
	 */
	public static function rses_export_public_json( int $key_id ): void {
		$rses_key = KeyRepository::rses_get( $key_id );

		if ( ! $rses_key ) {
			wp_die( esc_html__( 'Key not found.', 'relatasoft-secure-election-suite' ) );
		}

		$rses_data = self::rses_public_key_export_array( $rses_key );

		AuditLogger::rses_log( 'key_export_public', 'key', $key_id );

		JsonExport::rses_send_download( 'public-key-' . $key_id . '.json', $rses_data );
	}

	/**
	 * Public-key JSON fields for Voting / Tallying sites (includes identity).
	 *
	 * @param object $key Key row.
	 * @return array<string,mixed>
	 */
	public static function rses_public_key_export_array( object $key ): array {
		return array(
			'p'           => $key->public_p,
			'q'           => $key->public_q,
			'g'           => $key->public_g,
			'y'           => $key->public_y,
			'keySizeBits' => (int) $key->key_size,
			'key_id'      => (int) $key->id,
			'key_label'   => (string) $key->key_label,
		);
	}

	/**
	 * Export the current official's own Shamir share as JSON.
	 *
	 * @param int $key_id Key ID.
	 */
	public static function rses_export_own_share_json( int $key_id ): void {
		if ( ! Capability::rses_can_export_own_share() ) {
			wp_die(
				esc_html__( 'Only election officials may export their Shamir share.', 'relatasoft-secure-election-suite' ),
				esc_html__( 'Permission Denied', 'relatasoft-secure-election-suite' ),
				array( 'response' => 403 )
			);
		}

		$rses_share = KeyRepository::rses_get_share_for_user( $key_id, get_current_user_id() );
		if ( ! $rses_share ) {
			wp_die( esc_html__( 'No Shamir share is assigned to your account for this key.', 'relatasoft-secure-election-suite' ) );
		}

		$rses_data = self::rses_package_own_share( $key_id );
		if ( null === $rses_data ) {
			wp_die( esc_html__( 'Stored share payload is invalid.', 'relatasoft-secure-election-suite' ) );
		}

		AuditLogger::rses_log( 'share_export_own_json', 'share', (int) $rses_share->id );

		JsonExport::rses_send_download(
			'own-share-key-' . $key_id . '-index-' . (int) $rses_share->share_index . '.json',
			$rses_data
		);
	}

	/**
	 * Decrypt and return the current user's share payload for on-screen viewing.
	 *
	 * @param int $key_id Key ID.
	 * @return array<string,mixed>|null
	 */
	public static function rses_get_own_share_payload( int $key_id ): ?array {
		if ( ! Capability::rses_can_export_own_share() ) {
			return null;
		}

		$rses_share = KeyRepository::rses_get_share_for_user( $key_id, get_current_user_id() );
		if ( ! $rses_share ) {
			return null;
		}

		$rses_plain = ShareEncryptionService::rses_decrypt( $rses_share->share_payload_encrypted );
		$rses_data  = json_decode( $rses_plain, true );

		return is_array( $rses_data ) ? $rses_data : null;
	}

	/**
	 * Build a human-readable share package (identity + cryptographic share).
	 *
	 * The inner `share` object keeps the original checksum. Outer fields help
	 * officials match the fraction to the correct election on Tallying.
	 *
	 * @param int $key_id Key ID.
	 * @return array<string,mixed>|null
	 */
	public static function rses_package_own_share( int $key_id ): ?array {
		$rses_key     = KeyRepository::rses_get( $key_id );
		$rses_payload = self::rses_get_own_share_payload( $key_id );
		if ( ! $rses_key || ! is_array( $rses_payload ) ) {
			return null;
		}

		$rses_linked = array();
		foreach ( ElectionRepository::rses_list_usage_by_key( $key_id ) as $rses_row ) {
			$rses_linked[] = array(
				'election_id'    => (int) $rses_row->election_id,
				'election_title' => (string) $rses_row->election_title,
				'round_id'       => (int) $rses_row->round_id,
				'round_title'    => (string) ( $rses_row->round_title ?? '' ),
				'round_number'   => isset( $rses_row->round_number ) ? (int) $rses_row->round_number : null,
				'round_status'   => (string) ( $rses_row->round_status ?? '' ),
			);
		}

		return array(
			'rses_package'     => self::RSES_SHARE_PACKAGE,
			'key_id'           => (int) $rses_key->id,
			'key_label'        => (string) $rses_key->key_label,
			'key_description'  => (string) ( $rses_key->description ?? '' ),
			'public_y_prefix'  => substr( (string) $rses_key->public_y, 0, 20 ),
			'share_index'      => (int) ( $rses_payload['share_index'] ?? 0 ),
			'threshold_t'      => (int) ( $rses_key->threshold_t ?? $rses_payload['threshold_t'] ?? 0 ),
			'total_n'          => (int) ( $rses_key->total_n ?? $rses_payload['total_n'] ?? 0 ),
			'linked_elections' => $rses_linked,
			'note'             => __(
				'Paste this JSON on the Tallying site under Share Submission. Match key_label (and linked elections when shown) to the election card before submitting.',
				'relatasoft-secure-election-suite'
			),
			'share'            => $rses_payload,
		);
	}

	/**
	 * Unwrap a share package (or accept a legacy bare share payload).
	 *
	 * @param array<string,mixed> $payload Submitted JSON.
	 * @return array<string,mixed> Cryptographic share payload.
	 */
	public static function rses_unwrap_share_payload( array $payload ): array {
		if (
			isset( $payload['share'] )
			&& is_array( $payload['share'] )
			&& (
				( $payload['rses_package'] ?? '' ) === self::RSES_SHARE_PACKAGE
				|| ! array_key_exists( 'checksum', $payload )
			)
		) {
			return $payload['share'];
		}

		return $payload;
	}

	/**
	 * Format linked election lines for admin UI.
	 *
	 * @param int $key_id Key ID.
	 * @return array<int,string>
	 */
	public static function rses_linked_election_labels( int $key_id ): array {
		$rses_labels = array();
		foreach ( ElectionRepository::rses_list_usage_by_key( $key_id ) as $rses_row ) {
			$rses_round = trim( (string) ( $rses_row->round_title ?? '' ) );
			if ( '' === $rses_round && isset( $rses_row->round_number ) ) {
				$rses_round = sprintf(
					/* translators: %d: round number */
					__( 'Round %d', 'relatasoft-secure-election-suite' ),
					(int) $rses_row->round_number
				);
			}
			$rses_labels[] = trim(
				(string) $rses_row->election_title
				. ( '' !== $rses_round ? ' — ' . $rses_round : '' )
				. ( ! empty( $rses_row->round_status ) ? ' (' . $rses_row->round_status . ')' : '' )
			);
		}
		return array_values( array_filter( $rses_labels ) );
	}

	/**
	 * Export key package as ZIP.
	 *
	 * @param int  $key_id       Key ID.
	 * @param bool $include_full Include private key and all shares (admin only).
	 * @param bool $own_share    Export only own share (official).
	 */
	public static function rses_export_zip( int $key_id, bool $include_full = false, bool $own_share = false ): void {
		$rses_key = KeyRepository::rses_get( $key_id );

		if ( ! $rses_key ) {
			wp_die( esc_html__( 'Key not found.', 'relatasoft-secure-election-suite' ) );
		}

		$rses_files = array();

		$rses_public = self::rses_public_key_export_array( $rses_key );

		$rses_files['public-key.json'] = wp_json_encode( $rses_public, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		if ( $include_full && Capability::rses_can_export_all_shares() ) {
			if ( ! empty( $rses_key->private_x_encrypted ) ) {
				$rses_files['private-key.json'] = ShareEncryptionService::rses_decrypt( $rses_key->private_x_encrypted );
			}

			$rses_all_shares = array();
			foreach ( KeyRepository::rses_get_shares( $key_id ) as $rses_share ) {
				$rses_all_shares[] = json_decode(
					ShareEncryptionService::rses_decrypt( $rses_share->share_payload_encrypted ),
					true
				);
			}
			$rses_files['shamir-shares.json'] = wp_json_encode( $rses_all_shares, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

			AuditLogger::rses_log( 'key_export_full', 'key', $key_id, array( 'warning' => 'full_export' ) );
		} elseif ( $own_share ) {
			if ( ! Capability::rses_can_export_own_share() ) {
				wp_die(
					esc_html__( 'Only election officials may export their Shamir share.', 'relatasoft-secure-election-suite' ),
					esc_html__( 'Permission Denied', 'relatasoft-secure-election-suite' ),
					array( 'response' => 403 )
				);
			}

			$rses_share = KeyRepository::rses_get_share_for_user( $key_id, get_current_user_id() );
			if ( ! $rses_share ) {
				wp_die( esc_html__( 'No Shamir share is assigned to your account for this key.', 'relatasoft-secure-election-suite' ) );
			}

			$rses_package = self::rses_package_own_share( $key_id );
			if ( null === $rses_package ) {
				wp_die( esc_html__( 'Stored share payload is invalid.', 'relatasoft-secure-election-suite' ) );
			}

			$rses_files['own-share.json'] = wp_json_encode( $rses_package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			AuditLogger::rses_log( 'share_export_own', 'share', (int) $rses_share->id );
		} else {
			AuditLogger::rses_log( 'key_export_public_zip', 'key', $key_id );
		}

		$rses_manifest = ManifestBuilder::rses_build_key_manifest( $key_id, $rses_public );
		$rses_files['manifest.json']   = wp_json_encode( $rses_manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$rses_files['checksums.json']  = wp_json_encode( ManifestBuilder::rses_build_checksums( $rses_files ), JSON_PRETTY_PRINT );
		$rses_files['README.txt']      = __( 'RelataSoft Secure Election Suite - Key Export Package', 'relatasoft-secure-election-suite' );

		ZipExport::rses_send_download( 'key-export-' . $key_id . '.zip', $rses_files );
	}
}
