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
use RelataSoft\SecureElectionSuite\Frontend\JourneySettings;
use RelataSoft\SecureElectionSuite\Painel\Domain\Material\PublicKeyPackage;
use RelataSoft\SecureElectionSuite\Security\AuditLogger;
use RelataSoft\SecureElectionSuite\Security\Capability;

defined( 'ABSPATH' ) || exit;

/**
 * Exports keys, public keys, and Shamir shares.
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

		$rses_cliente = JourneySettings::rses_cliente_stamp();
		$rses_data    = PublicKeyPackage::build(
			array(
				'key_label'    => (string) ( $rses_key->key_label ?? '' ),
				'key_size'     => (int) $rses_key->key_size,
				'p'            => (string) $rses_key->public_p,
				'q'            => (string) $rses_key->public_q,
				'g'            => (string) $rses_key->public_g,
				'y'            => (string) $rses_key->public_y,
				'field_prime'  => (string) ( $rses_key->field_prime ?? '' ),
				'threshold_t'  => (int) ( $rses_key->threshold_t ?? 0 ),
				'total_n'      => (int) ( $rses_key->total_n ?? 0 ),
				'source_mode'  => 'key_authority',
				'cliente_id'   => $rses_cliente['cliente_id'],
				'cliente_nome' => $rses_cliente['cliente_nome'],
			)
		);

		AuditLogger::rses_log( 'key_export_public', 'key', $key_id );

		JsonExport::rses_send_download( 'public-key-' . $key_id . '.json', $rses_data );
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

		$rses_files = array();

		$rses_cliente = JourneySettings::rses_cliente_stamp();
		$rses_public  = PublicKeyPackage::build(
			array(
				'key_label'    => (string) ( $rses_key->key_label ?? '' ),
				'key_size'     => (int) $rses_key->key_size,
				'p'            => (string) $rses_key->public_p,
				'q'            => (string) $rses_key->public_q,
				'g'            => (string) $rses_key->public_g,
				'y'            => (string) $rses_key->public_y,
				'field_prime'  => (string) ( $rses_key->field_prime ?? '' ),
				'threshold_t'  => (int) ( $rses_key->threshold_t ?? 0 ),
				'total_n'      => (int) ( $rses_key->total_n ?? 0 ),
				'source_mode'  => 'key_authority',
				'cliente_id'   => $rses_cliente['cliente_id'],
				'cliente_nome' => $rses_cliente['cliente_nome'],
			)
		);

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

			$rses_files['own-share.json'] = ShareEncryptionService::rses_decrypt( $rses_share->share_payload_encrypted );
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
