<?php
/**
 * Key export service.
 *
 * @package RelataSoft\SecureElectionSuite\KeyAuthority
 */

namespace RelataSoft\SecureElectionSuite\KeyAuthority;

use RelataSoft\SecureElectionSuite\Crypto\CeremonyTranscript;
use RelataSoft\SecureElectionSuite\Crypto\CryptoSchemeRegistry;
use RelataSoft\SecureElectionSuite\Crypto\FeldmanVss;
use RelataSoft\SecureElectionSuite\Exports\JsonExport;
use RelataSoft\SecureElectionSuite\Exports\ManifestBuilder;
use RelataSoft\SecureElectionSuite\Exports\ZipExport;
use RelataSoft\SecureElectionSuite\Security\AuditLogger;
use RelataSoft\SecureElectionSuite\Security\Capability;
use RelataSoft\SecureElectionSuite\Voting\ElectionRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Exports keys, public keys, and Feldman VSS shares.
 */
class KeyExportService {

	/**
	 * Package type for human-readable Feldman VSS share exports.
	 */
	public const RSES_SHARE_PACKAGE = 'feldman-share-v1';

	/**
	 * Legacy package id accepted on unwrap (lab Shamir branch).
	 */
	public const RSES_SHARE_PACKAGE_LEGACY = 'shamir-share-v1';

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
			'scheme_id'   => (string) ( $key->scheme_id ?? FeldmanVss::SCHEME_ID ),
			'ceremony_id' => (string) ( $key->ceremony_id ?? '' ),
		);
	}

	/**
	 * Export the current official's own Feldman share as JSON.
	 *
	 * @param int $key_id Key ID.
	 */
	public static function rses_export_own_share_json( int $key_id ): void {
		if ( ! Capability::rses_can_export_own_share() ) {
			wp_die(
				esc_html__( 'Only election officials may export their share.', 'relatasoft-secure-election-suite' ),
				esc_html__( 'Permission Denied', 'relatasoft-secure-election-suite' ),
				array( 'response' => 403 )
			);
		}

		$rses_key = KeyRepository::rses_get( $key_id );
		if ( $rses_key && ! KeyRepository::rses_ceremony_is_active( $rses_key ) ) {
			wp_die(
				esc_html__( 'This ceremony was invalidated after a failed share verification. Generate new election material.', 'relatasoft-secure-election-suite' ),
				esc_html__( 'Ceremony invalid', 'relatasoft-secure-election-suite' ),
				array( 'response' => 409 )
			);
		}

		$rses_share = KeyRepository::rses_get_share_for_user( $key_id, get_current_user_id() );
		if ( ! $rses_share ) {
			wp_die( esc_html__( 'No share is assigned to your account for this key.', 'relatasoft-secure-election-suite' ) );
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

		$rses_y = (string) $rses_key->public_y;

		return array(
			'rses_package'           => self::RSES_SHARE_PACKAGE,
			'key_id'                 => (int) $rses_key->id,
			'key_label'              => (string) $rses_key->key_label,
			'key_description'        => (string) ( $rses_key->description ?? '' ),
			'source_site'            => get_site_url(),
			'public_key_fingerprint' => substr( hash( 'sha256', $rses_y ), 0, 12 ),
			'public_y_prefix'        => substr( $rses_y, 0, 20 ),
			'share_index'            => (int) ( $rses_payload['share_index'] ?? 0 ),
			'threshold_t'            => (int) ( $rses_key->threshold_t ?? $rses_payload['threshold_t'] ?? 0 ),
			'total_n'                => (int) ( $rses_key->total_n ?? $rses_payload['total_n'] ?? 0 ),
			'scheme_id'              => (string) ( $rses_key->scheme_id ?? FeldmanVss::SCHEME_ID ),
			'ceremony_id'            => (string) ( $rses_key->ceremony_id ?? '' ),
			'linked_elections'       => $rses_linked,
			'note'                   => __(
				'On Tallying, paste this JSON into the card for the matching imported election. Match by public_key_fingerprint and source_site — key labels may be identical across servers. Submit one fraction per election.',
				'relatasoft-secure-election-suite'
			),
			'share'                  => $rses_payload,
		);
	}

	/**
	 * Unwrap a share package (or accept a legacy bare share payload).
	 *
	 * Accepts feldman-share-v1, legacy shamir-share-v1, and bare cryptographic payloads.
	 *
	 * @param array<string,mixed> $payload Submitted JSON.
	 * @return array<string,mixed> Cryptographic share payload.
	 */
	public static function rses_unwrap_share_payload( array $payload ): array {
		$rses_package = (string) ( $payload['rses_package'] ?? '' );
		$rses_known   = in_array(
			$rses_package,
			array( self::RSES_SHARE_PACKAGE, self::RSES_SHARE_PACKAGE_LEGACY ),
			true
		);

		if (
			isset( $payload['share'] )
			&& is_array( $payload['share'] )
			&& (
				$rses_known
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

		if ( ! KeyRepository::rses_ceremony_is_active( $rses_key ) ) {
			wp_die(
				esc_html__( 'This ceremony was invalidated after a failed share verification. Generate new election material.', 'relatasoft-secure-election-suite' ),
				esc_html__( 'Ceremony invalid', 'relatasoft-secure-election-suite' ),
				array( 'response' => 409 )
			);
		}

		$rses_files = array();

		$rses_public = self::rses_public_key_export_array( $rses_key );

		$rses_files['public-key.json'] = wp_json_encode( $rses_public, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		foreach ( self::rses_transcript_files_for_key( $rses_key ) as $path => $contents ) {
			$rses_files[ $path ] = $contents;
		}

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
			$rses_files['feldman-shares.json'] = wp_json_encode( $rses_all_shares, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

			AuditLogger::rses_log( 'key_export_full', 'key', $key_id, array( 'warning' => 'full_export' ) );
		} elseif ( $own_share ) {
			if ( ! Capability::rses_can_export_own_share() ) {
				wp_die(
					esc_html__( 'Only election officials may export their share.', 'relatasoft-secure-election-suite' ),
					esc_html__( 'Permission Denied', 'relatasoft-secure-election-suite' ),
					array( 'response' => 403 )
				);
			}

			$rses_share = KeyRepository::rses_get_share_for_user( $key_id, get_current_user_id() );
			if ( ! $rses_share ) {
				wp_die( esc_html__( 'No share is assigned to your account for this key.', 'relatasoft-secure-election-suite' ) );
			}

			$rses_package = self::rses_package_own_share( $key_id );
			if ( null === $rses_package ) {
				wp_die( esc_html__( 'Stored share payload is invalid.', 'relatasoft-secure-election-suite' ) );
			}

			$rses_files['own-share.json']                = wp_json_encode( $rses_package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			$rses_files['encrypted-share.json']          = ShareEncryptionService::rses_decrypt( $rses_share->share_payload_encrypted );
			$rses_files['verification-instructions.txt'] = self::rses_verification_instructions();
			AuditLogger::rses_log( 'share_export_own', 'share', (int) $rses_share->id );
		} else {
			AuditLogger::rses_log( 'key_export_public_zip', 'key', $key_id );
		}

		$rses_manifest = ManifestBuilder::rses_build_key_manifest( $key_id, $rses_public );
		if ( ! empty( $rses_key->scheme_id ) ) {
			$rses_manifest['scheme_id']              = (string) $rses_key->scheme_id;
			$rses_manifest['ceremony_id']            = (string) ( $rses_key->ceremony_id ?? '' );
			$rses_manifest['public_transcript_hash'] = (string) ( $rses_key->public_transcript_hash ?? '' );
		}
		$rses_files['manifest.json']  = wp_json_encode( $rses_manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$rses_files['checksums.json'] = wp_json_encode( ManifestBuilder::rses_build_checksums( $rses_files ), JSON_PRETTY_PRINT );
		$rses_files['README.txt']     = __( 'RelataSoft Secure Election Suite - Key Export Package (Feldman VSS)', 'relatasoft-secure-election-suite' );

		ZipExport::rses_send_download( 'key-export-' . $key_id . '.zip', $rses_files );
	}

	/**
	 * @param object $key Key row.
	 * @return array<string,string>
	 */
	private static function rses_transcript_files_for_key( object $key ): array {
		$scheme_id = (string) ( $key->scheme_id ?? '' );
		if (
			'' === $scheme_id
			|| (
				FeldmanVss::SCHEME_ID !== $scheme_id
				&& CryptoSchemeRegistry::SCHEME_MODP_ELGAMAL_THRESHOLD_CP_V1 !== $scheme_id
			)
		) {
			return array();
		}
		if ( empty( $key->ceremony_transcript_json ) ) {
			return array();
		}

		$transcript = json_decode( (string) $key->ceremony_transcript_json, true );
		if ( ! is_array( $transcript ) ) {
			return array();
		}

		return CeremonyTranscript::rses_public_files( $transcript );
	}

	private static function rses_verification_instructions(): string {
		return implode(
			"\n",
			array(
				'RelataSoft Secure Election Suite — Feldman VSS share verification',
				'',
				'1. Open Key Authority → My Shamir Shares (or Verify my share).',
				'2. Paste encrypted-share.json / own-share.json contents (or the inner share object).',
				'3. Run “Verify my share”.',
				'4. Accept only SHARE VALID results.',
				'5. On SHARE INVALID, do not use the file — the ceremony must be regenerated.',
				'',
				'Offline math: g^{share} must equal Π commitments[k]^(index^k) mod p (Feldman VSS).',
				'Scheme: modp-elgamal-feldman-v1 (field = ElGamal subgroup order q).',
			)
		);
	}
}
