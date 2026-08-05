<?php
/**
 * Key export service.
 *
 * @package RelataSoft\SecureElectionSuite\KeyAuthority
 */

namespace RelataSoft\SecureElectionSuite\KeyAuthority;

use RelataSoft\SecureElectionSuite\Crypto\CeremonyTranscript;
use RelataSoft\SecureElectionSuite\Crypto\FeldmanVss;
use RelataSoft\SecureElectionSuite\Exports\JsonExport;
use RelataSoft\SecureElectionSuite\Exports\ManifestBuilder;
use RelataSoft\SecureElectionSuite\Exports\ZipExport;
use RelataSoft\SecureElectionSuite\Security\AuditLogger;
use RelataSoft\SecureElectionSuite\Security\Capability;

defined( 'ABSPATH' ) || exit;

/**
 * Exports keys, public keys, and Feldman VSS shares.
 */
class KeyExportService {

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

		$rses_data = array(
			'p'           => $rses_key->public_p,
			'q'           => $rses_key->public_q,
			'g'           => $rses_key->public_g,
			'y'           => $rses_key->public_y,
			'keySizeBits' => (int) $rses_key->key_size,
			'scheme_id'   => (string) ( $rses_key->scheme_id ?? FeldmanVss::SCHEME_ID ),
			'ceremony_id' => (string) ( $rses_key->ceremony_id ?? '' ),
		);

		AuditLogger::rses_log( 'key_export_public', 'key', $key_id );

		JsonExport::rses_send_download( 'public-key-' . $key_id . '.json', $rses_data );
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

		$rses_plain = ShareEncryptionService::rses_decrypt( $rses_share->share_payload_encrypted );
		$rses_data  = json_decode( $rses_plain, true );
		if ( ! is_array( $rses_data ) ) {
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

		$rses_public = array(
			'p'           => $rses_key->public_p,
			'q'           => $rses_key->public_q,
			'g'           => $rses_key->public_g,
			'y'           => $rses_key->public_y,
			'keySizeBits' => (int) $rses_key->key_size,
		);

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

			$rses_files['encrypted-share.json'] = ShareEncryptionService::rses_decrypt( $rses_share->share_payload_encrypted );
			$rses_files['own-share.json']       = $rses_files['encrypted-share.json'];
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
		if ( empty( $key->scheme_id ) || FeldmanVss::SCHEME_ID !== (string) $key->scheme_id ) {
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
				'2. Paste encrypted-share.json / own-share.json contents.',
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
